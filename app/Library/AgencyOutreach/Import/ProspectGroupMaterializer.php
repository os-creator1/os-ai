<?php

namespace App\Library\AgencyOutreach\Import;

use App\Enums\AgencyProspecting\AgencyProspectStatus;
use App\Library\AgencyOutreach\OutreachExistingClients;
use App\Library\Contacts\ContactPhone;
use App\Library\CustomFields\CustomFieldDefinitionManager;
use App\Library\CustomFields\CustomFieldRuleException;
use App\Library\CustomFields\CustomFieldValueService;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectGroupLink;
use App\Models\AgencyProspectImportBatch;
use App\Models\AgencyProspectImportRow;
use App\Models\Blacklists;
use App\Models\Business;
use App\Models\ContactGroupFields;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\ContactsCustomField;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use App\Models\Workspace;
use App\Library\Workspace\LocationAccessGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * One import → ONE Contact Group under the Agency's Outreach Business, with every enrichment value stored as a real
 * Business Custom Field value on its Contact. It reuses (never replaces) the CRM: Contact Groups, Contacts, the
 * built-in identity fields (FIRST_NAME / EMAIL / COMPANY) and the Custom Field services. The canonical Outreach
 * prospect (unique per Workspace + phone) is untouched; a link row records which groups reference it.
 *
 * Safety (the same for the live import and the explicit backfill):
 *   - never sends or queues anything;
 *   - STOP / opted-out, Existing Clients and blacklisted numbers get NO contact, so nothing is reactivated;
 *   - a number already owned by a Contact of this Business is never moved out of its group, never overwritten:
 *     only BLANK custom-field values are filled, and the prospect is linked to the new group as a reference;
 *   - idempotent: rows remember their Contact, the batch remembers its group, links are unique.
 */
final class ProspectGroupMaterializer
{
    /** normalised key => [label, type] */
    public const ENRICHMENT = [
        'business_type' => ['Business type', 'text'],
        'rating' => ['Google rating', 'number'],
        'review_count' => ['Review count', 'number'],
        'website' => ['Website URL', 'text'],
        'social_url' => ['Social URL', 'long_text'],
        'has_website' => ['Has website', 'boolean'],
        'address' => ['Address', 'text'],
        'city' => ['City', 'text'],
        'state' => ['State', 'text'],
        'country' => ['Country', 'text'],
        'maps_url' => ['Google Maps URL', 'long_text'],
        'opening_hours' => ['Opening hours', 'long_text'],
        'latitude' => ['Latitude', 'number'],
        'longitude' => ['Longitude', 'number'],
        'source' => ['Source', 'text'],
    ];

    public const ACTION_REUSE = 'reuse';
    public const ACTION_CREATE = 'create';
    public const ACTION_ARCHIVED = 'archived';
    public const ACTION_CONFLICT = 'conflict';
    public const ACTION_LIMIT = 'limit';

    public function __construct(
        private readonly CustomFieldDefinitionManager $definitions,
        private readonly CustomFieldValueService $values,
        private readonly OutreachExistingClients $clients,
    ) {
    }

    /**
     * Which Custom Fields will be created or reused. Pure read; shown to the user before they commit.
     *
     * @return list<array{source: string, key: string, label: string, type: string, action: string, note: ?string, definition_id: ?int}>
     */
    public function plan(Business $business, AgencyProspectImportBatch $batch): array
    {
        $mapping = array_filter((array) $batch->mapping);
        $headers = array_flip(array_filter($mapping, fn ($f) => is_string($f)));
        $all = CustomFieldDefinition::query()->where('business_id', (int) $business->id)->where('entity', CustomFieldDefinition::ENTITY_CONTACT)->get();
        $free = max(0, CustomFieldDefinitionManager::MAX_FIELDS - $all->count());
        $out = [];
        $wanted = [];

        foreach (self::ENRICHMENT as $key => [$label, $type]) {
            $derived = ($key === 'social_url' && isset($headers['website'])) || (in_array($key, ['city', 'state'], true) && isset($headers['address']));

            if (isset($headers[$key]) || $derived) {
                $wanted[] = ['source' => isset($headers[$key]) ? 'Column “' . $headers[$key] . '”' : ($key === 'social_url' ? 'Website column (social pages)' : 'Read from the address'),
                    'key' => $key, 'label' => $label, 'type' => $type];
            }
        }

        foreach ((array) $batch->extra_fields as $header => $cfg) {
            if (($cfg['action'] ?? 'ignore') === 'new' && trim((string) ($cfg['label'] ?? $header)) !== '') {
                $wanted[] = ['source' => 'Column “' . $header . '”', 'key' => 'extra:' . $header, 'label' => mb_substr(trim((string) ($cfg['label'] ?? $header)), 0, CustomFieldDefinitionManager::MAX_LABEL), 'type' => 'text'];
            } elseif (($cfg['action'] ?? '') === 'existing') {
                $def = $all->firstWhere('uid', (string) ($cfg['definition_uid'] ?? ''));
                $wanted[] = ['source' => 'Column “' . $header . '”', 'key' => 'extra:' . $header, 'label' => $def?->label ?? '—', 'type' => $def?->type ?? 'text', 'forced' => $def];
            }
        }

        $createdLabels = [];

        foreach ($wanted as $w) {
            $def = $w['forced'] ?? $this->match($all, $w['label']);
            $item = ['source' => $w['source'], 'key' => $w['key'], 'label' => $w['label'], 'type' => $w['type'], 'action' => self::ACTION_CREATE, 'note' => null, 'definition_id' => $def?->id];

            if ($def !== null) {
                if ($def->isArchived()) {
                    $item['action'] = self::ACTION_ARCHIVED;
                    $item['note'] = 'This field is archived, so its values are skipped. Restore it in Custom Fields to use it.';
                } elseif (! $this->compatible($def->type, $w['type'])) {
                    $item['action'] = self::ACTION_CONFLICT;
                    $item['note'] = 'A field with this name already exists as ' . $def->type . ', so these values are skipped.';
                } else {
                    $item['action'] = self::ACTION_REUSE;
                }
            } elseif (isset($createdLabels[mb_strtolower($w['label'])])) {
                $item['action'] = self::ACTION_CONFLICT;
                $item['note'] = 'Another column already creates a field with this name.';
            } elseif ($free <= 0) {
                $item['action'] = self::ACTION_LIMIT;
                $item['note'] = 'This business already has the maximum number of custom fields.';
            } else {
                $free--;
                $createdLabels[mb_strtolower($w['label'])] = true;
            }

            $out[] = $item;
        }

        return $out;
    }

    /**
     * Create (or, for a retry / backfill, reuse) the group and write contacts, identity and custom-field values.
     * $apply = false changes nothing and only reports.
     *
     * @return array<string, mixed> report
     */
    public function materialize(Workspace $workspace, Business $business, AgencyProspectImportBatch $batch, string $groupName, bool $apply = true, ?int $actorId = null, ?string $destinationUid = null): array
    {
        abort_unless((int) $batch->workspace_id === (int) $workspace->id, 404);

        // A finished import that already has its group is never reprocessed: a retry would recreate deleted Contacts, refill
        // Custom Fields the owner cleared, rewrite links and overwrite the original completion report. Return what was stored.
        // (A historical imported batch WITHOUT a group still materializes once.)
        if ($apply && $batch->status === 'imported' && $batch->contact_group_id !== null) {
            return ((array) $batch->group_report) + ['group_id' => $batch->contact_group_id, 'applied' => false, 'already_materialized' => true];
        }

        $report = ['group' => $groupName, 'group_id' => $batch->contact_group_id, 'group_uid' => null, 'created' => 0, 'existing_in_group' => 0, 'referenced' => 0,
            'skipped' => ['stop' => 0, 'client' => 0, 'blacklisted' => 0, 'no_prospect' => 0], 'values_saved' => 0, 'values_skipped' => 0,
            'fields' => [], 'name_conflict' => false, 'applied' => $apply];

        $plan = $this->plan($business, $batch);
        $report['fields'] = array_map(fn ($p) => ['label' => $p['label'], 'type' => $p['type'], 'action' => $p['action'], 'note' => $p['note']], $plan);

        $existingGroup = $batch->contact_group_id !== null
            ? ContactGroups::query()->where('id', $batch->contact_group_id)->where('business_id', $business->id)->first() : null;

        if ($destinationUid !== null && $existingGroup === null) {
            $existingGroup = ContactGroups::where('business_id', $business->id)->where('status', 1)
                ->where('uid', $destinationUid)->lockForUpdate()->firstOrFail();
            $report['group'] = $existingGroup->name;
        }

        if ($existingGroup === null && ContactGroups::query()->where('customer_id', $business->customer_id)->where('name', $groupName)->exists()) {
            // The same rule as the Contacts "new group" form: never silently merge into a group that already exists.
            $report['name_conflict'] = true;
        }

        $rows = AgencyProspectImportRow::query()->where('batch_id', $batch->id)->whereNotNull('prospect_id')
            ->whereIn('outcome', [ProspectImporter::OUT_NEW, ProspectImporter::OUT_EXISTING])->orderBy('id');

        if (! $apply || $report['name_conflict']) {
            $this->dryRun($workspace, $business, $batch, $rows, $report);

            return $report;
        }

        DB::transaction(function () use ($workspace, $business, $batch, $groupName, $existingGroup, $rows, $plan, $actorId, &$report) {
            $locked = AgencyProspectImportBatch::query()->whereKey($batch->id)->lockForUpdate()->first();
            $group = $existingGroup ?? ($locked->contact_group_id !== null
                ? ContactGroups::query()->where('id', $locked->contact_group_id)->where('business_id', $business->id)->first() : null);

            if ($group === null) {
                $group = ContactGroups::create(['customer_id' => $business->customer_id, 'business_id' => $business->id, 'name' => $groupName, 'status' => true]);
            }

            $fields = $this->identityFields($group);
            $defs = $this->resolveDefinitions($business, $plan);
            $report['group_id'] = $group->id;
            $report['group_uid'] = $group->uid;
            $extra = (array) $locked->extra_fields;

            $rows->chunkById(200, function ($chunk) use ($workspace, $business, $batch, $group, $fields, $defs, $extra, $actorId, &$report) {
                $blocked = $this->blacklisted($business, $chunk->pluck('phone')->filter()->all());

                foreach ($chunk as $row) {
                    $this->writeRow($workspace, $business, $batch, $group, $fields, $defs, $extra, $row, $blocked, $report, $actorId);
                }
            });

            $locked->update(['contact_group_id' => $group->id, 'group_name' => $group->name, 'group_report' => $report]);
        });

        return $report;
    }

    // ------------------------------------------------------------------------------------------------

    /** @param array<string, ContactGroupFields> $fields @param array<string, array{def: CustomFieldDefinition, type: string}> $defs */
    private function writeRow(Workspace $workspace, Business $business, AgencyProspectImportBatch $batch, ContactGroups $group, array $fields, array $defs, array $extra, AgencyProspectImportRow $row, array $blocked, array &$report, ?int $actorId = null): void
    {
        // Lock the canonical prospect: serialises concurrent imports of the same number.
        $prospect = AgencyProspect::query()->where('workspace_id', $workspace->id)->whereKey($row->prospect_id)->lockForUpdate()->first();

        if ($prospect === null) {
            $report['skipped']['no_prospect']++;

            return;
        }

        if ($prospect->status === AgencyProspectStatus::Stopped) {
            $report['skipped']['stop']++;

            return;
        }

        if ($prospect->client_won_at !== null || $this->clients->isExcluded((int) $workspace->id, $prospect->phone)) {
            $report['skipped']['client']++;

            return;
        }

        if (isset($blocked[$prospect->phone])) {
            $report['skipped']['blacklisted']++;

            return;
        }

        $phone = ContactPhone::canonical($prospect->phone, ContactPhone::regionFor($business)) ?: ContactPhone::legacyDigits($prospect->phone);
        $contact = $row->contact_id !== null ? Contacts::query()->where('business_id', $business->id)->find($row->contact_id) : null;
        // Equivalent formats (national / canonical / legacy digits) are ONE number: an existing Contact, subscribed or not, is
        // reused, so an unsubscribed Contact can never be bypassed by a freshly created subscribed duplicate.
        $contact ??= $this->contactForPhone($business, (string) $prospect->phone);
        $inGroup = true;

        // Same Location rule as the normal CRM edit path: a matched Contact at a Location the actor cannot reach is never modified.
        // The whole import is refused (and rolled back) rather than silently half-applied.
        if ($contact !== null && $actorId !== null && $contact->location_id !== null) {
            $location = $contact->location;

            if ($location === null || ! app(LocationAccessGuard::class)->userCanAccessLocation($actorId, $location)) {
                throw new AuthorizationException('This import matches a contact at a location you do not have access to.');
            }
        }

        if ($contact === null) {
            $contact = Contacts::create([
                'customer_id' => $business->customer_id, 'business_id' => $business->id, 'group_id' => $group->id,
                'phone' => $phone, 'status' => Contacts::STATUS_SUBSCRIBE,
            ]);
            $report['created']++;
            $this->writeIdentity($contact, $fields, (array) $row->normalized);
        } elseif ((int) $contact->group_id === (int) $group->id) {
            $report['existing_in_group']++;
        } else {
            // Owned by another group of this Business: never moved. Referenced through the link only.
            $inGroup = false;
            $report['referenced']++;
        }

        $this->writeValues($business, $contact, $defs, $extra, $row, $report);

        $row->update(['contact_id' => $contact->id]);

        AgencyProspectGroupLink::query()->updateOrCreate(
            ['contact_group_id' => $group->id, 'prospect_id' => $prospect->id],
            ['workspace_id' => $workspace->id, 'contact_id' => $contact->id, 'import_batch_id' => $batch->id, 'contact_in_group' => $inGroup],
        );
    }

    /** @param array<string, ContactGroupFields> $fields */
    private function writeIdentity(Contacts $contact, array $fields, array $v): void
    {
        $name = trim((string) ($v['contact_name'] ?? ''));
        [$first, $last] = $name === '' ? [null, null] : (str_contains($name, ' ') ? explode(' ', $name, 2) : [$name, null]);

        foreach (['FIRST_NAME' => $first, 'LAST_NAME' => $last, 'EMAIL' => $v['email'] ?? null, 'COMPANY' => $v['business_name'] ?? null] as $tag => $value) {
            if ($value !== null && trim((string) $value) !== '' && isset($fields[$tag])) {
                ContactsCustomField::query()->updateOrCreate(['contact_id' => $contact->id, 'field_id' => $fields[$tag]->id], ['value' => mb_substr(trim((string) $value), 0, 255)]);
            }
        }
    }

    /** Only BLANK values are filled; an existing non-blank value is never overwritten. */
    private function writeValues(Business $business, Contacts $contact, array $defs, array $extra, AgencyProspectImportRow $row, array &$report): void
    {
        $v = (array) $row->normalized;
        $original = (array) $row->original;
        $have = CustomFieldValue::query()->where('contact_id', $contact->id)->pluck('definition_id')->flip()->all();

        foreach ($defs as $key => $entry) {
            $def = $entry['def'];

            if (isset($have[$def->id])) {
                continue;
            }

            $raw = str_starts_with($key, 'extra:') ? ($original[substr($key, 6)] ?? null) : ($v[$key] ?? null);

            if ($raw === null || (is_string($raw) && trim($raw) === '')) {
                continue;
            }

            if (is_bool($raw) && in_array($def->type, ['text', 'long_text'], true)) {
                $raw = $raw ? 'Yes' : 'No';
            } elseif (is_numeric($raw) && in_array($def->type, ['text', 'long_text'], true)) {
                $raw = (string) $raw;
            }

            $result = $this->values->applyAnswer($business, $contact, $def, $raw);
            if ($result === CustomFieldValueService::APPLIED) {
                // The initial $have snapshot is stale after this write. Two source columns can map
                // to the same definition, but only the first nonblank successful value may fill it.
                $have[$def->id] = true;
                $report['values_saved']++;
            } else {
                $report['values_skipped']++;
            }
        }
    }

    /** @return array<string, array{def: CustomFieldDefinition, type: string}> usable definitions by source key (created now if needed). */
    private function resolveDefinitions(Business $business, array $plan): array
    {
        $out = [];

        foreach ($plan as $p) {
            if (! in_array($p['action'], [self::ACTION_REUSE, self::ACTION_CREATE], true)) {
                continue;
            }

            $def = $p['definition_id'] !== null ? CustomFieldDefinition::query()->where('business_id', $business->id)->find($p['definition_id']) : null;

            if ($def === null) {
                try {
                    $def = $this->definitions->create($business, $p['label'], $p['type']);
                } catch (CustomFieldRuleException) {
                    // A concurrent create or a rule (limit, name): fall back to what exists now, else skip the field.
                    $def = $this->match(CustomFieldDefinition::query()->where('business_id', $business->id)->where('entity', CustomFieldDefinition::ENTITY_CONTACT)->get(), $p['label']);

                    if ($def === null || $def->isArchived() || ! $this->compatible($def->type, $p['type'])) {
                        continue;
                    }
                }
            }

            $out[$p['key']] = ['def' => $def, 'type' => $p['type']];
        }

        return $out;
    }

    /** @return array<string, ContactGroupFields> */
    private function identityFields(ContactGroups $group): array
    {
        $out = [];

        foreach (['FIRST_NAME' => 'First name', 'LAST_NAME' => 'Last name', 'EMAIL' => 'Email', 'COMPANY' => 'Company'] as $tag => $label) {
            $out[$tag] = ContactGroupFields::query()->firstOrCreate(
                ['contact_group_id' => $group->id, 'tag' => $tag],
                ['label' => $label, 'type' => 'text', 'visible' => true, 'required' => false],
            );
        }

        return $out;
    }

    /** The Business's existing Contact for this number in ANY equivalent stored form (ContactPhone::candidates with the Business region). */
    private function contactForPhone(Business $business, string $rawPhone): ?Contacts
    {
        $forms = ContactPhone::candidates($rawPhone, ContactPhone::regionFor($business));

        return $forms === [] ? null : Contacts::query()->where('business_id', $business->id)->whereIn('phone', $forms)->orderBy('id')->first();
    }

    private function dryRun(Workspace $workspace, Business $business, AgencyProspectImportBatch $batch, $rows, array &$report): void
    {
        $rows->chunkById(500, function ($chunk) use ($workspace, $business, &$report) {
            $prospects = AgencyProspect::query()->where('workspace_id', $workspace->id)->whereIn('id', $chunk->pluck('prospect_id'))->get()->keyBy('id');
            $blocked = $this->blacklisted($business, $prospects->pluck('phone')->all());
            $clients = $this->clients->lookup((int) $workspace->id, $prospects->pluck('phone')->all());

            foreach ($chunk as $row) {
                $p = $prospects->get($row->prospect_id);

                if ($p === null) {
                    $report['skipped']['no_prospect']++;
                } elseif ($p->status === AgencyProspectStatus::Stopped) {
                    $report['skipped']['stop']++;
                } elseif ($p->client_won_at !== null || isset($clients[(string) $p->phone])) {
                    $report['skipped']['client']++;
                } elseif (isset($blocked[$p->phone])) {
                    $report['skipped']['blacklisted']++;
                } else {
                    $existing = $this->contactForPhone($business, (string) $p->phone);
                    $existing === null ? $report['created']++ : ($report['referenced']++);
                }
            }
        });
    }

    /** @param list<string> $phones @return array<string,true> */
    private function blacklisted(Business $business, array $phones): array
    {
        if ($phones === []) {
            return [];
        }

        return Blacklists::query()->where('business_id', (int) $business->id)->whereIn('number', $phones)->pluck('number')->mapWithKeys(fn ($n) => [(string) $n => true])->all();
    }

    private function match($definitions, string $label): ?CustomFieldDefinition
    {
        $lower = mb_strtolower(trim($label));
        $slug = trim(preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($label)) ?? '', '_');

        return $definitions->first(fn (CustomFieldDefinition $d) => mb_strtolower($d->label) === $lower || $d->key === $slug);
    }

    private function compatible(string $existing, string $wanted): bool
    {
        return $existing === $wanted
            || (in_array($existing, ['text', 'long_text'], true) && in_array($wanted, ['text', 'long_text', 'number', 'boolean'], true));
    }
}

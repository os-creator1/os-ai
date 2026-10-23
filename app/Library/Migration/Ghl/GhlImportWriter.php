<?php

namespace App\Library\Migration\Ghl;

use App\Library\Contacts\ContactPhone;
use App\Library\CustomFields\CustomFieldDefinitionManager;
use App\Library\CustomFields\CustomFieldValueService;
use App\Library\Crm\TagManager;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\ChatBox;
use App\Models\ContactGroupFields;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\ContactsCustomField;
use App\Models\ContactTag;
use App\Models\CrmOpportunity;
use App\Models\CrmPipeline;
use App\Models\CustomFieldDefinition;
use App\Models\Tag;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Applies a GhlImportPlanner plan to ONE MotionGrove Business.
 *
 * SAFETY, by construction:
 *  - Nothing is transmitted and nothing is dispatched. Rows are written directly
 *    (no domain events, no queue, no HTTP), so no automation can enrol and no
 *    provider is reached. SMS history is a native `chat_box_messages` row with
 *    no send state; email / calls / notes / activity go to `imported_timeline_items`.
 *  - Idempotent and resumable: every source record has a row in
 *    `external_import_records` (unique per business+system+type+id). A second run
 *    finds it and skips; a crashed run resumes. Each contact and each conversation
 *    is one transaction (retried on deadlock), so a record is either wholly there or
 *    not at all.
 *  - Tenant-isolated: every lookup and write is keyed by this Business's id; pipelines,
 *    stages, tags and definitions are resolved inside the Business only.
 *  - Consent is never invented: a contact GHL flagged DND is imported as unsubscribed;
 *    everyone else gets the default status and an explicit "consent not recorded" note
 *    in the ledger.
 */
final class GhlImportWriter
{
    public const SYSTEM = 'gohighlevel';

    public const MESSAGE_SOURCE = 'ghl_import';

    public const GROUP_NAME = 'Leads & Clients';

    /** Contacts GHL holds without a phone cannot be dialled; they get a clearly non-dialable key. */
    public const NO_PHONE_PREFIX = 'np-';

    public const EVENT_TYPE_OPTIONS = ['Wedding', 'Birthday Party', 'Corporate Party', 'Brand Activation', 'Event', 'Party', 'Graduation Party', 'Fundraiser', 'Trade Show', 'Baby Shower'];

    private bool $dryRun = true;

    /** @var array<string, int> */
    private array $report = [];

    /** @var array<string, mixed> */
    private array $cache = [];

    public function __construct(
        private readonly CustomFieldDefinitionManager $definitions,
        private readonly CustomFieldValueService $values,
        private readonly TagManager $tags,
    ) {
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed> report
     */
    public function import(array $plan, Business $business, BusinessLocation $location, string $businessNumber, bool $dryRun = true, int $retries = 3): array
    {
        if ((int) $location->business_id !== (int) $business->id) {
            throw new RuntimeException('That Location does not belong to this Business.');
        }

        $this->dryRun = $dryRun;
        $this->report = [];
        $this->cache = [];
        $number = ContactPhone::legacyDigits($businessNumber);

        $this->tagsStep($plan, $business);
        $this->fieldsStep($business);
        $this->contactsStep($plan, $business, $location, $retries);
        $this->opportunitiesStep($plan, $business, $location, $retries);
        $this->conversationsStep($plan, $business, $location, $number, $retries);

        ksort($this->report);

        return ['dry_run' => $dryRun, 'counts' => $this->report];
    }

    // ------------------------------------------------------------------ tags

    private function tagsStep(array $plan, Business $business): void
    {
        foreach ($plan['tags'] as $name) {
            $existing = $this->tagNamed($business, $name);
            if ($existing !== null) {
                $this->count('tags_existing');

                continue;
            }
            $this->count('tags_created');
            if (! $this->dryRun) {
                $this->cache['tag:'.mb_strtolower($name)] = $this->tags->createTag($business, $name);
            }
        }
    }

    private function tagNamed(Business $business, string $name): ?Tag
    {
        $key = 'tag:'.mb_strtolower(trim($name));

        return $this->cache[$key] ??= Tag::query()->where('business_id', $business->id)->where('normalized_name', mb_strtolower(trim($name)))->first();
    }

    // ---------------------------------------------------------------- fields

    private function fieldsStep(Business $business): void
    {
        foreach ([['Event date', 'date', null], ['Event type', 'multi_select', self::EVENT_TYPE_OPTIONS]] as [$label, $type, $options]) {
            $def = $this->definition($business, $label);
            if ($def !== null) {
                $this->count('custom_fields_existing');

                continue;
            }
            $this->count('custom_fields_created');
            if (! $this->dryRun) {
                $this->cache['def:'.$label] = $this->definitions->create($business, $label, $type, $options);
            }
        }
    }

    private function definition(Business $business, string $label): ?CustomFieldDefinition
    {
        return $this->cache['def:'.$label] ??= CustomFieldDefinition::query()->where('business_id', $business->id)->where('key', str_replace(' ', '_', strtolower($label)))->first();
    }

    // -------------------------------------------------------------- contacts

    private function contactsStep(array $plan, Business $business, BusinessLocation $location, int $retries): void
    {
        $group = null;
        $identity = [];

        if (! $this->dryRun) {
            $group = ContactGroups::query()->firstOrCreate(
                ['business_id' => $business->id, 'name' => self::GROUP_NAME],
                ['customer_id' => $business->customer_id, 'status' => true],
            );
            foreach (['FIRST_NAME' => 'First name', 'LAST_NAME' => 'Last name', 'EMAIL' => 'Email', 'COMPANY' => 'Company'] as $tag => $label) {
                $identity[$tag] = ContactGroupFields::query()->firstOrCreate(
                    ['contact_group_id' => $group->id, 'tag' => $tag],
                    ['label' => $label, 'type' => $tag === 'EMAIL' ? 'email' : 'text', 'visible' => true, 'required' => false],
                );
            }
        }

        foreach ($plan['contacts'] as $c) {
            if ($this->known($business, 'contact', $c['source_id']) !== null) {
                $this->count('contacts_skipped_existing');

                continue;
            }

            $phone = $c['phone_e164'] === null
                ? self::NO_PHONE_PREFIX.substr(strtolower($c['source_id']), 0, 16)
                : ContactPhone::legacyDigits($c['phone_e164']);

            $clash = Contacts::query()->where('business_id', $business->id)->where('phone', $phone)->exists();
            if ($clash) {
                // A different contact already owns this number in this Business: never merge silently.
                $this->count('contacts_conflict_flagged');

                continue;
            }

            $this->count('contacts_created');
            if ($c['phone_e164'] === null) {
                $this->count('contacts_without_phone');
            }
            if ($c['consent']['sms'] === 'suppressed') {
                $this->count('contacts_opted_out');
            }
            if ($this->dryRun) {
                // Planned, so later steps (deals, threads) of the dry run can resolve it.
                $this->cache['ledger:contact:'.$c['source_id']] = -1;

                continue;
            }

            DB::transaction(function () use ($c, $business, $location, $group, $identity, $phone): void {
                $contact = Contacts::create([
                    'customer_id' => $business->customer_id,
                    'business_id' => $business->id,
                    'group_id' => $group->id,
                    'phone' => $phone,
                    'status' => $c['consent']['sms'] === 'suppressed' ? Contacts::STATUS_UNSUBSCRIBE : Contacts::STATUS_SUBSCRIBE,
                ]);
                $created = $c['created_at'] ?? now()->toIso8601String();
                $contact->forceFill(['location_id' => $location->id, 'created_at' => $created, 'updated_at' => $created])->save();

                foreach (['FIRST_NAME' => $c['first_name'], 'LAST_NAME' => $c['last_name'], 'EMAIL' => $c['email']] as $tag => $value) {
                    if ($value !== null && trim((string) $value) !== '') {
                        ContactsCustomField::query()->updateOrCreate(['contact_id' => $contact->id, 'field_id' => $identity[$tag]->id], ['value' => $value]);
                    }
                }

                $this->customValues($business, $contact, $c['custom_fields']);

                foreach ($c['tags'] as $name) {
                    $tag = $this->tagNamed($business, $name);
                    if ($tag !== null) {
                        $this->attach($contact, $tag);
                    }
                }

                $this->remember($business, 'contact', $c['source_id'], 'contacts', (int) $contact->id, [
                    'consent' => $c['consent'], 'lead_source' => $c['lead_source'], 'no_phone' => $c['phone_e164'] === null,
                ]);
            }, $retries);
        }
    }

    private function customValues(Business $business, Contacts $contact, array $fields): void
    {
        foreach ($fields as $name => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            if ($name === 'Event Date') {
                $def = $this->definition($business, 'Event date');
                $this->values->set($business, $contact, $def, Carbon::createFromTimestampMsUTC((int) $value)->toDateString());
            } elseif ($name === 'Event Type') {
                $def = $this->definition($business, 'Event type');
                $ids = [];
                foreach ((array) $value as $label) {
                    foreach ((array) $def->options as $option) {
                        if (($option['label'] ?? null) === $label) {
                            $ids[] = (string) $option['id'];
                        }
                    }
                }
                if ($ids !== []) {
                    $this->values->set($business, $contact, $def, $ids);
                }
            } else {
                $this->count('custom_fields_unmapped_values');
            }
        }
    }

    private function attach(Contacts $contact, Tag $tag): void
    {
        try {
            ContactTag::query()->create(['contact_id' => $contact->id, 'tag_id' => $tag->id, 'business_id' => $tag->business_id]);
        } catch (UniqueConstraintViolationException) {
        }
    }

    // --------------------------------------------------------- opportunities

    private function opportunitiesStep(array $plan, Business $business, BusinessLocation $location, int $retries): void
    {
        foreach ($plan['opportunities'] as $o) {
            if ($this->known($business, 'opportunity', $o['source_id']) !== null) {
                $this->count('opportunities_skipped_existing');

                continue;
            }

            $pipeline = $this->cache['pipe:'.$o['pipeline']] ??= CrmPipeline::query()->where('business_id', $business->id)->whereNull('archived_at')->where('name', $o['pipeline'])->first();
            $stage = $pipeline?->stages()->whereNull('archived_at')->where('name', $o['stage'])->first();
            $contactId = $this->known($business, 'contact', $o['contact_source_id']);

            if ($pipeline === null || $stage === null || $contactId === null) {
                $this->count('opportunities_unmapped');

                continue;
            }

            $this->count('opportunities_created');
            $this->count('opportunities_'.$o['status']);
            if ($this->dryRun) {
                continue;
            }

            DB::transaction(function () use ($o, $business, $location, $pipeline, $stage, $contactId): void {
                $createdAt = $o['created_at'] ?? now()->toIso8601String();
                $stageAt = $o['stage_changed_at'] ?? $createdAt;
                $statusAt = $o['status_changed_at'] ?? $stageAt;

                $opp = CrmOpportunity::create([
                    'business_id' => $business->id,
                    'location_id' => $location->id,
                    'pipeline_id' => $pipeline->id,
                    'stage_id' => $stage->id,
                    'contact_id' => $contactId,
                    'title' => Str::limit($o['title'], 150, ''),
                    'value_minor' => $o['value_minor'] > 0 ? $o['value_minor'] : null,
                    'currency_code' => $o['currency'] ?: $business->currency_code,
                    'status' => $o['status'],
                    'contact_status' => 'no_contact',
                    'source' => $o['lead_source'] === 'Check Availability Form' ? CrmOpportunity::SOURCE_FORM : CrmOpportunity::SOURCE_MANUAL,
                    'stage_entered_at' => $stageAt,
                    'won_at' => $o['status'] === 'won' ? $statusAt : null,
                    'lost_at' => $o['status'] === 'lost' ? $statusAt : null,
                ]);
                $opp->forceFill(['created_at' => $createdAt, 'updated_at' => $o['stage_changed_at'] ?? $createdAt])->save();

                // History is written straight to the table: no model events, no
                // CrmOpportunityCreated/StageChanged dispatch, so no workflow can enrol.
                $history = [['created', null, $stage, $createdAt]];
                if ($stageAt !== $createdAt) {
                    $history[] = ['stage_changed', null, $stage, $stageAt];
                }
                if (in_array($o['status'], ['won', 'lost'], true)) {
                    $history[] = [$o['status'], null, $stage, $statusAt];
                }
                foreach ($history as [$event, , $to, $at]) {
                    DB::table('crm_opportunity_history')->insert([
                        'business_id' => $business->id, 'opportunity_id' => $opp->id, 'event' => $event,
                        'to_stage_id' => $to->id, 'to_stage_name' => $to->name, 'created_at' => $at,
                    ]);
                }

                $this->remember($business, 'opportunity', $o['source_id'], 'crm_opportunities', (int) $opp->id, ['source_stage' => $o['stage'], 'source_status' => $o['status']]);
            }, $retries);
        }
    }

    // --------------------------------------------------------- conversations

    private function conversationsStep(array $plan, Business $business, BusinessLocation $location, string $number, int $retries): void
    {
        $byConversation = [];
        foreach ($plan['messages'] as $m) {
            $byConversation[$m['conversation_source_id']][] = $m;
        }

        foreach ($plan['conversations'] as $conv) {
            $contactId = $this->known($business, 'contact', (string) $conv['contact_source_id']);
            if ($contactId === null) {
                $this->count('conversations_unmapped');

                continue;
            }
            $contact = $this->dryRun ? null : Contacts::query()->where('business_id', $business->id)->find($contactId);
            $messages = $byConversation[$conv['source_id']] ?? [];

            $newMessages = array_values(array_filter($messages, fn ($m) => $this->known($business, 'message', $m['source_id']) === null));
            $boxId = $this->known($business, 'conversation', $conv['source_id']);

            if ($boxId !== null) {
                $this->count('conversations_skipped_existing');
            } else {
                $this->count('conversations_created');
            }
            foreach ($newMessages as $m) {
                $this->count('messages_'.(in_array($m['kind'], ['sms', 'sms_reaction'], true) && ! $this->neverDelivered($m) ? 'native_sms' : 'timeline_'.($m['kind'] === 'sms_reaction' ? 'sms' : $m['kind'])));
            }
            if ($this->dryRun || ($boxId !== null && $newMessages === [])) {
                continue;
            }

            DB::transaction(function () use ($business, $location, $conv, $contact, $number, $newMessages, &$boxId): void {
                if ($boxId === null) {
                    $box = new ChatBox([
                        'user_id' => $business->customer_id, 'business_id' => $business->id, 'location_id' => $location->id,
                        'from' => $number, 'to' => $contact->phone,
                    ]);
                    $box->uid = (string) Str::uuid();
                    $box->save();
                    $boxId = (int) $box->id;
                    $this->remember($business, 'conversation', $conv['source_id'], 'chat_boxes', $boxId);
                }

                foreach ($newMessages as $m) {
                    if (in_array($m['kind'], ['sms', 'sms_reaction'], true) && ! $this->neverDelivered($m)) {
                        $incoming = $m['direction'] === 'inbound';
                        $id = DB::table('chat_box_messages')->insertGetId([
                            'box_id' => $boxId,
                            'message' => (string) ($m['body'] ?? ''),
                            'sms_type' => 'plain',
                            'direction' => $incoming ? 'incoming' : 'outgoing',
                            'send_by' => $incoming ? 'to' : 'from',
                            // Historical: no send state, no operation, no step run => no retry, no billing.
                            'source' => self::MESSAGE_SOURCE,
                            'send_status' => null,
                            'created_at' => $m['occurred_at'],
                            'updated_at' => $m['occurred_at'],
                        ]);
                        $this->remember($business, 'message', $m['source_id'], 'chat_box_messages', (int) $id, ['source_status' => $m['source_status'], 'origin' => $m['origin']]);
                    } else {
                        $id = DB::table('imported_timeline_items')->insertGetId([
                            'business_id' => $business->id, 'location_id' => $location->id, 'chat_box_id' => $boxId, 'contact_id' => $contact->id,
                            'source_system' => self::SYSTEM, 'source_id' => $m['source_id'],
                            'kind' => $m['kind'] === 'sms_reaction' ? 'sms' : $m['kind'], 'direction' => $m['direction'],
                            'title' => $this->title($m), 'body' => $this->bodyText($m),
                            'source_status' => $m['source_status'],
                            'meta' => json_encode(array_filter(['origin' => $m['origin'], 'call_seconds' => $m['call_seconds'], 'from' => $m['from'], 'to' => $m['to']], fn ($v) => $v !== null)),
                            'occurred_at' => $m['occurred_at'],
                        ]);
                        $this->remember($business, 'message', $m['source_id'], 'imported_timeline_items', (int) $id, ['source_status' => $m['source_status']]);
                    }
                }

                $sms = DB::table('chat_box_messages')->where('box_id', $boxId)->selectRaw('min(created_at) as first, max(created_at) as last')->first();
                $items = DB::table('imported_timeline_items')->where('chat_box_id', $boxId)->selectRaw('min(occurred_at) as first, max(occurred_at) as last')->first();
                $firsts = array_filter([$sms->first ?? null, $items->first ?? null]);
                $lasts = array_filter([$sms->last ?? null, $items->last ?? null]);
                DB::table('chat_boxes')->where('id', $boxId)->update([
                    // Historical threads are never "unread" and never awaiting a reply.
                    'notification' => 0, 'reply_by_customer' => false, 'ai_replied' => false,
                    'created_at' => $firsts === [] ? now() : min($firsts), 'updated_at' => $lasts === [] ? now() : max($lasts),
                ]);
            }, $retries);
        }
    }

    /**
     * An SMS GHL itself failed to deliver is history, not a message that happened: it is kept (visible, marked
     * "Originally: failed") in the read-only timeline and NEVER given a send state, so it can be neither retried nor resent.
     */
    private function neverDelivered(array $m): bool
    {
        return in_array($m['source_status'] ?? null, ['failed', 'undelivered'], true);
    }

    private function title(array $m): string
    {
        return match ($m['kind']) {
            'email' => (string) ($m['subject'] ?? ''),
            'sms', 'sms_reaction' => '',
            'call' => ucfirst($m['direction']).' call'.($m['source_status'] ? ' — '.$m['source_status'] : '').($m['call_seconds'] ? ' ('.$m['call_seconds'].'s)' : ''),
            'note' => 'Internal note',
            default => Str::limit(trim(strip_tags((string) ($m['body'] ?? ''))) ?: 'Activity', 200),
        };
    }

    private function bodyText(array $m): ?string
    {
        $body = trim(html_entity_decode(strip_tags(preg_replace('#<(br|/p|/div)[^>]*>#i', "\n", (string) ($m['body'] ?? '')))));

        if (in_array($m['kind'], ['sms', 'sms_reaction'], true)) {
            return $body === '' ? null : $body;
        }

        return $body === '' || $m['kind'] === 'activity' && $body === $this->title($m) ? null : $body;
    }

    // ---------------------------------------------------------------- ledger

    private function known(Business $business, string $type, string $id): ?int
    {
        $key = "ledger:{$type}:{$id}";
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $local = DB::table('external_import_records')
            ->where('business_id', $business->id)->where('source_system', self::SYSTEM)
            ->where('source_type', $type)->where('source_id', $id)->value('local_id');

        return $local === null ? null : ($this->cache[$key] = (int) $local);
    }

    private function remember(Business $business, string $type, string $id, string $localType, int $localId, array $meta = []): void
    {
        DB::table('external_import_records')->insert([
            'business_id' => $business->id, 'source_system' => self::SYSTEM, 'source_type' => $type, 'source_id' => $id,
            'local_type' => $localType, 'local_id' => $localId, 'meta' => json_encode($meta), 'imported_at' => now(),
        ]);
        $this->cache["ledger:{$type}:{$id}"] = $localId;
    }

    private function count(string $key): void
    {
        $this->report[$key] = ($this->report[$key] ?? 0) + 1;
    }
}

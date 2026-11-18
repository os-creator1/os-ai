<?php

namespace App\Library\AgencyOutreach;

use App\Enums\AgencyProspecting\AgencyProspectCampaignStatus;
use App\Enums\AgencyProspecting\AgencyProspectStage;
use App\Enums\AgencyProspecting\AgencyProspectStatus;
use App\Library\AgencyProspecting\AgencyProspectPhoneNormalizer;
use App\Library\Workspace\WorkspaceManager;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaign;
use App\Models\AgencyProspectCampaignMember;
use App\Models\Blacklists;
use App\Models\Business;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The deliberate bridge between two systems that must never be conflated:
 *
 *   CRM       ContactGroups / Contacts        scoped to ONE Business
 *   Outreach  AgencyProspect / CampaignMember scoped to ONE Workspace
 *
 * A group is only ever read for the Business {@see AgencyOutreachBusinessResolver}
 * derives from the Workspace (never from a request value, never guessed), and the
 * actor must be able to access that Business. Eligible contacts are COPIED into
 * Workspace prospects keyed by canonical phone; database ids are never shared or
 * assumed equal across the two systems.
 *
 * Every excluded contact gets exactly one reason, so the numbers always add up:
 * group_total = eligible + excluded. Nothing here sends anything.
 */
final class OutreachContactGroupBridge
{
    /** Synchronous, bounded work: larger groups are refused rather than half-processed. */
    public const MAX_CONTACTS = 5000;

    private const CHUNK = 500;
    private const MAX_REPORTED_SKIPS = 200;

    public const REASON_UNSUBSCRIBED = 'unsubscribed';
    public const REASON_MISSING_PHONE = 'missing_phone';
    public const REASON_INVALID_PHONE = 'invalid_phone';
    public const REASON_DUPLICATE = 'duplicate_phone';
    public const REASON_SUPPRESSED = 'suppressed';
    public const REASON_STOPPED = 'prospect_stopped';
    public const REASON_BOOKED = 'prospect_booked';
    public const REASON_OTHER_CAMPAIGN = 'open_in_other_campaign';

    private const TERMINAL = [AgencyProspectStage::Booked->value, AgencyProspectStage::StoppedOptOut->value];

    public const REASON_LABELS = [
        self::REASON_UNSUBSCRIBED => 'Unsubscribed',
        self::REASON_MISSING_PHONE => 'Missing phone number',
        self::REASON_INVALID_PHONE => 'Invalid phone number (needs a country code)',
        self::REASON_DUPLICATE => 'Duplicate phone number in this group',
        self::REASON_SUPPRESSED => 'Opted out / suppressed',
        self::REASON_STOPPED => 'Prospect already stopped',
        self::REASON_BOOKED => 'Prospect already booked',
        self::REASON_OTHER_CAMPAIGN => 'Already in an open conversation in another campaign',
    ];

    public static function reasonLabel(string $reason): string
    {
        return self::REASON_LABELS[$reason] ?? $reason;
    }

    // ------------------------------------------------------------------
    // Group access
    // ------------------------------------------------------------------

    /** The ONE Business this Workspace sends as, if the actor may use it. */
    public function businessFor(Workspace $workspace, User|int|null $actor): ?Business
    {
        $business = AgencyOutreachBusinessResolver::forWorkspace($workspace);
        $actorId = $actor instanceof User ? (int) $actor->id : (int) $actor;

        if ($business === null || $actorId <= 0) {
            return null;
        }

        return app(WorkspaceManager::class)->userCanAccessBusiness($actorId, $business) ? $business : null;
    }

    /** @return Collection<int, ContactGroups> accessible, enabled groups with `subscribers_count`. */
    public function groupsFor(Business $business): Collection
    {
        return ContactGroups::query()
            ->where('business_id', (int) $business->id)
            ->where('status', 1)
            ->withCount('subscribers')
            ->orderBy('name')
            ->get();
    }

    /** A group by uid, only if it belongs to this Business and is enabled. */
    public function findGroup(Business $business, string $uid): ?ContactGroups
    {
        return ContactGroups::query()
            ->where('business_id', (int) $business->id)
            ->where('status', 1)
            ->where('uid', $uid)
            ->first();
    }

    /** The campaign's linked group, re-validated against the Workspace's Business right now. */
    public function linkedGroup(Workspace $workspace, AgencyProspectCampaign $campaign): ?ContactGroups
    {
        $business = AgencyOutreachBusinessResolver::forWorkspace($workspace);

        if ($business === null || $campaign->contact_group_id === null
            || (int) $campaign->contact_group_business_id !== (int) $business->id) {
            return null;
        }

        return ContactGroups::query()
            ->where('id', (int) $campaign->contact_group_id)
            ->where('business_id', (int) $business->id)
            ->where('status', 1)
            ->first();
    }

    // ------------------------------------------------------------------
    // Summary (read-only) and populate (writes, draft only)
    // ------------------------------------------------------------------

    /**
     * What populating would do, without writing anything.
     *
     * @return array{group_total: int, eligible: int, excluded: int, reasons: array<string,int>, skipped: list<array{phone: string, reason: string}>, too_large: bool}
     */
    public function summarize(Workspace $workspace, ContactGroups $group, ?AgencyProspectCampaign $campaign = null): array
    {
        return $this->run($workspace, $group, $campaign, false);
    }

    /**
     * Snapshot the group's eligible contacts into the campaign. Caller must hold
     * the draft lock (see {@see populateLocked}); this is the entry for a campaign
     * that was just created in the same transaction.
     *
     * @return array<string, mixed> the report that was stored on the campaign
     */
    public function populate(Workspace $workspace, AgencyProspectCampaign $campaign, ContactGroups $group): array
    {
        return DB::transaction(function () use ($workspace, $campaign, $group) {
            $locked = AgencyProspectCampaign::where('id', $campaign->id)->where('workspace_id', $workspace->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== AgencyProspectCampaignStatus::Draft) {
                throw new \DomainException('Recipients can only change while a campaign is still a draft.');
            }

            $report = $this->run($workspace, $group, $locked, true);

            if ($report['too_large']) {
                throw new \DomainException('This group has more than ' . self::MAX_CONTACTS . ' contacts. Split it into smaller groups.');
            }

            $locked->update([
                'recipients_synced_at' => now(),
                'recipient_report' => $report,
            ]);

            return $report;
        });
    }

    /**
     * Remove the group-sourced rows of a draft (never manual enrollments), so a
     * different group can replace them. Rows with any message history are kept.
     */
    public function clearGroupSnapshot(Workspace $workspace, AgencyProspectCampaign $campaign): int
    {
        return AgencyProspectCampaignMember::query()
            ->where('workspace_id', $workspace->id)
            ->where('campaign_id', $campaign->id)
            ->whereNotNull('source_contact_id')
            ->whereDoesntHave('messages')
            ->delete();
    }

    // ------------------------------------------------------------------

    private function run(Workspace $workspace, ContactGroups $group, ?AgencyProspectCampaign $campaign, bool $persist): array
    {
        $business = AgencyOutreachBusinessResolver::forWorkspace($workspace);

        // The group must be this Business's. Checked again here so no caller can bypass it.
        if ($business === null || (int) $group->business_id !== (int) $business->id) {
            throw new \DomainException('That contact group does not belong to this Workspace\'s Business.');
        }

        $total = Contacts::query()->where('group_id', $group->id)->where('business_id', (int) $business->id)->count();
        $report = ['group_total' => $total, 'eligible' => 0, 'excluded' => 0, 'reasons' => [], 'skipped' => [], 'too_large' => $total > self::MAX_CONTACTS];

        if ($report['too_large']) {
            return $report;
        }

        $suppressed = $this->suppressedNumbers($business);
        $seen = [];
        $keepSourceIds = [];
        $fieldTags = $this->fieldIds($group);

        Contacts::query()
            ->where('group_id', $group->id)
            ->where('business_id', (int) $business->id)
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($contacts) use (&$report, &$seen, &$keepSourceIds, $suppressed, $workspace, $campaign, $persist, $fieldTags, $group) {
                $details = $this->details($contacts->pluck('id')->all(), $fieldTags);
                $candidates = [];

                foreach ($contacts as $contact) {
                    // The model casts `phone` to int, which would mangle formatting; read what is stored.
                    $raw = trim((string) $contact->getRawOriginal('phone'));
                    $phone = AgencyProspectPhoneNormalizer::normalize($raw);
                    $reason = null;

                    if ($contact->status !== Contacts::STATUS_SUBSCRIBE) {
                        $reason = self::REASON_UNSUBSCRIBED;
                    } elseif ($raw === '' || preg_replace('/\D/', '', $raw) === '') {
                        $reason = self::REASON_MISSING_PHONE;
                    } elseif ($phone === null) {
                        $reason = self::REASON_INVALID_PHONE;
                    } elseif (isset($suppressed[$phone])) {
                        $reason = self::REASON_SUPPRESSED;
                    } elseif (isset($seen[$phone])) {
                        $reason = self::REASON_DUPLICATE;
                    }

                    if ($reason !== null) {
                        $this->skip($report, $raw, $reason);

                        continue;
                    }

                    $seen[$phone] = true;
                    $candidates[$phone] = [$contact, $details[$contact->id] ?? []];
                }

                if ($candidates === []) {
                    return;
                }

                $existing = AgencyProspect::query()
                    ->where('workspace_id', $workspace->id)
                    ->whereIn('phone', array_keys($candidates))
                    ->orderBy('id')
                    ->when($persist, fn ($q) => $q->lockForUpdate())
                    ->get()->keyBy('phone');

                $otherOpen = $existing->isEmpty() ? [] : AgencyProspectCampaignMember::query()
                    ->where('workspace_id', $workspace->id)
                    ->whereIn('prospect_id', $existing->pluck('id')->all())
                    ->when($campaign, fn ($q) => $q->where('campaign_id', '!=', $campaign->id))
                    ->whereNotIn('stage', self::TERMINAL)
                    ->whereHas('campaign', fn ($q) => $q->whereIn('status', [AgencyProspectCampaignStatus::Active->value, AgencyProspectCampaignStatus::Paused->value]))
                    ->pluck('prospect_id')->flip()->all();

                foreach ($candidates as $phone => [$contact, $info]) {
                    $prospect = $existing->get($phone);

                    if ($prospect !== null) {
                        if ($prospect->status === AgencyProspectStatus::Stopped) {
                            $this->skip($report, $phone, self::REASON_STOPPED);

                            continue;
                        }

                        if ($prospect->status === AgencyProspectStatus::Booked) {
                            $this->skip($report, $phone, self::REASON_BOOKED);

                            continue;
                        }

                        if (isset($otherOpen[$prospect->id])) {
                            $this->skip($report, $phone, self::REASON_OTHER_CAMPAIGN);

                            continue;
                        }
                    }

                    $report['eligible']++;

                    if (! $persist) {
                        continue;
                    }

                    $prospect ??= $this->createProspect($workspace, $phone, $contact, $info, $group);
                    $keepSourceIds[] = (int) $contact->id;

                    // An existing (manually enrolled) row keeps its NULL source: refresh never removes it.
                    AgencyProspectCampaignMember::firstOrCreate(
                        ['campaign_id' => $campaign->id, 'prospect_id' => $prospect->id],
                        ['workspace_id' => $workspace->id, 'enrolled_at' => now(), 'source_contact_id' => $contact->id],
                    );
                }
            });

        // Refresh: group-sourced rows whose contact left the group or stopped being eligible
        // are dropped from the DRAFT snapshot (rows with message history are always kept).
        if ($persist && $campaign !== null) {
            AgencyProspectCampaignMember::query()
                ->where('workspace_id', $workspace->id)
                ->where('campaign_id', $campaign->id)
                ->whereNotNull('source_contact_id')
                ->whereNotIn('source_contact_id', $keepSourceIds === [] ? [0] : $keepSourceIds)
                ->whereDoesntHave('messages')
                ->delete();
        }

        $report['excluded'] = $total - $report['eligible'];

        return $report;
    }

    private function skip(array &$report, string $label, string $reason): void
    {
        $report['reasons'][$reason] = ($report['reasons'][$reason] ?? 0) + 1;

        if (count($report['skipped']) < self::MAX_REPORTED_SKIPS) {
            $report['skipped'][] = ['phone' => $label, 'reason' => $reason];
        }
    }

    /** @return array<string,true> canonical digits the Business must never text. */
    private function suppressedNumbers(Business $business): array
    {
        $numbers = Blacklists::query()
            ->where(function ($q) use ($business) {
                $q->where('business_id', (int) $business->id)
                    ->orWhere(fn ($legacy) => $legacy->whereNull('business_id')->where('user_id', $business->customer_id));
            })
            ->pluck('number');

        $out = [];

        foreach ($numbers as $number) {
            $digits = preg_replace('/\D/', '', (string) $number) ?? '';

            if ($digits !== '') {
                $out[$digits] = true;
            }
        }

        return $out;
    }

    /** @return array<string,int> field id by tag for this group (FIRST_NAME, LAST_NAME, EMAIL, COMPANY). */
    private function fieldIds(ContactGroups $group): array
    {
        return DB::table('contact_group_fields')
            ->where('contact_group_id', $group->id)
            ->whereIn('tag', ['FIRST_NAME', 'LAST_NAME', 'EMAIL', 'COMPANY'])
            ->pluck('id', 'tag')->all();
    }

    /** @return array<int, array<string,string>> real field values per contact id. */
    private function details(array $contactIds, array $fieldIds): array
    {
        if ($contactIds === [] || $fieldIds === []) {
            return [];
        }

        $tagById = array_flip($fieldIds);
        $out = [];

        DB::table('contacts_custom_field')
            ->whereIn('contact_id', $contactIds)
            ->whereIn('field_id', array_values($fieldIds))
            ->get(['contact_id', 'field_id', 'value'])
            ->each(function ($row) use (&$out, $tagById) {
                $value = trim((string) $row->value);

                if ($value !== '') {
                    $out[$row->contact_id][$tagById[$row->field_id]] = $value;
                }
            });

        return $out;
    }

    /**
     * Only real data is mapped. `company_name` is required by the prospect table and the
     * CRM has no company concept, so it carries the contact's own identity — a COMPANY
     * field if the group has one, else their name, else their phone. Nothing is invented.
     */
    private function createProspect(Workspace $workspace, string $phone, Contacts $contact, array $info, ContactGroups $group): AgencyProspect
    {
        $name = trim(($info['FIRST_NAME'] ?? '') . ' ' . ($info['LAST_NAME'] ?? ''));
        $attributes = [
            'workspace_id' => $workspace->id,
            'phone' => $phone,
            'company_name' => mb_substr($info['COMPANY'] ?? ($name !== '' ? $name : '+' . $phone), 0, 255),
            'contact_name' => $name !== '' ? mb_substr($name, 0, 255) : null,
            'email' => isset($info['EMAIL']) && filter_var($info['EMAIL'], FILTER_VALIDATE_EMAIL) ? mb_substr($info['EMAIL'], 0, 255) : null,
            'source' => mb_substr('Contact group: ' . $group->name, 0, 255),
            'status' => AgencyProspectStatus::Active->value,
        ];

        try {
            return DB::transaction(fn () => AgencyProspect::create($attributes));
        } catch (QueryException $e) {
            // Lost a race on unique(workspace_id, phone): use the winner, never overwrite it.
            $winner = AgencyProspect::where('workspace_id', $workspace->id)->where('phone', $phone)->first();

            if ($winner === null) {
                throw $e;
            }

            return $winner;
        }
    }
}

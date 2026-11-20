<?php

namespace App\Library\AgencyOutreach;

use App\Library\AgencyProspecting\AgencyProspectPhoneNormalizer;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectExclusion;
use App\Models\AgencyProspectMessage;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Workspace-wide "Do Not Prospect — Existing Client" exclusions and the durable Client won outcome.
 *
 * COMMERCIAL suppression only. STOP / opt-out is a separate compliance suppression (prospect status
 * `stopped` + the Business blacklist) that this class never reads as removable and never writes:
 * removing an exclusion deletes the exclusion row's *activity* and nothing else, so a prospect who
 * said STOP stays stopped (see {@see OutreachEligibility}, which still refuses them).
 *
 * Every number is canonicalised with the one shared normaliser ({@see AgencyProspectPhoneNormalizer}).
 * Conversation history is never deleted: exclusions only stop FUTURE automatic sends.
 */
final class OutreachExistingClients
{
    public const SKIPPED_REASON = 'existing_client';

    /** Is this canonical number an active Existing Client of the Workspace? */
    public function isExcluded(int $workspaceId, ?string $canonicalPhone): bool
    {
        if ($canonicalPhone === null || $canonicalPhone === '') {
            return false;
        }

        return AgencyProspectExclusion::query()
            ->where('workspace_id', $workspaceId)
            ->where('active_phone', $canonicalPhone)
            ->exists();
    }

    /**
     * @param  list<string>  $canonicalPhones
     * @return array<string, AgencyProspectExclusion> keyed by canonical phone
     */
    public function lookup(int $workspaceId, array $canonicalPhones): array
    {
        $canonicalPhones = array_values(array_unique(array_filter($canonicalPhones, fn ($p) => $p !== null && $p !== '')));

        if ($canonicalPhones === []) {
            return [];
        }

        $found = [];

        foreach (array_chunk($canonicalPhones, 500) as $chunk) {
            AgencyProspectExclusion::query()
                ->where('workspace_id', $workspaceId)
                ->whereIn('active_phone', $chunk)
                ->get()
                ->each(function (AgencyProspectExclusion $e) use (&$found) {
                    $found[$e->phone] = $e;
                });
        }

        return $found;
    }

    /**
     * Add (or return the already-active) exclusion for a number.
     *
     * @return array{ok: bool, created: bool, exclusion: ?AgencyProspectExclusion, error: ?string}
     */
    public function add(
        Workspace $workspace,
        ?string $rawPhone,
        ?string $businessName = null,
        string $reason = AgencyProspectExclusion::REASON_MANUAL,
        ?string $sourceLabel = null,
        ?int $actorId = null,
        ?int $prospectId = null,
        ?int $batchId = null,
    ): array {
        $phone = AgencyProspectPhoneNormalizer::normalize($rawPhone);

        if ($phone === null) {
            return ['ok' => false, 'created' => false, 'exclusion' => null,
                'error' => 'Enter a valid international phone number (include a country code).'];
        }

        return DB::transaction(function () use ($workspace, $phone, $businessName, $reason, $sourceLabel, $actorId, $prospectId, $batchId) {
            $existing = AgencyProspectExclusion::query()
                ->where('workspace_id', $workspace->id)->where('active_phone', $phone)->lockForUpdate()->first();

            if ($existing !== null) {
                return ['ok' => true, 'created' => false, 'exclusion' => $existing, 'error' => null];
            }

            $prospect = $prospectId !== null
                ? AgencyProspect::query()->where('workspace_id', $workspace->id)->find($prospectId)
                : AgencyProspect::query()->where('workspace_id', $workspace->id)->where('phone', $phone)->first();

            try {
                $exclusion = AgencyProspectExclusion::create([
                    'workspace_id' => $workspace->id,
                    'phone' => $phone,
                    'active_phone' => $phone,
                    'business_name' => $businessName !== null && trim($businessName) !== '' ? trim($businessName) : $prospect?->company_name,
                    'reason' => $reason,
                    'source_label' => $sourceLabel,
                    'prospect_id' => $prospect?->id,
                    'import_batch_id' => $batchId,
                    'created_by_user_id' => $actorId,
                ]);
            } catch (QueryException) {
                // A concurrent request created it first: the unique (workspace, active phone) key held.
                $exclusion = AgencyProspectExclusion::query()
                    ->where('workspace_id', $workspace->id)->where('active_phone', $phone)->first();

                return ['ok' => true, 'created' => false, 'exclusion' => $exclusion, 'error' => null];
            }

            if ($prospect !== null) {
                $this->suppressUnsent($workspace->id, [$prospect->id]);
            }

            return ['ok' => true, 'created' => true, 'exclusion' => $exclusion, 'error' => null];
        });
    }

    /**
     * Remove an accidental exclusion. Audited (who and when); history stays on the row.
     * Never touches STOP: a stopped prospect remains stopped and blacklisted numbers remain blacklisted.
     */
    public function remove(Workspace $workspace, AgencyProspectExclusion $exclusion, ?int $actorId): bool
    {
        if ((int) $exclusion->workspace_id !== (int) $workspace->id || ! $exclusion->isActive()) {
            return false;
        }

        DB::transaction(function () use ($exclusion, $actorId) {
            $exclusion->update(['removed_at' => now(), 'removed_by_user_id' => $actorId, 'active_phone' => null]);

            if ($exclusion->prospect_id !== null) {
                AgencyProspect::query()
                    ->where('workspace_id', $exclusion->workspace_id)
                    ->whereKey($exclusion->prospect_id)
                    ->whereNotNull('client_won_at')
                    ->update(['client_won_at' => null]);
            }
        });

        return true;
    }

    /** May this user undo exclusions? The owner always; an Admin only those they added themselves. */
    public function mayRemove(Workspace $workspace, AgencyProspectExclusion $exclusion, int $userId): bool
    {
        return (int) $workspace->owner_user_id === $userId
            || ($exclusion->created_by_user_id !== null && (int) $exclusion->created_by_user_id === $userId);
    }

    /**
     * Durable Client won outcome. One prospect counts once however many campaigns reached them; the
     * attribution campaign is the one with the latest real conversation evidence. A booked call is NOT
     * promoted to won anywhere else. Prospect status (including STOP) is left exactly as it is.
     *
     * @return array{ok: bool, already: bool, error: ?string}
     */
    public function markClientWon(Workspace $workspace, AgencyProspect $prospect, ?int $actorId = null): array
    {
        return DB::transaction(function () use ($workspace, $prospect, $actorId) {
            $locked = AgencyProspect::query()
                ->where('workspace_id', $workspace->id)->whereKey($prospect->id)->lockForUpdate()->first();

            if ($locked === null) {
                return ['ok' => false, 'already' => false, 'error' => 'Prospect not found.'];
            }

            if ($locked->client_won_at !== null) {
                $this->add($workspace, $locked->phone, $locked->company_name, AgencyProspectExclusion::REASON_CLIENT_WON, 'Client won', $actorId, $locked->id);

                return ['ok' => true, 'already' => true, 'error' => null];
            }

            $member = AgencyProspectCampaignMember::query()
                ->where('workspace_id', $workspace->id)->where('prospect_id', $locked->id)
                // The campaign with the latest real conversation evidence (a sent or received message) wins; a
                // campaign that merely enrolled them never out-ranks one that actually talked to them.
                ->orderByRaw('(SELECT MAX(COALESCE(m.received_at, m.sent_at)) FROM agency_prospect_messages m WHERE m.campaign_member_id = agency_prospect_campaign_members.id) IS NULL')
                ->orderByRaw('(SELECT MAX(COALESCE(m.received_at, m.sent_at)) FROM agency_prospect_messages m WHERE m.campaign_member_id = agency_prospect_campaign_members.id) DESC')
                ->orderByDesc('id')
                ->first();

            $locked->update([
                'client_won_at' => now(),
                'acquisition_campaign_id' => $member?->campaign_id,
                'acquisition_member_id' => $member?->id,
                'client_won_evidence' => $member === null ? null : [
                    'member_id' => $member->id,
                    'campaign_id' => $member->campaign_id,
                    'stage' => $member->stage->value,
                    'last_inbound_at' => $member->last_inbound_at?->toIso8601String(),
                    'last_outbound_at' => $member->last_outbound_at?->toIso8601String(),
                    'marked_by_user_id' => $actorId,
                ],
            ]);

            $this->add($workspace, $locked->phone, $locked->company_name, AgencyProspectExclusion::REASON_CLIENT_WON, 'Client won', $actorId, $locked->id);
            $this->suppressUnsent($workspace->id, [$locked->id]);

            return ['ok' => true, 'already' => false, 'error' => null];
        });
    }

    /**
     * Stop any not-yet-sent automatic message for these prospects. Only rows that have NOT been sent
     * are touched (held / deferred / unsent timers); sent history is never altered or deleted.
     *
     * @param  list<int>  $prospectIds
     */
    public function suppressUnsent(int $workspaceId, array $prospectIds): int
    {
        if ($prospectIds === []) {
            return 0;
        }

        $memberIds = AgencyProspectCampaignMember::query()
            ->where('workspace_id', $workspaceId)->whereIn('prospect_id', $prospectIds)->pluck('id')->all();

        if ($memberIds === []) {
            return 0;
        }

        AgencyProspectCampaignMember::query()
            ->whereIn('id', $memberIds)->whereNotNull('followup_at')->whereNull('followup_sent_at')->whereNull('followup_cancelled_at')
            ->update(['followup_cancelled_at' => now()]);

        return AgencyProspectMessage::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('campaign_member_id', $memberIds)
            ->where('direction', AgencyProspectMessage::DIRECTION_OUTBOUND)
            ->where('status', AgencyProspectMessage::STATUS_PAUSED)
            ->update(['status' => AgencyProspectMessage::STATUS_FAILED, 'failure_reason' => self::SKIPPED_REASON, 'deferred_until' => null]);
    }

    /** A prospect whose number is an active Existing Client cannot be prospected (checked on a fresh row at send time). */
    public function blocksProspect(AgencyProspect $prospect): bool
    {
        return $this->isExcluded((int) $prospect->workspace_id, $prospect->phone);
    }
}

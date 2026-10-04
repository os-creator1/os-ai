<?php

namespace App\Library\AgencyOutreach;

use App\Enums\AgencyProspecting\AgencyProspectCampaignStatus;
use App\Enums\AgencyProspecting\AgencyProspectStage;
use App\Enums\AgencyProspecting\AgencyProspectStatus;
use App\Jobs\Outreach\OutreachInitialSendJob;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaign;
use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectMessage;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Start / pause / resume / summaries for MANAGED Outreach campaigns. The
 * channel-based campaigns keep their own controller code untouched.
 *
 * A managed campaign becomes Active only when AgencyOutreachReadiness says
 * every item is ok; the exact reason is returned otherwise. Starting locks the
 * campaign and its prospects in the same deterministic order the channel
 * runtime uses (so the one-open-conversation rule holds), flips Active, and
 * only AFTER commit dispatches one OutreachInitialSendJob per eligible member
 * (the job re-checks everything under its own member lock).
 */
class OutreachCampaignService
{
    private const TERMINAL = [AgencyProspectStage::Booked->value, AgencyProspectStage::StoppedOptOut->value];

    public function __construct(private readonly AgencyOutreachReadiness $readiness)
    {
    }

    /** @return array{ok: bool, error: ?string, message: ?string} */
    public function start(Workspace $workspace, AgencyProspectCampaign $campaign): array
    {
        if (! $campaign->isManaged() || (int) $campaign->workspace_id !== (int) $workspace->id) {
            return $this->fail('This campaign cannot be started from Outreach.');
        }

        if (blank($campaign->opening_message)) {
            return $this->fail('Write the first text before starting this campaign.');
        }

        $reason = $this->readiness->forWorkspace($workspace)->firstBlockingReason();

        if ($reason !== null) {
            return $this->fail($reason);
        }

        $outcome = DB::transaction(function () use ($workspace, $campaign) {
            $locked = AgencyProspectCampaign::where('id', $campaign->id)
                ->where('workspace_id', $workspace->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->status !== AgencyProspectCampaignStatus::Draft) {
                return ['error' => 'Only a draft campaign can be started.'];
            }

            $members = $locked->members()->whereNotIn('stage', self::TERMINAL)->get();
            $prospectIds = $members->pluck('prospect_id')->unique()->sort()->values()->all();

            $prospects = AgencyProspect::whereIn('id', $prospectIds)
                ->where('workspace_id', $workspace->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $eligible = $members->filter(fn ($member) => $prospects->get($member->prospect_id)?->status === AgencyProspectStatus::Active)->values();

            if ($eligible->isEmpty()) {
                return ['error' => 'Add at least one active prospect to this campaign before starting it.'];
            }

            $conflict = AgencyProspectCampaignMember::where('workspace_id', $workspace->id)
                ->whereIn('prospect_id', $eligible->pluck('prospect_id')->all())
                ->where('campaign_id', '!=', $locked->id)
                ->whereNotIn('stage', self::TERMINAL)
                ->whereHas('campaign', fn ($q) => $q->whereIn('status', [AgencyProspectCampaignStatus::Active->value, AgencyProspectCampaignStatus::Paused->value]))
                ->lockForUpdate()
                ->exists();

            if ($conflict) {
                return ['error' => 'One or more prospects already have an open conversation in another active campaign.'];
            }

            $locked->update(['status' => AgencyProspectCampaignStatus::Active->value]);

            return ['memberIds' => $eligible->pluck('id')->all()];
        });

        if (isset($outcome['error'])) {
            return $this->fail($outcome['error']);
        }

        foreach ($outcome['memberIds'] as $memberId) {
            OutreachInitialSendJob::dispatch($memberId);
        }

        return ['ok' => true, 'error' => null, 'message' => 'Campaign started.'];
    }

    /** @return array{ok: bool, error: ?string, message: ?string} */
    public function pause(Workspace $workspace, AgencyProspectCampaign $campaign): array
    {
        $changed = DB::transaction(function () use ($workspace, $campaign) {
            $locked = AgencyProspectCampaign::where('id', $campaign->id)->where('workspace_id', $workspace->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== AgencyProspectCampaignStatus::Active) {
                return false;
            }

            $locked->update(['status' => AgencyProspectCampaignStatus::Paused->value]);

            return true;
        });

        return $changed
            ? ['ok' => true, 'error' => null, 'message' => 'Campaign paused.']
            : $this->fail('Only an active campaign can be paused.');
    }

    /** @return array{ok: bool, error: ?string, message: ?string} */
    public function resume(Workspace $workspace, AgencyProspectCampaign $campaign): array
    {
        $reason = $this->readiness->forWorkspace($workspace)->firstBlockingReason();

        if ($reason !== null) {
            return $this->fail($reason);
        }

        $changed = DB::transaction(function () use ($workspace, $campaign) {
            $locked = AgencyProspectCampaign::where('id', $campaign->id)->where('workspace_id', $workspace->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== AgencyProspectCampaignStatus::Paused) {
                return false;
            }

            $locked->update(['status' => AgencyProspectCampaignStatus::Active->value]);

            return true;
        });

        if (! $changed) {
            return $this->fail('Only a paused campaign can be resumed.');
        }

        // Members whose opener was never attempted (the campaign was paused
        // first) get it now; an already-attempted opener is never re-sent here.
        $members = $campaign->members()
            ->whereNotIn('stage', self::TERMINAL)
            ->whereNull('ai_paused_at')
            ->whereHas('prospect', fn ($q) => $q->where('status', AgencyProspectStatus::Active->value))
            ->whereDoesntHave('messages', fn ($q) => $q->where('operation_key', 'like', 'outreach:opener:%'))
            ->pluck('id');

        foreach ($members as $memberId) {
            OutreachInitialSendJob::dispatch($memberId);
        }

        return ['ok' => true, 'error' => null, 'message' => 'Campaign resumed.'];
    }

    /**
     * Per-campaign figures for the Campaigns list, in a fixed number of queries.
     *
     * @param  Collection<int, AgencyProspectCampaign>  $campaigns
     * @return array<int, array{prospects: int, replies: int, booked: int, paused_for_funds: bool}>
     */
    public function summaries(Workspace $workspace, Collection $campaigns): array
    {
        $ids = $campaigns->pluck('id')->all();
        $out = [];

        foreach ($ids as $id) {
            $out[$id] = ['prospects' => 0, 'replies' => 0, 'booked' => 0, 'paused_for_funds' => false];
        }

        if ($ids === []) {
            return $out;
        }

        $members = DB::table('agency_prospect_campaign_members')
            ->where('workspace_id', $workspace->id)->whereIn('campaign_id', $ids)
            ->selectRaw('campaign_id, COUNT(*) as prospects, SUM(stage = ?) as booked', [AgencyProspectStage::Booked->value])
            ->groupBy('campaign_id')->get();

        foreach ($members as $row) {
            $out[$row->campaign_id]['prospects'] = (int) $row->prospects;
            $out[$row->campaign_id]['booked'] = (int) $row->booked;
        }

        $replies = DB::table('agency_prospect_messages as m')
            ->join('agency_prospect_campaign_members as cm', 'cm.id', '=', 'm.campaign_member_id')
            ->where('m.workspace_id', $workspace->id)->whereIn('cm.campaign_id', $ids)
            ->where('m.direction', AgencyProspectMessage::DIRECTION_INBOUND)
            ->selectRaw('cm.campaign_id, COUNT(DISTINCT cm.id) as replies')
            ->groupBy('cm.campaign_id')->get();

        foreach ($replies as $row) {
            $out[$row->campaign_id]['replies'] = (int) $row->replies;
        }

        $paused = DB::table('agency_prospect_messages as m')
            ->join('agency_prospect_campaign_members as cm', 'cm.id', '=', 'm.campaign_member_id')
            ->where('m.workspace_id', $workspace->id)->whereIn('cm.campaign_id', $ids)
            ->where('m.status', AgencyProspectMessage::STATUS_PAUSED)
            ->where('m.failure_reason', 'insufficient_balance')
            ->select('cm.campaign_id')->distinct()->pluck('campaign_id');

        foreach ($paused as $campaignId) {
            $out[$campaignId]['paused_for_funds'] = true;
        }

        return $out;
    }

    public function hasPausedForFunds(Workspace $workspace): bool
    {
        return AgencyProspectMessage::where('workspace_id', $workspace->id)
            ->where('status', AgencyProspectMessage::STATUS_PAUSED)
            ->where('failure_reason', 'insufficient_balance')
            ->exists();
    }

    private function fail(string $error): array
    {
        return ['ok' => false, 'error' => $error, 'message' => null];
    }
}

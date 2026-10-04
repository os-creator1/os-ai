<?php

namespace App\Jobs\Outreach;

use App\Jobs\Base;
use App\Library\AgencyOutreach\OutreachEligibility;
use App\Library\AgencyOutreach\OutreachScript;
use App\Library\AgencyOutreach\OutreachScriptRenderer;
use App\Library\AgencyOutreach\OutreachSendPipeline;
use App\Library\AgencyOutreach\OutreachSendPlan;
use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectMessage;

/**
 * The one follow-up after the calendar link was sent (contract §11).
 *
 * Dispatched delayed when message 3 goes out AND swept every five minutes
 * (`outreach:dispatch-due-followups`), so a lost job is recovered. Either way it is
 * exactly once per member: the ledger key `outreach:followup:{memberId}` is claimed under
 * the member lock, and a second run finds it and does nothing.
 *
 * Closed (the follow-up is cancelled, never sent): the member is booked / rejected / opted
 * out, the prospect replied after the link went out, the number is on Blacklists, the
 * prospect is no longer active, or follow-ups are switched off. Held (nothing sent, still
 * pending, retried by the sweeper): AI paused, campaign paused, not yet due, or the
 * Agency's Business / entitlement unavailable.
 */
class OutreachFollowUpJob extends Base
{
    /** Eligibility refusals that only HOLD the follow-up; any other refusal closes it. */
    private const HOLD = ['manual_hold', 'campaign_not_active', 'no_business', 'workspace_inactive', 'not_entitled'];

    public function __construct(private readonly int $campaignMemberId)
    {
    }

    public function handle(OutreachEligibility $eligibility, OutreachSendPipeline $pipeline): void
    {
        $key = 'outreach:followup:' . $this->campaignMemberId;

        if (AgencyProspectMessage::query()->where('operation_key', $key)->exists()) {
            return;
        }

        $member = AgencyProspectCampaignMember::query()->where('id', $this->campaignMemberId)->first();

        if ($member === null
            || $member->followup_at === null
            || $member->followup_sent_at !== null
            || $member->followup_cancelled_at !== null) {
            return;
        }

        if ($member->followup_at->isFuture()) {
            return;
        }

        $script = OutreachScript::forWorkspace($member->workspace);

        if (! $script->followUpEnabled() || $this->prospectRepliedSinceLink($member)) {
            $this->cancel($member->id);

            return;
        }

        $eligible = $eligibility->check($member, true);

        if (! $eligible->ok()) {
            if (! in_array($eligible->reason, self::HOLD, true)) {
                $this->cancel($member->id);
            }

            return;
        }

        $body = OutreachScriptRenderer::render($script->get('followup_message'), $eligible->workspace, $eligible->prospect);

        if ($body === '') {
            $this->cancel($member->id);

            return;
        }

        $result = $pipeline->run($member->id, new OutreachSendPlan(
            operationKey: $key,
            body: $body,
            source: AgencyProspectMessage::SOURCE_FOLLOWUP,
            purpose: AgencyProspectMessage::PURPOSE_FOLLOWUP,
            stageFrom: $member->stage->value,
            stageTo: $member->stage->value,
            scriptVersion: $script->version(),
        ), function (AgencyProspectCampaignMember $locked): ?string {
            if ($locked->followup_sent_at !== null || $locked->followup_cancelled_at !== null) {
                return 'followup_closed';
            }

            return $this->prospectRepliedSinceLink($locked) ? 'prospect_replied' : null;
        });

        if ($result->skipReason === 'prospect_replied') {
            $this->cancel($member->id);
        }
    }

    /** A reply after the link went out (or any reply when the link time is unknown) ends the nudge. */
    private function prospectRepliedSinceLink(AgencyProspectCampaignMember $member): bool
    {
        if ($member->last_inbound_at === null) {
            return false;
        }

        return $member->booking_link_sent_at === null
            || $member->last_inbound_at->greaterThan($member->booking_link_sent_at);
    }

    private function cancel(int $memberId): void
    {
        AgencyProspectCampaignMember::query()
            ->where('id', $memberId)
            ->whereNull('followup_sent_at')
            ->whereNull('followup_cancelled_at')
            ->update(['followup_cancelled_at' => now()]);
    }
}

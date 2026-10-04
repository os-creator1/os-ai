<?php

namespace App\Jobs\Outreach;

use App\Jobs\Base;
use App\Library\AgencyOutreach\OutreachEligibility;
use App\Library\AgencyOutreach\OutreachScript;
use App\Library\AgencyOutreach\OutreachScriptRenderer;
use App\Library\AgencyOutreach\OutreachScriptTokens;
use App\Library\AgencyOutreach\OutreachSendPipeline;
use App\Library\AgencyOutreach\OutreachSendPlan;
use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectMessage;

/**
 * The opening message of a `managed` campaign (contract §15), sent when the campaign
 * starts: `AgencyProspectingInitialSendJob` stays the opener for `channel` campaigns.
 *
 * Same gates as every Outreach send, through the canonical sender (member row lock,
 * eligibility re-check, ledger claim under `outreach:opener:{memberId}`). The campaign's
 * `opening_message` is rendered through the canonical merge engine; the owner's own
 * vocabulary ({{agency_name}} ...) is accepted here too, since this text is typed in a
 * campaign form rather than saved through the script editor.
 *
 * The member stays at stage 1 (INTRO): the opener only starts the conversation; the
 * prospect's first reply is what moves the process on.
 */
class OutreachInitialSendJob extends Base
{
    public function __construct(private readonly int $campaignMemberId)
    {
    }

    public function handle(OutreachEligibility $eligibility, OutreachSendPipeline $pipeline): void
    {
        $key = 'outreach:opener:' . $this->campaignMemberId;

        if (AgencyProspectMessage::query()->where('operation_key', $key)->exists()) {
            return;
        }

        $member = AgencyProspectCampaignMember::query()->where('id', $this->campaignMemberId)->first();
        $eligible = $eligibility->check($member, true);

        if (! $eligible->ok()) {
            return;
        }

        $opening = trim((string) $eligible->campaign->opening_message);

        if ($opening === '') {
            return;
        }

        $body = OutreachScriptRenderer::render(OutreachScriptTokens::canonicalise($opening), $eligible->workspace, $eligible->prospect);

        if ($body === '') {
            return;
        }

        $pipeline->run($member->id, new OutreachSendPlan(
            operationKey: $key,
            body: $body,
            source: AgencyProspectMessage::SOURCE_OPENER,
            purpose: AgencyProspectMessage::PURPOSE_INITIAL,
            stageFrom: 1,
            stageTo: 1,
            scriptVersion: OutreachScript::forWorkspace($eligible->workspace)->version(),
        ));
    }
}

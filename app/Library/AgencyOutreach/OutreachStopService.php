<?php

namespace App\Library\AgencyOutreach;

use App\Enums\AgencyProspecting\AgencyProspectStage;
use App\Enums\AgencyProspecting\AgencyProspectStatus;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaignMember;
use App\Models\Blacklists;
use Illuminate\Support\Facades\DB;

/**
 * Applies a prospect's "no" (contract §9). The only writer of Outreach's opt-out and
 * rejection state, called from the inbound listener (immediately, before any reply
 * work is queued) and again by the responder — so it is idempotent by construction.
 *
 *   opt-out   Blacklists row for the Agency Business (idempotent), prospect `stopped` with
 *             stop_reason `opt_out`, EVERY membership of the prospect to stage 99,
 *             pending follow-ups cancelled. Nothing is sent. Cannot be switched off.
 *   rejected  the same, minus the Blacklists row (a polite no is not a carrier opt-out),
 *             stop_reason `rejected`.
 *
 * Never deletes anything, and never downgrades an `opt_out` reason to `rejected`.
 */
final class OutreachStopService
{
    public function __construct(private readonly OutreachInboundLedger $ledger)
    {
    }

    public function apply(AgencyProspectCampaignMember $member, bool $optOut, ?int $inboundMessageId = null): void
    {
        DB::transaction(function () use ($member, $optOut): void {
            $prospect = AgencyProspect::query()->where('id', $member->prospect_id)->lockForUpdate()->first();

            // A forged member/prospect pairing across Workspaces fails closed: nothing is touched.
            if ($prospect === null || (int) $prospect->workspace_id !== (int) $member->workspace_id) {
                return;
            }

            $prospect->update([
                'status' => AgencyProspectStatus::Stopped->value,
                'stopped_at' => $prospect->stopped_at ?? now(),
                'stop_reason' => $optOut || $prospect->stop_reason === AgencyProspect::STOP_OPT_OUT
                    ? AgencyProspect::STOP_OPT_OUT
                    : AgencyProspect::STOP_REJECTED,
            ]);

            AgencyProspectCampaignMember::query()
                ->where('prospect_id', $prospect->id)
                ->where('workspace_id', $prospect->workspace_id)
                ->update([
                    'stage' => AgencyProspectStage::StoppedOptOut->value,
                    'updated_at' => now(),
                ]);

            AgencyProspectCampaignMember::query()
                ->where('prospect_id', $prospect->id)
                ->where('workspace_id', $prospect->workspace_id)
                ->whereNull('followup_sent_at')
                ->whereNull('followup_cancelled_at')
                ->update(['followup_cancelled_at' => now()]);

            if ($optOut) {
                $this->blacklist($prospect);
            }
        });

        if ($inboundMessageId !== null) {
            $this->ledger->markHandled($inboundMessageId, $optOut ? 'opt_out' : 'rejected', $optOut ? 'opted_out' : 'rejected');
        }
    }

    /** One Blacklists row per (Agency Business, number); the number is the canonical digits-only form quickSend() checks. */
    private function blacklist(AgencyProspect $prospect): void
    {
        $workspace = $prospect->workspace;
        $business = $workspace === null ? null : AgencyOutreachBusinessResolver::forWorkspace($workspace);
        $number = preg_replace('/\D/', '', (string) $prospect->phone) ?? '';

        if ($business === null || $number === '') {
            return;
        }

        $exists = Blacklists::query()
            ->where('business_id', (int) $business->id)
            ->where('number', $number)
            ->exists();

        if (! $exists) {
            Blacklists::create([
                'user_id' => $business->customer_id,
                'business_id' => (int) $business->id,
                'number' => $number,
                'reason' => 'Outreach opt-out',
            ]);
        }
    }
}

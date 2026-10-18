<?php

namespace App\Library\AgencyOutreach;

use App\Enums\AgencyProspecting\AgencyProspectStage;
use App\Enums\AgencyProspecting\AgencyProspectStatus;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaignMember;

/**
 * The one place a prospect's plain-language Status is derived (Prospects tab):
 * Active | Awaiting reply | Call proposed | Booking | Booked | Rejected | Opted out.
 * Pure: reads only the prospect and its latest membership, never the database.
 */
final class OutreachProspectStatusPresenter
{
    public const ACTIVE = 'Active';
    public const AWAITING_REPLY = 'Awaiting reply';
    public const CALL_PROPOSED = 'Call proposed';
    public const BOOKING = 'Booking';
    public const BOOKED = 'Booked';
    public const REJECTED = 'Rejected';
    public const OPTED_OUT = 'Opted out';

    /** @return array{label: string, variant: string} */
    public function present(AgencyProspect $prospect, ?AgencyProspectCampaignMember $member): array
    {
        $label = $this->label($prospect, $member);

        return ['label' => $label, 'variant' => match ($label) {
            self::BOOKED => 'accent',
            self::REJECTED, self::OPTED_OUT => 'neutral',
            self::ACTIVE => 'success',
            default => 'warning',
        }];
    }

    public function label(AgencyProspect $prospect, ?AgencyProspectCampaignMember $member): string
    {
        if ($prospect->status === AgencyProspectStatus::Booked || $member?->stage === AgencyProspectStage::Booked) {
            return self::BOOKED;
        }

        if ($prospect->status === AgencyProspectStatus::Stopped || $member?->stage === AgencyProspectStage::StoppedOptOut) {
            return $prospect->stop_reason === AgencyProspect::STOP_OPT_OUT ? self::OPTED_OUT : self::REJECTED;
        }

        if ($member === null) {
            return self::ACTIVE;
        }

        return match ($member->stage) {
            AgencyProspectStage::CallInvitation => self::CALL_PROPOSED,
            AgencyProspectStage::BookingLinkSent, AgencyProspectStage::SchedulingConfirmation => self::BOOKING,
            default => $this->awaitingReply($member) ? self::AWAITING_REPLY : self::ACTIVE,
        };
    }

    public static function stageLabel(?AgencyProspectStage $stage): string
    {
        return match ($stage) {
            AgencyProspectStage::Introduction => 'Intro',
            AgencyProspectStage::Qualification => 'Pitch sent',
            AgencyProspectStage::CallInvitation => 'Call asked',
            AgencyProspectStage::BookingLinkSent => 'Booking link sent',
            AgencyProspectStage::SchedulingConfirmation => 'Scheduling',
            AgencyProspectStage::Booked => 'Booked',
            AgencyProspectStage::StoppedOptOut => 'Stopped',
            default => '—',
        };
    }

    private function awaitingReply(AgencyProspectCampaignMember $member): bool
    {
        if ($member->last_outbound_at === null) {
            return false;
        }

        return $member->last_inbound_at === null || $member->last_outbound_at->greaterThan($member->last_inbound_at);
    }
}

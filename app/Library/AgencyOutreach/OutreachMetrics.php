<?php

namespace App\Library\AgencyOutreach;

use App\Enums\AgencyProspecting\AgencyProspectStatus;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectMessage;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Overview numbers (contract section 16): real counts over this Workspace's
 * own rows, never estimates. A rate whose denominator is 0 is null ("—").
 */
class OutreachMetrics
{
    /**
     * @return array{active: int, messaged: int, replies: int, booked: int, reply_rate: ?float, booking_rate: ?float}
     */
    public function forWorkspace(Workspace $workspace): array
    {
        $active = AgencyProspect::where('workspace_id', $workspace->id)
            ->where('status', AgencyProspectStatus::Active->value)
            ->count();

        $booked = AgencyProspect::where('workspace_id', $workspace->id)
            ->where('status', AgencyProspectStatus::Booked->value)
            ->count();

        $distinctProspects = fn (string $direction, ?string $status) => DB::table('agency_prospect_messages as m')
            ->join('agency_prospect_campaign_members as cm', 'cm.id', '=', 'm.campaign_member_id')
            ->where('m.workspace_id', $workspace->id)
            ->where('cm.workspace_id', $workspace->id)
            ->where('m.direction', $direction)
            ->when($status !== null, fn ($q) => $q->where('m.status', $status))
            ->distinct()
            ->count('cm.prospect_id');

        $messaged = $distinctProspects(AgencyProspectMessage::DIRECTION_OUTBOUND, AgencyProspectMessage::STATUS_SENT);
        $replies = $distinctProspects(AgencyProspectMessage::DIRECTION_INBOUND, null);

        return [
            'active' => $active,
            'messaged' => $messaged,
            'replies' => $replies,
            'booked' => $booked,
            'reply_rate' => $messaged > 0 ? round($replies / $messaged * 100, 1) : null,
            'booking_rate' => $replies > 0 ? round($booked / $replies * 100, 1) : null,
        ];
    }
}

<?php

namespace App\Library\AgencyOutreach;

use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectMessage;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rows for the Outreach Prospects tab: one per prospect of THIS Workspace, with
 * its latest campaign membership, last message and plain-language Status. A fixed
 * number of queries however many prospects there are (no per-row lookups).
 */
class OutreachProspectListReader
{
    public function __construct(private readonly OutreachProspectStatusPresenter $statuses)
    {
    }

    /**
     * @return list<array{prospect: AgencyProspect, campaign: string, stage: string, last_message: string, last_activity: string, status: array{label: string, variant: string}}>
     */
    public function rows(Workspace $workspace): array
    {
        $prospects = AgencyProspect::where('workspace_id', $workspace->id)->orderByDesc('id')->get();

        if ($prospects->isEmpty()) {
            return [];
        }

        $members = AgencyProspectCampaignMember::where('workspace_id', $workspace->id)
            ->with('campaign:id,name')
            ->orderByDesc('id')
            ->get()
            ->unique('prospect_id')
            ->keyBy('prospect_id');

        $memberIds = $members->pluck('id')->all();
        $latestIds = $memberIds === [] ? [] : DB::table('agency_prospect_messages')
            ->where('workspace_id', $workspace->id)
            ->whereIn('campaign_member_id', $memberIds)
            ->whereIn('direction', [AgencyProspectMessage::DIRECTION_INBOUND, AgencyProspectMessage::DIRECTION_OUTBOUND])
            ->selectRaw('MAX(id) as id')
            ->groupBy('campaign_member_id')
            ->pluck('id')
            ->all();

        $messages = $latestIds === [] ? collect() : AgencyProspectMessage::whereIn('id', $latestIds)->get()->keyBy('campaign_member_id');

        $rows = [];

        foreach ($prospects as $prospect) {
            $member = $members->get($prospect->id);
            $message = $member !== null ? $messages->get($member->id) : null;
            $activity = $member === null ? null : collect([$member->last_inbound_at, $member->last_outbound_at])->filter()->max();

            $rows[] = [
                'prospect' => $prospect,
                'campaign' => $member?->campaign?->name ?? '—',
                'stage' => $member === null ? '—' : OutreachProspectStatusPresenter::stageLabel($member->stage),
                'last_message' => $message === null ? '—' : Str::limit((string) $message->body, 60),
                'last_activity' => $activity === null ? '—' : $activity->diffForHumans(),
                'status' => $this->statuses->present($prospect, $member),
            ];
        }

        return $rows;
    }
}

<?php

namespace App\Library\AgencyOutreach;

use App\Enums\AgencyProspecting\AgencyProspectStage;
use App\Models\AgencyProspectCampaign;
use App\Models\AgencyProspectMessage;
use App\Models\Workspace;

/**
 * Read-only numbers for the "campaign is live" view. Everything is counted from this campaign's own rows;
 * nothing is estimated, and an empty campaign yields zeros and an empty activity list (never invented rows).
 */
final class OutreachCampaignLaunchSnapshot
{
    private const INTERESTED_STAGES = [
        AgencyProspectStage::CallInvitation,
        AgencyProspectStage::BookingLinkSent,
        AgencyProspectStage::SchedulingConfirmation,
        AgencyProspectStage::Booked,
    ];

    /**
     * @return array{total: int, sent: int, replies: int, interested: int, booked: int, activity: list<array<string, mixed>>, milestone: array{title: string, text: string, steps: list<array{label: string, done: bool}>}}
     */
    public function forCampaign(Workspace $workspace, AgencyProspectCampaign $campaign, array $summary): array
    {
        $members = $campaign->members()->with('prospect:id,company_name,contact_name,phone')->get();
        $memberIds = $members->pluck('id')->all();

        $sent = $memberIds === [] ? 0 : AgencyProspectMessage::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('campaign_member_id', $memberIds)
            ->where('direction', AgencyProspectMessage::DIRECTION_OUTBOUND)
            ->where('purpose', AgencyProspectMessage::PURPOSE_INITIAL)
            ->where('status', AgencyProspectMessage::STATUS_SENT)
            ->count();

        $interestedValues = array_map(fn ($s) => $s->value, self::INTERESTED_STAGES);
        $interested = $members->filter(fn ($m) => $m->stage !== null && in_array($m->stage->value, $interestedValues, true))->count();

        $byId = $members->keyBy('id');
        $activity = [];

        if ($memberIds !== []) {
            $messages = AgencyProspectMessage::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('campaign_member_id', $memberIds)
                ->where(function ($q) {
                    $q->where(fn ($i) => $i->where('direction', AgencyProspectMessage::DIRECTION_INBOUND))
                        ->orWhere(fn ($o) => $o->where('direction', AgencyProspectMessage::DIRECTION_OUTBOUND)->where('status', AgencyProspectMessage::STATUS_SENT));
                })
                ->orderByRaw('COALESCE(received_at, sent_at, created_at) DESC')
                ->orderByDesc('id')
                ->limit(6)
                ->get();

            foreach ($messages as $message) {
                $member = $byId->get($message->campaign_member_id);
                $prospect = $member?->prospect;
                $inbound = $message->direction === AgencyProspectMessage::DIRECTION_INBOUND;
                $isInterested = $member?->stage !== null && in_array($member->stage->value, $interestedValues, true);

                $activity[] = [
                    'name' => $prospect?->company_name ?: ($prospect?->contact_name ?: 'Prospect'),
                    'text' => $inbound ? '“' . \Illuminate\Support\Str::limit((string) $message->body, 70) . '”' : 'Text delivered',
                    'pill' => $inbound ? ($member?->stage === AgencyProspectStage::Booked ? 'Booked' : ($isInterested ? 'Interested' : 'Replied')) : 'Sent',
                    'tone' => $inbound ? ($isInterested ? 'g' : 'b') : 'n',
                    'at' => ($message->received_at ?? $message->sent_at ?? $message->created_at)?->diffForHumans(),
                ];
            }
        }

        $replies = (int) ($summary['replies'] ?? 0);
        $booked = (int) ($summary['booked'] ?? 0);

        if ($booked > 0) {
            $title = 'Calls are getting booked';
            $text = $booked . ' ' . \Illuminate\Support\Str::plural('call', $booked) . ' booked so far. Keep replying to the rest.';
        } elseif ($interested > 0) {
            $title = 'Next milestone: first booked call';
            $text = $interested . ' ' . \Illuminate\Support\Str::plural('prospect', $interested) . ' ' . ($interested === 1 ? 'is' : 'are') . ' interested. Reply fast — or let the AI send your calendar link.';
        } elseif ($replies > 0) {
            $title = 'Next milestone: first interested prospect';
            $text = 'You have replies. Keep the conversation going to turn them into calls.';
        } else {
            $title = 'Next milestone: first reply';
            $text = $sent > 0 ? 'Texts are out. Replies usually arrive within a few hours.' : 'Texts are being sent. Replies will appear here as they arrive.';
        }

        return [
            'total' => $members->count(),
            'sent' => $sent,
            'replies' => $replies,
            'interested' => $interested,
            'booked' => $booked,
            'activity' => $activity,
            'milestone' => [
                'title' => $title,
                'text' => $text,
                'steps' => [
                    ['label' => 'Launched', 'done' => true],
                    ['label' => 'Replies', 'done' => $replies > 0],
                    ['label' => 'Interested', 'done' => $interested > 0],
                    ['label' => 'Booked', 'done' => $booked > 0],
                ],
            ],
        ];
    }
}

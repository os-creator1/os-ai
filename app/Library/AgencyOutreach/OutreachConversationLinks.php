<?php

namespace App\Library\AgencyOutreach;

use App\Enums\AgencyProspecting\AgencyProspectStage;
use App\Models\AgencyProspectCampaignMember;
use App\Models\ChatBox;
use App\Models\Workspace;
use App\Library\Workspace\WorkspaceManager;
use Illuminate\Support\Facades\Auth;

/**
 * Presentation helpers for the Outreach tabs: where "Open" should go for a campaign member's real
 * conversation, and which conversations need the Agency owner right now. Read-only; it never
 * creates a conversation and never changes a member.
 */
final class OutreachConversationLinks
{
    /**
     * @param iterable<AgencyProspectCampaignMember> $members
     * @return array<int, ?string> member id => URL of the real conversation (null when unavailable)
     */
    public function forMembers(Workspace $workspace, iterable $members): array
    {
        $members = collect($members);
        $business = AgencyOutreachBusinessResolver::forWorkspace($workspace);

        $boxUids = $business === null || $members->isEmpty()
            ? collect()
            : ChatBox::query()->where('business_id', $business->id)->whereIn('id', $members->pluck('chat_box_id')->filter())->pluck('uid', 'id');

        $canOpen = $business !== null && app(WorkspaceManager::class)->userCanAccessBusiness((int) Auth::id(), $business);

        $links = [];

        foreach ($members as $member) {
            $uid = $boxUids->get($member->chat_box_id);
            $links[$member->id] = $canOpen && $uid !== null
                ? route('customer.workspaces.businesses.conversations.index', [$workspace->uid, $business->uid]) . '?open=' . urlencode((string) $uid)
                : null;
        }

        return $links;
    }

    /**
     * Conversations that need a person: the prospect asked for a call, was sent the booking link,
     * or the AI is paused (the owner took over). Newest reply first.
     *
     * @return list<array{member: AgencyProspectCampaignMember, reason: string, pill: string, tone: string, url: ?string}>
     */
    public function needsYou(Workspace $workspace, int $limit = 5): array
    {
        $members = AgencyProspectCampaignMember::query()
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('chat_box_id')
            ->whereNotNull('last_inbound_at')
            ->where(function ($q) {
                $q->whereIn('stage', [AgencyProspectStage::CallInvitation->value, AgencyProspectStage::BookingLinkSent->value])
                    ->orWhereNotNull('ai_paused_at');
            })
            ->whereNotIn('stage', [AgencyProspectStage::Booked->value, AgencyProspectStage::StoppedOptOut->value])
            ->with('prospect')
            ->orderByDesc('last_inbound_at')
            ->limit($limit)
            ->get();

        $links = $this->forMembers($workspace, $members);

        return $members->map(function (AgencyProspectCampaignMember $m) use ($links) {
            $paused = $m->isAiPaused();
            [$reason, $pill, $tone] = match (true) {
                $m->stage === AgencyProspectStage::CallInvitation => ['Asked for a call', 'Call asked', 'a'],
                $m->stage === AgencyProspectStage::BookingLinkSent => ['Booking link sent', 'Link sent', 'b'],
                default => ['You took over', 'Manual', 'n'],
            };

            return [
                'member' => $m,
                'reason' => $reason,
                'pill' => $paused && $m->stage !== AgencyProspectStage::CallInvitation && $m->stage !== AgencyProspectStage::BookingLinkSent ? 'Manual' : $pill,
                'tone' => $tone,
                'url' => $links[$m->id] ?? null,
            ];
        })->all();
    }
}

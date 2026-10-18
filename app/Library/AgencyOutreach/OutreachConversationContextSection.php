<?php

namespace App\Library\AgencyOutreach;

use App\Enums\AgencyProspecting\AgencyProspectStage;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Conversations\Contracts\ConversationContextSection;
use App\Models\AgencyProspectCampaignMember;
use App\Models\Business;
use App\Models\ChatBox;
use App\Models\Contacts;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Auth;

/**
 * The Outreach block in the Conversations contact panel (contract section 8).
 *
 * Shown ONLY when this conversation belongs to the Agency's own Business and is
 * linked to an Outreach campaign member of that same Workspace, and only to the
 * Workspace owner or an active Workspace Admin (the same people Outreach itself
 * admits). Every other conversation gets nothing.
 */
final class OutreachConversationContextSection implements ConversationContextSection
{
    public function describe(Business $business, ChatBox $conversation, ?Contacts $contact): ?array
    {
        $userId = (int) Auth::id();

        if ($userId === 0 || (int) $conversation->business_id !== (int) $business->id) {
            return null;
        }

        $member = AgencyProspectCampaignMember::query()
            ->where('chat_box_id', $conversation->id)
            ->where('workspace_id', $business->workspace_id)
            ->with(['prospect', 'campaign'])
            ->first();

        if ($member === null || $member->prospect === null || (int) $member->prospect->workspace_id !== (int) $business->workspace_id) {
            return null;
        }

        $workspace = Workspace::find($business->workspace_id);

        if ($workspace === null || ! $this->mayManage($workspace, $userId)
            || AgencyOutreachBusinessResolver::forWorkspace($workspace)?->id !== $business->id) {
            return null;
        }

        $prospect = $member->prospect;
        $paused = $member->isAiPaused();

        $outcome = match (true) {
            $member->stage === AgencyProspectStage::Booked => 'Booked',
            $member->stage === AgencyProspectStage::StoppedOptOut => 'Stopped',
            default => 'In progress',
        };

        return [
            'title' => 'Outreach',
            'rows' => [
                ['label' => 'Prospect', 'value' => (string) ($prospect->contact_name ?: $prospect->phone)],
                ['label' => 'Company', 'value' => (string) $prospect->company_name],
                ['label' => 'Campaign', 'value' => (string) ($member->campaign?->name ?? '—')],
                ['label' => 'Stage', 'value' => OutreachProspectStatusPresenter::stageLabel($member->stage)],
                ['label' => 'Replies', 'value' => $paused ? 'Manual (you are replying)' : 'AI'],
                ['label' => 'Outcome', 'value' => $outcome],
            ],
            'actions' => $member->isTerminal() ? [] : [[
                'label' => $paused ? 'Resume AI' : 'Pause AI',
                'url' => route($paused ? 'customer.workspaces.prospecting.conversations.resume' : 'customer.workspaces.prospecting.conversations.pause', [$workspace->uid, $member->uid]),
                'method' => 'post',
            ]],
        ];
    }

    private function mayManage(Workspace $workspace, int $userId): bool
    {
        if ((int) $workspace->owner_user_id === $userId) {
            return true;
        }

        return WorkspaceMembership::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->where('role', WorkspaceMembershipRole::Admin->value)
            ->exists();
    }
}

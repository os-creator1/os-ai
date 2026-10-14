<?php

namespace App\Library\Assistant;

use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Dashboard\DashboardLinkGate;
use App\Library\Workspace\AccountFrameAccess;
use App\Repositories\Contracts\WorkspaceMembershipRepository;

/**
 * Where "Upgrade plan" may point — and for whom. Only someone who can actually manage the subscription (the account
 * owner, or an active Admin who sees the account frame — the very rule the Plan & subscription page enforces) is
 * handed the real plan page; everyone else is told to ask the person who manages billing. A link is never offered
 * that the destination would answer with a 404, and never while viewing as a client.
 */
final class AssistantUpgradePath
{
    public function __construct(
        private readonly WorkspaceMembershipRepository $memberships,
        private readonly AccountFrameAccess $frame,
        private readonly DashboardLinkGate $links,
    ) {
    }

    /** @return array{url: string, label: string}|null null = this actor cannot manage the subscription */
    public function for(AssistantContext $context): ?array
    {
        $userId = (int) $context->user->id;
        $workspace = $context->workspace;

        $manages = (int) $workspace->owner_user_id === $userId
            || (($membership = $this->memberships->findByWorkspaceAndUser($workspace, $userId)) !== null
                && $membership->role === WorkspaceMembershipRole::Admin
                && $this->frame->allows($workspace, $userId));

        if (! $manages) {
            return null;
        }

        $url = $this->links->url($context->customer, $context->user, $context->entitlements, 'customer.workspaces.plan.show', [$workspace->uid], ['access_backend']);

        return $url === null ? null : ['url' => $url, 'label' => __('ask_ai.trial_limit.cta')];
    }
}

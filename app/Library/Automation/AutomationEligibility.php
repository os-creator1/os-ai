<?php

namespace App\Library\Automation;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Automation;
use App\Models\AutomationExecution;
use App\Models\Business;
use App\Models\Contacts;
use App\Models\Workspace;

/**
 * B4 Business Automations — the single authoritative "may this automation
 * run right now?" check (contract §9). Always re-fetches from the
 * database; never trusts a model instance carried in from an earlier
 * step, because state read at trigger time is never sufficient to
 * authorize an action (§9.2).
 *
 * Used at every required checkpoint (§9.1): trigger evaluation, immediately
 * before the execution claim, and inside the action job before any
 * provider call.
 */
class AutomationEligibility
{
    public function __construct(private readonly EntitlementManager $entitlementManager)
    {
    }

    /**
     * @return array{automation: Automation, business: Business, workspace: Workspace}|null
     */
    public function resolve(int $automationId, ?string &$reason = null): ?array
    {
        $automation = Automation::query()->find($automationId);

        if ($automation === null) {
            $reason = 'automation_missing';

            return null;
        }

        if (! $automation->isActive()) {
            $reason = 'automation_disabled';

            return null;
        }

        if (! $automation->isRunnableDefinition()) {
            // Includes every legacy NULL-business row (§3.5).
            $reason = 'automation_not_runnable';

            return null;
        }

        $business = Business::query()->find($automation->business_id);

        if ($business === null || $business->status !== BusinessStatus::Active) {
            $reason = 'business_inactive';

            return null;
        }

        $workspace = $business->workspace_id !== null ? Workspace::query()->find($business->workspace_id) : null;

        if ($workspace === null || ! $workspace->is_active) {
            $reason = 'workspace_inactive';

            return null;
        }

        if (! $this->entitled($workspace, $business)) {
            $reason = 'not_entitled';

            return null;
        }

        return ['automation' => $automation, 'business' => $business, 'workspace' => $workspace];
    }

    /**
     * Contract §9 / Correction 2 — the FULL authoritative check for one
     * already-claimed execution, read fresh from the database. Used as the
     * final checkpoint AFTER the durable execution-start claim and
     * immediately before the action, so the objects handed to the action
     * are the ones verified here, never anything loaded earlier.
     *
     * Verifies, in order: automation exists / active / runnable, Business
     * active, Workspace active, entitlement allowed (all via resolve()),
     * then automation.business_id == execution.business_id,
     * automation.trigger_type == execution.trigger_type, the Contact still
     * exists and belongs to that Business, and — when the current trigger
     * names an explicit audience group — the Contact is still in it.
     *
     * @return array{automation: Automation, business: Business, workspace: Workspace, contact: Contacts}|null
     */
    public function resolveForExecution(AutomationExecution $execution, ?string &$reason = null): ?array
    {
        $resolved = $this->resolve((int) $execution->automation_id, $reason);

        if ($resolved === null) {
            return null;
        }

        /** @var Automation $automation */
        $automation = $resolved['automation'];

        if ((int) $automation->business_id !== (int) $execution->business_id) {
            $reason = 'business_mismatch';

            return null;
        }

        if ($automation->trigger_type !== $execution->trigger_type) {
            $reason = 'trigger_mismatch';

            return null;
        }

        $contact = Contacts::query()->find($execution->contact_id);

        if ($contact === null || ! $this->contactBelongsToBusiness($contact, $resolved['business'])) {
            $reason = 'contact_not_in_business';

            return null;
        }

        if (! $this->contactInAudience($automation, $contact)) {
            $reason = 'contact_outside_audience';

            return null;
        }

        return $resolved + ['contact' => $contact];
    }

    /**
     * The Contact must belong to the SAME Business as the automation.
     */
    public function contactBelongsToBusiness(Contacts $contact, Business $business): bool
    {
        return $contact->business_id !== null && (int) $contact->business_id === (int) $business->id;
    }

    /**
     * When the CURRENT trigger definition names an explicit audience group,
     * the Contact must still be in it; an unrestricted audience matches any
     * group of the Business.
     */
    public function contactInAudience(Automation $automation, Contacts $contact): bool
    {
        $groupId = $automation->trigger_config['contact_group_id'] ?? null;

        return $groupId === null || (int) $groupId === (int) $contact->group_id;
    }

    /**
     * Contract §2.4/§2.5 — the Business-scoped decision seam. Asynchronous
     * execution has no acting browser user, so the Business's own
     * persistence owner (`businesses.customer_id`, a real users FK) is
     * passed as the actor id. EntitlementManager::decide() accepts that
     * argument but never reads it (verified: it is a signature/audit
     * parameter only), so this is a structurally-required, behaviorally-
     * inert value — NOT an authorization decision, and never Auth::id().
     */
    public function entitled(Workspace $workspace, Business $business): bool
    {
        try {
            $decision = $this->entitlementManager->decide(
                $workspace,
                $business,
                PlatformFeature::Automations->value,
                (int) $business->customer_id,
            );
        } catch (WorkspaceBusinessNotFoundException|BusinessWorkspaceMismatchException) {
            return false;
        }

        return $decision->allowed;
    }
}

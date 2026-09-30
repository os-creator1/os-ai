<?php

namespace App\Library\Automation\Workflow\Executors;

use App\Enums\Automation\Workflow\WorkflowNodeType;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Library\Automation\Workflow\Contracts\NodeExecutionOutcome;
use App\Library\Automation\Workflow\Contracts\NodeExecutor;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflowNode;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Contacts;
use App\Models\User;
use App\Notifications\WorkflowInternalNotification;
use App\Repositories\Contracts\WorkspaceMembershipBusinessRepository;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Automations V2 §10 — "Notify the team".
 *
 * WHO IS TOLD is the whole of this class, and it is answered against the
 * platform's existing authority model rather than a new one. There is no
 * "assignee" concept in this product and this slice does not invent one: the
 * recipients are the Business owner and the active Workspace members whose
 * business_access_scope actually covers this Business — the same predicate
 * BillingProfileManager and UsageWalletManager use to decide who may act on a
 * Business, minus their Admin-only restriction, because being told something is
 * not the same authority as changing it.
 *
 * Four exclusions fall out of that, and each is a way this could leak:
 *
 *   ANOTHER WORKSPACE'S MEMBERS  memberships are read for this Business's own
 *                                workspace only, so a member of a different
 *                                workspace is never even a candidate.
 *   INACTIVE MEMBERS             a deactivated membership is still a row; it is
 *                                filtered by is_active, not by its absence.
 *   MEMBERS WITHOUT ACCESS       scope `selected` means exactly the assigned
 *                                Businesses, checked against the real
 *                                workspace_membership_businesses rows.
 *   DUPLICATES                   the Business owner very often also holds a
 *                                membership, so recipients are keyed by user id
 *                                and each person is notified once.
 *
 * Side-effect class External: an interrupted notification step is failed and
 * never re-run, for the same reason a send is — the outcome is unknown, and a
 * duplicate is worse than a miss.
 *
 * LOCATION ACL (independent review, pre-merge finding #3). The predicate
 * above answers "who may see this BUSINESS" — it says nothing about
 * Location, so applied alone it can hand a run at Location A's Contact
 * information to staff authorized only for Location B. Every candidate
 * recipient is additionally required to pass
 * `LocationAccessGuard::userCanAccessLocation()` against the run's own
 * PINNED Location (the enrollment's `business_location_id`, re-derived
 * fresh here) — never the Contact's current Location, for the same
 * immutability reason the checkpoint uses the pin (§13). The Business owner
 * is not special-cased: the guard's own authority table already grants a
 * direct owner unconditional ('all') reach, so routing them through the
 * same check is correct, not redundant.
 */
class InternalNotificationNodeExecutor implements NodeExecutor
{
    public function __construct(
        private readonly WorkspaceMembershipRepository $memberships,
        private readonly WorkspaceMembershipBusinessRepository $membershipBusinesses,
        private readonly LocationAccessGuard $locationAccessGuard,
    ) {
    }

    public function handles(): WorkflowNodeType
    {
        return WorkflowNodeType::InternalNotification;
    }

    public function execute(
        AutomationWorkflowNode $node,
        AutomationEnrollment $enrollment,
        Business $business,
        Contacts $contact,
    ): NodeExecutionOutcome {
        $config = is_array($node->config) ? $node->config : [];
        $message = is_string($config['message'] ?? null) ? trim((string) $config['message']) : '';

        if ($message === '') {
            return NodeExecutionOutcome::skipped('notification_config_invalid');
        }

        $location = $this->pinnedLocation($enrollment, $business);

        if ($location === null) {
            // The checkpoint (review finding #2) already holds/exits an
            // enrollment whose pinned Location cannot be proven, so this
            // should be unreachable in practice. Fail closed anyway rather
            // than notifying anyone about a run whose Location cannot be
            // confirmed at all.
            return NodeExecutionOutcome::skipped('location_unresolved');
        }

        $recipients = $this->recipients($business, $location);

        if ($recipients === []) {
            // Nobody can see this Business AT THIS LOCATION. Not a failure —
            // there is simply no one to tell, and ending a customer's journey
            // over that would be the wrong trade.
            return NodeExecutionOutcome::skipped('no_notification_recipients');
        }

        $workflow = $enrollment->workflow;

        $notification = new WorkflowInternalNotification(
            businessName: (string) $business->name,
            workflowName: (string) ($workflow->name ?? 'Workflow'),
            contactReference: $this->maskedPhone($contact),
            message: $message,
            businessId: (int) $business->id,
            workflowId: (int) $enrollment->workflow_id,
        );

        try {
            Notification::send(array_values($recipients), $notification);
        } catch (Throwable $exception) {
            return NodeExecutionOutcome::failed('notification_exception: ' . class_basename($exception));
        }

        return NodeExecutionOutcome::succeeded(sprintf('Notified %d recipient(s)', count($recipients)));
    }

    /**
     * The run's own pinned Location, re-derived fresh and re-verified
     * against this Business — never trusted from a passed-in model, the
     * same fail-closed discipline `WorkflowLocationAdmission` and
     * `LocationAccessGuard` already follow.
     */
    private function pinnedLocation(AutomationEnrollment $enrollment, Business $business): ?BusinessLocation
    {
        $locationId = $enrollment->business_location_id;

        if ($locationId === null) {
            return null;
        }

        $location = BusinessLocation::query()->find($locationId);

        if ($location === null || $location->business_id === null || (int) $location->business_id !== (int) $business->id) {
            return null;
        }

        return $location;
    }

    /**
     * The Business owner plus every active member who can see this Business
     * AND is authorized for the run's own pinned Location, each exactly
     * once.
     *
     * @return array<int, User> keyed by user id, which is what makes it a set
     */
    private function recipients(Business $business, BusinessLocation $location): array
    {
        $recipients = [];

        $owner = User::query()->find((int) $business->customer_id);

        if ($owner instanceof User && $this->locationAccessGuard->userCanAccessLocation((int) $owner->id, $location)) {
            $recipients[(int) $owner->id] = $owner;
        }

        $workspace = $business->workspace;

        if ($workspace === null) {
            return $recipients;
        }

        foreach ($this->memberships->activeForWorkspace($workspace) as $membership) {
            // Defence in depth: activeForWorkspace() already filters is_active,
            // but this is the invariant the exclusion actually depends on, so it
            // is asserted where it matters rather than assumed of a helper.
            if (! $membership->is_active) {
                continue;
            }

            $covers = $membership->business_access_scope === WorkspaceBusinessAccessScope::All
                || $this->membershipBusinesses->isAssigned($membership, (int) $business->id);

            if (! $covers) {
                continue;
            }

            $userId = (int) $membership->user_id;

            if (isset($recipients[$userId])) {
                continue;
            }

            if (! $this->locationAccessGuard->userCanAccessLocation($userId, $location)) {
                continue;
            }

            $user = $membership->user;

            if ($user instanceof User) {
                $recipients[$userId] = $user;
            }
        }

        return $recipients;
    }

    private function maskedPhone(Contacts $contact): string
    {
        $digits = preg_replace('/\D/', '', (string) $contact->phone) ?? '';

        return strlen($digits) > 4 ? str_repeat('*', strlen($digits) - 4) . substr($digits, -4) : '****';
    }
}

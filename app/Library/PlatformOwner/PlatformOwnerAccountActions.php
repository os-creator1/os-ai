<?php

namespace App\Library\PlatformOwner;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspaceEntitlementTransitionType;
use App\Exceptions\Workspace\WorkspaceNotFoundException;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Business;
use App\Models\WorkspacePlanAssignment;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\WorkspaceEntitlementTransitionRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Platform Owner / Admin V1 — the two account-state writes this surface
 * exposes, and nothing else.
 *
 * Neither is a new capability. Each one routes an existing domain operation
 * through the Platform Owner support pages with the three things a support
 * action needs and the bare operation lacked: an authority re-check that does
 * not trust the caller, a REQUIRED reason, and a durable audit row.
 *
 *  1. restoreAccess() — EntitlementManager::recoverAccess(). Clears the
 *     recorded Grace/Locked timestamps in one write (Contract 03 §6 case E).
 *     The manager already locks the Workspace row, audits through
 *     workspace_entitlement_transitions (actor + reason, exactly one row per
 *     real change, none for a repeat) and dispatches its event; this class
 *     adds nothing to that and re-implements none of it.
 *
 *  2. changeBusinessStatus() — BusinessRepository::updateStatus(), the very
 *     write the admin Business page has always made, now inside a
 *     transaction on the locked Business row with an audit row in the same
 *     transaction. Moving a Business to Inactive is high-impact, so it needs
 *     a reason.
 *
 * Deliberately NOT here: plan assignment, tier changes, Stripe or
 * subscription status edits, payer or ownership changes, credential edits.
 * Those either already have their own audited admin surface (Workspace plan
 * cards) or are out of scope for this lane.
 */
final class PlatformOwnerAccountActions
{
    public function __construct(
        private readonly PlatformOwnerAuthority $authority,
        private readonly EntitlementManager $entitlementManager,
        private readonly WorkspaceSupportReader $reader,
        private readonly WorkspaceRepository $workspaces,
        private readonly BusinessRepository $businesses,
        private readonly WorkspaceEntitlementTransitionRepository $transitions,
    ) {
    }

    /**
     * @throws \Illuminate\Auth\Access\AuthorizationException   not a platform administrator
     * @throws InvalidArgumentException                          empty reason
     * @throws WorkspaceNotFoundException                        target no longer exists
     * @throws NothingToRestoreException                         no recorded Grace/Locked state to clear
     */
    public function restoreAccess(int $workspaceId, int $actorUserId, string $reason): WorkspacePlanAssignment
    {
        $this->authority->assertAdministrator($actorUserId);

        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required to restore access.');
        }

        // Re-read the TARGET authoritatively; never trust a model the
        // controller bound earlier in the request.
        $workspace = $this->workspaces->findById($workspaceId);

        if ($workspace === null) {
            throw new WorkspaceNotFoundException($workspaceId);
        }

        $summary = $this->entitlementManager->getWorkspaceEntitlementSummary($workspace);

        if (! $this->reader->canRestoreAccess($summary)) {
            throw new NothingToRestoreException($workspaceId);
        }

        return $this->entitlementManager->recoverAccess($workspace, $actorUserId, $reason);
    }

    /**
     * @throws \Illuminate\Auth\Access\AuthorizationException   not a platform administrator
     * @throws InvalidArgumentException                          deactivating without a reason
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException target no longer exists
     */
    public function changeBusinessStatus(int $businessId, BusinessStatus $to, int $actorUserId, ?string $reason): Business
    {
        $this->authority->assertAdministrator($actorUserId);

        $reason = $reason === null ? null : trim($reason);
        $reason = $reason === '' ? null : $reason;

        if ($to === BusinessStatus::Inactive && $reason === null) {
            throw new InvalidArgumentException('A reason is required to deactivate a Business.');
        }

        return DB::transaction(function () use ($businessId, $to, $actorUserId, $reason) {
            $locked = $this->businesses->findForUpdate($businessId);

            if ($locked === null) {
                throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())->setModel(Business::class, [$businessId]);
            }

            $from = $locked->status;

            // Idempotent: a repeat submission changes nothing and audits nothing.
            if ($from === $to) {
                return $locked;
            }

            $updated = $this->businesses->updateStatus($locked, $to);

            $this->transitions->create([
                'workspace_id' => $updated->workspace_id,
                'transition_type' => WorkspaceEntitlementTransitionType::BusinessStatusChanged,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
                'payload' => [
                    'business_id' => (int) $updated->id,
                    'business_uid' => (string) $updated->uid,
                    'from' => $from?->value,
                    'to' => $to->value,
                ],
            ]);

            // Raised once, after commit, only when the status really changed: the single
            // canonical seam for "Business suspended / reactivated".
            DB::afterCommit(fn () => \App\Events\Business\BusinessStatusChanged::dispatch((int) $updated->id, (string) $from?->value, $to->value, $actorUserId));

            return $updated;
        });
    }
}

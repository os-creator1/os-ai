<?php

namespace App\Library\PlatformBilling;

use App\Enums\PlatformBilling\PlatformSubscriptionStatus;
use App\Library\Entitlement\EntitlementManager;
use App\Models\PlatformSubscription;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspacePlanAssignmentRepository;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Implementation Contract 21 §9/§12 — THE shared idempotent finalizer for
 * lane A.
 *
 * Every path that learns a provider outcome comes through here: the
 * post-checkout return, the verified webhook, and any future reconciliation.
 * One finalizer is what makes replay safety a property of the system rather
 * than of each caller — the same reason Contract 17 §8.2 has one.
 *
 * IT SUPPLIES REASONS; IT DOES NOT OWN THE LIFECYCLE. The canonical account
 * lifecycle already exists and is already correct: `WorkspacePlanAssignment` +
 * `EntitlementManager`'s writers + `CustomerAccountAccessResolver`'s derivation
 * of Trial / Active / Grace / Locked (§3.3). This class adds NO status enum, NO
 * second grace window and NO second authority. It translates
 * provider-confirmed subscription state into calls on writers that already
 * exist:
 *
 *   trialing            -> nothing. The trial marker was SNAPSHOTTED at signup
 *                          (§8), and provider drift must not rewrite what the
 *                          customer was promised.
 *   active              -> recoverAccess(): a confirmed payment restores
 *                          access immediately (Blueprint §27) and clears the
 *                          trial/grace/lock markers in one idempotent write.
 *   past_due / unpaid   -> enterGracePeriod(): ONE 3-day window. The writer is
 *   / paused               already idempotent — an assignment that is already
 *                          in Grace or Locked is returned untouched — which is
 *                          precisely why repeated provider failure events
 *                          cannot extend it (§9).
 *   canceled /          -> lockForNonPayment(): end of service. Read-only,
 *   incomplete_expired     data preserved, and a confirmed payment restores it.
 *   incomplete          -> nothing. Nothing has been confirmed, and §7 forbids
 *                          fabricating state from an unconfirmed attempt.
 *
 * On `lockForNonPayment()` for a CANCELLATION: the writer's subject is
 * "locked_at = now, data preserved, payment restores access", which is exactly
 * the end-of-service outcome. WHY it happened travels in the reason string,
 * which is what a reason string is for. Adding a near-duplicate writer that
 * differed only in its name would be duplication, not clarity.
 *
 * COMPLIMENTARY ASSIGNMENTS ARE NEVER TOUCHED (§11.1). A complimentary
 * Workspace has no lane-A subscription to speak for it, and a stray provider
 * event must never be able to lock an account the Platform Owner is
 * deliberately carrying.
 *
 * OUT-OF-ORDER DELIVERY (§12.6). Stripe does not guarantee ordering. A snapshot
 * whose event is OLDER than the last one already applied is ignored, so a late
 * `past_due` cannot overwrite a newer `active` and re-lock a customer who has
 * already paid.
 *
 * NO NETWORK HERE, so holding locks is safe.
 */
final class PlatformSubscriptionFinalizer
{
    public const APPLIED = 'applied';
    public const IGNORED_OUT_OF_ORDER = 'ignored_out_of_order';
    public const IGNORED_NO_CHANGE = 'ignored_no_change';
    public const IGNORED_COMPLIMENTARY = 'ignored_complimentary';
    public const SUBSCRIPTION_MISMATCH = 'subscription_mismatch';
    public const CUSTOMER_MISMATCH = 'customer_mismatch';
    public const OPERATION_ID_MISMATCH = 'operation_id_mismatch';

    public function __construct(
        private readonly EntitlementManager $entitlements,
        private readonly WorkspacePlanAssignmentRepository $assignments,
    ) {
    }

    /**
     * Applies one provider observation to one local lane-A subscription.
     *
     * @return string one of the disposition constants above
     */
    public function apply(
        PlatformSubscription $subscription,
        PlatformSubscriptionSnapshot $snapshot,
        ?string $eventId = null,
        ?CarbonInterface $eventCreatedAt = null,
    ): string {
        $mismatch = $this->crossCheck($subscription, $snapshot);

        if ($mismatch !== null) {
            return $mismatch;
        }

        [$disposition, $workspaceId, $localStatus] = DB::transaction(function () use ($subscription, $snapshot, $eventId, $eventCreatedAt) {
            $locked = PlatformSubscription::query()
                ->whereKey($subscription->id)
                ->lockForUpdate()
                ->firstOrFail();

            // §12.6 — a strictly older event never moves canonical state.
            // Equal timestamps are allowed through: Stripe can emit several
            // events in the same second, and the writers below are all
            // idempotent, so admitting them is safe while dropping them is not.
            if ($eventCreatedAt !== null
                && $locked->last_event_at !== null
                && $eventCreatedAt->lt($locked->last_event_at)
                && (string) $locked->last_event_id !== (string) $eventId) {
                return [self::IGNORED_OUT_OF_ORDER, null, null];
            }

            $locked->forceFill([
                'provider_subscription_id' => $snapshot->providerSubscriptionId,
                'provider_customer_id' => $snapshot->providerCustomerId !== ''
                    ? $snapshot->providerCustomerId
                    : $locked->provider_customer_id,
                'provider_price_id' => $snapshot->providerPriceId ?? $locked->provider_price_id,
                'status' => $snapshot->status->value,
                'cancel_at_period_end' => $snapshot->cancelAtPeriodEnd,
                'current_period_start' => $snapshot->periodStart,
                'current_period_end' => $snapshot->periodEnd,
                'trial_ends_at' => $snapshot->trialEndsAt,
                'canceled_at' => $snapshot->canceledAt,
                'ended_at' => $snapshot->endedAt,
                'last_event_id' => $eventId ?? $locked->last_event_id,
                'last_event_at' => $eventCreatedAt ?? $locked->last_event_at,
                'last_reason' => $snapshot->status->value,
            ])->save();

            return [self::APPLIED, (int) $locked->workspace_id, $snapshot->status];
        });

        if ($disposition !== self::APPLIED) {
            return $disposition;
        }

        return $this->driveLifecycle((int) $workspaceId, $localStatus);
    }

    /**
     * The §3.3 lifecycle, driven entirely through EntitlementManager's
     * existing writers. Each one is idempotent on its own, so this method is
     * safe to run for every delivery of every event.
     */
    private function driveLifecycle(int $workspaceId, PlatformSubscriptionStatus $status): string
    {
        $workspace = Workspace::query()->find($workspaceId);

        if ($workspace === null) {
            return self::SUBSCRIPTION_MISMATCH;
        }

        $assignment = $this->assignments->findByWorkspaceId($workspaceId);

        // No assignment yet (checkout confirmed before provisioning finished),
        // or a complimentary Workspace the Platform Owner is carrying — either
        // way lane A does not write the lifecycle here.
        if ($assignment === null) {
            return self::APPLIED;
        }

        if ((bool) $assignment->is_complimentary) {
            return self::IGNORED_COMPLIMENTARY;
        }

        $reason = 'platform_subscription_' . $status->value;

        match (true) {
            $status === PlatformSubscriptionStatus::Active
                => $this->entitlements->recoverAccess($workspace, null, $reason),

            in_array($status, [
                PlatformSubscriptionStatus::PastDue,
                PlatformSubscriptionStatus::Unpaid,
                PlatformSubscriptionStatus::Paused,
            ], true)
                => $this->entitlements->enterGracePeriod($workspace, null, $reason),

            in_array($status, [
                PlatformSubscriptionStatus::Canceled,
                PlatformSubscriptionStatus::IncompleteExpired,
            ], true)
                => $this->entitlements->lockForNonPayment($workspace, null, $reason),

            // Trialing and Incomplete deliberately write nothing.
            default => null,
        };

        return self::APPLIED;
    }

    /**
     * §12.4's fail-closed identity checks. A snapshot that cannot be proven to
     * belong to THIS local row is refused with a reason code — never
     * reconciled by guessing which side is right, and never allowed to write.
     */
    private function crossCheck(PlatformSubscription $subscription, PlatformSubscriptionSnapshot $snapshot): ?string
    {
        if ($subscription->provider_subscription_id !== null
            && (string) $subscription->provider_subscription_id !== $snapshot->providerSubscriptionId) {
            return self::SUBSCRIPTION_MISMATCH;
        }

        if ($subscription->provider_customer_id !== null
            && $snapshot->providerCustomerId !== ''
            && (string) $subscription->provider_customer_id !== $snapshot->providerCustomerId) {
            return self::CUSTOMER_MISMATCH;
        }

        // The provider echoes our own durable row UID back in subscription
        // metadata. When present it must be ours.
        if ($snapshot->operationId !== null && $snapshot->operationId !== (string) $subscription->uid) {
            return self::OPERATION_ID_MISMATCH;
        }

        return null;
    }
}

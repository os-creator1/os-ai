<?php

namespace App\Library\PlatformBilling;

use App\Enums\PlatformBilling\PlatformSubscriptionStatus;
use App\Library\Entitlement\EntitlementManager;
use App\Models\PlatformSubscription;
use App\Models\Workspace;
use App\Models\WorkspacePlanCatalog;
use App\Repositories\Contracts\WorkspacePlanAssignmentRepository;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
 *   trialing            -> startProviderConfirmedTrial(): the account is
 *                          usable, on the trial end the PROVIDER confirmed,
 *                          with any stale grace/lock cleared. It writes only
 *                          when something is actually stale, so an ordinary
 *                          running trial replays as a no-op, and it refuses to
 *                          unlock at all when the provider gives no usable
 *                          trial end. (Nothing here reads the catalog: §8's
 *                          rule that a later catalog edit cannot rewrite an
 *                          existing trial is untouched.)
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

        // §10 — finish any durable plan-change operation this observation
        // proves. Deliberately BEFORE the lifecycle: if the tier is moving and
        // access is being restored in the same breath, the customer should end
        // up usable on the tier they just bought, not usable on the old one.
        $this->convergePlanChange($subscription->refresh(), $snapshot);

        return $this->driveLifecycle((int) $workspaceId, $snapshot);
    }

    /**
     * §10 — THE CONVERGENCE POINT for a durable plan-change operation.
     *
     * Both routes reach it: the synchronous response to
     * `requestPlanChange()`/the scheduled downgrade sweep, and a later
     * `customer.subscription.updated` webhook. Whichever arrives first
     * finishes the operation; the other finds nothing pending and does
     * nothing. That is what makes the invariant hold:
     *
     *   PROVIDER PRICE CHANGED TO TARGET
     *     → eventually the local commercial snapshot AND the canonical V1
     *       entitlement converge to that same target, exactly once.
     *
     * PROVIDER TRUTH IS THE GATE. The operation finishes only when the
     * snapshot shows the TARGET Price actually active on the subscription. A
     * provider call that did not take effect — or one whose response was lost
     * and whose change never happened — leaves the operation pending and the
     * entitlement exactly where it was. Entitlement is never widened on our
     * own intent.
     *
     * THE ORDER IS DELIBERATE: commercial snapshot, then entitlement, then
     * clear the operation. Every step is idempotent, so a crash anywhere in
     * the middle is repaired by the next delivery; clearing first would drop
     * the very record that repair depends on.
     *
     * NO NETWORK, and the entitlement writer opens its own transaction, so
     * nothing here holds a lock across anything slow.
     */
    private function convergePlanChange(PlatformSubscription $subscription, PlatformSubscriptionSnapshot $snapshot): void
    {
        if ($subscription->pending_operation_uid === null || blank($subscription->pending_price_id)) {
            return;
        }

        if ($snapshot->providerPriceId === null
            || (string) $snapshot->providerPriceId !== (string) $subscription->pending_price_id) {
            return;
        }

        $target = WorkspacePlanCatalog::query()->find($subscription->pending_plan_catalog_id);
        $workspace = Workspace::query()->find($subscription->workspace_id);

        if ($target === null || $workspace === null) {
            return;
        }

        // 1. The commercial record: the terms AGREED WHEN THE CHANGE WAS
        //    REQUESTED, not whatever the catalog says today.
        DB::transaction(function () use ($subscription): void {
            $locked = PlatformSubscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();

            if ($locked->pending_operation_uid === null) {
                return;
            }

            $locked->forceFill([
                'workspace_plan_catalog_id' => $locked->pending_plan_catalog_id,
                'provider_price_id' => $locked->pending_price_id,
                'price_snapshot' => $locked->pending_price_snapshot,
                'currency_id' => $locked->pending_currency_id,
                'currency_code' => $locked->pending_currency_code,
                'billing_cycle_snapshot' => $locked->pending_billing_cycle,
            ])->save();
        });

        // 2. The canonical V1 entitlement. `changePlan()` is a no-op when the
        //    assignment already holds the destination catalog, so replaying
        //    this writes nothing and dispatches nothing.
        //
        //    An UNASSIGNED Workspace is not an error here: signup's own
        //    activation seam assigns the first plan from
        //    `workspace_plan_catalog_id`, which step 1 has just moved to the
        //    target, so that path converges on the same tier without this one
        //    inventing an assignment out of a plan change.
        if ($this->assignments->findByWorkspaceId((int) $workspace->id) !== null) {
            $this->entitlements->changePlanFromVerifiedSubscription(
                $workspace,
                $target->tier,
                (int) $workspace->owner_user_id,
                (string) $subscription->uid,
                $snapshot->providerSubscriptionId,
                'platform_subscription_' . ($subscription->pending_kind ?? 'plan_change'),
            );
        }

        // 3. Only now is the operation finished.
        DB::transaction(function () use ($subscription): void {
            PlatformSubscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail()->forceFill([
                'pending_operation_uid' => null,
                'pending_kind' => null,
                'pending_plan_catalog_id' => null,
                'pending_price_id' => null,
                'pending_price_snapshot' => null,
                'pending_currency_id' => null,
                'pending_currency_code' => null,
                'pending_billing_cycle' => null,
                'pending_effective_at' => null,
            ])->save();
        });
    }

    /**
     * The §3.3 lifecycle, driven entirely through EntitlementManager's
     * existing writers. Each one is idempotent on its own, so this method is
     * safe to run for every delivery of every event.
     */
    private function driveLifecycle(int $workspaceId, PlatformSubscriptionSnapshot $snapshot): string
    {
        $status = $snapshot->status;
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

            $status === PlatformSubscriptionStatus::Trialing
                => $this->convergeProviderTrial($workspace, $snapshot, $reason),

            // Incomplete deliberately writes nothing: §7 forbids fabricating
            // any state from an attempt the provider has not confirmed.
            default => null,
        };

        return self::APPLIED;
    }

    /**
     * §10.4 — a PROVIDER-CONFIRMED TRIAL on a Workspace that already holds a
     * plan assignment.
     *
     * WHY THIS ARM EXISTS AT ALL. It used to do nothing, and for an ordinary
     * first signup that was right: the assignment is created afterwards,
     * carrying the same provider-confirmed trial end, so there was nothing to
     * converge. Re-subscribing broke that assumption. A customer who cancels
     * is LOCKED; if they come back on a plan that carries a trial, the
     * provider confirms `trialing`, the tier converges — and the stale
     * `locked_at` stayed, telling a customer with a valid Stripe trial that
     * their account was locked. The money moved; the access did not.
     *
     * FAIL CLOSED WITHOUT A PROVIDER-CONFIRMED TRIAL END. `trialing` with no
     * usable `trial_end` is a contradiction we cannot resolve, and the
     * tempting resolution — unlock anyway — would hand out access on the
     * strength of a status alone, which is precisely what §7 forbids. So the
     * lock stays, the operator gets a warning, and a later, complete
     * observation converges it. A trial end already in the past is treated the
     * same way: an ended trial is not a trial, and unlocking on one would
     * restore access only for the expiry sweep to take it away again.
     *
     * Nothing here reads the catalog, and nothing here writes the assignment
     * directly — EntitlementManager remains the only lifecycle writer.
     */
    private function convergeProviderTrial(Workspace $workspace, PlatformSubscriptionSnapshot $snapshot, string $reason): void
    {
        $trialEndsAt = $snapshot->trialEndsAt;

        if ($trialEndsAt === null || ! $trialEndsAt->isFuture()) {
            Log::warning('Lane A refused to unlock a trialing subscription with no usable provider trial end', [
                'workspace_id' => $workspace->id,
                'has_trial_end' => $trialEndsAt !== null,
            ]);

            return;
        }

        $this->entitlements->startProviderConfirmedTrial($workspace, $trialEndsAt, null, $reason);
    }

    /**
     * §12.4's fail-closed identity checks. A snapshot that cannot be proven to
     * belong to THIS local row is refused with a reason code — never
     * reconciled by guessing which side is right, and never allowed to write.
     */
    private function crossCheck(PlatformSubscription $subscription, PlatformSubscriptionSnapshot $snapshot): ?string
    {
        // §10.4 — A SUBSCRIPTION THIS ROW HAS FINISHED WITH MAY NEVER SPEAK
        // FOR IT AGAIN.
        //
        // One row per Workspace means a customer who cancels and re-subscribes
        // reuses it. Stripe keeps emitting events for the OLD subscription,
        // and those events still resolve here — through the shared customer
        // id, or through our own `app_operation_id` metadata, both of which
        // legitimately belong to this row. Without this check a late
        // `customer.subscription.deleted` for the previous life would lock the
        // account the customer has just paid to restart. The retired list is
        // what makes the two lives distinguishable.
        if (in_array($snapshot->providerSubscriptionId, $subscription->retiredProviderSubscriptionIds(), true)) {
            return self::SUBSCRIPTION_MISMATCH;
        }

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

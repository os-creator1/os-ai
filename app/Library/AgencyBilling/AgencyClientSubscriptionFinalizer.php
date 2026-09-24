<?php

namespace App\Library\AgencyBilling;

use App\Enums\AgencyBilling\AgencyClientSubscriptionStatus;
use App\Library\Entitlement\EntitlementManager;
use App\Models\AgencyClientSubscription;
use App\Models\AgencySaasPlan;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspacePlanAssignmentRepository;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Lane C §C7 — THE shared idempotent finalizer for lane C.
 *
 * Every path that learns a provider outcome comes through here: the
 * post-checkout return, the verified Connect webhook, the scheduled downgrade
 * sweep, and any future reconciliation. One finalizer is what makes replay
 * safety a property of the system rather than of each caller.
 *
 * IT SUPPLIES REASONS; IT DOES NOT OWN THE LIFECYCLE. The CLIENT Workspace's
 * own `workspace_plan_assignments` row remains the entitlement authority, and
 * `EntitlementManager`'s existing writers remain the only things that touch it.
 * This class adds no status enum, no second grace window and no second
 * authority; it translates provider-confirmed subscription state into calls on
 * writers that already exist:
 *
 *   trialing            -> startProviderConfirmedTrial(): usable, on the trial
 *                          end the PROVIDER confirmed.
 *   active              -> recoverAccess().
 *   past_due / unpaid   -> enterGracePeriod(): ONE 3-day window, already
 *   / paused               idempotent, so repeated failure events cannot
 *                          extend it.
 *   canceled /          -> lockForNonPayment(): end of service, data preserved.
 *   incomplete_expired
 *   incomplete          -> nothing. §C6 forbids fabricating state from an
 *                          attempt the provider has not confirmed.
 *
 * ONE CLIENT ONLY, EVERY TIME. Nothing here reaches another client's
 * assignment, and nothing here touches the AGENCY's own lifecycle or its
 * `platform_subscriptions` row. The Agency's own delinquency composes as an
 * upstream prerequisite inside `CustomerAccountAccessResolver`, which lane C
 * deliberately does not modify.
 *
 * THE CONNECTED ACCOUNT IS PART OF IDENTITY. A snapshot that did not come from
 * this subscription's own Agency account is refused before it can write
 * anything — that, plus the retired-subscription list, is what keeps one
 * Agency's events out of another Agency's records.
 *
 * NO NETWORK HERE, so holding locks is safe.
 */
final class AgencyClientSubscriptionFinalizer
{
    public const APPLIED = 'applied';
    public const IGNORED_OUT_OF_ORDER = 'ignored_out_of_order';
    public const IGNORED_COMPLIMENTARY = 'ignored_complimentary';
    public const ACCOUNT_MISMATCH = 'account_mismatch';
    public const SUBSCRIPTION_MISMATCH = 'subscription_mismatch';
    public const CUSTOMER_MISMATCH = 'customer_mismatch';
    public const OPERATION_ID_MISMATCH = 'operation_id_mismatch';

    /** Dispositions that mean "we could not prove this event is ours". */
    public const IDENTITY_MISMATCHES = [
        self::ACCOUNT_MISMATCH,
        self::SUBSCRIPTION_MISMATCH,
        self::CUSTOMER_MISMATCH,
        self::OPERATION_ID_MISMATCH,
    ];

    public function __construct(
        private readonly EntitlementManager $entitlements,
        private readonly WorkspacePlanAssignmentRepository $assignments,
    ) {
    }

    /**
     * Applies one provider observation to one lane-C subscription.
     *
     * @return string one of the disposition constants above
     */
    public function apply(
        AgencyClientSubscription $subscription,
        AgencySubscriptionSnapshot $snapshot,
        ?string $eventId = null,
        ?CarbonInterface $eventCreatedAt = null,
    ): string {
        $mismatch = $this->crossCheck($subscription, $snapshot);

        if ($mismatch !== null) {
            return $mismatch;
        }

        [$disposition, $clientWorkspaceId] = DB::transaction(function () use ($subscription, $snapshot, $eventId, $eventCreatedAt) {
            $locked = AgencyClientSubscription::query()
                ->whereKey($subscription->id)
                ->lockForUpdate()
                ->firstOrFail();

            // A strictly OLDER event never moves canonical state. Equal
            // timestamps are admitted: Stripe can emit several events in the
            // same second and every writer below is idempotent, so letting
            // them through is safe while dropping them is not.
            if ($eventCreatedAt !== null
                && $locked->last_event_at !== null
                && $eventCreatedAt->lt($locked->last_event_at)
                && (string) $locked->last_event_id !== (string) $eventId) {
                return [self::IGNORED_OUT_OF_ORDER, null];
            }

            $locked->forceFill([
                'provider_subscription_id' => $snapshot->providerSubscriptionId,
                'provider_customer_id' => $snapshot->providerCustomerId !== ''
                    ? $snapshot->providerCustomerId
                    : $locked->provider_customer_id,
                'provider_price_id' => $snapshot->providerPriceId ?? $locked->provider_price_id,
                'connected_account_id' => $snapshot->connectedAccountId,
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

            return [self::APPLIED, (int) $locked->client_workspace_id];
        });

        if ($disposition !== self::APPLIED) {
            return $disposition;
        }

        $subscription = $subscription->refresh();

        // §C7 — finish any durable plan-change operation this observation
        // proves, BEFORE driving the lifecycle: if the tier is moving and
        // access is being restored in the same breath, the client should end up
        // usable on the tier they just bought, not on the old one.
        $this->convergePlanChange($subscription, $snapshot);

        // The client's canonical assignment, created or corrected from
        // provider-confirmed truth.
        $this->ensureEntitlement($subscription->refresh(), $snapshot);

        return $this->driveLifecycle((int) $clientWorkspaceId, $snapshot);
    }

    /**
     * §C6 — the CLIENT's canonical plan assignment, from a provider-confirmed
     * subscription and never from the Agency's intent.
     *
     * A client the Agency provisioned has NO assignment yet: Contract 07's
     * provisioning deliberately creates the Workspace, Business and Primary
     * Location without a plan, exactly as an abandoned signup leaves one. This
     * is where a confirmed lane-C payment gives them one.
     *
     * A client that already has an assignment — because they were a direct
     * customer once, or because a previous lane-C plan was converged — has its
     * tier corrected instead. `changePlan()` is a no-op when the tier already
     * matches, so replay writes nothing.
     */
    private function ensureEntitlement(AgencyClientSubscription $subscription, AgencySubscriptionSnapshot $snapshot): void
    {
        if (! $snapshot->status->grantsAccess()) {
            // Nothing confirmed. §C6's "no fabricated paid state" is enforced
            // here rather than hoped for.
            return;
        }

        $plan = AgencySaasPlan::query()->find($subscription->agency_saas_plan_id);
        $workspace = Workspace::query()->find($subscription->client_workspace_id);

        if ($plan === null || $workspace === null) {
            return;
        }

        $assignment = $this->assignments->findByWorkspaceId((int) $workspace->id);

        if ($assignment !== null && (bool) $assignment->is_complimentary) {
            // §C6.1 — the Platform Owner is deliberately carrying this account.
            // An Agency subscription must never quietly convert it.
            return;
        }

        $providerReference = (string) $snapshot->providerSubscriptionId;

        if ($assignment === null) {
            $this->entitlements->assignFirstPlanFromVerifiedSubscription(
                $workspace,
                $plan->tier,
                (int) $workspace->owner_user_id,
                (string) $subscription->uid,
                $providerReference,
                // The trial the PROVIDER confirmed, not the Agency's catalog.
                $subscription->trial_ends_at,
                'agency_saas_subscription_enrolled',
            );

            return;
        }

        $currentTier = $this->entitlements->getWorkspaceEntitlementSummary($workspace)->tier;

        if ($currentTier === $plan->tier) {
            return;
        }

        $this->entitlements->changePlanFromVerifiedSubscription(
            $workspace,
            $plan->tier,
            (int) $workspace->owner_user_id,
            (string) $subscription->uid,
            $providerReference,
            'agency_saas_subscription_tier_sync',
        );
    }

    /**
     * §C7 — THE CONVERGENCE POINT for a durable plan-change operation.
     *
     * Both routes reach it: the synchronous response to a plan change or the
     * scheduled downgrade sweep, and a later `customer.subscription.updated`.
     * Whichever arrives first finishes the operation; the other finds nothing
     * pending and does nothing.
     *
     *   PROVIDER PRICE CHANGED TO TARGET
     *     → eventually the local commercial snapshot AND the client's canonical
     *       entitlement converge to that same target, exactly once.
     *
     * PROVIDER TRUTH IS THE GATE. If the snapshot does not show the TARGET
     * Price active, the operation stays pending and the entitlement is not
     * widened — an Agency's intent alone never upgrades its client.
     *
     * The order — commercial snapshot, then entitlement, then clear — is
     * deliberate: every step is idempotent, so a crash in the middle is
     * repaired by the next delivery, while clearing first would destroy the
     * record repair depends on.
     */
    private function convergePlanChange(AgencyClientSubscription $subscription, AgencySubscriptionSnapshot $snapshot): void
    {
        if ($subscription->pending_operation_uid === null || blank($subscription->pending_price_id)) {
            return;
        }

        if ($snapshot->providerPriceId === null
            || (string) $snapshot->providerPriceId !== (string) $subscription->pending_price_id) {
            return;
        }

        $target = AgencySaasPlan::query()->find($subscription->pending_plan_id);
        $workspace = Workspace::query()->find($subscription->client_workspace_id);

        if ($target === null || $workspace === null) {
            return;
        }

        // 1. The commercial record: the terms agreed when the change was
        //    REQUESTED, not whatever the Agency's catalog says today.
        DB::transaction(function () use ($subscription): void {
            $locked = AgencyClientSubscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();

            if ($locked->pending_operation_uid === null) {
                return;
            }

            $locked->forceFill([
                'agency_saas_plan_id' => $locked->pending_plan_id,
                'provider_price_id' => $locked->pending_price_id,
                'price_snapshot' => $locked->pending_price_snapshot,
                'currency_id' => $locked->pending_currency_id,
                'currency_code' => $locked->pending_currency_code,
                'billing_cycle_snapshot' => $locked->pending_billing_cycle,
            ])->save();
        });

        // 2. The client's canonical entitlement. `changePlan()` is a no-op when
        //    the assignment already holds the destination tier, so replaying
        //    writes nothing and dispatches nothing. An unassigned Workspace is
        //    not an error here — ensureEntitlement() assigns from the plan this
        //    step has just moved to.
        $assignment = $this->assignments->findByWorkspaceId((int) $workspace->id);

        if ($assignment !== null && ! (bool) $assignment->is_complimentary) {
            $this->entitlements->changePlanFromVerifiedSubscription(
                $workspace,
                $target->tier,
                (int) $workspace->owner_user_id,
                (string) $subscription->uid,
                $snapshot->providerSubscriptionId,
                'agency_saas_subscription_' . ($subscription->pending_kind ?? 'plan_change'),
            );
        }

        // 3. Only now is the operation finished.
        DB::transaction(function () use ($subscription): void {
            AgencyClientSubscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail()->forceFill([
                'pending_operation_uid' => null,
                'pending_kind' => null,
                'pending_plan_id' => null,
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
     * §C7 — the canonical lifecycle, driven entirely through
     * EntitlementManager's existing writers. Each one is idempotent on its own,
     * so this is safe to run for every delivery of every event.
     */
    private function driveLifecycle(int $clientWorkspaceId, AgencySubscriptionSnapshot $snapshot): string
    {
        $workspace = Workspace::query()->find($clientWorkspaceId);

        if ($workspace === null) {
            return self::SUBSCRIPTION_MISMATCH;
        }

        $assignment = $this->assignments->findByWorkspaceId($clientWorkspaceId);

        // No assignment yet (an unconfirmed attempt), so there is no lifecycle
        // to drive.
        if ($assignment === null) {
            return self::APPLIED;
        }

        if ((bool) $assignment->is_complimentary) {
            return self::IGNORED_COMPLIMENTARY;
        }

        $status = $snapshot->status;
        $reason = 'agency_saas_subscription_' . $status->value;

        match (true) {
            $status === AgencyClientSubscriptionStatus::Active
                => $this->entitlements->recoverAccess($workspace, null, $reason),

            $status === AgencyClientSubscriptionStatus::Trialing
                => $this->convergeProviderTrial($workspace, $snapshot, $reason),

            in_array($status, [
                AgencyClientSubscriptionStatus::PastDue,
                AgencyClientSubscriptionStatus::Unpaid,
                AgencyClientSubscriptionStatus::Paused,
            ], true)
                => $this->entitlements->enterGracePeriod($workspace, null, $reason),

            in_array($status, [
                AgencyClientSubscriptionStatus::Canceled,
                AgencyClientSubscriptionStatus::IncompleteExpired,
            ], true)
                => $this->entitlements->lockForNonPayment($workspace, null, $reason),

            // Incomplete, Offered and Pending deliberately write nothing.
            default => null,
        };

        return self::APPLIED;
    }

    /**
     * A provider-confirmed trial on a client that already holds an assignment
     * — the re-subscribe and plan-change cases.
     *
     * FAIL CLOSED WITHOUT A USABLE TRIAL END. `trialing` with no `trial_end`,
     * or one already in the past, is a contradiction we cannot resolve, and the
     * tempting resolution — unlock anyway — would hand out access on the
     * strength of a status alone.
     */
    private function convergeProviderTrial(Workspace $workspace, AgencySubscriptionSnapshot $snapshot, string $reason): void
    {
        $trialEndsAt = $snapshot->trialEndsAt;

        if ($trialEndsAt === null || ! $trialEndsAt->isFuture()) {
            Log::warning('Lane C refused to unlock a trialing subscription with no usable provider trial end', [
                'client_workspace_id' => $workspace->id,
                'has_trial_end' => $trialEndsAt !== null,
            ]);

            return;
        }

        $this->entitlements->startProviderConfirmedTrial($workspace, $trialEndsAt, null, $reason);
    }

    /**
     * §C4.1/§C7's fail-closed identity checks. A snapshot that cannot be proven
     * to belong to THIS subscription is refused with a reason code — never
     * reconciled by guessing which side is right, and never allowed to write.
     */
    private function crossCheck(AgencyClientSubscription $subscription, AgencySubscriptionSnapshot $snapshot): ?string
    {
        // THE ACCOUNT FIRST. Lane C's whole safety story is that a
        // subscription can only ever be moved by its own Agency's connected
        // account; an event from Agency Y must be unable to touch Agency X's
        // client even if every other identifier somehow lined up.
        if ($subscription->connected_account_id !== null
            && (string) $subscription->connected_account_id !== $snapshot->connectedAccountId) {
            return self::ACCOUNT_MISMATCH;
        }

        // A SUBSCRIPTION THIS ROW HAS FINISHED WITH MAY NEVER SPEAK FOR IT
        // AGAIN. One row per client means a client who cancels and
        // re-subscribes reuses it, and Stripe keeps emitting events for the old
        // subscription — which still resolve here through the shared customer
        // id and through our own metadata.
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

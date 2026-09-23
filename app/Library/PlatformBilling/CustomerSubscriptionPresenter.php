<?php

namespace App\Library\PlatformBilling;

use App\Enums\PlatformBilling\PlatformSubscriptionStatus;
use App\Library\Entitlement\CustomerAccountAccessResolver;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Workspace;
use App\Models\WorkspacePlanCatalog;

/**
 * Implementation Contract 21 §13 — what the customer's Plan & subscription page
 * says, derived from the CANONICAL V1 lane-A subscription.
 *
 * IT NEVER READS LEGACY `Plan` OR `Subscription` (§4). The legacy
 * `Customer\SubscriptionController` renders the Ultimate SMS plan model — a
 * different product — and resurrecting it here would put two contradictory
 * answers in front of the same customer.
 *
 * THE PRICE SHOWN IS THE SNAPSHOT, NOT THE CATALOG (§10.1). A subscriber who
 * bought at one amount keeps seeing that amount even after the Platform Owner
 * reprices the tier, because that is what they are actually being charged.
 *
 * LIFECYCLE IS THE CANONICAL AUTHORITY'S, IN CUSTOMER LANGUAGE.
 * `CustomerAccountAccessResolver` already decides Usable / Grace-with-hint /
 * Locked from the plan assignment; this class translates that decision rather
 * than re-deriving it from provider status, so the page can never disagree with
 * the gate that actually controls access.
 */
final class CustomerSubscriptionPresenter
{
    public function __construct(
        private readonly PlatformSubscriptionManager $subscriptions,
        private readonly EntitlementManager $entitlements,
        private readonly CustomerAccountAccessResolver $access,
        private readonly PlatformPlanPresenter $plans,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Workspace $workspace): array
    {
        $subscription = $this->subscriptions->findForWorkspace($workspace);
        $summary = $this->entitlements->getWorkspaceEntitlementSummary($workspace);
        $decision = $this->access->resolve($workspace);
        $catalog = $subscription === null
            ? null
            : WorkspacePlanCatalog::query()->find($subscription->workspace_plan_catalog_id);
        $pending = $subscription?->pending_plan_catalog_id === null
            ? null
            : WorkspacePlanCatalog::query()->find($subscription->pending_plan_catalog_id);

        return [
            'has_subscription' => $subscription !== null
                && $subscription->status !== PlatformSubscriptionStatus::Pending,
            // §11.1 — a complimentary Workspace is never shown as a paid one.
            'is_complimentary' => (bool) ($summary->isComplimentary ?? false),
            'tier' => $summary->tier?->value,
            'tier_name' => $catalog?->display_name ?? $summary->tier?->value,
            'state' => $this->customerState($subscription, $decision->reason),
            'state_reason' => $decision->reason,
            // The SNAPSHOT — what this customer actually pays.
            'price' => $subscription?->price_snapshot,
            'currency_code' => $subscription?->currency_code,
            'billing_cycle' => $subscription?->billing_cycle_snapshot,
            'trial_ends_at' => $subscription?->trial_ends_at,
            'current_period_end' => $subscription?->current_period_end,
            'cancel_at_period_end' => (bool) ($subscription?->cancel_at_period_end ?? false),
            'pending_tier_name' => $pending?->display_name,
            'pending_effective_at' => $subscription?->pending_effective_at,
            'grace_ends_at' => $decision->graceEndsAt ?? null,
            // §10.2 — what the customer may change to, and in which direction.
            'available_plans' => $this->availablePlans($catalog),
            // §10.3 — only offered when the provider model actually supports
            // reversing it, which it does: cancel_at_period_end is a boolean we
            // can set back to false while the period is still running.
            'can_resume' => (bool) ($subscription?->cancel_at_period_end ?? false),
            'can_manage_payment_method' => $subscription !== null && ! blank($subscription->provider_customer_id),
        ];
    }

    /**
     * The customer-facing state word. Derived from the ACCESS decision first,
     * because that is what actually governs their account, and only then from
     * the provider status.
     */
    private function customerState(?\App\Models\PlatformSubscription $subscription, ?string $reason): string
    {
        if ($subscription === null || $subscription->status === PlatformSubscriptionStatus::Pending) {
            return 'none';
        }

        return match (true) {
            $reason === 'plan_locked' => 'locked',
            $reason === 'plan_grace' => 'past_due',
            $reason === 'plan_inactive' => 'inactive',
            $reason === 'plan_suspended' => 'suspended',
            $subscription->status === PlatformSubscriptionStatus::Trialing => 'trialing',
            $subscription->status === PlatformSubscriptionStatus::Canceled => 'ended',
            (bool) $subscription->cancel_at_period_end => 'cancelling',
            default => 'active',
        };
    }

    /**
     * Sellable tiers other than the current one, each labelled with what
     * choosing it would do — §10.2 requires the customer to see the resulting
     * behaviour BEFORE confirming.
     *
     * @return array<int, array<string, mixed>>
     */
    private function availablePlans(?WorkspacePlanCatalog $current): array
    {
        $rank = ['core' => 1, 'growth' => 2, 'agency' => 3];
        $plans = [];

        foreach ($this->plans->sellablePlans() as $plan) {
            if ($current !== null && (int) $plan['id'] === (int) $current->id) {
                continue;
            }

            $isUpgrade = $current === null
                || ($rank[$plan['tier_value']] ?? 0) > ($rank[$current->tier->value] ?? 0);

            $plans[] = [...$plan, 'direction' => $isUpgrade ? 'upgrade' : 'downgrade'];
        }

        return $plans;
    }
}

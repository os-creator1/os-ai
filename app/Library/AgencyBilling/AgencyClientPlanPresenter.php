<?php

namespace App\Library\AgencyBilling;

use App\Enums\AgencyBilling\AgencyClientSubscriptionStatus;
use App\Library\Entitlement\CustomerAccountAccessResolver;
use App\Models\AgencySaasPlan;
use App\Models\Workspace;

/**
 * Lane C §C8 — what the CLIENT sees about the subscription their Agency bills
 * them for.
 *
 * THE CLIENT IS TOLD WHO IS CHARGING THEM. This is the single most important
 * thing on the page: the money goes to their agency, not to us, and a customer
 * who cannot tell the difference cannot dispute the right charge with the right
 * party.
 *
 * THE PRICE SHOWN IS THE SNAPSHOT, NOT THE AGENCY'S CURRENT CATALOG (§C3.4). A
 * client who bought at one amount keeps seeing that amount after their agency
 * reprices the plan, because that is what they are actually being charged.
 *
 * LIFECYCLE IS THE CANONICAL AUTHORITY'S, IN CUSTOMER LANGUAGE.
 * `CustomerAccountAccessResolver` already decides Usable / Grace / Locked — and
 * already composes the managing Agency's own delinquency as an upstream
 * prerequisite (Contract 05). This class translates that decision rather than
 * re-deriving it, so the page can never disagree with the gate that actually
 * controls access, and never claims a client is fine when their agency's own
 * lapse has made them unusable.
 */
final class AgencyClientPlanPresenter
{
    public function __construct(
        private readonly AgencyClientSubscriptionManager $subscriptions,
        private readonly AgencySaasPlanManager $plans,
        private readonly CustomerAccountAccessResolver $access,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Workspace $clientWorkspace): array
    {
        $subscription = $this->subscriptions->findForClientWorkspace($clientWorkspace);
        $decision = $this->access->resolve($clientWorkspace);

        if ($subscription === null) {
            return [
                'has_agency_billing' => false,
                'state' => 'none',
                'state_reason' => $decision->reason,
            ];
        }

        $agencyWorkspace = Workspace::query()->find($subscription->agency_workspace_id);
        $plan = AgencySaasPlan::query()->find($subscription->agency_saas_plan_id);
        $pending = $subscription->pending_plan_id === null
            ? null
            : AgencySaasPlan::query()->find($subscription->pending_plan_id);

        $isOffer = $subscription->isOffer();
        $hasEnded = $subscription->status->isTerminal();

        return [
            'has_agency_billing' => true,
            // Who is charging them. Never ambiguous.
            'agency_name' => $agencyWorkspace?->name,
            'subscription_uid' => $subscription->uid,

            'is_offer' => $isOffer,
            'has_ended' => $hasEnded,
            'state' => $this->customerState($subscription, $decision->reason),
            'state_reason' => $decision->reason,

            'plan_name' => $plan?->name,
            'plan_description' => $plan?->description,
            'tier' => $plan?->tier->value,
            // The SNAPSHOT — what this client actually pays.
            'price' => $subscription->price_snapshot,
            'currency_code' => $subscription->currency_code,
            'billing_cycle' => $subscription->billing_cycle_snapshot,
            'trial_days' => $subscription->trial_days_snapshot,
            'trial_ends_at' => $subscription->trial_ends_at,
            'current_period_end' => $subscription->current_period_end,
            'cancel_at_period_end' => (bool) $subscription->cancel_at_period_end,
            'grace_ends_at' => $decision->graceEndsAt ?? null,

            'pending_plan_name' => $pending?->name,
            'pending_effective_at' => $subscription->pending_effective_at,

            // §C8 — what the client may do, and only what they may actually do.
            'can_consent' => $isOffer,
            'can_change_plan' => $subscription->status->isLive() && ! $hasEnded,
            'can_cancel' => $subscription->status->isLive() && ! (bool) $subscription->cancel_at_period_end,
            'can_resume' => (bool) $subscription->cancel_at_period_end && $subscription->status->isLive(),
            'can_manage_payment_method' => ! blank($subscription->provider_customer_id)
                && ! blank($subscription->connected_account_id),
            'can_resubscribe' => $hasEnded,

            'available_plans' => $agencyWorkspace === null
                ? []
                : $this->offerablePlans($agencyWorkspace, $plan, $subscription->status),
        ];
    }

    /**
     * The customer-facing state word, derived from the ACCESS decision first
     * because that is what actually governs their account, and only then from
     * the provider status.
     */
    private function customerState(\App\Models\AgencyClientSubscription $subscription, ?string $reason): string
    {
        if ($subscription->isOffer()) {
            return 'offered';
        }

        if ($subscription->status === AgencyClientSubscriptionStatus::Pending) {
            return 'awaiting_payment';
        }

        return match (true) {
            // "Ended" outranks "locked": the lock is the CONSEQUENCE of the
            // subscription ending, and telling a client whose subscription is
            // over that their account is merely locked invites them to fix a
            // payment method that has nothing left to pay.
            $subscription->status->isTerminal() => 'ended',
            // The managing agency's own lapse, surfaced honestly rather than
            // blamed on the client's card. Contract 05 gives three distinct
            // agency-caused reasons; all three mean the same thing to this
            // client — nothing on their billing page can fix it.
            in_array($reason, ['agency_locked', 'agency_inactive', 'agency_suspended'], true) => 'agency_unavailable',
            $reason === 'plan_locked' => 'locked',
            $reason === 'plan_grace' => 'past_due',
            $reason === 'plan_inactive' => 'inactive',
            $reason === 'plan_suspended' => 'suspended',
            $subscription->status === AgencyClientSubscriptionStatus::Trialing => 'trialing',
            (bool) $subscription->cancel_at_period_end => 'cancelling',
            default => 'active',
        };
    }

    /**
     * The agency's other published plans, labelled with what choosing one would
     * do — §C7 requires the client to see the resulting behaviour BEFORE
     * confirming.
     *
     * @return array<int, array<string, mixed>>
     */
    private function offerablePlans(Workspace $agencyWorkspace, ?AgencySaasPlan $current, AgencyClientSubscriptionStatus $status): array
    {
        $rank = ['core' => 1, 'growth' => 2, 'agency' => 3];
        $rows = [];

        foreach ($this->plans->sellablePlans($agencyWorkspace) as $plan) {
            if ($current !== null && (int) $plan->id === (int) $current->id && ! $status->isTerminal()) {
                continue;
            }

            $isUpgrade = $current === null
                || ($rank[$plan->tier->value] ?? 0) > ($rank[$current->tier->value] ?? 0);

            $rows[] = [
                'uid' => $plan->uid,
                'name' => $plan->name,
                'description' => $plan->description,
                'tier_value' => $plan->tier->value,
                'price' => $plan->price,
                'currency_code' => $plan->currency_code,
                'billing_cycle' => $plan->billing_cycle,
                'trial_days' => $plan->configuredTrialDays(),
                'direction' => $isUpgrade ? 'upgrade' : 'downgrade',
            ];
        }

        return $rows;
    }
}

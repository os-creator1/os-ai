<?php

namespace App\Library\AgencyBilling;

/**
 * Lane C §C4 — the normalized state of an Agency's connected Stripe account.
 *
 * Nothing Stripe-shaped travels past this: no `Stripe\Account`, no raw payload,
 * no API key. `requirementsDisabledReason` is the provider's own machine code
 * (e.g. `requirements.past_due`), never its prose, so nothing a provider wrote
 * can reach a screen.
 *
 * FAIL-CLOSED PAYMENT-READINESS POLICY — the four `controller*` fields are
 * Stripe's own authoritative record of who pays Stripe's processing fees, who
 * is liable for payment losses/negative balances, who collects onboarding
 * requirements, and what Dashboard access the account has (Stripe API
 * reference: `Account.controller`, and
 * https://docs.stripe.com/connect/migrate-to-controller-properties for the
 * exact mapping this class checks against). `isCompatibleController()` is the
 * single place that decides whether an account may EVER be enabled for Agency
 * customer payments — verified from a real `accounts.retrieve()` response,
 * never assumed from `type` (deprecated) or from how the account was
 * connected (new onboarding vs. existing-account OAuth land here identically).
 * An account connected via OAuth may carry any commercial shape its own
 * platform gave it; this is what stops one that would make Jazmin Media the
 * fee-payer or the loss-bearer from ever becoming chargeable.
 */
final readonly class AgencyAccountSnapshot
{
    /** The exact controller shape objective #4/#5 requires — Stripe's own "Standard account" mapping. */
    private const REQUIRED_FEES_PAYER = 'account';

    private const REQUIRED_LOSSES_PAYER = 'stripe';

    private const REQUIRED_REQUIREMENT_COLLECTION = 'stripe';

    private const REQUIRED_DASHBOARD_TYPE = 'full';

    public function __construct(
        public string $stripeAccountId,
        public bool $chargesEnabled,
        public bool $payoutsEnabled,
        public bool $detailsSubmitted,
        public ?string $requirementsDisabledReason = null,
        public ?string $defaultCurrency = null,
        /** `controller.fees.payer` — must be `account` (the Agency pays Stripe's fees), never `application`/`application_express`/`application_custom` (the platform would). */
        public ?string $controllerFeesPayer = null,
        /** `controller.losses.payments` — must be `stripe` (Stripe bears payment-loss risk), never `application` (the platform would). */
        public ?string $controllerLossesPayer = null,
        /** `controller.requirement_collection` — must be `stripe` (Stripe collects/verifies KYC), never `application`. */
        public ?string $controllerRequirementCollection = null,
        /** `controller.stripe_dashboard.type` — must be `full` (the Agency operates its own payments/refunds), never `express`/`none`. */
        public ?string $controllerDashboardType = null,
    ) {
    }

    /**
     * Fail closed on anything missing, unverifiable, or not exactly the
     * required shape — never a best-effort or partial match. `null` (a
     * provider response with no `controller` object at all, e.g. a
     * pre-controller-era account type this class has never seen) is
     * incompatible, not a pass.
     */
    public function isCompatibleController(): bool
    {
        return $this->controllerFeesPayer === self::REQUIRED_FEES_PAYER
            && $this->controllerLossesPayer === self::REQUIRED_LOSSES_PAYER
            && $this->controllerRequirementCollection === self::REQUIRED_REQUIREMENT_COLLECTION
            && $this->controllerDashboardType === self::REQUIRED_DASHBOARD_TYPE;
    }

    /**
     * A machine code (never provider prose) naming the FIRST mismatched
     * property, for an operator screen — the same
     * `requirements_disabled_reason`-shaped vocabulary the rest of lane C
     * already uses. Null when compatible.
     */
    public function incompatibilityReason(): ?string
    {
        if ($this->isCompatibleController()) {
            return null;
        }

        return match (true) {
            $this->controllerFeesPayer !== self::REQUIRED_FEES_PAYER => 'controller.fees_payer_not_account',
            $this->controllerLossesPayer !== self::REQUIRED_LOSSES_PAYER => 'controller.losses_not_stripe',
            $this->controllerRequirementCollection !== self::REQUIRED_REQUIREMENT_COLLECTION => 'controller.requirement_collection_not_stripe',
            default => 'controller.dashboard_not_full',
        };
    }
}

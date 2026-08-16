<?php

namespace App\Library\Usage;

use Illuminate\Pagination\LengthAwarePaginator;

/**
 * RFC-005 M2 contract §9/§10 — the complete, presentation-only read model
 * for the customer-visible Usage & Billing dashboard. Assembled exclusively
 * by UsageBillingPresenter from repository read calls; Blade performs no
 * balance, cap, headroom, or entitlement recomputation against it.
 *
 * @param  array{name: string, billing_status: string}  $business
 * @param  array{available_balance_micro: string, reserved_balance_micro: string, debt_balance_micro: string, currency_code: string, spend_period_key: string, committed_spend_this_period_micro: string, monthly_spend_cap_micro: ?string, remaining_headroom_micro: ?string}|null  $wallet
 * @param  array<int, array{feature_key: string, monthly_limit_micro: ?string, safety_limit_micro: ?string}>  $featureLimits
 * @param  array{payer_type: string}|null  $payer
 * @param  array{name: ?string, email: ?string, notification_opt_in: bool}|null  $billingContact
 * @param  LengthAwarePaginator<int, UsageLedgerEntryPresentationRow>  $ledger
 */
final readonly class UsageBillingDashboardViewModel
{
    public function __construct(
        public array $business,
        public ?array $wallet,
        public array $featureLimits,
        public ?array $payer,
        public ?array $billingContact,
        public LengthAwarePaginator $ledger,
    ) {
    }
}

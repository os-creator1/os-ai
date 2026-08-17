<?php

namespace App\Library\Usage;

use App\Models\Business;
use App\Models\BusinessFundingAttempt;
use App\Models\BusinessUsageLedgerEntry;
use App\Repositories\Contracts\BusinessBillingContactRepository;
use App\Repositories\Contracts\BusinessFeatureUsageLimitRepository;
use App\Repositories\Contracts\BusinessFundingAttemptRepository;
use App\Repositories\Contracts\BusinessPayerAssignmentRepository;
use App\Repositories\Contracts\BusinessPaymentInstrumentRepository;
use App\Repositories\Contracts\BusinessUsageLedgerEntryRepository;
use App\Repositories\Contracts\BusinessUsageWalletRepository;
use App\Repositories\Contracts\PaymentProviderCustomerRepository;
use App\Repositories\Contracts\PlatformFeatureUsageSafetyLimitRepository;

/**
 * RFC-005 M2 contract §9/§10, extended RFC-005 M3 contract §18.1 (Correction
 * Round 1, item 105) — plain read-assembly service, no write authority, no
 * transaction, no lock. Calls repository read methods only — never a raw
 * DB::table() query — assembling UsageBillingDashboardViewModel for the
 * customer dashboard controller.
 */
class UsageBillingPresenter
{
    public function __construct(
        private readonly BusinessUsageWalletRepository $walletRepository,
        private readonly BusinessFeatureUsageLimitRepository $featureLimitRepository,
        private readonly PlatformFeatureUsageSafetyLimitRepository $safetyLimitRepository,
        private readonly BusinessPayerAssignmentRepository $payerAssignmentRepository,
        private readonly BusinessBillingContactRepository $billingContactRepository,
        private readonly BusinessUsageLedgerEntryRepository $ledgerRepository,
        private readonly PaymentProviderCustomerRepository $providerCustomerRepository,
        private readonly BusinessPaymentInstrumentRepository $paymentInstrumentRepository,
        private readonly BusinessFundingAttemptRepository $fundingAttemptRepository,
    ) {
    }

    public function buildDashboardViewModel(Business $business): UsageBillingDashboardViewModel
    {
        $wallet = $this->walletRepository->findByBusinessId((int) $business->id);

        $walletView = null;

        if ($wallet !== null) {
            $remainingHeadroom = null;

            if ($wallet->monthly_spend_cap_micro !== null) {
                $consumed = bcadd((string) $wallet->committed_spend_this_period_micro, (string) $wallet->reserved_spend_this_period_micro);
                $remaining = bcsub((string) $wallet->monthly_spend_cap_micro, $consumed);
                $remainingHeadroom = bccomp($remaining, '0') < 0 ? '0' : $remaining;
            }

            $walletView = [
                'available_balance_micro' => (string) $wallet->available_balance_micro,
                'reserved_balance_micro' => (string) $wallet->reserved_balance_micro,
                'debt_balance_micro' => (string) $wallet->debt_balance_micro,
                'currency_code' => $wallet->currency?->code ?? '',
                'spend_period_key' => $wallet->spend_period_key,
                'committed_spend_this_period_micro' => (string) $wallet->committed_spend_this_period_micro,
                'monthly_spend_cap_micro' => $wallet->monthly_spend_cap_micro !== null ? (string) $wallet->monthly_spend_cap_micro : null,
                'remaining_headroom_micro' => $remainingHeadroom,
            ];
        }

        $featureLimits = $this->featureLimitRepository->forBusiness((int) $business->id)
            ->map(function ($limit) {
                $safetyLimit = $this->safetyLimitRepository->findByFeatureKey($limit->feature_key);

                return [
                    'feature_key' => $limit->feature_key,
                    'monthly_limit_micro' => $limit->monthly_limit_micro !== null ? (string) $limit->monthly_limit_micro : null,
                    'safety_limit_micro' => $safetyLimit !== null ? (string) $safetyLimit->max_monthly_limit_micro : null,
                ];
            })
            ->all();

        $payerAssignment = $this->payerAssignmentRepository->findByBusinessId((int) $business->id);
        $payerView = $payerAssignment !== null ? ['payer_type' => $payerAssignment->payer_type->value] : null;

        $billingContact = $this->billingContactRepository->findByBusinessId((int) $business->id);
        $billingContactView = null;

        if ($billingContact !== null) {
            $billingContactView = [
                'name' => $billingContact->contact_user_id !== null ? $billingContact->contactUser?->first_name . ' ' . $billingContact->contactUser?->last_name : $billingContact->contact_name,
                'email' => $billingContact->contact_user_id !== null ? $billingContact->contactUser?->email : $billingContact->contact_email,
                'notification_opt_in' => (bool) $billingContact->notification_opt_in,
            ];
        }

        // Uses the shared BaseRepository::query() method (already exposed,
        // unmodified, by every repository including this one) rather than
        // a dedicated new paginateForBusiness() method — the M2 contract
        // is not amended to add an unlisted modified path for this file;
        // query() already fulfills the pagination requirement.
        $ledgerPage = $this->ledgerRepository->query()
            ->where('business_id', (int) $business->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);
        $ledgerPage->setCollection(
            $ledgerPage->getCollection()->map(fn (BusinessUsageLedgerEntry $entry) => new UsageLedgerEntryPresentationRow(
                entryType: $entry->entry_type->value,
                signedAmountMicro: $this->signedAmountFor($entry),
                createdAt: \Carbon\CarbonImmutable::instance($entry->created_at),
                featureKey: $entry->feature_key,
            ))
        );

        $paymentMethodView = $this->resolvePaymentMethod((int) $business->id, $payerAssignment?->payer_type?->value, (int) $business->workspace_id);

        $autoRechargeView = [
            'enabled' => (bool) ($wallet->auto_recharge_enabled ?? false),
            'threshold_micro' => $wallet !== null && $wallet->auto_recharge_threshold_micro !== null ? (string) $wallet->auto_recharge_threshold_micro : null,
            'amount_micro' => $wallet !== null && $wallet->auto_recharge_amount_micro !== null ? (string) $wallet->auto_recharge_amount_micro : null,
            'monthly_cap_micro' => $wallet !== null && $wallet->monthly_recharge_cap_micro !== null ? (string) $wallet->monthly_recharge_cap_micro : null,
            'recharged_this_period_micro' => (string) ($wallet->recharged_this_period_micro ?? '0'),
            'consecutive_recharge_failures' => (int) ($wallet->consecutive_recharge_failures ?? 0),
        ];

        $fundingHistoryPage = $this->fundingAttemptRepository->query()
            ->where('business_id', (int) $business->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);
        $fundingHistoryPage->setCollection(
            $fundingHistoryPage->getCollection()->map(fn (BusinessFundingAttempt $attempt) => [
                'state' => $attempt->state->value,
                'purpose' => $attempt->purpose->value,
                'amount_micro' => (string) $attempt->expected_amount_micro,
                'payment_method_display' => $attempt->payment_method_display_snapshot,
                'created_at' => \Carbon\CarbonImmutable::instance($attempt->created_at),
                'failure_reason' => $attempt->failure_reason,
            ])
        );

        $providerConfigured = filled(config('services.stripe.secret'))
            && filled(config('services.stripe.key'))
            && filled(config('services.stripe.webhook.secret'))
            && in_array(config('services.stripe.mode'), ['test', 'live'], true);

        return new UsageBillingDashboardViewModel(
            business: ['name' => $business->name, 'billing_status' => $wallet?->billing_status->value ?? 'active'],
            wallet: $walletView,
            featureLimits: $featureLimits,
            payer: $payerView,
            billingContact: $billingContactView,
            ledger: $ledgerPage,
            paymentMethod: $paymentMethodView,
            autoRecharge: $autoRechargeView,
            fundingHistory: $fundingHistoryPage,
            providerConfigured: $providerConfigured,
        );
    }

    /**
     * @return array{id: int, brand: ?string, last_four: ?string, expiry_month: ?int, expiry_year: ?int}|null
     */
    private function resolvePaymentMethod(int $businessId, ?string $payerType, int $workspaceId): ?array
    {
        $providerCustomer = match ($payerType) {
            'business' => $this->providerCustomerRepository->findActiveByBusinessId($businessId),
            'workspace' => $this->providerCustomerRepository->findActiveByWorkspaceId($workspaceId),
            default => null,
        };

        if ($providerCustomer === null) {
            return null;
        }

        $instrument = $this->paymentInstrumentRepository->findDefaultForProviderCustomer((int) $providerCustomer->id);

        if ($instrument === null) {
            return null;
        }

        return [
            'id' => (int) $instrument->id,
            'brand' => $instrument->brand,
            'last_four' => $instrument->last_four,
            'expiry_month' => $instrument->expiry_month !== null ? (int) $instrument->expiry_month : null,
            'expiry_year' => $instrument->expiry_year !== null ? (int) $instrument->expiry_year : null,
        ];
    }

    private function signedAmountFor(BusinessUsageLedgerEntry $entry): string
    {
        foreach ([$entry->available_delta_micro, $entry->reserved_delta_micro, $entry->debt_delta_micro] as $delta) {
            if ((int) $delta !== 0) {
                return (string) $delta;
            }
        }

        return '0';
    }
}

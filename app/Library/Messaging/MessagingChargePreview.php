<?php

namespace App\Library\Messaging;

use App\Library\Dashboard\DashboardMoney;
use App\Models\Business;
use App\Repositories\Contracts\BusinessUsageWalletRepository;
use App\Repositories\Contracts\UsageMeterRepository;

/**
 * Read-only "what will this cost" figures shown BEFORE a paid action,
 * read from the same meters/rates UsageWalletManager::reserve() applies.
 * Nothing here is a price of its own: an unconfigured fee reads as null,
 * and the authoritative funding check stays in the wallet reservation.
 * One-time fees (purchase, registration) and recurring ones (monthly
 * rental, monthly campaign) are returned separately so they are never
 * presented as the same thing.
 */
final class MessagingChargePreview
{
    public function __construct(
        private readonly UsageMeterRepository $meters,
        private readonly BusinessUsageWalletRepository $wallets,
    ) {
    }

    /**
     * @return array{micro: int, text: string}|null null when no active, metered rate exists
     */
    public function fee(string $meterKey): ?array
    {
        $meter = $this->meters->findByMeterKey($meterKey);

        if ($meter === null || ! $meter->is_metered || $meter->activeRate === null) {
            return null;
        }

        $rate = $meter->activeRate;

        return [
            'micro' => (int) $rate->retail_rate_micro,
            'text' => DashboardMoney::format((string) $rate->retail_rate_micro, $rate->currency?->code)
                . ($rate->unit_label !== null && $rate->unit_label !== '' ? ' ' . $rate->unit_label : ''),
        ];
    }

    /**
     * The owner's multiplier on a pass-through carrier cost: the meter's
     * retail rate per 1.00 of carrier cost, as a factor (1.0 = zero
     * markup). Null when the fee is not configured.
     */
    public function multiplier(string $meterKey): ?float
    {
        $meter = $this->meters->findByMeterKey($meterKey);

        if ($meter === null || ! $meter->is_metered || $meter->activeRate === null) {
            return null;
        }

        return ((int) $meter->activeRate->retail_rate_micro) / 1_000_000;
    }

    /**
     * Buying a specific number: the real, quoted carrier price (today =
     * upfront + first month, then the monthly rental from next month), with
     * the owner's multiplier applied (zero markup by default).
     *
     * @return array<string, mixed>
     */
    public function forNumberQuote(Business $business, \App\Library\Messaging\DTO\AvailableNumberCandidate $candidate): array
    {
        $purchase = $this->multiplier(TelnyxProvisioningAdapter::FEATURE_NUMBER_PURCHASE);
        $rental = $this->multiplier(NumberLifecycleManager::FEATURE_NUMBER_RENTAL_RENEWAL);
        $wallet = $this->wallets->findByBusinessId((int) $business->id);
        $balanceMicro = $wallet === null ? 0 : (int) $wallet->available_balance_micro;

        $configured = $purchase !== null && $rental !== null && $candidate->hasQuote();
        $todayMicro = $configured ? (int) round($candidate->initialCostMicro() * $purchase) : 0;
        $monthlyMicro = $configured ? (int) round((int) $candidate->monthlyCostMicro * $rental) : 0;

        return [
            'one_time' => $configured ? ['micro' => $todayMicro, 'text' => DashboardMoney::format((string) $todayMicro, 'USD')] : null,
            'recurring' => $configured ? ['micro' => $monthlyMicro, 'text' => DashboardMoney::format((string) $monthlyMicro, 'USD') . ' per month'] : null,
            'configured' => $configured,
            'balance_text' => DashboardMoney::format((string) $balanceMicro, $wallet?->currency?->code),
            'sufficient' => $configured && $balanceMicro >= $todayMicro,
        ];
    }

    /**
     * Buying a number: a one-time purchase charge, then monthly rental.
     *
     * @return array<string, mixed>
     */
    public function forNumberPurchase(Business $business): array
    {
        return $this->build($business, TelnyxProvisioningAdapter::FEATURE_NUMBER_PURCHASE, NumberLifecycleManager::FEATURE_NUMBER_RENTAL_RENEWAL);
    }

    /**
     * Submitting a local registration: a one-time registration charge, then
     * a monthly campaign cost.
     *
     * @return array<string, mixed>
     */
    public function forLocalRegistration(Business $business, ?string $useCase = null): array
    {
        $brand = $this->fee(TelnyxProvisioningAdapter::FEATURE_TEN_DLC_BRAND_REGISTRATION);
        $prepaid = $this->fee(TelnyxProvisioningAdapter::registrationFeatureFor($useCase));
        $recurring = $this->fee(NumberLifecycleManager::campaignRenewalFeatureFor($useCase));
        $wallet = $this->wallets->findByBusinessId((int) $business->id);
        $balanceMicro = $wallet === null ? 0 : (int) $wallet->available_balance_micro;
        $configured = $brand !== null && $prepaid !== null && $recurring !== null;
        $totalMicro = $configured ? $brand['micro'] + $prepaid['micro'] : 0;

        return [
            // Charged when you submit: the carrier brand fee plus the three
            // campaign months the carrier bills upfront. Review charges, if the
            // carrier ever bills one, are billed separately and explicitly.
            'one_time' => $configured ? [
                'micro' => $totalMicro,
                'text' => DashboardMoney::format((string) $totalMicro, 'USD'),
                'detail' => 'carrier brand fee ' . $brand['text'] . ' + first three campaign months ' . $prepaid['text'],
            ] : null,
            'recurring' => $recurring,
            // Disclosure only, never part of the submit-time total or the
            // sufficiency check: the carrier may bill a one-time campaign
            // review, which is charged separately, explicitly and at cost
            // (messaging:charge-carrier-fee) and never silently.
            'possible_review_fee' => ['micro' => ($reviewMicro = max(0, (int) config('messaging.ten_dlc_review_fee_micro', 15000000))), 'text' => DashboardMoney::format((string) $reviewMicro, 'USD')],
            'configured' => $configured,
            'balance_text' => DashboardMoney::format((string) $balanceMicro, $wallet?->currency?->code),
            'sufficient' => $configured && $balanceMicro >= $totalMicro,
        ];
    }

    /** @return array<string, mixed> */
    private function build(Business $business, string $oneTimeKey, string $recurringKey): array
    {
        $oneTime = $this->fee($oneTimeKey);
        $recurring = $this->fee($recurringKey);
        $wallet = $this->wallets->findByBusinessId((int) $business->id);
        $balanceMicro = $wallet === null ? 0 : (int) $wallet->available_balance_micro;

        return [
            'one_time' => $oneTime,
            'recurring' => $recurring,
            // A paid action is only offered when BOTH its one-time and its
            // recurring price are configured, so no recurring carrier cost
            // can ever be left unbilled.
            'configured' => $oneTime !== null && $recurring !== null,
            'balance_text' => DashboardMoney::format((string) $balanceMicro, $wallet?->currency?->code),
            'sufficient' => $oneTime !== null && $recurring !== null && $balanceMicro >= $oneTime['micro'],
        ];
    }
}

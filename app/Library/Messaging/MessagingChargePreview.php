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
    public function forLocalRegistration(Business $business): array
    {
        return $this->build($business, TelnyxProvisioningAdapter::FEATURE_TEN_DLC_REGISTRATION, NumberLifecycleManager::FEATURE_TEN_DLC_CAMPAIGN_RENEWAL);
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

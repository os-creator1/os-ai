<?php

namespace App\Console\Commands;

use App\Enums\Entitlement\PlatformFeature;
use App\Library\Messaging\TelnyxProvisioningAdapter;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Models\Currency;
use App\Models\User;
use App\Repositories\Contracts\BusinessUsageRateRepository;
use App\Repositories\Contracts\BusinessUsageWalletRepository;
use App\Repositories\Contracts\UsageMeterRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The platform owner's one repeatable, auditable way to give a messaging
 * provisioning fee (10DLC registration and campaign renewal, number purchase and
 * rental renewal; toll-free verification is $0 and has no meter) a platform-wide meter and an owner-approved rate, through the
 * same UsageWalletManager rate/metering services every other meter uses.
 *
 * It never invents a price: the retail rate, provider cost, unit label,
 * currency and a human reason are all required arguments; zero (or any
 * non-positive) retail rate is refused; nothing runs on deploy; and
 * --dry-run reports the current state and writes nothing. It never
 * credits, charges or reserves anything for any Business — a customer is
 * still charged only through the normal wallet reservation, and an
 * unfunded wallet still blocks the fee.
 *
 * Rates are integers in micro-units of the currency (1,000,000 = 1.00).
 */
class ActivateMessagingProvisioningRate extends Command
{
    private const FEATURES = [
        '10dlc' => TelnyxProvisioningAdapter::FEATURE_TEN_DLC_REGISTRATION,
        'number-purchase' => TelnyxProvisioningAdapter::FEATURE_NUMBER_PURCHASE,
        'number-rental-renewal' => \App\Library\Messaging\NumberLifecycleManager::FEATURE_NUMBER_RENTAL_RENEWAL,
        'sms-outbound' => \App\Library\Messaging\MessageMeters::SMS_OUTBOUND,
        'sms-inbound' => \App\Library\Messaging\MessageMeters::SMS_INBOUND,
        'mms-outbound' => \App\Library\Messaging\MessageMeters::MMS_OUTBOUND,
        'mms-inbound-local' => \App\Library\Messaging\MessageMeters::MMS_INBOUND_LOCAL,
        'mms-inbound-toll-free' => \App\Library\Messaging\MessageMeters::MMS_INBOUND_TOLL_FREE,
        'carrier-surcharge' => \App\Library\Messaging\MessageMeters::SURCHARGE,
        'carrier-pass-through' => \App\Library\Messaging\ChargeCarrierFee::FEATURE,
        '10dlc-brand' => TelnyxProvisioningAdapter::FEATURE_TEN_DLC_BRAND_REGISTRATION,
        '10dlc-low-volume' => TelnyxProvisioningAdapter::FEATURE_TEN_DLC_LOW_VOLUME_REGISTRATION,
        'campaign-renewal-low-volume' => \App\Library\Messaging\NumberLifecycleManager::FEATURE_TEN_DLC_LOW_VOLUME_CAMPAIGN_RENEWAL,
        'campaign-renewal' => \App\Library\Messaging\NumberLifecycleManager::FEATURE_TEN_DLC_CAMPAIGN_RENEWAL,
    ];

    protected $signature = 'messaging:activate-provisioning-rate
        {feature : sms-outbound | sms-inbound | mms-outbound | mms-inbound-local | mms-inbound-toll-free | carrier-surcharge | carrier-pass-through | 10dlc | 10dlc-brand | 10dlc-low-volume | number-purchase | number-rental-renewal | campaign-renewal | campaign-renewal-low-volume (toll-free verification is $0: no meter)}
        {retail-rate-micro : approved customer price per action, in micro-units (1000000 = 1.00)}
        {provider-cost-micro : approved provider cost per action, in micro-units}
        {unit-label : e.g. "per registration"}
        {currency-code : the platform billing currency, e.g. USD}
        {--actor-user-id= : platform administrator recording the approval}
        {--reason= : who approved this price and where (required to write)}
        {--business= : also diagnose this Business id (read-only)}
        {--dry-run : report current state and what would change; write nothing}
        {--new-version : allow replacing a different, already-active rate}
        {--yes : skip the confirmation prompt}';

    protected $description = 'Provision the platform-wide meter for a messaging provisioning fee and activate an owner-approved rate (never invents a price).';

    public function handle(
        UsageMeterRepository $meters,
        BusinessUsageRateRepository $rates,
        BusinessUsageWalletRepository $wallets,
        UsageWalletManager $walletManager,
    ): int {
        $meterKey = self::FEATURES[(string) $this->argument('feature')] ?? null;

        if ($meterKey === null) {
            $this->error('feature must be one of: ' . implode(', ', array_keys(self::FEATURES)) . '.');

            return self::FAILURE;
        }

        $retail = (string) $this->argument('retail-rate-micro');
        $providerCost = (string) $this->argument('provider-cost-micro');
        $unitLabel = trim((string) $this->argument('unit-label'));

        if (! ctype_digit($retail) || (int) $retail < 1) {
            $this->error('retail-rate-micro must be a positive whole number. A zero-cost or invented rate is refused.');

            return self::FAILURE;
        }

        if (! ctype_digit($providerCost)) {
            $this->error('provider-cost-micro must be a whole number (0 or more).');

            return self::FAILURE;
        }

        // Pass-through fees take a MULTIPLIER on the carrier's real price, never a
        // dollar amount: 1,000,000 is exactly the carrier cost (zero markup). Below it
        // would silently absorb a carrier cost; far above it is almost certainly a
        // dollar amount typed in the wrong field.
        $passThrough = [TelnyxProvisioningAdapter::FEATURE_NUMBER_PURCHASE, \App\Library\Messaging\NumberLifecycleManager::FEATURE_NUMBER_RENTAL_RENEWAL, \App\Library\Messaging\ChargeCarrierFee::FEATURE];

        if (in_array($meterKey, $passThrough, true) && ((int) $retail < 1_000_000 || (int) $retail > 2_000_000)) {
            $this->error("{$meterKey} is a pass-through fee: its retail-rate-micro is a multiplier on the carrier's price between 1000000 (cost, zero markup) and 2000000 (+100%), not a dollar amount.");

            return self::FAILURE;
        }

        // The carrier surcharge is billed at exactly its actual cost: no markup, never a discount.
        if ($meterKey === \App\Library\Messaging\MessageMeters::SURCHARGE && (int) $retail !== 1_000_000) {
            $this->error('carrier-surcharge is billed at exactly the actual Telnyx surcharge: retail-rate-micro must be 1000000.');

            return self::FAILURE;
        }

        if ($unitLabel === '') {
            $this->error('unit-label is required.');

            return self::FAILURE;
        }

        $currency = Currency::query()
            ->whereRaw('UPPER(code) = ?', [strtoupper((string) $this->argument('currency-code'))])
            ->where('status', true)
            ->get();

        if ($currency->count() !== 1) {
            $this->error('currency-code must resolve to exactly one active currency.');

            return self::FAILURE;
        }

        $currencyId = (int) $currency->first()->id;
        $dryRun = (bool) $this->option('dry-run');
        $actorUserId = $this->option('actor-user-id');
        $reason = trim((string) $this->option('reason'));

        if (! $dryRun) {
            if ($actorUserId === null || ! ctype_digit((string) $actorUserId) || ! User::query()->whereKey((int) $actorUserId)->value('is_admin')) {
                $this->error('--actor-user-id is required and must be a platform administrator.');

                return self::FAILURE;
            }

            if ($reason === '') {
                $this->error('--reason is required: record who approved this price and where.');

                return self::FAILURE;
            }
        }

        $existing = $meters->findByMeterKey($meterKey);

        if ($existing !== null && ($existing->feature_key !== PlatformFeature::MessagingTransport->value
            || $existing->business_id !== null
            || (int) $existing->currency_id !== $currencyId)) {
            $this->error("A usage_meters row for '{$meterKey}' exists with a different identity (feature, scope or currency). Refusing to proceed; resolve it manually.");

            return self::FAILURE;
        }

        $activeRate = $existing?->active_rate_id !== null ? $rates->findById((int) $existing->active_rate_id) : null;

        $this->line("Meter key:        {$meterKey}");
        $this->line('Meter:            ' . ($existing === null ? 'does not exist (would be created, platform-wide)' : 'exists #' . $existing->id . ($existing->is_metered ? ', metered' : ', NOT metered')));
        $this->line('Active rate:      ' . ($activeRate === null ? 'none' : "retail {$activeRate->retail_rate_micro} / provider {$activeRate->provider_cost_micro} micro ({$activeRate->unit_label})"));
        $this->line("Requested rate:   retail {$retail} / provider {$providerCost} micro ({$unitLabel}), currency {$currency->first()->code}");

        $withoutWallet = DB::table('businesses')
            ->leftJoin('business_usage_wallets', 'business_usage_wallets.business_id', '=', 'businesses.id')
            ->whereNull('business_usage_wallets.id')->count();
        $otherCurrency = DB::table('business_usage_wallets')->where('currency_id', '!=', $currencyId)->count();
        $this->line("Businesses with no usage wallet: {$withoutWallet} (the next submit creates it at zero balance; or run usage:backfill-wallets)");
        $this->line("Wallets in a different currency than this meter: {$otherCurrency} (those Businesses cannot be charged this fee)");

        if ($this->option('business') !== null) {
            $business = Business::query()->find((int) $this->option('business'));
            $wallet = $business === null ? null : $wallets->findByBusinessId((int) $business->id);

            $this->line('Business #' . $this->option('business') . ': ' . match (true) {
                $business === null => 'does not exist',
                $wallet === null => 'no usage wallet yet',
                default => "wallet currency_id {$wallet->currency_id}"
                    . ((int) $wallet->currency_id === $currencyId ? ' (matches meter)' : ' (DIFFERENT from meter — cannot be charged)')
                    . ", available {$wallet->available_balance_micro} micro, billing_status {$wallet->billing_status->value}",
            });
        }

        if ($activeRate !== null && $existing->is_metered
            && (string) $activeRate->retail_rate_micro === $retail && (string) $activeRate->provider_cost_micro === $providerCost
            && (int) $activeRate->currency_id === $currencyId) {
            $this->info('Already configured with these exact values — nothing to do.');

            return self::SUCCESS;
        }

        if ($activeRate !== null && ! $this->option('new-version')) {
            $this->error('A different rate is already active. Pass --new-version to replace it.');

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->info('Dry run — nothing was written.');

            return self::SUCCESS;
        }

        if (! $this->option('yes') && ! $this->confirm('Activate this approved rate? Customers with funded wallets will be charged it on submission.')) {
            $this->info('Aborted — no change made.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($meters, $existing, $meterKey, $currencyId, $actorUserId, $walletManager, $retail, $providerCost, $unitLabel, $reason): void {
            if ($existing === null) {
                $meters->create([
                    'meter_key' => $meterKey,
                    'feature_key' => PlatformFeature::MessagingTransport->value,
                    'business_id' => null,
                    'currency_id' => $currencyId,
                    'description' => "Messaging provisioning fee ({$meterKey}), platform-wide.",
                    'updated_by_user_id' => (int) $actorUserId,
                ]);
            }

            $walletManager->setActiveRate($meterKey, $retail, $providerCost, $unitLabel, $currencyId, (int) $actorUserId, $reason);
            $walletManager->activateMetering($meterKey, (int) $actorUserId, $reason);
        });

        $this->info("Rate activated and metering enabled for '{$meterKey}'.");

        return self::SUCCESS;
    }
}

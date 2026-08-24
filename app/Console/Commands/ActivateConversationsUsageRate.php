<?php

namespace App\Console\Commands;

use App\Enums\Entitlement\PlatformFeature;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Models\User;
use App\Repositories\Contracts\BusinessUsageWalletRepository;
use App\Repositories\Contracts\UsageMeterRepository;
use Illuminate\Console\Command;

/**
 * RFC-005 Milestone 5 §9.1/§3.14 — the sole human-operable mechanism that
 * provisions the pilot UsageMeter and then activates its rate/metering.
 * There is no {feature} argument: PlatformFeature::Conversations is
 * hard-coded, and the pilot meter_key is computed internally from the
 * locked format, never accepted as free-form operator input.
 *
 * This command never runs automatically on deploy/merge, never seeds a
 * real numeric rate, and never mutates
 * platform_feature_usage_classifications — that legacy table is not the
 * activation authority (§3.6, §5.1).
 */
class ActivateConversationsUsageRate extends Command
{
    protected $signature = 'usage:activate-conversations-rate
        {retail-rate-micro}
        {provider-cost-micro}
        {unit-label}
        {currency-code}
        {--actor-user-id=}
        {--reason=}';

    protected $description = 'Provision (or verify) the RFC-005 Milestone 5 pilot UsageMeter and activate its rate/metering.';

    public function handle(
        UsageMeterRepository $meterRepository,
        BusinessUsageWalletRepository $walletRepository,
        UsageWalletManager $walletManager,
    ): int {
        $actorUserId = $this->option('actor-user-id');

        if ($actorUserId === null || ! ctype_digit((string) $actorUserId)) {
            $this->error('--actor-user-id is required and must be a numeric User id.');

            return self::FAILURE;
        }

        $actorUserId = (int) $actorUserId;

        $isAdmin = (bool) User::query()->whereKey($actorUserId)->value('is_admin');

        if (! $isAdmin) {
            $this->error("User {$actorUserId} is not a platform administrator. Aborting.");

            return self::FAILURE;
        }

        $reason = $this->option('reason') ?? 'RFC-005 Milestone 5 pilot activation.';

        $pilotBusinessId = config('usage_billing.conversations_metering.pilot_business_id');

        if ($pilotBusinessId === null) {
            $this->error('conversations_metering.pilot_business_id is not configured. Aborting.');

            return self::FAILURE;
        }

        $business = Business::query()->find((int) $pilotBusinessId);

        if ($business === null) {
            $this->error("Pilot Business {$pilotBusinessId} does not exist. Aborting.");

            return self::FAILURE;
        }

        $wallet = $walletRepository->findByBusinessId((int) $business->id);

        if ($wallet === null) {
            $this->error("Pilot Business {$pilotBusinessId} has no usage wallet. Aborting.");

            return self::FAILURE;
        }

        $meterKey = 'conversations.pilot.' . $business->id;
        $featureKey = PlatformFeature::Conversations->value;
        $description = "RFC-005 Milestone 5 pilot meter — Conversations plain/unicode SMS, business/country/sending-server pilot tuple.";

        $existing = $meterRepository->findByMeterKey($meterKey);

        if ($existing !== null) {
            $conflicts = $existing->feature_key !== $featureKey
                || (int) $existing->business_id !== (int) $business->id
                || (int) $existing->currency_id !== (int) $wallet->currency_id;

            if ($conflicts) {
                $this->error(
                    "An existing usage_meters row for '{$meterKey}' has a conflicting identity "
                    . "(feature_key/business_id/currency_id). Refusing to proceed."
                );

                return self::FAILURE;
            }

            $this->info("Pilot meter '{$meterKey}' already exists with matching identity — provisioning is a no-op.");
            $meter = $existing;
        } else {
            $meter = $meterRepository->create([
                'meter_key' => $meterKey,
                'feature_key' => $featureKey,
                'business_id' => $business->id,
                'currency_id' => $wallet->currency_id,
                'description' => $description,
                'updated_by_user_id' => $actorUserId,
            ]);

            $this->info("Created pilot meter '{$meterKey}'.");
        }

        $retailRateMicro = $this->argument('retail-rate-micro');
        $providerCostMicro = $this->argument('provider-cost-micro');
        $unitLabel = $this->argument('unit-label');
        $currencyCode = $this->argument('currency-code');

        $this->line("Meter key:      {$meterKey}");
        $this->line("Business id:    {$business->id}");
        $this->line("Currency id:    {$wallet->currency_id}");
        $this->line("Retail rate:    {$retailRateMicro} micro");
        $this->line("Provider cost:  {$providerCostMicro} micro");
        $this->line("Unit label:     {$unitLabel}");
        $this->line("Currency code:  {$currencyCode}");

        if (! $this->confirm('Activate this rate for the pilot meter?')) {
            $this->info('Aborted — no change made.');

            return self::SUCCESS;
        }

        $walletManager->setActiveRate(
            $meterKey,
            (string) $retailRateMicro,
            (string) $providerCostMicro,
            (string) $unitLabel,
            (int) $wallet->currency_id,
            $actorUserId,
            (string) $reason,
        );

        $walletManager->activateMetering($meterKey, $actorUserId, (string) $reason);

        $this->info("Pilot meter '{$meterKey}' rate activated and metering enabled.");

        return self::SUCCESS;
    }
}

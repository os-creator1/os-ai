<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Applies the owner-approved messaging fee schedule from configuration
 * (config/messaging.php 'rates', set via environment variables) to the
 * platform-wide usage meters, once per deploy rather than once per
 * customer. Each fee goes through messaging:activate-provisioning-rate,
 * so every safeguard there applies: a fee with any unset value is skipped
 * (and customers keep seeing "pricing not set up"), a non-positive retail
 * rate is refused, a repeat with unchanged values is a no-op, a changed
 * value becomes a new rate version, and nothing is ever charged here.
 */
class SyncMessagingProvisioningRates extends Command
{
    private const MAP = [
        'number_purchase' => 'number-purchase',
        'number_rental_renewal' => 'number-rental-renewal',
        'carrier_pass_through' => 'carrier-pass-through',
        'sms_outbound' => 'sms-outbound',
        'sms_inbound' => 'sms-inbound',
        'mms_outbound' => 'mms-outbound',
        'mms_inbound_local' => 'mms-inbound-local',
        'mms_inbound_toll_free' => 'mms-inbound-toll-free',
        'carrier_surcharge' => 'carrier-surcharge',
        'ten_dlc_brand_registration' => '10dlc-brand',
        'ten_dlc_registration' => '10dlc',
        'ten_dlc_campaign_renewal' => 'campaign-renewal',
        'ten_dlc_low_volume_registration' => '10dlc-low-volume',
        'ten_dlc_low_volume_campaign_renewal' => 'campaign-renewal-low-volume',
    ];

    protected $signature = 'messaging:sync-provisioning-rates {--dry-run : report what would change; write nothing}';

    protected $description = 'Apply the owner-approved messaging fee schedule from config/messaging.php to the platform usage meters (idempotent).';

    public function handle(): int
    {
        $config = (array) config('messaging.rates');
        $dryRun = (bool) $this->option('dry-run');
        $approvedBy = trim((string) ($config['approved_by'] ?? ''));
        $currency = trim((string) ($config['currency'] ?? ''));
        $actorId = $config['actor_user_id'] ?? null;

        if (! $dryRun) {
            if ($approvedBy === '') {
                $this->error('MESSAGING_RATE_APPROVED_BY is required: record who approved this fee schedule.');

                return self::FAILURE;
            }

            if ($actorId === null || $actorId === '' || ! ctype_digit((string) $actorId) || ! User::query()->whereKey((int) $actorId)->value('is_admin')) {
                $this->error('MESSAGING_RATE_ACTOR_USER_ID must be the id of a platform administrator.');

                return self::FAILURE;
            }
        }

        $status = self::SUCCESS;

        foreach (self::MAP as $key => $feature) {
            $rate = (array) ($config[$key] ?? []);
            $retail = (string) ($rate['retail_micro'] ?? '');
            $cost = (string) ($rate['cost_micro'] ?? '');

            if ($retail === '' || $cost === '' || $currency === '') {
                $this->warn("{$key}: not configured (retail, provider cost and currency are all required) — skipped; customers see 'pricing not set up'.");

                continue;
            }

            $this->line("== {$key}");

            $exit = $this->call('messaging:activate-provisioning-rate', array_filter([
                'feature' => $feature,
                'retail-rate-micro' => $retail,
                'provider-cost-micro' => $cost,
                'unit-label' => (string) ($rate['unit'] ?? 'per action'),
                'currency-code' => $currency,
                '--actor-user-id' => $dryRun ? null : (string) $actorId,
                '--reason' => $dryRun ? null : "Synced from environment configuration; approved by {$approvedBy}.",
                '--new-version' => true,
                '--yes' => true,
                '--dry-run' => $dryRun ?: null,
            ], fn ($v) => $v !== null));

            if ($exit !== self::SUCCESS) {
                $status = self::FAILURE;
            }
        }

        return $status;
    }
}

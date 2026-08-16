<?php

declare(strict_types=1);

namespace App\Library\Usage\Migration;

use App\Enums\Usage\WalletBillingStatus;
use App\Exceptions\Usage\BusinessCurrencyUnresolvableException;
use App\Exceptions\Usage\UsageWalletBackfillIncompleteException;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Query-builder-only, versioned backfill action (M1 contract §9.2) that
 * creates a business_usage_wallets row for every pre-existing Business
 * lacking one. Adapted from WorkspaceEntitlementBackfillV1, extended this
 * round (Correction Round 1) for per-Business currency resolution: a
 * Business whose currency_code cannot be resolved to exactly one active
 * currencies row is skipped (no wallet, no partial state) and its failure
 * recorded — processing continues to the rest of the run rather than
 * aborting, mirroring this exact precedent's own continue-and-report
 * shape.
 *
 * Must never depend on Eloquent models or model events — every read/write
 * goes through DB's query builder.
 */
class UsageWalletBackfillV1
{
    protected const CHUNK_SIZE = 500;

    public function run(): UsageWalletBackfillResult
    {
        $businessesProcessed = 0;
        $walletsCreated = 0;
        $currencyResolutionFailures = [];
        $lastSeenBusinessId = 0;

        while (true) {
            $businessIds = $this->nextBusinessIdPage($lastSeenBusinessId);

            if ($businessIds->isEmpty()) {
                break;
            }

            foreach ($businessIds as $businessId) {
                $outcome = $this->processBusiness((int) $businessId);

                $businessesProcessed++;

                if ($outcome === 'created') {
                    $walletsCreated++;
                } elseif (is_array($outcome)) {
                    $currencyResolutionFailures[] = $outcome;
                }
            }

            $lastSeenBusinessId = (int) $businessIds->last();
        }

        $remainingUnwalletedCount = $this->unwalletedBusinessQuery()->count();

        if ($remainingUnwalletedCount > 0) {
            throw new UsageWalletBackfillIncompleteException($remainingUnwalletedCount, $currencyResolutionFailures);
        }

        return new UsageWalletBackfillResult(
            businessesProcessed: $businessesProcessed,
            walletsCreated: $walletsCreated,
            remainingUnwalletedCount: 0,
            currencyResolutionFailures: $currencyResolutionFailures,
        );
    }

    private function unwalletedBusinessQuery()
    {
        return DB::table('businesses')
            ->leftJoin('business_usage_wallets', 'business_usage_wallets.business_id', '=', 'businesses.id')
            ->whereNull('business_usage_wallets.id');
    }

    private function nextBusinessIdPage(int $afterBusinessId): Collection
    {
        return $this->unwalletedBusinessQuery()
            ->where('businesses.id', '>', $afterBusinessId)
            ->orderBy('businesses.id')
            ->limit(static::CHUNK_SIZE)
            ->pluck('businesses.id');
    }

    /**
     * @return 'created'|'skipped'|array{business_id: int, classification: string}
     */
    protected function processBusiness(int $businessId): string|array
    {
        return DB::transaction(function () use ($businessId) {
            $business = DB::table('businesses')->where('id', $businessId)->first(['id', 'currency_code', 'timezone']);

            if ($business === null) {
                return 'skipped';
            }

            $currencyId = $this->resolveCurrencyId($businessId, (string) $business->currency_code);

            if (is_array($currencyId)) {
                return $currencyId;
            }

            $now = Carbon::now();
            $timezone = $business->timezone !== null && $business->timezone !== ''
                ? $business->timezone
                : config('app.timezone');

            $spend = $this->computePeriodBoundaries($timezone, $now);
            $recharge = $this->computePeriodBoundaries($timezone, $now);

            try {
                DB::table('business_usage_wallets')->insert([
                    'business_id' => $businessId,
                    'currency_id' => $currencyId,
                    'available_balance_micro' => 0,
                    'reserved_balance_micro' => 0,
                    'debt_balance_micro' => 0,
                    'monthly_spend_cap_micro' => null,
                    'spend_period_key' => $spend['key'],
                    'spend_period_start_utc' => $spend['start_utc'],
                    'spend_period_end_utc' => $spend['end_utc'],
                    'auto_recharge_enabled' => false,
                    'auto_recharge_threshold_micro' => null,
                    'auto_recharge_amount_micro' => null,
                    'monthly_recharge_cap_micro' => null,
                    'recharge_period_key' => $recharge['key'],
                    'recharge_period_start_utc' => $recharge['start_utc'],
                    'recharge_period_end_utc' => $recharge['end_utc'],
                    'committed_spend_this_period_micro' => 0,
                    'reserved_spend_this_period_micro' => 0,
                    'recharged_this_period_micro' => 0,
                    'consecutive_recharge_failures' => 0,
                    'low_balance_notified_at' => null,
                    'billing_status' => WalletBillingStatus::Active->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (QueryException $e) {
                if ($this->isDuplicateRace($e)) {
                    return 'skipped';
                }

                throw $e;
            }

            return 'created';
        });
    }

    /**
     * @return int|array{business_id: int, classification: string}
     */
    private function resolveCurrencyId(int $businessId, string $currencyCode): int|array
    {
        $normalized = strtoupper($currencyCode);

        $matches = DB::table('currencies')
            ->whereRaw('UPPER(code) = ?', [$normalized])
            ->where('status', true)
            ->pluck('id');

        if ($matches->count() === 0) {
            return ['business_id' => $businessId, 'classification' => BusinessCurrencyUnresolvableException::CLASSIFICATION_NOT_FOUND];
        }

        if ($matches->count() > 1) {
            return ['business_id' => $businessId, 'classification' => BusinessCurrencyUnresolvableException::CLASSIFICATION_AMBIGUOUS];
        }

        return (int) $matches->first();
    }

    /**
     * @return array{key: string, start_utc: Carbon, end_utc: Carbon}
     */
    private function computePeriodBoundaries(string $timezone, Carbon $now): array
    {
        $local = CarbonImmutable::instance($now)->setTimezone($timezone);
        $periodStartLocal = $local->startOfMonth();
        $periodEndLocal = $periodStartLocal->addMonthNoOverflow();

        return [
            'key' => $periodStartLocal->format('Y-m'),
            'start_utc' => Carbon::instance($periodStartLocal->setTimezone('UTC')),
            'end_utc' => Carbon::instance($periodEndLocal->setTimezone('UTC')),
        ];
    }

    private function isDuplicateRace(QueryException $e): bool
    {
        $driverErrorCode = (int) ($e->errorInfo[1] ?? 0);

        return $driverErrorCode === 1062
            && str_contains($e->getMessage(), 'business_usage_wallets_business_id_unique');
    }
}

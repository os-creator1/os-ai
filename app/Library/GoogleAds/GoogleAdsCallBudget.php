<?php

namespace App\Library\GoogleAds;

use App\Enums\GoogleBusinessProfile\GoogleConnectionProduct;
use App\Exceptions\GoogleAds\GoogleAdsProviderException;
use App\Models\BusinessGoogleConnection;
use App\Models\BusinessGoogleOperation;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Google Ads Module V1 contract §5 / §14 — the per-Business hourly
 * provider-call budget (parallel to GoogleBusinessProfileCallBudget; that
 * class is GBP-scoped and untouched).
 *
 * Counts actual OUTBOUND REQUESTS, not high-level operations: the provider
 * clients call reserve() immediately before every HTTP round trip (one per
 * report PAGE, one per token exchange). Each reservation opens a SHORT
 * transaction, row-locks the Business's connection row, recomputes the
 * rolling one-hour total from business_google_operations.provider_call_count
 * and increments the current operation's counter, so two simultaneous
 * reservations serialise and cannot both take the last unit. The network
 * call happens OUTSIDE that transaction.
 *
 * The hourly total is Business-wide across every Google product on purpose:
 * it is a ceiling on how hard one Business may lean on Google, not a
 * per-product quota.
 *
 * WHEN EXHAUSTED: reserve() throws `budget_exhausted`, which is DEFERRABLE —
 * the ledger records `deferred`, zero provider calls are made, and nothing is
 * stored from any provider. A call made OUTSIDE withinOperation() also
 * throws (fail closed): an unaccounted request never reaches Google.
 *
 * MUST be a container singleton: withinOperation() sets the context on the
 * instance and the separately-resolved provider client reads the same one.
 */
final class GoogleAdsCallBudget
{
    private ?BusinessGoogleConnection $connection = null;

    private ?BusinessGoogleOperation $operation = null;

    public function __construct(private readonly GoogleAdsConfig $config)
    {
    }

    /**
     * Runs $work with a reservation context bound to one operation. Always
     * restores the previous context.
     *
     * @template T
     *
     * @param  callable():T  $work
     * @return T
     */
    public function withinOperation(BusinessGoogleConnection $connection, BusinessGoogleOperation $operation, callable $work): mixed
    {
        $previousConnection = $this->connection;
        $previousOperation = $this->operation;

        $this->connection = $connection;
        $this->operation = $operation;

        try {
            return $work();
        } finally {
            $this->connection = $previousConnection;
            $this->operation = $previousOperation;
        }
    }

    /**
     * Reserves exactly ONE outbound request.
     *
     * @throws GoogleAdsProviderException `budget_exhausted` when the cap is reached or there is no context
     */
    public function reserve(): void
    {
        if ($this->connection === null || $this->operation === null) {
            throw GoogleAdsProviderException::budgetExhausted();
        }

        $connectionId = (int) $this->connection->id;
        $businessId = (int) $this->connection->business_id;
        $operationId = (int) $this->operation->id;
        $budget = $this->budgetPerHour();

        DB::transaction(function () use ($connectionId, $businessId, $operationId, $budget) {
            // Product-scoped like every other access to this table: only a google_ads row.
            DB::table('business_google_connections')
                ->where('id', $connectionId)
                ->where('product', GoogleConnectionProduct::GoogleAds->value)
                ->lockForUpdate()
                ->first();

            $used = (int) DB::table('business_google_operations')
                ->where('business_id', $businessId)
                ->where('created_at', '>=', now()->subHour())
                ->sum('provider_call_count');

            if ($used + 1 > $budget) {
                throw GoogleAdsProviderException::budgetExhausted();
            }

            DB::table('business_google_operations')->where('id', $operationId)->increment('provider_call_count');
        });
    }

    /** Outbound requests this Business has made in the rolling hour. */
    public function usedThisHour(int $businessId): int
    {
        return (int) DB::table('business_google_operations')
            ->where('business_id', $businessId)
            ->where('created_at', '>=', now()->subHour())
            ->sum('provider_call_count');
    }

    public function budgetPerHour(): int
    {
        return $this->config->maxCallsPerBusinessPerHour();
    }

    /** True when the exception is OUR budget refusing, rather than Google. */
    public static function isBudgetRefusal(Throwable $exception): bool
    {
        return $exception instanceof GoogleAdsProviderException
            && $exception->classification === GoogleAdsProviderException::BUDGET_EXHAUSTED;
    }
}

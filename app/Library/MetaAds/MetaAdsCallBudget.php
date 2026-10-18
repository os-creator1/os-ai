<?php

namespace App\Library\MetaAds;

use App\Exceptions\MetaAds\MetaProviderException;
use App\Library\MetaAds\Contracts\MetaAdsCallCounter;
use App\Models\BusinessMetaConnection;
use App\Models\BusinessMetaOperation;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Meta Ads Module V1 contract 24 §6 — the per-Business hourly provider-call
 * budget. Counts actual OUTBOUND REQUESTS: the provider clients call reserve()
 * immediately before every HTTP round trip (one per report page, one per token
 * exchange). Each reservation opens a SHORT transaction, row-locks the
 * Business's Meta connection row, recomputes the rolling one-hour total from
 * business_meta_operations.provider_call_count (Meta rows only — Google calls
 * never count against it) and increments the current operation, so two
 * simultaneous reservations serialise and cannot both take the last unit.
 *
 * WHEN EXHAUSTED: reserve() throws `budget_exhausted`, which is DEFERRABLE —
 * the ledger records `deferred`, zero provider calls are made. A call made
 * OUTSIDE withinOperation() also throws (fail closed): an unaccounted request
 * never reaches Meta.
 *
 * MUST be a container singleton (the context lives on the instance and the
 * separately resolved provider client reads the same one).
 */
final class MetaAdsCallBudget implements MetaAdsCallCounter
{
    private ?BusinessMetaConnection $connection = null;

    private ?BusinessMetaOperation $operation = null;

    public function __construct(private readonly MetaAdsConfig $config)
    {
    }

    /**
     * @template T
     *
     * @param  callable():T  $work
     * @return T
     */
    public function withinOperation(BusinessMetaConnection $connection, BusinessMetaOperation $operation, callable $work): mixed
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
     * @throws MetaProviderException `budget_exhausted` when the cap is reached or there is no context
     */
    public function reserve(): void
    {
        if ($this->connection === null || $this->operation === null) {
            throw MetaProviderException::budgetExhausted();
        }

        $connectionId = (int) $this->connection->id;
        $businessId = (int) $this->connection->business_id;
        $operationId = (int) $this->operation->id;
        $budget = $this->budgetPerHour();

        DB::transaction(function () use ($connectionId, $businessId, $operationId, $budget) {
            DB::table('business_meta_connections')
                ->where('id', $connectionId)
                ->lockForUpdate()
                ->first();

            $used = (int) DB::table('business_meta_operations')
                ->where('business_id', $businessId)
                ->where('created_at', '>=', now()->subHour())
                ->sum('provider_call_count');

            if ($used + 1 > $budget) {
                throw MetaProviderException::budgetExhausted();
            }

            DB::table('business_meta_operations')->where('id', $operationId)->increment('provider_call_count');
        });
    }

    /** Outbound requests this Business has made to Meta in the rolling hour. */
    public function usedThisHour(int $businessId): int
    {
        return (int) DB::table('business_meta_operations')
            ->where('business_id', $businessId)
            ->where('created_at', '>=', now()->subHour())
            ->sum('provider_call_count');
    }

    public function budgetPerHour(): int
    {
        return $this->config->maxCallsPerBusinessPerHour();
    }

    /** True when the exception is OUR budget refusing, rather than Meta. */
    public static function isBudgetRefusal(Throwable $exception): bool
    {
        return $exception instanceof MetaProviderException
            && $exception->classification === MetaProviderException::BUDGET_EXHAUSTED;
    }
}

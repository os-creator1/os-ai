<?php

namespace App\Library\GoogleBusinessProfile;

use App\Exceptions\GoogleBusinessProfile\GoogleBusinessProfileProviderException;
use App\Models\BusinessGoogleConnection;
use App\Models\BusinessGoogleOperation;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * GBP Slice A contract §24.3 — the per-Business provider-call budget
 * (correction pass item 6).
 *
 * Google's 300 QPM quota is per CLOUD PROJECT and shared across every
 * customer, so route throttles (per user, per route) and the project-level
 * circuit breaker (reactive, after the damage) do not bound what a single
 * Business can consume. This does.
 *
 * HOW IT COUNTS: actual OUTBOUND REQUESTS, not high-level operations. One
 * enumeration that refreshes a token and pages through three pages of
 * locations consumes four. The client calls reserve() immediately before
 * every HTTP round trip; the pagination loop calls it once per page.
 *
 * CONCURRENCY: each reservation opens a SHORT transaction, takes a row
 * lock on the Business's connection row, recomputes the rolling one-hour
 * total, and increments the current operation's counter — so two
 * simultaneous reservations serialize on that lock and cannot both consume
 * the last unit. THE NETWORK CALL HAPPENS OUTSIDE THAT TRANSACTION
 * (contract §24.9): reserve() returns before the client dials out.
 *
 * WHEN EXHAUSTED: reserve() throws with the `budget_exhausted`
 * classification, which is DEFERRABLE — the ledger records `deferred`, not
 * `failed`, last_synced_at is left alone, zero provider calls are made,
 * background work exits cleanly, and the manual path shows a plain
 * message. No secret and no provider response is stored.
 *
 * Laravel Cache is deliberately not used (contract §31), and no fourth GBP
 * table is added (contract §12).
 */
final class GoogleBusinessProfileCallBudget
{
    private const DEFAULT_BUDGET = 60;

    private ?BusinessGoogleConnection $connection = null;

    private ?BusinessGoogleOperation $operation = null;

    /**
     * Runs $work with a reservation context bound to one operation. Every
     * reserve() the provider client makes inside $work is charged to that
     * operation and to the connection's Business.
     *
     * The context is always cleared, so a later provider call made outside
     * any operation cannot silently escape accounting — reserve() throws
     * in that case rather than passing.
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
     * Reserves exactly ONE outbound request. Called by the provider client
     * immediately before each HTTP round trip.
     *
     * @throws GoogleBusinessProfileProviderException when the Business has
     *                                                exhausted its hourly budget.
     */
    public function reserve(): void
    {
        if ($this->connection === null || $this->operation === null) {
            // A provider call outside any operation context is a
            // programming error, not a budget event: fail closed rather
            // than let an unaccounted request reach Google.
            throw GoogleBusinessProfileProviderException::budgetExhausted();
        }

        $connectionId = (int) $this->connection->id;
        $businessId = (int) $this->connection->business_id;
        $operationId = (int) $this->operation->id;
        $budget = $this->budgetPerHour();

        DB::transaction(function () use ($connectionId, $businessId, $operationId, $budget) {
            // Serializes concurrent reservations for this Business.
            DB::table('business_google_connections')->where('id', $connectionId)->lockForUpdate()->first();

            $used = (int) DB::table('business_google_operations')
                ->where('business_id', $businessId)
                ->where('created_at', '>=', now()->subHour())
                ->sum('provider_call_count');

            if ($used + 1 > $budget) {
                throw GoogleBusinessProfileProviderException::budgetExhausted();
            }

            DB::table('business_google_operations')->where('id', $operationId)->increment('provider_call_count');
        });
    }

    /**
     * Outbound requests this Business has already made in the rolling
     * hour. Read-only; used by tests and by the settings surface.
     */
    public function usedThisHour(int $businessId): int
    {
        return (int) DB::table('business_google_operations')
            ->where('business_id', $businessId)
            ->where('created_at', '>=', now()->subHour())
            ->sum('provider_call_count');
    }

    /**
     * Validated with the house idiom — an int, or a digit-only string,
     * then a range check. An absent or invalid value falls back to the
     * documented default rather than becoming "unlimited".
     */
    public function budgetPerHour(): int
    {
        $configured = config('google_business_profile.sync.max_calls_per_business_per_hour');

        if (! is_int($configured) && ! (is_string($configured) && ctype_digit($configured))) {
            return self::DEFAULT_BUDGET;
        }

        $value = (int) $configured;

        return $value > 0 ? $value : self::DEFAULT_BUDGET;
    }

    /**
     * True when the exception is this budget refusing, rather than Google.
     */
    public static function isBudgetRefusal(Throwable $exception): bool
    {
        return $exception instanceof GoogleBusinessProfileProviderException
            && $exception->classification === \App\Models\BusinessGoogleOperation::FAILURE_BUDGET_EXHAUSTED;
    }
}

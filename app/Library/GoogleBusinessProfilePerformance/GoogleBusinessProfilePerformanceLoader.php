<?php

namespace App\Library\GoogleBusinessProfilePerformance;

use App\Enums\GoogleBusinessProfile\GoogleOperationType;
use App\Exceptions\GoogleBusinessProfile\GoogleBusinessProfileProviderException;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileCallBudget;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileConnectionManager;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileOperationLedger;
use App\Models\BusinessGoogleConnection;
use App\Models\BusinessGoogleLocation;
use Carbon\CarbonImmutable;

/**
 * One manual "Load Google performance" for one linked location, inside the Business's own connection, call budget and
 * operations ledger. The result lives only in the returned array: nothing Google sent is stored, cached, flashed or logged
 * (the ledger summary names the operation, never a value). Read-only.
 */
final class GoogleBusinessProfilePerformanceLoader
{
    /** The latest complete month is tried first; if Google has nothing for it, the month before. No further. */
    private const KEYWORD_MONTHS_TRIED = 2;

    public function __construct(
        private readonly GoogleBusinessProfilePerformanceReader $reader,
        private readonly GoogleBusinessProfileConnectionManager $connections,
        private readonly GoogleBusinessProfileOperationLedger $ledger,
        private readonly GoogleBusinessProfileCallBudget $budget,
        private readonly GoogleBusinessProfilePerformanceStore $store,
    ) {
    }

    /**
     * @return array{period: array<string, string>, kpis: array<int, array<string, mixed>>, keywords: array{month: ?string, items: array<int, array<string, mixed>>, error: ?string}, fetched_at: CarbonImmutable}
     *
     * @throws GoogleBusinessProfileProviderException when the daily metrics could not be read at all
     */
    public function load(BusinessGoogleLocation $binding, BusinessGoogleConnection $connection, ?int $actorUserId = null, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $periods = GoogleBusinessProfilePerformanceSummary::periods($now);
        $resource = (string) $binding->provider_location_resource_name;

        $operation = $this->ledger->open(
            businessId: (int) $binding->business_id,
            type: GoogleOperationType::MetricsSynced,
            actorUserId: $actorUserId,
            googleLocationId: (int) $binding->id,
            summary: 'Reading Google performance for ' . $resource,
            fingerprintParts: ['performance', $resource, $periods['start']->toDateString(), $periods['end']->toDateString()],
        );

        try {
            $result = $this->budget->withinOperation($connection, $operation, function () use ($connection, $resource, $periods, $now): array {
                $token = $this->connections->accessTokenFor($connection);
                $series = $this->reader->dailyMetrics($token, $resource, $periods['previous_start'], $periods['end']);

                return [
                    'series' => $series,
                    'keywords' => $this->keywords($token, $resource, $now),
                ];
            });
        } catch (GoogleBusinessProfileProviderException $exception) {
            $this->ledger->fail($operation, $exception, 'Reading Google performance');

            // A revoked authorization means nothing kept for this connection should be shown again.
            if ($exception->classification === \App\Models\BusinessGoogleOperation::FAILURE_INVALID_GRANT) {
                $this->store->forget((int) $binding->business_id, (int) $binding->id);
            }

            $this->store->markFailure((int) $binding->business_id, (int) $binding->id, $exception->userMessage());

            throw $exception;
        }

        $this->ledger->succeed($operation, 'Performance read');

        // Only what Google returned is kept (see the Store); totals and comparisons are drawn from it on every view.
        $raw = ['series' => $result['series'], 'keywords' => $result['keywords']];
        $this->store->put((int) $binding->business_id, (int) $binding->id, $now, $raw);

        return $this->present($now, $raw);
    }

    /**
     * The last successful read for this linked location, if it is still inside its 24-hour window. Never calls Google.
     *
     * @return array<string, mixed>|null
     */
    public function cached(BusinessGoogleLocation $binding): ?array
    {
        $stored = $this->store->get((int) $binding->business_id, (int) $binding->id);

        return $stored === null ? null : $this->present($stored['fetched_at'], $stored);
    }

    /** True when this location already holds a result read within the last 20 hours: the daily sweep and its jobs leave it alone. */
    public function isFresh(BusinessGoogleLocation $binding): bool
    {
        $cached = $this->cached($binding);

        return $cached !== null && $cached['fetched_at']->greaterThan(CarbonImmutable::now('UTC')->subHours(20));
    }

    /** @return array{at: CarbonImmutable, message: string}|null */
    public function recentFailure(BusinessGoogleLocation $binding): ?array
    {
        return $this->store->failure((int) $binding->business_id, (int) $binding->id);
    }

    public function forgetBinding(int $businessId, int $bindingId): void
    {
        $this->store->forget($businessId, $bindingId);
    }

    public function forgetBusiness(int $businessId): void
    {
        BusinessGoogleLocation::query()->where('business_id', $businessId)->pluck('id')->each(fn ($id) => $this->store->forget($businessId, (int) $id));
    }

    /**
     * @param  array{series: array, keywords: array}  $raw
     * @return array{period: array<string, string>, kpis: array<int, array<string, mixed>>, keywords: array{month: ?string, items: array<int, array<string, mixed>>, error: ?string}, fetched_at: CarbonImmutable}
     */
    private function present(CarbonImmutable $fetchedAt, array $raw): array
    {
        $periods = GoogleBusinessProfilePerformanceSummary::periods($fetchedAt);
        $keywords = $raw['keywords'];

        return [
            'period' => array_map(fn (CarbonImmutable $date) => $date->toDateString(), $periods),
            'kpis' => GoogleBusinessProfilePerformanceSummary::kpis($raw['series'], $periods),
            'keywords' => [
                'month' => $keywords['month'] ?? null,
                'items' => GoogleBusinessProfilePerformanceSummary::topTerms((array) ($keywords['terms'] ?? [])),
                'error' => $keywords['error'] ?? null,
            ],
            'fetched_at' => $fetchedAt,
        ];
    }

    /**
     * Keyword failures never discard the metrics already read: they are reported on their own.
     *
     * @return array{month: ?string, terms: array<int, array<string, mixed>>, error: ?string}
     */
    private function keywords(string $token, string $resource, CarbonImmutable $now): array
    {
        try {
            for ($i = 1; $i <= self::KEYWORD_MONTHS_TRIED; $i++) {
                $month = $now->startOfMonth()->subMonthsNoOverflow($i);
                $terms = $this->reader->monthlySearchKeywords($token, $resource, $month->year, $month->month);

                if ($terms !== []) {
                    return ['month' => $month->format('Y-m'), 'terms' => $terms, 'error' => null];
                }
            }
        } catch (GoogleBusinessProfileProviderException $exception) {
            return ['month' => null, 'terms' => [], 'error' => $exception->userMessage()];
        }

        return ['month' => null, 'terms' => [], 'error' => null];
    }
}

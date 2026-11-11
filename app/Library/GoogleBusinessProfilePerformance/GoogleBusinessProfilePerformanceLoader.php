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

            throw $exception;
        }

        $this->ledger->succeed($operation, 'Performance read');

        return [
            'period' => array_map(fn (CarbonImmutable $date) => $date->toDateString(), $periods),
            'kpis' => GoogleBusinessProfilePerformanceSummary::kpis($result['series'], $periods),
            'keywords' => $result['keywords'],
            'fetched_at' => $now,
        ];
    }

    /**
     * Keyword failures never discard the metrics already read: they are reported on their own.
     *
     * @return array{month: ?string, items: array<int, array<string, mixed>>, error: ?string}
     */
    private function keywords(string $token, string $resource, CarbonImmutable $now): array
    {
        try {
            for ($i = 1; $i <= self::KEYWORD_MONTHS_TRIED; $i++) {
                $month = $now->startOfMonth()->subMonthsNoOverflow($i);
                $terms = $this->reader->monthlySearchKeywords($token, $resource, $month->year, $month->month);

                if ($terms !== []) {
                    return ['month' => $month->format('Y-m'), 'items' => GoogleBusinessProfilePerformanceSummary::topTerms($terms), 'error' => null];
                }
            }
        } catch (GoogleBusinessProfileProviderException $exception) {
            return ['month' => null, 'items' => [], 'error' => $exception->userMessage()];
        }

        return ['month' => null, 'items' => [], 'error' => null];
    }
}

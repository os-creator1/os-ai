<?php

namespace App\Library\GoogleBusinessProfilePerformance;

use App\Exceptions\GoogleBusinessProfile\GoogleBusinessProfileProviderException;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileCallBudget;
use App\Library\GoogleBusinessProfile\GoogleProviderValueNormalizer as N;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Business Profile Performance API v1 — READ ONLY.
 *
 * Every request is an HTTP GET to the one constant host below, for a resource name validated as `locations/<id>`. Nothing
 * user-supplied, stored or provider-supplied is ever fetched, and no response body or provider message escapes this class:
 * failures become the module's closed GoogleBusinessProfileProviderException classifications. Each request is reserved
 * against the same per-Business call budget as every other Business Profile read.
 *
 * It returns what Google returned and nothing else: a day or month Google did not report is ABSENT from the result, never zero.
 */
final class GoogleBusinessProfilePerformanceReader
{
    private const BASE = 'https://businessprofileperformance.googleapis.com/v1';

    public const IMPRESSION_METRICS = [
        'BUSINESS_IMPRESSIONS_DESKTOP_MAPS',
        'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH',
        'BUSINESS_IMPRESSIONS_MOBILE_MAPS',
        'BUSINESS_IMPRESSIONS_MOBILE_SEARCH',
    ];

    public const ACTION_METRICS = ['WEBSITE_CLICKS', 'CALL_CLICKS', 'BUSINESS_DIRECTION_REQUESTS'];

    private const KEYWORD_PAGE_SIZE = 100;

    private const KEYWORD_MAX_PAGES = 3;

    public function __construct(private readonly GoogleBusinessProfileCallBudget $budget)
    {
    }

    /**
     * @return array<string, array<string, int>> metric => [Y-m-d => value], only for the days Google reported
     */
    public function dailyMetrics(string $accessToken, string $locationResourceName, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $location = $this->location($locationResourceName);

        $pairs = [];
        foreach (array_merge(self::IMPRESSION_METRICS, self::ACTION_METRICS) as $metric) {
            $pairs[] = ['dailyMetrics', $metric];
        }
        foreach (['startDate' => $start, 'endDate' => $end] as $name => $date) {
            $pairs[] = ["dailyRange.{$name}.year", (string) $date->year];
            $pairs[] = ["dailyRange.{$name}.month", (string) $date->month];
            $pairs[] = ["dailyRange.{$name}.day", (string) $date->day];
        }

        $payload = $this->get(self::BASE . '/' . $location . ':fetchMultiDailyMetricsTimeSeries', $pairs, $accessToken);

        $series = [];

        foreach (N::listOf($payload['multiDailyMetricTimeSeries'] ?? null, 20) as $group) {
            foreach (N::listOf(is_array($group) ? ($group['dailyMetricTimeSeries'] ?? null) : null, 20) as $entry) {
                $metric = is_array($entry) ? N::string($entry['dailyMetric'] ?? null, 64) : null;

                if ($metric === null) {
                    continue;
                }

                $series[$metric] ??= [];

                foreach (N::listOf(N::dig($entry, ['timeSeries', 'datedValues']), 400) as $dated) {
                    $date = is_array($dated) ? $this->date($dated['date'] ?? null) : null;

                    if ($date === null) {
                        continue;
                    }

                    // Google omits a zero (proto3 JSON): a reported day with no value is zero; a day it did not report stays absent.
                    $series[$metric][$date] = is_array($dated) ? ($this->count($dated['value'] ?? null) ?? 0) : 0;
                }
            }
        }

        return $series;
    }

    /**
     * Search terms that surfaced the business in one calendar month.
     *
     * @return array<int, array{term: string, value: ?int, threshold: ?int}>  an exact value, or only a "fewer than" threshold
     */
    public function monthlySearchKeywords(string $accessToken, string $locationResourceName, int $year, int $month): array
    {
        $location = $this->location($locationResourceName);
        $terms = [];
        $pageToken = null;

        for ($page = 0; $page < self::KEYWORD_MAX_PAGES; $page++) {
            $pairs = [];
            foreach (['startMonth', 'endMonth'] as $name) {
                $pairs[] = ["monthlyRange.{$name}.year", (string) $year];
                $pairs[] = ["monthlyRange.{$name}.month", (string) $month];
            }
            $pairs[] = ['pageSize', (string) self::KEYWORD_PAGE_SIZE];

            if ($pageToken !== null) {
                $pairs[] = ['pageToken', $pageToken];
            }

            $payload = $this->get(self::BASE . '/' . $location . '/searchkeywords/impressions/monthly', $pairs, $accessToken);

            foreach (N::listOf($payload['searchKeywordsCounts'] ?? null, self::KEYWORD_PAGE_SIZE) as $row) {
                $term = is_array($row) ? N::string($row['searchKeyword'] ?? null, 200) : null;

                if ($term === null) {
                    continue;
                }

                $terms[] = [
                    'term' => $term,
                    'value' => $this->count(N::dig($row, ['insightsValue', 'value'])),
                    'threshold' => $this->count(N::dig($row, ['insightsValue', 'threshold'])),
                ];
            }

            $pageToken = N::string($payload['nextPageToken'] ?? null, 4096);

            if ($pageToken === null) {
                break;
            }
        }

        return array_values(array_filter($terms, fn (array $row) => $row['value'] !== null || $row['threshold'] !== null));
    }

    private function location(string $resourceName): string
    {
        $location = N::locationResourceName($resourceName);

        if ($location === null) {
            throw GoogleBusinessProfileProviderException::unexpectedResponse();
        }

        return $location;
    }

    /** Google sends int64 values as strings. Anything that is not a non-negative whole number is "not reported". */
    private function count(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        return is_string($value) && preg_match('/^\d{1,15}$/', $value) === 1 ? (int) $value : null;
    }

    private function date(mixed $date): ?string
    {
        if (! is_array($date)) {
            return null;
        }

        $y = $date['year'] ?? null;
        $m = $date['month'] ?? null;
        $d = $date['day'] ?? null;

        return is_int($y) && is_int($m) && is_int($d) && checkdate($m, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : null;
    }

    /**
     * The ONLY transport in this class, and it is GET. A repeated parameter (dailyMetrics) is sent as Google expects,
     * `name=a&name=b`, not as PHP array notation.
     *
     * @param  array<int, array{0: string, 1: string}>  $pairs
     * @return array<string, mixed>
     */
    private function get(string $url, array $pairs, string $accessToken): array
    {
        $this->budget->reserve();

        $query = implode('&', array_map(fn (array $pair) => rawurlencode($pair[0]) . '=' . rawurlencode($pair[1]), $pairs));

        try {
            $response = Http::withToken($accessToken)
                ->accept('application/json')
                ->connectTimeout($this->seconds('connect_timeout_seconds', 5))
                ->timeout($this->seconds('request_timeout_seconds', 20))
                ->withOptions(['allow_redirects' => false])
                ->get($url . '?' . $query);
        } catch (ConnectionException) {
            throw GoogleBusinessProfileProviderException::timeout();
        } catch (Throwable) {
            throw GoogleBusinessProfileProviderException::providerUnavailable();
        }

        return $this->decode($this->guard($response));
    }

    private function guard(Response $response): Response
    {
        if ($response->successful()) {
            return $response;
        }

        return match (true) {
            $response->status() === 429 => throw GoogleBusinessProfileProviderException::rateLimited(),
            in_array($response->status(), [401, 403], true) => throw GoogleBusinessProfileProviderException::accessDenied(),
            $response->serverError() => throw GoogleBusinessProfileProviderException::providerUnavailable(),
            default => throw GoogleBusinessProfileProviderException::unexpectedResponse(),
        };
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        try {
            $decoded = $response->json();
        } catch (Throwable) {
            throw GoogleBusinessProfileProviderException::unexpectedResponse();
        }

        if (! is_array($decoded)) {
            throw GoogleBusinessProfileProviderException::unexpectedResponse();
        }

        return $decoded;
    }

    private function seconds(string $key, int $default): int
    {
        $configured = config('google_business_profile.http.' . $key);

        return is_int($configured) && $configured > 0 ? $configured : $default;
    }
}

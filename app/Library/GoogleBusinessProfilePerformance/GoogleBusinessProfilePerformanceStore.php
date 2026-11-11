<?php

namespace App\Library\GoogleBusinessProfilePerformance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * The smallest compliant temporary store for Performance Insights.
 *
 * Google's Business Profile API policy allows storing Content only temporarily (at most 30 calendar days), securely, only to
 * improve the performance of the project, and without manipulating or aggregating it. So this keeps ONLY what Google returned
 * for the last successful read (the daily series and the month's search terms, as parsed numbers and text; the totals and
 * comparisons are computed when the page is drawn, never stored), for at most 24 hours (config
 * google_business_profile.performance.cache_hours, 1..24), encrypted, under a key made of the Business id and the linked
 * location's id. It expires by itself, is deleted when the location is unlinked or the account disconnected or revoked, and is
 * never an archive: each successful read replaces the last.
 *
 * A failed read stores no Google Content at all — only a short back-off marker (a time and a safe message).
 */
final class GoogleBusinessProfilePerformanceStore
{
    /** @return array{fetched_at: CarbonImmutable, series: array<string, array<string, int>>, keywords: array{month: ?string, terms: array<int, array<string, mixed>>, error: ?string}}|null */
    public function get(int $businessId, int $bindingId): ?array
    {
        $ttl = $this->ttlHours();

        if ($ttl === 0) {
            return null;
        }

        try {
            $encrypted = Cache::get($this->key($businessId, $bindingId));
            $payload = is_string($encrypted) ? json_decode(Crypt::decryptString($encrypted), true) : null;
        } catch (Throwable) {
            return null;
        }

        if (! is_array($payload) || ! is_string($payload['fetched_at'] ?? null) || ! is_array($payload['series'] ?? null) || ! is_array($payload['keywords'] ?? null)) {
            return null;
        }

        $fetchedAt = CarbonImmutable::parse($payload['fetched_at']);

        // Defence in depth: the cache entry expires by itself, but nothing older than the window is ever shown.
        if ($fetchedAt->addHours($ttl)->isPast()) {
            $this->forget($businessId, $bindingId);

            return null;
        }

        return ['fetched_at' => $fetchedAt, 'series' => $payload['series'], 'keywords' => $payload['keywords']];
    }

    /** @param array{series: array, keywords: array} $raw */
    public function put(int $businessId, int $bindingId, CarbonImmutable $fetchedAt, array $raw): void
    {
        $ttl = $this->ttlHours();

        if ($ttl === 0) {
            return;
        }

        Cache::put(
            $this->key($businessId, $bindingId),
            Crypt::encryptString((string) json_encode(['fetched_at' => $fetchedAt->toIso8601String(), 'series' => $raw['series'], 'keywords' => $raw['keywords']])),
            now()->addHours($ttl),
        );
        $this->clearFailure($businessId, $bindingId);
    }

    public function forget(int $businessId, int $bindingId): void
    {
        Cache::forget($this->key($businessId, $bindingId));
        $this->clearFailure($businessId, $bindingId);
    }

    /**
     * Deletes everything kept for ONE connection's linked locations (and ONLY that connection's: the ids come from the
     * connection itself, so another Business's entries can never be reached). Idempotent. Call it BEFORE the locations are
     * deleted, because the ids are read from them.
     */
    public function forgetConnection(\App\Models\BusinessGoogleConnection $connection): void
    {
        $connection->locations()->where('business_id', $connection->business_id)->pluck('id')
            ->each(fn ($id) => $this->forget((int) $connection->business_id, (int) $id));
    }

    public function markFailure(int $businessId, int $bindingId, string $message): void
    {
        Cache::put($this->failureKey($businessId, $bindingId), ['at' => now()->toIso8601String(), 'message' => $message], now()->addMinutes($this->backoffMinutes()));
    }

    /** @return array{at: CarbonImmutable, message: string}|null while the back-off after a failed read is running */
    public function failure(int $businessId, int $bindingId): ?array
    {
        $failure = Cache::get($this->failureKey($businessId, $bindingId));

        return is_array($failure) && is_string($failure['at'] ?? null) && is_string($failure['message'] ?? null)
            ? ['at' => CarbonImmutable::parse($failure['at']), 'message' => $failure['message']]
            : null;
    }

    public function clearFailure(int $businessId, int $bindingId): void
    {
        Cache::forget($this->failureKey($businessId, $bindingId));
    }

    public function ttlHours(): int
    {
        $configured = config('google_business_profile.performance.cache_hours');

        if ($configured === 0 || $configured === '0') {
            return 0;
        }

        $hours = is_int($configured) || (is_string($configured) && ctype_digit($configured)) ? (int) $configured : 24;

        return $hours >= 1 && $hours <= 24 ? $hours : 24;
    }

    private function backoffMinutes(): int
    {
        $configured = config('google_business_profile.performance.failure_backoff_minutes');
        $minutes = is_int($configured) || (is_string($configured) && ctype_digit($configured)) ? (int) $configured : 15;

        return $minutes >= 1 && $minutes <= 240 ? $minutes : 15;
    }

    private function key(int $businessId, int $bindingId): string
    {
        return 'gbp-performance:v1:b' . $businessId . ':l' . $bindingId;
    }

    private function failureKey(int $businessId, int $bindingId): string
    {
        return 'gbp-performance-failure:v1:b' . $businessId . ':l' . $bindingId;
    }
}

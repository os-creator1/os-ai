<?php

namespace App\Library\ExternalSite;

use App\Jobs\ExternalSite\CrawlExternalSite;
use App\Models\Business;
use App\Models\ExternalSiteCrawl;
use Illuminate\Database\QueryException;

/**
 * External Website Audit — the ONE place a crawl is requested, cancelled or
 * recovered. The abuse guard: external monitoring must never become an
 * infrastructure or traffic-amplification vector.
 *
 * Request rules:
 *   - only a Business with a website address can be crawled, and only that stored
 *     address — a request never names a URL;
 *   - at most ONE crawl is queued or running per Business, enforced by the
 *     database (a unique `active_lock` column), not just by a check-then-insert
 *     that two simultaneous clicks could both pass: whoever loses the race is
 *     handed the winner's crawl;
 *   - a MANUAL request is throttled (config: manual_throttle_minutes) after the
 *     previous one; scheduled and URL-change crawls are not, but are bounded by
 *     the scheduler cadence and by the one-active-crawl rule;
 *   - a queued crawl nobody picked up, or a running crawl that stopped reporting
 *     progress (a lost worker), is failed after a stale window so it can never
 *     block the Business forever; its lock is released with it.
 */
final class ExternalSiteCrawlManager
{
    public const STATE_QUEUED = 'queued';

    public const STATE_ACTIVE = 'already_running';

    public const STATE_THROTTLED = 'throttled';

    public function __construct(private readonly ExternalSiteConfig $config)
    {
    }

    /**
     * @return array{state: string, crawl: ?ExternalSiteCrawl, retry_after_minutes: ?int}
     *
     * @throws ExternalSiteException when the Business has no external website to crawl
     */
    public function request(Business $business, string $trigger = 'manual'): array
    {
        $url = $this->startUrl($business);

        $this->failStale((int) $business->id);

        $active = $this->active((int) $business->id);

        // A crawl still running for a PREVIOUS address must not block the new one (and must not be mistaken for it):
        // the address changed, so that crawl is cancelled and the new address is queued.
        if ($active !== null && $this->bare($active->host) !== $this->bare((string) parse_url($url, PHP_URL_HOST))) {
            $this->cancel($business);
            $active = null;
        }

        if ($active !== null) {
            return ['state' => self::STATE_ACTIVE, 'crawl' => $active, 'retry_after_minutes' => null];
        }

        if ($trigger === 'manual') {
            $last = ExternalSiteCrawl::query()->where('business_id', $business->id)->latest('id')->first();
            $wait = $this->config->manualThrottleMinutes();

            if ($last !== null && $wait > 0 && $last->created_at !== null && $last->created_at->gt(now()->subMinutes($wait)) && $last->status !== ExternalSiteCrawl::CANCELLED) {
                return ['state' => self::STATE_THROTTLED, 'crawl' => $last, 'retry_after_minutes' => max(1, (int) ceil($last->created_at->copy()->addMinutes($wait)->diffInSeconds(now(), true) / 60))];
            }
        }

        try {
            $crawl = ExternalSiteCrawl::query()->create([
                'business_id' => $business->id,
                'start_url' => $url,
                'host' => strtolower((string) parse_url($url, PHP_URL_HOST)),
                'status' => ExternalSiteCrawl::QUEUED,
                'stage' => 'queued',
                'trigger' => in_array($trigger, ['manual', 'scheduled', 'url_change'], true) ? $trigger : 'manual',
                'page_budget' => $this->config->maxPages(),
                'active_lock' => $business->id,
                'heartbeat_at' => now(),
            ]);
        } catch (QueryException $e) {
            // Lost the race: another request inserted the active crawl between our check and our insert.
            $winner = $this->active((int) $business->id);

            if ($winner !== null) {
                return ['state' => self::STATE_ACTIVE, 'crawl' => $winner, 'retry_after_minutes' => null];
            }

            throw $e;
        }

        CrawlExternalSite::dispatch((int) $crawl->id)->afterCommit();

        return ['state' => self::STATE_QUEUED, 'crawl' => $crawl, 'retry_after_minutes' => null];
    }

    /**
     * Stops the active crawl, if any. The runner notices between URLs and discards the partial result; the lock is
     * released immediately so a new check can be started.
     */
    public function cancel(Business $business): bool
    {
        return ExternalSiteCrawl::query()
            ->where('business_id', $business->id)
            ->whereIn('status', [ExternalSiteCrawl::QUEUED, ExternalSiteCrawl::RUNNING])
            ->update(['status' => ExternalSiteCrawl::CANCELLED, 'stage' => null, 'finish_reason' => 'cancelled', 'active_lock' => null, 'finished_at' => now()]) > 0;
    }

    /** The Business's stored website address, normalised, or a refusal. */
    public function startUrl(Business $business): string
    {
        $raw = trim((string) $business->website_url);

        if ($raw === '') {
            throw new ExternalSiteException('no_website_url');
        }

        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $raw) !== 1) {
            $raw = 'https://'.$raw;
        }

        return UrlResolver::normalize($raw) ?? throw new ExternalSiteException('url_malformed');
    }

    private function bare(?string $host): string
    {
        $host = strtolower(trim((string) $host));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    public function active(int $businessId): ?ExternalSiteCrawl
    {
        return ExternalSiteCrawl::query()->where('business_id', $businessId)->whereIn('status', [ExternalSiteCrawl::QUEUED, ExternalSiteCrawl::RUNNING])->latest('id')->first();
    }

    /**
     * Fails a crawl whose worker is gone. Queued: never picked up within the queued window. Running: no heartbeat
     * (every URL refreshes it) within the running window.
     */
    public function failStale(int $businessId): int
    {
        $fail = ['status' => ExternalSiteCrawl::FAILED, 'stage' => null, 'failure_code' => 'stale', 'active_lock' => null, 'finished_at' => now()];

        $queued = ExternalSiteCrawl::query()
            ->where('business_id', $businessId)
            ->where('status', ExternalSiteCrawl::QUEUED)
            ->where('created_at', '<', now()->subMinutes($this->config->staleQueuedMinutes()))
            ->update($fail);

        $running = ExternalSiteCrawl::query()
            ->where('business_id', $businessId)
            ->where('status', ExternalSiteCrawl::RUNNING)
            ->whereRaw('COALESCE(heartbeat_at, started_at, created_at) < ?', [now()->subMinutes($this->config->staleRunningMinutes())])
            ->update($fail);

        return $queued + $running;
    }
}

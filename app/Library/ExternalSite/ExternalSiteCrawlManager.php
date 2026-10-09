<?php

namespace App\Library\ExternalSite;

use App\Jobs\ExternalSite\CrawlExternalSite;
use App\Models\Business;
use App\Models\ExternalSiteCrawl;

/**
 * External Website Audit Mode V1 — the ONE place a crawl is requested.
 *
 * Request rules (the abuse guard: external monitoring must never become an
 * infrastructure or traffic-amplification vector):
 *   - only a Business whose website mode is `external` and that has a website
 *     address can be crawled, and only that stored address — a request never
 *     names a URL;
 *   - at most one crawl is queued or running per Business: asking again while one
 *     is active returns it;
 *   - a MANUAL request is throttled (config: manual_throttle_minutes) after the
 *     previous one; scheduled and URL-change crawls are not, but are bounded by
 *     the scheduler cadence and by the one-active-crawl rule;
 *   - a crawl stuck queued or running longer than STALE_MINUTES (a lost worker) is
 *     failed so it can never block the Business forever.
 */
final class ExternalSiteCrawlManager
{
    public const STATE_QUEUED = 'queued';

    public const STATE_ACTIVE = 'already_running';

    public const STATE_THROTTLED = 'throttled';

    private const STALE_MINUTES = 30;

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

        $active = ExternalSiteCrawl::query()->where('business_id', $business->id)->whereIn('status', [ExternalSiteCrawl::QUEUED, ExternalSiteCrawl::RUNNING])->latest('id')->first();

        if ($active !== null) {
            return ['state' => self::STATE_ACTIVE, 'crawl' => $active, 'retry_after_minutes' => null];
        }

        if ($trigger === 'manual') {
            $last = ExternalSiteCrawl::query()->where('business_id', $business->id)->latest('id')->first();
            $wait = $this->config->manualThrottleMinutes();

            if ($last !== null && $wait > 0 && $last->created_at !== null && $last->created_at->gt(now()->subMinutes($wait))) {
                return ['state' => self::STATE_THROTTLED, 'crawl' => $last, 'retry_after_minutes' => max(1, (int) ceil($last->created_at->copy()->addMinutes($wait)->diffInSeconds(now(), true) / 60))];
            }
        }

        $crawl = ExternalSiteCrawl::query()->create([
            'business_id' => $business->id,
            'start_url' => $url,
            'host' => strtolower((string) parse_url($url, PHP_URL_HOST)),
            'status' => ExternalSiteCrawl::QUEUED,
            'trigger' => in_array($trigger, ['manual', 'scheduled', 'url_change'], true) ? $trigger : 'manual',
        ]);

        CrawlExternalSite::dispatch((int) $crawl->id)->afterCommit();

        return ['state' => self::STATE_QUEUED, 'crawl' => $crawl, 'retry_after_minutes' => null];
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

    private function failStale(int $businessId): void
    {
        ExternalSiteCrawl::query()
            ->where('business_id', $businessId)
            ->whereIn('status', [ExternalSiteCrawl::QUEUED, ExternalSiteCrawl::RUNNING])
            ->where('created_at', '<', now()->subMinutes(self::STALE_MINUTES))
            ->update(['status' => ExternalSiteCrawl::FAILED, 'failure_code' => 'stale', 'finished_at' => now()]);
    }
}

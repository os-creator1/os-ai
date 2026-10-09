<?php

namespace App\Library\ExternalSite;

/**
 * Typed reader of config/external_site_audit.php. Constructible from an array
 * so the security and limit tests can pin tiny limits without touching config.
 */
final class ExternalSiteConfig
{
    /** @param array<string, mixed> $values */
    public function __construct(private readonly array $values)
    {
    }

    public static function fromConfig(): self
    {
        return new self((array) config('external_site_audit', []));
    }

    public function driver(): string
    {
        return (string) ($this->values['driver'] ?? 'http');
    }

    public function userAgent(): string
    {
        return (string) ($this->values['user_agent'] ?? 'MotionGroveSiteAudit/1.0');
    }

    public function robotsToken(): string
    {
        return (string) ($this->values['robots_token'] ?? 'MotionGroveSiteAudit');
    }

    public function maxPages(): int
    {
        return max(1, (int) ($this->values['max_pages'] ?? 40));
    }

    public function maxResponseBytes(): int
    {
        return max(1024, (int) ($this->values['max_response_bytes'] ?? 1_500_000));
    }

    public function connectTimeout(): int
    {
        return max(1, (int) ($this->values['connect_timeout'] ?? 5));
    }

    public function requestTimeout(): int
    {
        return max(1, (int) ($this->values['request_timeout'] ?? 10));
    }

    public function maxRedirects(): int
    {
        return max(0, (int) ($this->values['max_redirects'] ?? 5));
    }

    public function maxTotalSeconds(): int
    {
        return max(1, (int) ($this->values['max_total_seconds'] ?? 120));
    }

    public function requestDelayMs(): int
    {
        return max(0, (int) ($this->values['request_delay_ms'] ?? 250));
    }

    public function maxSitemapFetches(): int
    {
        return max(0, (int) ($this->values['max_sitemap_fetches'] ?? 3));
    }

    public function maxSitemapBytes(): int
    {
        return max(1024, (int) ($this->values['max_sitemap_bytes'] ?? 2_000_000));
    }

    public function maxSitemapUrls(): int
    {
        return max(0, (int) ($this->values['max_sitemap_urls'] ?? 200));
    }

    /** @return list<int> */
    public function allowedPorts(): array
    {
        return array_values(array_map('intval', (array) ($this->values['allowed_ports'] ?? [80, 443])));
    }

    public function recrawlIntervalDays(): int
    {
        return max(1, (int) ($this->values['recrawl_interval_days'] ?? 7));
    }

    public function manualThrottleMinutes(): int
    {
        return max(0, (int) ($this->values['manual_throttle_minutes'] ?? 15));
    }

    public function retainedCrawls(): int
    {
        return max(1, (int) ($this->values['retained_crawls'] ?? 5));
    }
}

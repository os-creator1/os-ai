<?php

namespace Tests\Support\ExternalSite;

use App\Library\ExternalSite\Audit\ExternalAuditContext;
use App\Library\ExternalSite\Audit\ExternalAuditEvaluator;
use App\Library\ExternalSite\Audit\ExternalAuditResult;
use App\Library\ExternalSite\CrawlOutcome;
use App\Library\ExternalSite\ExternalSiteConfig;
use App\Library\ExternalSite\ExternalSiteCrawler;
use App\Library\ExternalSite\Fixtures\FixtureExternalSiteTransport;
use App\Library\ExternalSite\Fixtures\FixtureHostResolver;
use App\Library\ExternalSite\HtmlFactExtractor;
use App\Library\ExternalSite\SafeFetcher;
use App\Library\ExternalSite\UrlGuard;

/**
 * Crawls and audits a FakeSite without a database: the crawler and the pure
 * evaluator wired by hand over an in-memory internet.
 */
trait AuditsFakeSites
{
    protected FixtureExternalSiteTransport $transport;

    protected FixtureHostResolver $resolver;

    protected function bootFakeInternet(): void
    {
        $this->transport = new FixtureExternalSiteTransport();
        $this->resolver = new FixtureHostResolver();
    }

    protected function fakeSite(string $host = 'bizfixture.example'): FakeSite
    {
        return new FakeSite($this->transport, $this->resolver, $host);
    }

    /** @param array<string, mixed> $config */
    protected function crawlerWith(array $config = []): ExternalSiteCrawler
    {
        $config = new ExternalSiteConfig($config + ['request_delay_ms' => 0, 'max_pages' => 40, 'max_response_bytes' => 1_500_000, 'max_redirects' => 5, 'max_total_seconds' => 120, 'allowed_ports' => [80, 443], 'max_image_checks' => 25]);
        $guard = new UrlGuard($this->resolver, $config);

        return new ExternalSiteCrawler(new SafeFetcher($guard, $this->transport, $config), $guard, new HtmlFactExtractor(), $config);
    }

    /** @param array<string, mixed> $config */
    protected function crawlSite(FakeSite $site, array $config = [], string $path = '/', array $terms = ['Austin']): CrawlOutcome
    {
        return $this->crawlerWith($config)->crawl($site->url($path), null, null, $terms);
    }

    protected function auditOutcome(CrawlOutcome $outcome, ?ExternalAuditContext $context = null): ExternalAuditResult
    {
        return (new ExternalAuditEvaluator())->evaluate($outcome, $context ?? new ExternalAuditContext(businessName: 'Bizfixture Studio', phoneDigits: '5125550142', cities: ['Austin'], hasLocation: true));
    }

    /** @return list<string> rule keys, in order */
    protected function ruleKeys(ExternalAuditResult $result): array
    {
        return array_map(fn ($f) => $f->ruleKey, $result->findings);
    }

    /** @return list<\App\Library\ExternalSite\Audit\ExternalFindingDraft> */
    protected function findingsFor(ExternalAuditResult $result, string $rule): array
    {
        return array_values(array_filter($result->findings, fn ($f) => $f->ruleKey === $rule));
    }
}

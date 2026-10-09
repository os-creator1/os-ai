<?php

namespace Tests\Unit\ExternalSite;

use App\Library\ExternalSite\CrawlOutcome;
use App\Library\ExternalSite\ExternalSiteConfig;
use App\Library\ExternalSite\ExternalSiteCrawler;
use App\Library\ExternalSite\Fixtures\FixtureExternalSiteTransport;
use App\Library\ExternalSite\Fixtures\FixtureHostResolver;
use App\Library\ExternalSite\Fixtures\FixtureSite;
use App\Library\ExternalSite\HtmlFactExtractor;
use App\Library\ExternalSite\SafeFetcher;
use App\Library\ExternalSite\UrlGuard;
use Tests\TestCase;

/**
 * The fetch and crawl layers over an in-memory internet: redirect revalidation,
 * the destination pin, foreign-domain refusal, every bound (pages, bytes,
 * redirects, timeouts, content types), robots.txt, sitemap discovery and link
 * health. Nothing here touches a network.
 */
class SafeFetcherAndCrawlerTest extends TestCase
{
    private FixtureExternalSiteTransport $transport;

    private FixtureHostResolver $resolver;

    /** @param array<string, mixed> $config */
    private function crawler(array $config = []): ExternalSiteCrawler
    {
        $config = new ExternalSiteConfig($config + ['request_delay_ms' => 0, 'max_pages' => 40, 'max_response_bytes' => 1_500_000, 'max_redirects' => 5, 'max_total_seconds' => 120, 'allowed_ports' => [80, 443]]);
        $guard = new UrlGuard($this->resolver, $config);

        return new ExternalSiteCrawler(new SafeFetcher($guard, $this->transport, $config), $guard, new HtmlFactExtractor(), $config);
    }

    /** @param array<string, mixed> $config */
    private function fetcher(array $config = []): SafeFetcher
    {
        $config = new ExternalSiteConfig($config + ['max_redirects' => 5, 'max_response_bytes' => 1_500_000, 'allowed_ports' => [80, 443]]);

        return new SafeFetcher(new UrlGuard($this->resolver, $config), $this->transport, $config);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new FixtureExternalSiteTransport();
        $this->resolver = new FixtureHostResolver();
    }

    // ----- fetcher: redirects and the destination pin ----------------------------------

    public function test_a_redirect_from_a_public_looking_url_to_a_private_destination_is_refused_and_never_contacted(): void
    {
        $this->resolver->set('evil.example', ['93.184.216.34']);
        $this->transport->set('https://evil.example/', ['status' => 302, 'headers' => ['location' => 'http://127.0.0.1/admin']]);

        $result = $this->fetcher()->fetch('https://evil.example/', ['evil.example']);

        $this->assertSame('ip_literal_not_allowed', $result->error);
        $this->assertSame(['https://evil.example/'], $this->transport->requestedUrls(), 'The private target was never requested.');
    }

    public function test_a_redirect_to_a_hostname_that_resolves_privately_is_refused_at_the_hop(): void
    {
        $this->resolver->set('evil.example', ['93.184.216.34'])->set('internal-looking.example', ['10.0.0.7']);
        $this->transport->set('https://evil.example/', ['status' => 301, 'headers' => ['location' => 'https://internal-looking.example/secret']]);

        $result = $this->fetcher()->fetch('https://evil.example/', ['evil.example', 'internal-looking.example']);

        $this->assertSame('private_destination', $result->error);
        $this->assertCount(1, $this->transport->requests);
    }

    public function test_a_redirect_to_the_metadata_endpoint_by_name_or_address_is_refused(): void
    {
        $this->resolver->set('evil.example', ['93.184.216.34']);

        foreach (['http://169.254.169.254/latest/meta-data/', 'http://metadata.google.internal/', 'http://[::ffff:169.254.169.254]/', 'http://2852039166/'] as $location) {
            $this->transport->set('https://evil.example/', ['status' => 302, 'headers' => ['location' => $location]]);

            $this->assertNotNull($this->fetcher()->fetch('https://evil.example/', ['evil.example'])->error, $location);
        }

        $this->assertCount(4, $this->transport->requests, 'Only the starting URL was ever requested.');
    }

    public function test_a_redirect_to_a_scheme_that_is_not_http_is_refused(): void
    {
        $this->resolver->set('evil.example', ['93.184.216.34']);
        $this->transport->set('https://evil.example/', ['status' => 302, 'headers' => ['location' => 'file:///etc/passwd']]);

        $this->assertSame('url_malformed', $this->fetcher()->fetch('https://evil.example/', ['evil.example'])->error);
    }

    public function test_the_connection_is_pinned_to_the_address_that_was_validated_even_if_dns_later_changes(): void
    {
        $this->resolver->set('rebind.example', ['93.184.216.34']);
        $this->transport->set('https://rebind.example/', ['status' => 200, 'headers' => ['content-type' => 'text/html'], 'body' => '<html></html>']);

        // The guard resolves once, validates, and hands the transport that very address.
        $this->fetcher()->fetch('https://rebind.example/', ['rebind.example']);
        $this->resolver->set('rebind.example', ['127.0.0.1']);

        $this->assertSame('93.184.216.34', $this->transport->requests[0]['ip']);

        // A later fetch re-validates from scratch and is now refused: the changed answer is never connected to.
        $second = $this->fetcher()->fetch('https://rebind.example/', ['rebind.example']);
        $this->assertSame('private_destination', $second->error);
        $this->assertCount(1, $this->transport->requests);
    }

    public function test_a_redirect_to_a_foreign_domain_is_not_followed(): void
    {
        $this->resolver->set('shop.example', ['93.184.216.34'])->set('elsewhere.example', ['93.184.216.35']);
        $this->transport->set('https://shop.example/', ['status' => 301, 'headers' => ['location' => 'https://elsewhere.example/']]);

        $result = $this->fetcher()->fetch('https://shop.example/', ['shop.example']);

        $this->assertSame('foreign_domain', $result->error);
        $this->assertSame(['https://shop.example/'], $this->transport->requestedUrls());
    }

    public function test_the_redirect_limit_is_enforced(): void
    {
        $this->resolver->set('loop.example', ['93.184.216.34']);
        $this->transport->set('https://loop.example/a', ['status' => 302, 'headers' => ['location' => '/b']]);
        $this->transport->set('https://loop.example/b', ['status' => 302, 'headers' => ['location' => '/a']]);

        $result = $this->fetcher(['max_redirects' => 3])->fetch('https://loop.example/a', ['loop.example']);

        $this->assertSame('too_many_redirects', $result->error);
        $this->assertCount(4, $this->transport->requests);
    }

    public function test_the_response_byte_limit_is_enforced(): void
    {
        $this->resolver->set('big.example', ['93.184.216.34']);
        $this->transport->set('https://big.example/', ['status' => 200, 'headers' => ['content-type' => 'text/html'], 'body' => str_repeat('a', 5000)]);

        $result = $this->fetcher(['max_response_bytes' => 1024])->fetch('https://big.example/', ['big.example']);

        $this->assertSame('too_large', $result->error);
        $this->assertSame('', $result->body, 'No oversized body is ever handed on.');
    }

    public function test_a_response_that_is_not_html_is_refused(): void
    {
        $this->resolver->set('files.example', ['93.184.216.34']);
        $this->transport->set('https://files.example/', ['status' => 200, 'headers' => ['content-type' => 'application/zip'], 'body' => 'PK']);

        $this->assertSame('content_type_not_allowed', $this->fetcher()->fetch('https://files.example/', ['files.example'])->error);
    }

    public function test_a_timeout_is_reported_as_a_reason_code_without_any_remote_text(): void
    {
        $this->resolver->set('slow.example', ['93.184.216.34']);
        $this->transport->set('https://slow.example/', ['status' => 0, 'error' => 'timeout']);

        $result = $this->fetcher()->fetch('https://slow.example/', ['slow.example']);

        $this->assertSame('timeout', $result->error);
        $this->assertSame('', $result->body);
    }

    // ----- crawler ---------------------------------------------------------------------

    private function crawlFixture(array $config = []): CrawlOutcome
    {
        return $this->crawler($config)->crawl(FixtureSite::BASE.'/');
    }

    /** @return array<string, \App\Library\ExternalSite\CrawledPage> keyed by path */
    private function byPath(CrawlOutcome $outcome): array
    {
        $out = [];

        foreach ($outcome->pages as $page) {
            $out[(string) parse_url($page->url, PHP_URL_PATH)] = $page;
        }

        return $out;
    }

    public function test_the_crawl_discovers_pages_from_links_and_the_sitemap_and_records_their_facts(): void
    {
        $outcome = $this->crawlFixture();
        $pages = $this->byPath($outcome);

        $this->assertSame(CrawlOutcome::INDEXABLE, $outcome->indexability);
        $this->assertEqualsCanonicalizing(['/', '/classes', '/about', '/birthday', '/contact', '/extra', '/old-page'], array_keys($pages));
        $this->assertSame('Clay Kids Studio | Ceramics classes for children', $pages['/']->facts->title);
        $this->assertSame(1, $pages['/']->facts->h1Count);
        $this->assertTrue($pages['/']->facts->hasJsonLd);
        $this->assertTrue($pages['/']->facts->hasOpenGraph);
        $this->assertSame(0, $pages['/about']->facts->h1Count);
        $this->assertTrue($pages['/birthday']->facts->noindex);
        $this->assertSame(2, $pages['/birthday']->facts->h1Count);
        $this->assertSame(1, $pages['/classes']->facts->imagesMissingAlt);
        $this->assertSame(404, $pages['/old-page']->status);
        $this->assertSame(1, $pages['/classes']->brokenLinks, 'The link to the 404 page is counted against the page that carries it.');
    }

    public function test_robots_txt_is_obeyed_and_foreign_links_and_files_are_never_fetched(): void
    {
        $this->crawlFixture();

        $urls = $this->transport->requestedUrls();

        $this->assertNotContains(FixtureSite::BASE.'/private/secret', $urls, 'Disallowed by robots.txt.');
        $this->assertNotContains('https://facebook.com/clay-kids', $urls, 'A foreign domain is never contacted.');
        $this->assertNotContains('https://other-domain.example/not-ours', $urls, 'A foreign sitemap entry is ignored.');
        $this->assertContains(FixtureSite::BASE.'/extra', $urls, 'A page only the sitemap lists is found.');
        $repeats = array_keys(array_filter(array_count_values($urls), fn (int $n): bool => $n > 1));
        $this->assertSame([FixtureSite::BASE.'/'], $repeats, 'Only the redirect target of /home is requested twice (as a hop); no page is crawled twice.');
    }

    public function test_every_request_carries_a_validated_public_address(): void
    {
        $this->crawlFixture();

        foreach ($this->transport->requests as $request) {
            $this->assertSame(FixtureSite::PUBLIC_IP, $request['ip']);
            $this->assertSame(FixtureSite::HOST, $request['host']);
        }
    }

    public function test_the_page_limit_bounds_the_crawl(): void
    {
        $outcome = $this->crawlFixture(['max_pages' => 3]);

        $this->assertCount(3, $outcome->pages);
        $this->assertTrue($outcome->truncated);
    }

    public function test_the_time_budget_bounds_the_crawl(): void
    {
        $outcome = $this->crawlFixture(['max_total_seconds' => 1, 'request_delay_ms' => 1200]);

        $this->assertLessThan(7, count($outcome->pages));
        $this->assertTrue($outcome->truncated);
    }

    public function test_a_start_page_disallowed_by_robots_is_reported_not_crawled(): void
    {
        $this->resolver->set('closed.example', ['93.184.216.34']);
        $this->transport->set('https://closed.example/robots.txt', ['status' => 200, 'headers' => ['content-type' => 'text/plain'], 'body' => "User-agent: *\nDisallow: /\n"]);

        $outcome = $this->crawler()->crawl('https://closed.example/');

        $this->assertSame(CrawlOutcome::BLOCKED_BY_ROBOTS, $outcome->indexability);
        $this->assertSame([], $outcome->pages);
        $this->assertSame(['https://closed.example/robots.txt'], $this->transport->requestedUrls());
    }

    public function test_a_robots_group_for_our_own_token_beats_the_wildcard(): void
    {
        $this->resolver->set('picky.example', ['93.184.216.34']);
        $this->transport->set('https://picky.example/robots.txt', ['status' => 200, 'headers' => ['content-type' => 'text/plain'], 'body' => "User-agent: *\nAllow: /\n\nUser-agent: MotionGroveSiteAudit\nDisallow: /\n"]);

        $this->assertSame(CrawlOutcome::BLOCKED_BY_ROBOTS, $this->crawler(['robots_token' => 'MotionGroveSiteAudit'])->crawl('https://picky.example/')->indexability);
    }

    public function test_a_refused_start_url_fails_with_a_reason_and_makes_no_request(): void
    {
        foreach (['http://localhost/', 'http://127.0.0.1/', 'http://[::1]/', 'http://10.0.0.1/', 'file:///etc/passwd', 'ftp://studio-fixture.example/'] as $url) {
            $outcome = $this->crawler()->crawl($url);

            $this->assertNotNull($outcome->failureCode, $url);
            $this->assertSame([], $outcome->pages);
        }

        $this->assertSame([], $this->transport->requests);
    }

    public function test_a_start_url_that_redirects_to_a_private_address_yields_an_unreachable_site_and_no_internal_request(): void
    {
        $this->resolver->set('trap.example', ['93.184.216.34']);
        $this->transport->set('https://trap.example/robots.txt', ['status' => 404]);
        $this->transport->set('https://trap.example/sitemap.xml', ['status' => 404]);
        $this->transport->set('https://trap.example/', ['status' => 302, 'headers' => ['location' => 'http://169.254.169.254/latest/']]);

        $outcome = $this->crawler()->crawl('https://trap.example/');

        $this->assertSame(CrawlOutcome::UNREACHABLE, $outcome->indexability);
        $this->assertSame('ip_literal_not_allowed', $outcome->pages[0]->error);
        $this->assertNotContains('http://169.254.169.254/latest/', $this->transport->requestedUrls());
    }

    public function test_www_and_bare_host_are_the_same_site_but_a_sibling_domain_is_not(): void
    {
        $this->resolver->set('twin.example', ['93.184.216.34'])->set('www.twin.example', ['93.184.216.34'])->set('twin-other.example', ['93.184.216.34']);
        $html = fn (string $body) => ['status' => 200, 'headers' => ['content-type' => 'text/html'], 'body' => '<html><head><title>T</title></head><body>'.$body.'</body></html>'];
        $this->transport->set('https://twin.example/robots.txt', ['status' => 404]);
        $this->transport->set('https://twin.example/sitemap.xml', ['status' => 404]);
        $this->transport->set('https://twin.example/', $html('<a href="https://www.twin.example/two">www</a><a href="https://twin-other.example/x">sibling</a>'));
        $this->transport->set('https://www.twin.example/two', $html('page two'));

        $this->crawler()->crawl('https://twin.example/');

        $urls = $this->transport->requestedUrls();
        $this->assertContains('https://www.twin.example/two', $urls);
        $this->assertNotContains('https://twin-other.example/x', $urls);
    }

    public function test_extracted_values_are_bounded_text_and_no_html_leaves_the_extractor(): void
    {
        $html = '<html><head><title>'.str_repeat('T', 900).'</title><meta name="description" content="'.str_repeat('d', 2000).'"></head><body><script>alert(1)</script><h1>Hi</h1></body></html>';
        $facts = (new HtmlFactExtractor())->extract($html, 'https://x.example/');

        $this->assertSame(512, mb_strlen((string) $facts->title));
        $this->assertSame(1000, mb_strlen((string) $facts->metaDescription));
        $this->assertSame(1, $facts->h1Count);

        foreach (get_object_vars($facts) as $value) {
            $this->assertTrue(is_scalar($value) || $value === null || is_array($value), 'ExtractedPage holds plain values only.');
        }
    }
}

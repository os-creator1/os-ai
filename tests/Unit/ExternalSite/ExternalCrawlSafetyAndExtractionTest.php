<?php

namespace Tests\Unit\ExternalSite;

use App\Library\ExternalSite\BotProtectionDetector;
use App\Library\ExternalSite\CrawledPage;
use App\Library\ExternalSite\FetchResult;
use App\Library\ExternalSite\Fixtures\FixtureHostResolver;
use App\Library\ExternalSite\HostResolver;
use App\Library\ExternalSite\HtmlFactExtractor;
use App\Library\ExternalSite\StructuredDataInspector;
use App\Library\ExternalSite\UrlClassifier;
use App\Library\ExternalSite\UrlResolver;
use Tests\Support\ExternalSite\AuditsFakeSites;
use Tests\TestCase;

/**
 * The seams the audit's honesty rests on: which addresses count as pages, what the
 * extractor reads per page, how a firewall is told from a broken page, and that every
 * request is validated against private destinations — including after DNS changes.
 */
class ExternalCrawlSafetyAndExtractionTest extends TestCase
{
    use AuditsFakeSites;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootFakeInternet();
    }

    // ---------------------------------------------------------------- what is a page

    public function test_classification_keeps_technical_and_private_addresses_out_of_the_audit(): void
    {
        $page = fn (string $url, bool $sitemap = false) => UrlClassifier::classify($url, $sitemap)['class'];

        $this->assertSame('page', $page('https://x.example/'));
        $this->assertSame('page', $page('https://x.example/services/wedding-photography'));
        $this->assertSame('page', $page('https://x.example/feedback'), '"/feed" must not swallow "/feedback".');
        $this->assertSame('page', $page('https://x.example/accounting-services'), '"/account" must not swallow "/accounting-services".');
        $this->assertSame('page', $page('https://x.example/blog?page=2', true), 'A parameter address the owner put in the sitemap is trusted.');

        $this->assertSame('utility_endpoint', $page('https://x.example/cdn-cgi/l/email-protection'));
        $this->assertSame('utility_endpoint', $page('https://x.example/wp-json/wp/v2/pages'));
        $this->assertSame('utility_endpoint', $page('https://x.example/feed/'));
        $this->assertSame('non_html_asset', $page('https://x.example/brochure.PDF'));
        $this->assertSame('non_html_asset', $page('https://x.example/assets/app.js'));
        $this->assertSame('private_area', $page('https://x.example/wp-admin/options.php'));
        $this->assertSame('private_area', $page('https://x.example/my-account/orders'));
        $this->assertSame('private_area', $page('https://x.example/checkout'));
        $this->assertSame('query_variant', $page('https://x.example/shop?color=red&sort=price'));
    }

    public function test_equivalent_addresses_share_one_page_key(): void
    {
        $key = UrlResolver::pageKey('https://www.Example.com/About/?utm_source=ad&fbclid=1#team');

        $this->assertSame('example.com/About', $key);
        $this->assertSame($key, UrlResolver::pageKey('http://example.com/About'));
        $this->assertSame('example.com/', UrlResolver::pageKey('https://example.com'));
        $this->assertNotSame(UrlResolver::pageKey('https://example.com/about'), $key, 'Path case is kept: the server may treat them as different pages.');
        $this->assertSame('example.com/shop?color=red&size=l', UrlResolver::pageKey('https://example.com/shop/?size=l&color=red'));
        $this->assertNotSame(UrlResolver::pageKey('https://example.com/shop?page=2'), UrlResolver::pageKey('https://example.com/shop?page=3'));
    }

    public function test_the_same_page_reached_by_several_addresses_is_audited_and_counted_once(): void
    {
        $site = $this->fakeSite()->healthy();
        $nav = [['/', 'Home'], ['/about', 'About us'], ['/about/', 'About again'], ['/about?utm_source=x', 'About tracking'], ['/services', 'Our services'], ['/contact', 'Contact us'], ['/?ref=nav', 'Home again']];
        $site->page('/', ['title' => 'Bizfixture Studio | Photography in Austin', 'links' => $nav, 'jsonld' => json_encode(['@type' => 'LocalBusiness', 'name' => 'B', 'address' => 'x']), 'body' => '<a href="tel:5125550142">(512) 555-0142</a> Austin']);
        $site->page('/about/', ['title' => 'About Bizfixture Studio | Our Austin team']);

        $outcome = $this->crawlSite($site);
        $audited = collect($outcome->audited())->map(fn ($p) => UrlResolver::pageKey($p->url));

        $this->assertSame($audited->unique()->count(), $audited->count(), 'No page is audited twice.');
        $this->assertCount(4, $outcome->audited());
        $aboutRequests = array_filter($this->transport->requestedUrls(), fn ($u) => str_contains($u, '/about'));
        $this->assertCount(1, $aboutRequests, 'Equivalent addresses are fetched once.');
    }

    // ---------------------------------------------------------------- what the extractor reads, per page

    public function test_the_extractor_reports_each_pages_own_headings_images_and_anchors(): void
    {
        $html = '<html lang="en"><head><title> Hello   world </title><meta name="description" content="A description"><meta name="viewport" content="width=device-width">
            <link rel="canonical" href="/here"><meta property="og:title" content="t"><base href="https://x.example/sub/"></head><body>
            <h1>One</h1><h1>Two</h1><h1>  </h1><h2>Sub</h2><h4>Deep</h4>
            <a href="a">Text link</a><a href="b"><img src="i.png" alt="Logo alt"></a><a href="c"></a><a href="#frag">frag</a><a href="mailto:a@b.co">mail</a><a href="tel:+1 (512) 555-0142">call</a>
            <img src="/p.png"><img src="/q.png" alt=""><img src="/r.png" alt="Real"><img src="/s.png" role="presentation"><img src="/t.png" width="1" height="1">
            <script src="http://cdn.example/x.js"></script></body></html>';

        $page = (new HtmlFactExtractor())->extract($html, 'https://x.example/sub/page', ['Austin']);

        $this->assertSame('Hello world', $page->title);
        $this->assertSame(2, $page->h1Count, 'Two non-empty H1 elements on THIS page; the empty one is not counted.');
        $this->assertSame(1, $page->emptyHeadings);
        $this->assertSame(['One', 'Two'], $page->h1Texts);
        $this->assertSame(['https://x.example/here'], $page->canonicalAll);
        $this->assertTrue($page->hasViewport);
        $this->assertSame('en', $page->lang);
        $this->assertSame(['https://x.example/sub/a', 'https://x.example/sub/b', 'https://x.example/sub/c'], $page->links, '<base href> is honoured; fragments and mailto/tel are not page links.');
        $anchors = array_column($page->linkDetails, 'anchor', 'url');
        $this->assertSame('Text link', $anchors['https://x.example/sub/a']);
        $this->assertSame('Logo alt', $anchors['https://x.example/sub/b'], 'An image link is described by its alt text.');
        $this->assertSame('', $anchors['https://x.example/sub/c']);
        $this->assertTrue($page->hasTelLink);
        $this->assertTrue($page->hasMailtoLink);
        $this->assertSame(['5125550142'], $page->phones);
        $this->assertSame(5, $page->imageCount, 'The 1x1 tracking pixel is not counted.');
        $this->assertSame(1, $page->imagesMissingAlt, 'Only the image with NO alt and not marked presentational is missing one; alt="" is decorative.');
        $this->assertSame(['http://cdn.example/x.js'], $page->insecureResources === [] ? [] : array_values($page->insecureResources), 'An https page referencing http:// is flagged.');
        $this->assertSame([2 => 'Sub'], array_filter(array_map(fn ($h) => $h['level'] === 2 ? $h['text'] : null, $page->headings)));
    }

    public function test_structured_data_is_parsed_with_documented_required_properties_only(): void
    {
        $valid = StructuredDataInspector::inspect('{"@context":"https://schema.org","@graph":[{"@type":"LocalBusiness","name":"X","address":"1 Main"},{"@type":"BreadcrumbList"}]}');
        $this->assertTrue($valid['valid']);
        $this->assertContains('LocalBusiness', $valid['types']);
        $this->assertSame(['BreadcrumbList.itemListElement'], $valid['missing']);

        $broken = StructuredDataInspector::inspect('{"@type": "LocalBusiness",}');
        $this->assertFalse($broken['valid']);
        $this->assertSame('invalid_json', $broken['error']);

        $unknown = StructuredDataInspector::inspect('{"@type":"Organization"}');
        $this->assertSame([], $unknown['missing'], 'No requirement is invented for a type with none documented here.');
    }

    // ---------------------------------------------------------------- a firewall is not a broken page

    public function test_bot_protection_is_told_apart_from_ordinary_answers(): void
    {
        $result = fn (int $status, string $body = '', array $headers = []) => new FetchResult('u', 'u', $status, 'text/html', $body, null, 0, null, [], $headers);

        $this->assertSame('rate_limited', BotProtectionDetector::assess($result(429)));
        $this->assertSame('forbidden', BotProtectionDetector::assess($result(403, 'Forbidden')));
        $this->assertSame('challenge_page', BotProtectionDetector::assess($result(403, '<title>Just a moment...</title> cf-chl')));
        $this->assertSame('challenge_page', BotProtectionDetector::assess($result(200, '<html><title>Just a moment...</title>Checking your browser before accessing</html>')));
        $this->assertSame('challenge_page', BotProtectionDetector::assess($result(403, 'x', ['cf-mitigated' => 'challenge'])));
        $this->assertSame('rate_limited', BotProtectionDetector::assess($result(503, 'busy', ['retry-after' => '30'])));

        $this->assertNull(BotProtectionDetector::assess($result(404, '<html>not found</html>')));
        $this->assertNull(BotProtectionDetector::assess($result(500, 'boom')));
        $this->assertNull(BotProtectionDetector::assess($result(200, '<html><script src="/cdn-cgi/challenge-platform/scripts/jsd/main.js"></script><h1>A normal page</h1></html>')), 'Cloudflare injects that script into ordinary pages; it is not a challenge.');
        $this->assertNull(BotProtectionDetector::assess($result(503, 'Service unavailable')), 'A plain 503 without a Retry-After or a challenge is a server error, not a refusal.');
    }

    // ---------------------------------------------------------------- SSRF, redirects and DNS changes

    public function test_a_dns_answer_that_changes_to_a_private_address_mid_crawl_is_refused_before_any_request(): void
    {
        $flipping = new class implements HostResolver {
            public int $lookups = 0;

            public function resolve(string $host): array
            {
                $this->lookups++;

                // Public for the first lookups (robots.txt and the home page), private afterwards: DNS rebinding.
                return $this->lookups <= 3 ? ['93.184.216.34'] : ['10.0.0.5'];
            }
        };
        $this->resolver = new FixtureHostResolver();
        $site = $this->fakeSite()->healthy();
        $config = new \App\Library\ExternalSite\ExternalSiteConfig(['request_delay_ms' => 0, 'max_pages' => 40, 'allowed_ports' => [80, 443], 'max_image_checks' => 0]);
        $guard = new \App\Library\ExternalSite\UrlGuard($flipping, $config);
        $crawler = new \App\Library\ExternalSite\ExternalSiteCrawler(new \App\Library\ExternalSite\SafeFetcher($guard, $this->transport, $config), $guard, new HtmlFactExtractor(), $config);

        $outcome = $crawler->crawl($site->url('/'));

        $requested = $this->transport->requests;
        $this->assertNotEmpty($requested);

        foreach ($requested as $request) {
            $this->assertSame('93.184.216.34', $request['ip'], 'Nothing was ever requested at the rebound private address.');
        }

        $this->assertSame([], array_filter($outcome->pages, fn (CrawledPage $p) => $p->kind === 'html' && $p->url !== $site->url('/')), 'After the DNS answer turned private, no further page was fetched.');
        $this->assertContains('private_destination', array_map(fn ($p) => $p->reason, $outcome->pages));
    }

    public function test_a_redirect_found_while_crawling_to_a_private_address_is_never_followed(): void
    {
        $site = $this->fakeSite()->healthy();
        $nav = [['/', 'Home'], ['/about', 'About us'], ['/services', 'Our services'], ['/contact', 'Contact us'], ['/go', 'Go']];
        $site->page('/', ['title' => 'Bizfixture Studio | Photography in Austin', 'links' => $nav, 'jsonld' => json_encode(['@type' => 'LocalBusiness', 'name' => 'B', 'address' => 'x']), 'body' => '<a href="tel:5125550142">(512) 555-0142</a> Austin']);
        $site->redirect('/go', 'http://169.254.169.254/latest/meta-data/');

        $outcome = $this->crawlSite($site);

        $this->assertNotContains('http://169.254.169.254/latest/meta-data/', $this->transport->requestedUrls());
        $go = collect($outcome->pages)->first(fn ($p) => str_ends_with($p->url, '/go'));
        $this->assertContains($go->kind, ['unreachable', 'redirect']);
    }

    public function test_links_sitemap_entries_and_images_on_other_hosts_or_private_addresses_are_never_contacted(): void
    {
        $site = $this->fakeSite()->healthy();
        $site->sitemap(['/', '/about', '/services', '/contact', 'https://elsewhere.example/page', 'http://127.0.0.1/admin', 'http://10.0.0.1/']);
        $nav = [['/', 'Home'], ['/about', 'About us'], ['/services', 'Our services'], ['/contact', 'Contact us'], ['http://192.168.1.1/router', 'Router'], ['https://elsewhere.example/', 'Other']];
        $site->page('/', ['title' => 'Bizfixture Studio | Photography in Austin', 'links' => $nav, 'jsonld' => json_encode(['@type' => 'LocalBusiness', 'name' => 'B', 'address' => 'x']), 'body' => '<a href="tel:5125550142">(512) 555-0142</a> Austin', 'images' => [['src' => 'https://tracker.example/pixel.png', 'alt' => 'x'], ['src' => 'http://10.0.0.9/secret.png', 'alt' => 'y']]]);

        $this->crawlSite($site);

        foreach ($this->transport->requestedUrls() as $url) {
            $this->assertSame('bizfixture.example', strtolower((string) parse_url($url, PHP_URL_HOST)), 'Only the audited site is ever contacted: '.$url);
        }
    }

    public function test_responses_and_requests_stay_bounded(): void
    {
        $site = $this->fakeSite()->healthy();
        $site->raw('/about', 200, ['content-type' => 'text/html'], '<html><head><title>x</title></head><body>'.str_repeat('a', 2_000_000).'</body></html>');

        $outcome = $this->crawlSite($site, ['max_response_bytes' => 100_000]);
        $about = collect($outcome->pages)->first(fn ($p) => str_ends_with($p->url, '/about'));

        $this->assertSame('unreachable', $about->kind);
        $this->assertSame('too_large', $about->reason, 'An oversized page is not read, and is not called broken.');
    }

    public function test_a_size_probe_keeps_the_real_status_and_headers_without_reading_the_body(): void
    {
        $this->resolver->set('img.example', ['93.184.216.34']);
        $this->transport->set('https://img.example/a.jpg', ['status' => 200, 'headers' => ['content-type' => 'image/jpeg', 'content-length' => '3000000'], 'body' => str_repeat('x', 3000)]);
        $config = new \App\Library\ExternalSite\ExternalSiteConfig(['allowed_ports' => [80, 443]]);
        $fetcher = new \App\Library\ExternalSite\SafeFetcher(new \App\Library\ExternalSite\UrlGuard($this->resolver, $config), $this->transport, $config);

        $result = $fetcher->fetch('https://img.example/a.jpg', ['img.example'], 'image/*', ['image/'], 512);

        $this->assertSame('too_large', $result->error);
        $this->assertSame(200, $result->status);
        $this->assertSame('3000000', $result->header('content-length'));
        $this->assertSame('', $result->body);
    }
}

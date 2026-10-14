<?php

namespace Tests\Unit\ExternalSite;

use App\Library\ExternalSite\Audit\ExternalAuditCatalog;
use App\Library\ExternalSite\Audit\ExternalAuditContext;
use App\Library\ExternalSite\CrawlOutcome;
use App\Library\ExternalSite\CrawledPage;
use App\Library\ExternalSite\ConnectionResult;
use Tests\Support\ExternalSite\AuditsFakeSites;
use Tests\TestCase;

/**
 * The audit's decisions, over an in-memory website: what is reported, what is NOT,
 * and how loudly. Every test makes ONE thing worse on an otherwise healthy site and
 * asserts exactly what the audit says about it. No database, no network.
 */
class ExternalAuditEvaluatorTest extends TestCase
{
    use AuditsFakeSites;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootFakeInternet();
    }

    // ---------------------------------------------------------------- 1. a healthy small business website

    public function test_a_healthy_small_business_website_has_nothing_to_report(): void
    {
        $site = $this->fakeSite()->healthy();
        $outcome = $this->crawlSite($site);
        $result = $this->auditOutcome($outcome);

        $this->assertSame([], $this->ruleKeys($result), 'A site with nothing wrong must not be given invented problems.');
        $this->assertSame(CrawlOutcome::INDEXABLE, $outcome->indexability);
        $this->assertSame(4, $result->coverage['audited']);
        $this->assertSame($result->coverage['discovered'], array_sum(array_intersect_key($result->coverage, array_flip(['audited', 'redirected', 'errors', 'blocked', 'unreachable', 'robots_disallowed', 'excluded', 'skipped']))), 'Every URL is in exactly one bucket.');
        $this->assertSame(0, $result->summary['issue_groups']);
    }

    // ---------------------------------------------------------------- 2 + 10. broken navigation, with BOTH source and destination

    public function test_a_homepage_with_a_broken_menu_link_reports_the_source_page_and_the_destination(): void
    {
        $site = $this->fakeSite()->healthy();
        $site->page('/', ['title' => 'Bizfixture Studio | Photography in Austin', 'links' => [['/about', 'About us'], ['/services', 'Our services'], ['/contact', 'Contact us'], ['/gallery', 'Gallery']], 'jsonld' => json_encode(['@type' => 'LocalBusiness', 'name' => 'B', 'address' => 'x']), 'body' => '<a href="tel:5125550142">(512) 555-0142</a> Austin']);
        $site->missing('/gallery');

        $result = $this->auditOutcome($this->crawlSite($site));
        $broken = $this->findingsFor($result, 'broken_internal_link');

        $this->assertCount(1, $broken);
        $this->assertSame($site->url('/'), $broken[0]->pageUrl, 'The finding is on the page that carries the link.');
        $this->assertSame($site->url('/gallery'), $broken[0]->evidence['values']['destination']);
        $this->assertSame(404, $broken[0]->evidence['values']['http_status']);
        $this->assertSame('Gallery', $broken[0]->evidence['values']['anchor']);
        $this->assertSame($site->url('/gallery'), $broken[0]->evidence['related'][0]['url']);
        $this->assertSame('high', $broken[0]->priority, 'A broken link ON the home page is at least High.');
        $this->assertSame(1, $result->summary['broken_destinations']);
    }

    public function test_a_broken_link_to_a_contact_page_from_the_home_page_is_critical(): void
    {
        $site = $this->fakeSite()->healthy();
        $site->missing('/contact');

        $result = $this->auditOutcome($this->crawlSite($site));
        $broken = collect($this->findingsFor($result, 'broken_internal_link'));

        $this->assertTrue($broken->contains(fn ($f) => $f->priority === 'critical' && $f->evidence['values']['destination'] === $site->url('/contact')));
        $this->assertSame(1, $result->summary['broken_destinations'], 'One broken destination, however many pages link to it.');
        $this->assertGreaterThanOrEqual(3, $result->summary['broken_link_instances'], 'Every page that links to it is named as a source.');
        $this->assertSame(1, count(array_unique(array_map(fn ($f) => $f->groupKey, $broken->all()))), 'All sources of one broken destination share one group.');
    }

    public function test_a_404_page_does_not_also_get_missing_title_description_and_heading_findings(): void
    {
        $site = $this->fakeSite()->healthy();
        $site->missing('/services');

        $result = $this->auditOutcome($this->crawlSite($site));
        $onTheBrokenPage = array_map(fn ($f) => $f->ruleKey, array_values(array_filter($result->findings, fn ($f) => $f->pageUrl === $site->url('/services'))));

        $this->assertSame(['sitemap_url_error'], $onTheBrokenPage, 'A page that was never parsed has exactly one finding (it is listed in the sitemap but errors), never a missing title, description or H1.');
        $this->assertNotContains('title_missing', $this->ruleKeys($result));
        $this->assertNotContains('description_missing', $this->ruleKeys($result));
        $this->assertNotContains('h1_missing', $this->ruleKeys($result));
    }

    // ---------------------------------------------------------------- 3 + 4. metadata, grouped

    public function test_missing_descriptions_are_one_issue_naming_each_page(): void
    {
        $site = $this->fakeSite()->healthy();
        foreach (['/about', '/services', '/contact'] as $path) {
            $site->page($path, ['description' => null, 'links' => [['/', 'Home'], ['/about', 'About us'], ['/services', 'Our services'], ['/contact', 'Contact us']]]);
        }

        $result = $this->auditOutcome($this->crawlSite($site));
        $rows = $this->findingsFor($result, 'description_missing');

        $this->assertEqualsCanonicalizing([$site->url('/about'), $site->url('/services'), $site->url('/contact')], array_map(fn ($f) => $f->pageUrl, $rows));
        $this->assertSame(1, $result->summary['issue_groups'], 'Three pages missing a description are ONE issue, not three.');
        $this->assertSame(3, $result->summary['rows']);
    }

    public function test_duplicate_titles_form_one_group_listing_the_real_urls(): void
    {
        $site = $this->fakeSite()->healthy();
        $nav = [['/', 'Home'], ['/about', 'About us'], ['/services', 'Our services'], ['/contact', 'Contact us']];
        $site->page('/about', ['title' => 'Same title for everyone here', 'links' => $nav]);
        $site->page('/services', ['title' => 'Same title for everyone here', 'links' => $nav]);

        $rows = $this->findingsFor($this->auditOutcome($this->crawlSite($site)), 'title_duplicate');

        $this->assertCount(2, $rows);
        $this->assertSame($rows[0]->groupKey, $rows[1]->groupKey);
        $this->assertSame(2, $rows[0]->evidence['values']['shared_by']);
        $this->assertSame($site->url('/services'), $rows[0]->pageUrl === $site->url('/about') ? $rows[0]->evidence['related'][0]['url'] : $rows[0]->pageUrl);
    }

    public function test_a_noindex_page_is_not_counted_as_competing_for_a_duplicate_title(): void
    {
        $site = $this->fakeSite()->healthy();
        $nav = [['/', 'Home'], ['/about', 'About us'], ['/services', 'Our services'], ['/contact', 'Contact us']];
        $site->page('/about', ['title' => 'Same title for everyone here', 'links' => $nav]);
        $site->page('/services', ['title' => 'Same title for everyone here', 'links' => $nav, 'noindex' => true]);

        $this->assertSame([], $this->findingsFor($this->auditOutcome($this->crawlSite($site)), 'title_duplicate'));
    }

    // ---------------------------------------------------------------- 5 + 6. headings, per page

    public function test_a_page_with_two_h1s_is_reported_with_its_own_count_and_nothing_else_is(): void
    {
        $site = $this->fakeSite()->healthy();
        $site->page('/about', ['h1' => ['About us', 'Our story'], 'links' => [['/', 'Home'], ['/services', 'Our services'], ['/contact', 'Contact us'], ['/about', 'About us']]]);

        $rows = $this->findingsFor($this->auditOutcome($this->crawlSite($site)), 'h1_multiple');

        $this->assertCount(1, $rows);
        $this->assertSame($site->url('/about'), $rows[0]->pageUrl);
        $this->assertSame(2, $rows[0]->evidence['values']['h1_count']);
        $this->assertSame('low', $rows[0]->priority, 'Several H1s are allowed by HTML: proportionate severity.');
    }

    public function test_seventeen_pages_with_one_h1_each_never_become_seventeen_headings_on_a_page(): void
    {
        $site = $this->fakeSite();
        $links = [];
        for ($i = 1; $i <= 17; $i++) {
            $links[] = ['/p'.$i, 'Page '.$i];
        }
        $site->page('/', ['title' => 'Home of the seventeen pages | Bizfixture', 'links' => $links, 'h1' => 'Home']);
        for ($i = 1; $i <= 17; $i++) {
            $site->page('/p'.$i, ['title' => 'Distinct page number '.$i.' | Bizfixture Studio', 'description' => 'A description unique to page number '.$i.' that is comfortably long enough to be a proper summary of it.', 'h1' => 'Heading number '.$i, 'links' => [['/', 'Home']]]);
        }

        $result = $this->auditOutcome($this->crawlSite($site, ['max_pages' => 40]));

        $this->assertSame(18, $result->coverage['audited']);
        $this->assertSame([], $this->findingsFor($result, 'h1_multiple'));
        $this->assertSame([], $this->findingsFor($result, 'h1_missing'));
        foreach ($result->findings as $finding) {
            $this->assertStringNotContainsString('17 ', $finding->evidence['observed'], 'A crawl-wide total must never be attributed to a page.');
        }
    }

    public function test_a_page_that_really_has_seventeen_h1s_says_so_for_that_page_only(): void
    {
        $site = $this->fakeSite()->healthy();
        $site->page('/about', ['h1' => array_map(fn ($i) => 'Heading '.$i, range(1, 17)), 'links' => [['/', 'Home'], ['/services', 'Our services'], ['/contact', 'Contact us'], ['/about', 'About us']]]);

        $rows = $this->findingsFor($this->auditOutcome($this->crawlSite($site)), 'h1_multiple');

        $this->assertCount(1, $rows);
        $this->assertSame(17, $rows[0]->evidence['values']['h1_count']);
        $this->assertSame($site->url('/about'), $rows[0]->pageUrl);
    }

    public function test_a_missing_h1_on_the_services_page_is_medium_and_on_a_privacy_page_is_only_information(): void
    {
        $site = $this->fakeSite()->healthy();
        $nav = [['/', 'Home'], ['/about', 'About us'], ['/services', 'Our services'], ['/contact', 'Contact us'], ['/privacy-policy', 'Privacy']];
        $site->page('/', ['title' => 'Bizfixture Studio | Photography in Austin', 'links' => $nav, 'jsonld' => json_encode(['@type' => 'LocalBusiness', 'name' => 'B', 'address' => 'x']), 'body' => '<a href="tel:5125550142">(512) 555-0142</a> Austin']);
        $site->page('/services', ['h1' => null, 'links' => $nav]);
        $site->page('/privacy-policy', ['h1' => null, 'links' => $nav]);

        $rows = collect($this->findingsFor($this->auditOutcome($this->crawlSite($site)), 'h1_missing'))->keyBy('pageUrl');

        $this->assertSame('medium', $rows[$site->url('/services')]->priority);
        $this->assertSame('info', $rows[$site->url('/privacy-policy')]->priority, 'A privacy policy and the services page are not equally important.');
    }

    // ---------------------------------------------------------------- 7. robots.txt

    public function test_robots_txt_restrictions_are_obeyed_and_reported_not_crawled(): void
    {
        $site = $this->fakeSite()->healthy();
        $site->robots("User-agent: *\nDisallow: /private\nSitemap: ".$site->url('/sitemap.xml')."\n");
        $nav = [['/', 'Home'], ['/about', 'About us'], ['/services', 'Our services'], ['/contact', 'Contact us'], ['/private/area', 'Members']];
        $site->page('/', ['title' => 'Bizfixture Studio | Photography in Austin', 'links' => $nav, 'jsonld' => json_encode(['@type' => 'LocalBusiness', 'name' => 'B', 'address' => 'x']), 'body' => '<a href="tel:5125550142">(512) 555-0142</a> Austin']);
        $site->page('/private/area', ['title' => 'SHOULD NEVER BE FETCHED']);

        $outcome = $this->crawlSite($site);
        $result = $this->auditOutcome($outcome);

        $this->assertNotContains($site->url('/private/area'), $this->transport->requestedUrls());
        $records = collect($outcome->pages)->keyBy('url');
        $this->assertSame('robots_disallowed', $records[$site->url('/private/area')]->kind);
        $this->assertSame(1, $result->coverage['robots_disallowed']);
        $this->assertCount(1, $this->findingsFor($result, 'robots_blocks_pages'));
    }

    public function test_a_robots_txt_that_blocks_the_whole_site_means_no_page_is_fetched_and_the_reason_is_stated(): void
    {
        $site = $this->fakeSite()->healthy();
        $site->robots("User-agent: *\nDisallow: /\n");

        $outcome = $this->crawlSite($site);

        $this->assertSame(CrawlOutcome::BLOCKED_BY_ROBOTS, $outcome->indexability);
        $this->assertSame('blocked_by_robots', $outcome->failureCode);
        $this->assertSame(ConnectionResult::ROBOTS_BLOCKED, $outcome->connection->state);
        $this->assertSame([$site->url('/robots.txt')], $this->transport->requestedUrls());
    }

    // ---------------------------------------------------------------- 8. noindex and canonical combinations

    public function test_noindex_on_the_home_page_is_critical_and_on_an_ordinary_page_medium(): void
    {
        $site = $this->fakeSite()->healthy();
        $nav = [['/', 'Home'], ['/about', 'About us'], ['/services', 'Our services'], ['/contact', 'Contact us']];
        $site->page('/', ['title' => 'Bizfixture Studio | Photography in Austin', 'noindex' => true, 'links' => $nav, 'jsonld' => json_encode(['@type' => 'LocalBusiness', 'name' => 'B', 'address' => 'x']), 'body' => '<a href="tel:5125550142">(512) 555-0142</a> Austin']);
        $site->page('/about', ['noindex' => true, 'links' => $nav]);

        $rows = collect($this->findingsFor($this->auditOutcome($this->crawlSite($site)), 'noindex_page'))->keyBy('pageUrl');

        $this->assertSame('critical', $rows[$site->url('/')]->priority);
        $this->assertSame('medium', $rows[$site->url('/about')]->priority);
        $this->assertStringContainsString('meta robots', $rows[$site->url('/about')]->evidence['observed']);
    }

    public function test_an_x_robots_tag_noindex_is_found_even_when_the_html_has_none(): void
    {
        $site = $this->fakeSite()->healthy();
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>About Bizfixture Studio | Our Austin team</title><meta name="description" content="Meet the photographers behind Bizfixture Studio, a small Austin team focused on relaxed, natural portraits and events."><meta name="viewport" content="width=device-width"><link rel="canonical" href="'.$site->url('/about').'"><meta property="og:title" content="x"></head><body><h1>About</h1><a href="/">Home</a><a href="/services">Our services</a><a href="/contact">Contact us</a></body></html>';
        $site->raw('/about', 200, ['content-type' => 'text/html', 'x-robots-tag' => 'noindex'], $html);

        $rows = $this->findingsFor($this->auditOutcome($this->crawlSite($site)), 'noindex_page');

        $this->assertCount(1, $rows);
        $this->assertStringContainsString('X-Robots-Tag', $rows[0]->evidence['observed']);
    }

    public function test_a_noindex_page_listed_in_the_sitemap_is_a_contradiction(): void
    {
        $site = $this->fakeSite()->healthy();
        $site->page('/about', ['noindex' => true, 'links' => [['/', 'Home'], ['/services', 'Our services'], ['/contact', 'Contact us']]]);

        $rules = $this->ruleKeys($this->auditOutcome($this->crawlSite($site)));

        $this->assertContains('sitemap_url_noindex', $rules);
        $this->assertContains('noindex_page', $rules);
    }

    public function test_canonical_combinations_are_each_classified_correctly(): void
    {
        $site = $this->fakeSite()->healthy();
        $nav = [['/', 'Home'], ['/about', 'About us'], ['/services', 'Our services'], ['/contact', 'Contact us']];
        $site->page('/about', ['canonical' => 'https://elsewhere.example/about', 'links' => $nav]);                       // off site
        $site->page('/services', ['canonical' => [$site->url('/services'), $site->url('/about')], 'links' => $nav]);    // conflict
        $site->page('/contact', ['canonical' => $site->url('/gone'), 'links' => $nav]);                                 // points at a 404
        $site->missing('/gone');

        $rules = $this->ruleKeys($this->auditOutcome($this->crawlSite($site)));

        $this->assertContains('canonical_off_site', $rules);
        $this->assertContains('canonical_conflict', $rules);
        $this->assertContains('canonical_target_problem', $rules);
    }

    public function test_a_page_canonicalised_to_another_page_is_labelled_so_and_not_judged_a_duplicate(): void
    {
        $site = $this->fakeSite()->healthy();
        $nav = [['/', 'Home'], ['/about', 'About us'], ['/services', 'Our services'], ['/contact', 'Contact us']];
        $site->page('/services', ['title' => 'About Bizfixture Studio | Our Austin team', 'canonical' => $site->url('/about'), 'links' => $nav]);

        $result = $this->auditOutcome($this->crawlSite($site));

        $this->assertSame('other_page', $result->analysis[\App\Library\ExternalSite\UrlResolver::pageKey($site->url('/services'))]['canonical_status']);
        $this->assertSame([], $this->findingsFor($result, 'title_duplicate'));
    }

    // ---------------------------------------------------------------- 9. a sitemap with redirects and 404s

    public function test_a_sitemap_with_redirects_and_errors_is_reported_per_address(): void
    {
        $site = $this->fakeSite()->healthy();
        $site->sitemap(['/', '/about', '/services', '/contact', '/old-offer', '/deleted']);
        $site->redirect('/old-offer', $site->url('/services'));
        $site->missing('/deleted');

        $result = $this->auditOutcome($this->crawlSite($site));

        $this->assertSame([$site->url('/old-offer')], array_map(fn ($f) => $f->pageUrl, $this->findingsFor($result, 'sitemap_url_redirects')));
        $this->assertSame([$site->url('/deleted')], array_map(fn ($f) => $f->pageUrl, $this->findingsFor($result, 'sitemap_url_error')));
        $this->assertSame([], $this->findingsFor($result, 'broken_internal_link'), 'Nothing LINKS to the deleted page; only the sitemap does.');
    }

    // ---------------------------------------------------------------- 11. firewall refusals

    public function test_a_cloudflare_challenge_on_one_page_is_not_a_broken_page_and_is_not_audited(): void
    {
        $site = $this->fakeSite()->healthy();
        $site->blocked('/services', 403);

        $outcome = $this->crawlSite($site);
        $result = $this->auditOutcome($outcome);
        $records = collect($outcome->pages)->keyBy('url');

        $this->assertSame('blocked', $records[$site->url('/services')]->kind);
        $this->assertSame([], $this->findingsFor($result, 'broken_internal_link'));
        $this->assertSame([], array_filter($result->findings, fn ($f) => $f->pageUrl === $site->url('/services') && $f->ruleKey !== 'crawler_blocked'), 'A refused page produces no metadata findings.');
        $this->assertSame('info', $this->findingsFor($result, 'crawler_blocked')[0]->priority);
        $this->assertSame(1, $result->coverage['blocked']);
    }

    public function test_a_blocked_home_page_is_not_reported_as_the_site_being_down(): void
    {
        foreach ([403, 429, 503] as $status) {
            $this->bootFakeInternet();
            $site = $this->fakeSite()->healthy();
            $site->blocked('/', $status);

            $outcome = $this->crawlSite($site);

            $this->assertSame(ConnectionResult::CRAWLER_BLOCKED, $outcome->connection->state, "HTTP $status");
            $this->assertSame('crawler_blocked', $outcome->failureCode);
            $this->assertSame(CrawlOutcome::NOT_VERIFIED, $outcome->indexability, 'Nothing can be concluded about indexing from a refusal.');
            $this->assertSame([], array_values(array_diff($this->ruleKeys($this->auditOutcome($outcome)), ['crawler_blocked'])), 'No outage finding, no metadata findings: at most the information that the crawler was turned away.');
            $this->assertCount(2, $this->transport->requestedUrls(), 'robots.txt and the one refused request: nothing hammered.');
        }
    }

    public function test_a_plain_403_without_any_firewall_signature_is_still_a_refusal_not_an_error_page(): void
    {
        $site = $this->fakeSite()->healthy();
        $site->blocked('/about', 403, cloudflare: false);

        $records = collect($this->crawlSite($site)->pages)->keyBy('url');

        $this->assertSame('blocked', $records[$site->url('/about')]->kind);
    }

    public function test_a_real_error_on_the_home_page_is_reported_as_one_after_a_retry(): void
    {
        $site = $this->fakeSite()->healthy();
        $site->raw('/', 500, ['content-type' => 'text/html'], '<html><body>boom</body></html>');

        $outcome = $this->crawlSite($site);

        $this->assertSame(ConnectionResult::HTTP_ERROR, $outcome->connection->state);
        $this->assertSame(500, $outcome->connection->httpStatus);
        $this->assertCount(2, array_keys(array_filter($this->transport->requestedUrls(), fn ($u) => $u === $site->url('/'))), 'A server error on the start page is retried once.');
        $this->assertSame(CrawlOutcome::UNREACHABLE, $outcome->indexability);
    }

    // ---------------------------------------------------------------- 12. www / non-www

    public function test_a_redirect_between_www_and_the_bare_domain_is_followed_and_the_effective_address_is_reported(): void
    {
        $site = $this->fakeSite();
        $site->redirect('/', 'https://www.bizfixture.example/');
        $this->transport->set('https://www.bizfixture.example/', ['status' => 200, 'headers' => ['content-type' => 'text/html'], 'body' => '<html lang="en"><head><title>Bizfixture Studio | Photography in Austin</title><meta name="viewport" content="width=device-width"></head><body><h1>Hello</h1><a href="https://www.bizfixture.example/about">About us</a></body></html>']);
        $this->transport->set('https://www.bizfixture.example/about', ['status' => 200, 'headers' => ['content-type' => 'text/html'], 'body' => '<html lang="en"><head><title>About Bizfixture Studio | Our team</title></head><body><h1>About</h1></body></html>']);

        $outcome = $this->crawlSite($site);

        $this->assertSame(ConnectionResult::REACHABLE, $outcome->connection->state);
        $this->assertSame('https://www.bizfixture.example/', $outcome->connection->finalUrl);
        $this->assertSame(2, count($outcome->audited()), 'Both hosts count as the one site.');
    }

    public function test_an_address_that_redirects_to_a_different_website_is_reported_not_crawled(): void
    {
        $site = $this->fakeSite();
        $site->redirect('/', 'https://other-business.example/');
        $this->resolver->set('other-business.example', ['93.184.216.34']);

        $outcome = $this->crawlSite($site);

        $this->assertSame(ConnectionResult::REDIRECTS_ELSEWHERE, $outcome->connection->state);
        $this->assertSame('https://other-business.example/', $outcome->connection->finalUrl);
        $this->assertNotContains('https://other-business.example/', $this->transport->requestedUrls(), 'The other website is never contacted.');
    }

    public function test_an_http_site_that_does_not_redirect_to_https_is_a_medium_improvement(): void
    {
        $site = $this->fakeSite()->healthy();
        $this->transport->set('http://bizfixture.example/', ['status' => 200, 'headers' => ['content-type' => 'text/html'], 'body' => '<html><body>Hi</body></html>']);

        $this->assertContains('http_not_redirected', $this->ruleKeys($this->auditOutcome($this->crawlSite($site))));
    }

    // ---------------------------------------------------------------- 13. assets and technical endpoints

    public function test_email_protection_endpoints_and_files_are_excluded_not_audited_as_pages(): void
    {
        $site = $this->fakeSite()->healthy();
        $nav = [['/', 'Home'], ['/about', 'About us'], ['/services', 'Our services'], ['/contact', 'Contact us'], ['/cdn-cgi/l/email-protection#abcd', 'email'], ['/brochure.pdf', 'Brochure'], ['/wp-json/wp/v2/posts', 'API'], ['/feed', 'Feed'], ['/wp-admin/', 'Admin'], ['/search?s=cake', 'Search'], ['/photo.jpg', 'Photo']];
        $site->page('/', ['title' => 'Bizfixture Studio | Photography in Austin', 'links' => $nav, 'jsonld' => json_encode(['@type' => 'LocalBusiness', 'name' => 'B', 'address' => 'x']), 'body' => '<a href="tel:5125550142">(512) 555-0142</a> Austin']);
        $this->transport->set($site->url('/cdn-cgi/l/email-protection'), ['status' => 200, 'headers' => ['content-type' => 'text/html'], 'body' => '<html><body>protected</body></html>']);

        $outcome = $this->crawlSite($site);
        $result = $this->auditOutcome($outcome);

        foreach (['/cdn-cgi/l/email-protection', '/brochure.pdf', '/wp-json/wp/v2/posts', '/feed', '/wp-admin/', '/search?s=cake', '/photo.jpg'] as $path) {
            $this->assertNotContains($site->url($path), $this->transport->requestedUrls(), "$path must never be fetched as a page");
        }

        $this->assertSame(4, $result->coverage['audited']);
        $this->assertSame(7, $result->coverage['excluded']);
        $this->assertSame([], $this->ruleKeys($result), 'No missing title or description is reported for addresses that are not pages.');
        $this->assertEquals(['non_html_asset' => 2, 'utility_endpoint' => 3, 'private_area' => 1, 'query_variant' => 1], $result->coverage['excluded_by']);
    }

    // ---------------------------------------------------------------- 14. partial audits and the crawl budget

    public function test_a_crawl_that_hits_its_budget_is_partial_and_every_number_is_explained(): void
    {
        $site = $this->fakeSite();
        $links = [];
        for ($i = 1; $i <= 14; $i++) {
            $links[] = ['/page-'.$i, 'Page '.$i];
        }
        $site->page('/', ['title' => 'Bizfixture Studio | Photography in Austin', 'links' => $links]);
        for ($i = 1; $i <= 14; $i++) {
            $site->page('/page-'.$i, ['title' => 'Page number '.$i.' | Bizfixture Studio Austin', 'description' => 'Description number '.$i.' which is long enough to be considered a sensible summary of that page.', 'links' => [['/', 'Home']]]);
        }
        $site->sitemap(array_merge(['/'], array_map(fn ($i) => '/page-'.$i, range(1, 14)), ['/old-1', '/old-2']));
        $site->missing('/old-1');
        $site->missing('/old-2');

        $outcome = $this->crawlSite($site, ['max_pages' => 8]);
        $result = $this->auditOutcome($outcome);
        $c = $result->coverage;

        $this->assertTrue($outcome->truncated);
        $this->assertSame(CrawlOutcome::FINISH_PAGE_LIMIT, $outcome->finishReason);
        $this->assertSame(8, $c['audited']);
        $this->assertSame(17, $c['discovered'], 'Home + 14 pages + 2 sitemap-only addresses: the budget limits what is CHECKED, not what is known.');
        $this->assertSame(9, $c['skipped'], 'The rest are recorded as not checked, with the reason.');
        $this->assertSame('crawl_limit', $c['skipped_reason']);
        $this->assertSame($c['discovered'], $c['audited'] + $c['redirected'] + $c['errors'] + $c['blocked'] + $c['unreachable'] + $c['robots_disallowed'] + $c['excluded'] + $c['skipped']);
        $this->assertSame([], $this->findingsFor($result, 'orphan_candidate'), 'No orphan claims are made from an incomplete crawl.');

        $sentence = \App\Library\ExternalSite\ExternalAuditPresenter::coverageSentence($c);
        $this->assertStringContainsString('audited 8 web pages', $sentence);
        $this->assertStringContainsString('9 pages not checked because the page limit was reached', $sentence);
    }

    // ---------------------------------------------------------------- 20. unmeasured things stay unmeasured

    public function test_nothing_the_audit_cannot_measure_is_ever_reported_as_a_finding_or_a_number(): void
    {
        $result = $this->auditOutcome($this->crawlSite($this->fakeSite()->healthy()));
        $labels = implode(' ', array_column(\App\Library\ExternalSite\ExternalAuditPresenter::notMeasured(), 'label'));

        $this->assertStringContainsString('Core Web Vitals', $labels);
        $this->assertStringContainsString('Google indexing', $labels);
        foreach (ExternalAuditCatalog::keys() as $key) {
            $rule = ExternalAuditCatalog::rule($key);
            $text = strtolower($rule['title'].' '.$rule['what'].' '.$rule['why'].' '.$rule['proposal']);
            foreach (['will rank', 'increase traffic', 'more revenue', 'boost your ranking', 'will improve your ranking', 'lcp is', 'your score'] as $promise) {
                $this->assertStringNotContainsString($promise, $text, "$key must not promise outcomes");
            }
        }
        $this->assertArrayNotHasKey('score', $result->summary, 'No invented SEO score.');
    }

    public function test_the_indexability_wording_never_claims_google_has_indexed_anything(): void
    {
        foreach ([CrawlOutcome::INDEXABLE, CrawlOutcome::NOINDEX, CrawlOutcome::BLOCKED_BY_ROBOTS, CrawlOutcome::UNREACHABLE, CrawlOutcome::NOT_VERIFIED] as $state) {
            $text = strtolower(implode(' ', \App\Library\ExternalSite\ExternalAuditPresenter::indexability($state)));
            $this->assertStringNotContainsString('search engines can find your site', $text);
            $this->assertStringNotContainsString('is indexed', $text);
        }

        $this->assertSame('No obvious indexing block detected', \App\Library\ExternalSite\ExternalAuditPresenter::indexability(CrawlOutcome::INDEXABLE)['label']);
        $this->assertSame('Not verified', \App\Library\ExternalSite\ExternalAuditPresenter::indexability(CrawlOutcome::NOT_VERIFIED)['label']);
    }

    // ---------------------------------------------------------------- images, structured data, local SEO

    public function test_images_without_alt_are_found_per_page_and_decorative_ones_are_respected(): void
    {
        $site = $this->fakeSite()->healthy();
        $nav = [['/', 'Home'], ['/about', 'About us'], ['/services', 'Our services'], ['/contact', 'Contact us']];
        $site->page('/about', ['links' => $nav, 'images' => [['src' => '/img/hero.jpg'], ['src' => '/img/hero.jpg', 'alt' => ''], ['src' => '/img/hero.jpg', 'alt' => 'The team']]]);

        $rows = $this->findingsFor($this->auditOutcome($this->crawlSite($site)), 'image_alt_missing');

        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]->evidence['values']['missing_alt'], 'alt="" is a decorative image, not an error.');
        $this->assertSame(3, $rows[0]->evidence['values']['images_on_page']);
    }

    public function test_broken_images_are_probed_and_large_ones_only_when_the_server_states_a_size(): void
    {
        $site = $this->fakeSite()->healthy();
        $nav = [['/', 'Home'], ['/about', 'About us'], ['/services', 'Our services'], ['/contact', 'Contact us']];
        $site->page('/about', ['links' => $nav, 'images' => [['src' => '/img/missing.jpg', 'alt' => 'x'], ['src' => '/img/huge.jpg', 'alt' => 'y'], ['src' => '/img/nosize.jpg', 'alt' => 'z']]]);
        $site->missing('/img/missing.jpg');
        $site->raw('/img/huge.jpg', 200, ['content-type' => 'image/jpeg', 'content-length' => '4500000'], 'X');
        $site->raw('/img/nosize.jpg', 200, ['content-type' => 'image/jpeg'], 'X');

        $result = $this->auditOutcome($this->crawlSite($site));
        $broken = $this->findingsFor($result, 'image_broken');
        $large = $this->findingsFor($result, 'image_large');

        $this->assertCount(1, $broken);
        $this->assertSame($site->url('/img/missing.jpg'), $broken[0]->evidence['related'][0]['url']);
        $this->assertCount(1, $large);
        $this->assertCount(1, $large[0]->evidence['related'], 'The image with no stated size is never called large.');
        $this->assertStringContainsString('4.5 MB', $large[0]->evidence['related'][0]['note']);
    }

    public function test_structured_data_is_parsed_and_malformed_or_incomplete_blocks_are_reported(): void
    {
        $site = $this->fakeSite()->healthy();
        $nav = [['/', 'Home'], ['/about', 'About us'], ['/services', 'Our services'], ['/contact', 'Contact us']];
        $site->page('/about', ['links' => $nav, 'jsonld' => '{"@type": "LocalBusiness", "name": "X",}']);
        $site->page('/services', ['links' => $nav, 'jsonld' => json_encode(['@context' => 'https://schema.org', '@type' => 'LocalBusiness', 'name' => 'Bizfixture'])]);

        $result = $this->auditOutcome($this->crawlSite($site));

        $this->assertSame([$site->url('/about')], array_map(fn ($f) => $f->pageUrl, $this->findingsFor($result, 'structured_data_invalid')));
        $incomplete = $this->findingsFor($result, 'structured_data_incomplete');
        $this->assertCount(1, $incomplete);
        $this->assertSame(['LocalBusiness.address'], $incomplete[0]->evidence['values']['missing']);
        $this->assertStringContainsString('not a guarantee', ExternalAuditCatalog::rule('structured_data_incomplete')['what'], 'Valid markup is never presented as rich-result eligibility.');
    }

    public function test_local_checks_compare_the_phone_and_do_not_demand_a_city_on_every_page(): void
    {
        $site = $this->fakeSite()->healthy();
        $context = new ExternalAuditContext(businessName: 'Bizfixture Studio', phoneDigits: '2125559999', cities: ['Austin'], hasLocation: true);

        $result = $this->auditOutcome($this->crawlSite($site, terms: ['Austin']), $context);
        $mismatch = $this->findingsFor($result, 'nap_phone_mismatch');

        $this->assertCount(1, $mismatch);
        $this->assertSame(['(512) 555-0142'], $mismatch[0]->evidence['values']['numbers_on_site']);
        $this->assertSame([], $this->findingsFor($result, 'service_area_not_visible'), 'The city is mentioned somewhere: nothing is demanded of each page.');
        $this->assertSame([], array_values(array_filter($result->findings, fn ($f) => $f->pageUrl !== null && str_contains($f->ruleKey, 'city'))));
    }

    public function test_a_site_that_never_says_where_it_works_gets_one_site_level_opportunity(): void
    {
        $site = $this->fakeSite()->healthy();
        $context = new ExternalAuditContext(businessName: 'Bizfixture Studio', phoneDigits: '5125550142', cities: ['Dallas'], hasLocation: true);

        $rows = $this->findingsFor($this->auditOutcome($this->crawlSite($site, terms: ['Dallas']), $context), 'service_area_not_visible');

        $this->assertCount(1, $rows);
        $this->assertSame('opportunity', ExternalAuditCatalog::kind('service_area_not_visible'));
        $this->assertSame('heuristic', $rows[0]->confidence);
    }

    public function test_a_homepage_with_no_way_to_contact_or_book_is_an_opportunity_with_evidence(): void
    {
        $site = $this->fakeSite()->healthy();
        $site->page('/', ['title' => 'Bizfixture Studio | Photography in Austin', 'links' => [['/about', 'About us'], ['/services', 'Our services']], 'jsonld' => json_encode(['@type' => 'LocalBusiness', 'name' => 'B', 'address' => 'x']), 'body' => 'Austin']);

        $rows = $this->findingsFor($this->auditOutcome($this->crawlSite($site)), 'no_contact_path');

        $this->assertCount(1, $rows);
        $this->assertSame(false, $rows[0]->evidence['values']['tel_links']);
        $this->assertSame('medium', $rows[0]->priority);
    }

    // ---------------------------------------------------------------- redirects and links

    public function test_a_link_to_a_redirecting_address_names_the_final_address_to_use(): void
    {
        $site = $this->fakeSite()->healthy();
        $nav = [['/', 'Home'], ['/about', 'About us'], ['/services', 'Our services'], ['/contact', 'Contact us'], ['/old-services', 'Services']];
        $site->page('/', ['title' => 'Bizfixture Studio | Photography in Austin', 'links' => $nav, 'jsonld' => json_encode(['@type' => 'LocalBusiness', 'name' => 'B', 'address' => 'x']), 'body' => '<a href="tel:5125550142">(512) 555-0142</a> Austin']);
        $site->redirect('/old-services', $site->url('/services'));

        $rows = $this->findingsFor($this->auditOutcome($this->crawlSite($site)), 'internal_link_to_redirect');

        $this->assertCount(1, $rows);
        $this->assertSame($site->url('/old-services'), $rows[0]->evidence['values']['destination']);
        $this->assertSame($site->url('/services'), $rows[0]->evidence['values']['final_url']);
        $this->assertSame('Change the link to '.$site->url('/services'), $rows[0]->evidence['proposal']);
    }

    public function test_a_redirect_loop_and_a_long_chain_are_reported(): void
    {
        $site = $this->fakeSite()->healthy();
        $nav = [['/', 'Home'], ['/about', 'About us'], ['/services', 'Our services'], ['/contact', 'Contact us'], ['/loop-a', 'Loop'], ['/hop1', 'Hop']];
        $site->page('/', ['title' => 'Bizfixture Studio | Photography in Austin', 'links' => $nav, 'jsonld' => json_encode(['@type' => 'LocalBusiness', 'name' => 'B', 'address' => 'x']), 'body' => '<a href="tel:5125550142">(512) 555-0142</a> Austin']);
        $site->redirect('/loop-a', $site->url('/loop-b'))->redirect('/loop-b', $site->url('/loop-a'));
        $site->redirect('/hop1', $site->url('/hop2'))->redirect('/hop2', $site->url('/hop3'))->redirect('/hop3', $site->url('/about'));

        $rules = $this->ruleKeys($this->auditOutcome($this->crawlSite($site)));

        $this->assertContains('redirect_loop', $rules);
        $this->assertContains('redirect_chain', $rules);
    }

    public function test_a_page_only_the_sitemap_lists_is_an_orphan_candidate_when_coverage_is_complete(): void
    {
        $site = $this->fakeSite()->healthy();
        $site->sitemap(['/', '/about', '/services', '/contact', '/hidden-offer']);
        $site->page('/hidden-offer', ['links' => [['/', 'Home']]]);

        $rows = $this->findingsFor($this->auditOutcome($this->crawlSite($site)), 'orphan_candidate');

        $this->assertSame([$site->url('/hidden-offer')], array_map(fn ($f) => $f->pageUrl, $rows));
        $this->assertSame('heuristic', $rows[0]->confidence, 'Orphans are CANDIDATES.');
    }

    // ---------------------------------------------------------------- determinism and stable keys

    public function test_the_same_crawl_always_gives_identical_findings_and_stable_keys(): void
    {
        $site = $this->fakeSite()->healthy();
        $site->page('/about', ['description' => null, 'title' => null, 'links' => [['/', 'Home'], ['/services', 'Our services'], ['/contact', 'Contact us']]]);

        $first = $this->auditOutcome($this->crawlSite($site));
        $this->bootFakeInternet();
        $site2 = $this->fakeSite()->healthy();
        $site2->page('/about', ['description' => null, 'title' => null, 'links' => [['/', 'Home'], ['/services', 'Our services'], ['/contact', 'Contact us']]]);
        $second = $this->auditOutcome($this->crawlSite($site2));

        $key = fn ($r) => array_map(fn ($f) => $f->findingKey().'|'.$f->priority, $r->findings);

        $this->assertSame($key($first), $key($second));
        $this->assertNotEmpty($first->findings);
    }

    public function test_every_rule_in_the_catalogue_has_the_complete_explanation_a_plan_item_needs(): void
    {
        foreach (ExternalAuditCatalog::keys() as $key) {
            $rule = ExternalAuditCatalog::rule($key);

            foreach (['title', 'what', 'why', 'proposal', 'verify'] as $field) {
                $this->assertNotSame('', trim((string) $rule[$field]), "$key.$field");
            }

            $this->assertNotEmpty($rule['steps'], "$key.steps");
            $this->assertArrayHasKey($rule['category'], ExternalAuditCatalog::CATEGORIES);
            $this->assertArrayHasKey($rule['kind'], ExternalAuditCatalog::KINDS);
            $this->assertContains(ExternalAuditCatalog::priorityFor($key, 'standard'), ExternalAuditCatalog::PRIORITIES);
        }
    }
}

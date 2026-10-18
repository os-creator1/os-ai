<?php

namespace Tests\Feature\ExternalSite;

use App\Jobs\ExternalSite\CrawlExternalSite;
use App\Library\Acquisition\AcquisitionPurposeManager;
use App\Library\ExternalSite\ExternalSiteAuditRunner;
use App\Library\ExternalSite\ExternalSiteConfig;
use App\Library\ExternalSite\Fixtures\FixtureExternalSiteTransport;
use App\Library\ExternalSite\Fixtures\FixtureSite;
use App\Models\AcquisitionPurpose;
use App\Models\Business;
use App\Models\ExternalSiteCrawl;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Acquisition\Support\InstallsEducationNiches;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * The Website module for a Business that keeps its existing website: the first
 * choice, the external Overview / Audit / Pages / Settings, the absence of every
 * hosted-only control, re-crawl and URL change, and tenancy.
 */
class ExternalWebsiteHttpTest extends TestCase
{
    use CreatesWebsiteFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['external_site_audit.driver' => 'fake', 'external_site_audit.request_delay_ms' => 0]);
        $this->app->forgetInstance(ExternalSiteConfig::class);
        $this->app->forgetInstance(FixtureExternalSiteTransport::class);
    }

    private function url(string $name, Workspace $workspace, Business $business, array $extra = []): string
    {
        return route('customer.workspaces.businesses.website.'.$name, array_merge([$workspace->uid, $business->uid], $extra));
    }

    /** @return array{0: Business, 1: Workspace} a signed-in owner whose Business chose "use my existing website" and was crawled */
    private function crawledExternalBusiness(): array
    {
        Queue::fake();
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->post($this->url('mode.choose', $workspace, $business), ['mode' => 'external', 'website_url' => 'studio-fixture.example'])
            ->assertRedirect($this->url('external.overview', $workspace, $business));

        $crawl = ExternalSiteCrawl::query()->where('business_id', $business->id)->firstOrFail();
        app(ExternalSiteAuditRunner::class)->run($crawl);

        return [$business->fresh(), $workspace];
    }

    public function test_choosing_the_existing_website_saves_the_address_through_the_business_and_starts_a_check(): void
    {
        Queue::fake();
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->post($this->url('mode.choose', $workspace, $business), ['mode' => 'external', 'website_url' => 'Studio-Fixture.example/classes?x=1'])->assertRedirect();

        $business = $business->fresh();
        $this->assertSame('external', $business->website_mode);
        $this->assertSame('https://studio-fixture.example/', rtrim((string) $business->website_url, '/').'/', 'Only the site origin is kept; the address lives in businesses.website_url.');
        $this->assertSame('studio-fixture.example', $business->canonical_domain, 'The canonical domain stays consistent because the write used the Business update seam.');
        Queue::assertPushed(CrawlExternalSite::class, 1);
        $this->assertSame(1, ExternalSiteCrawl::query()->where('business_id', $business->id)->count());
    }

    public function test_a_refused_address_is_explained_and_nothing_is_stored_or_crawled(): void
    {
        Queue::fake();
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        DB::table('businesses')->where('id', $business->id)->update(['website_url' => null]);

        foreach (['http://127.0.0.1', 'localhost', 'http://[::1]/', '192.168.1.5', 'file:///etc/passwd', 'ftp://example.com', 'user:pass@example.com', ''] as $bad) {
            $this->post($this->url('mode.choose', $workspace, $business), ['mode' => 'external', 'website_url' => $bad])
                ->assertRedirect()->assertSessionHas('status', 'error');
        }

        $this->assertNull($business->fresh()->website_mode);
        Queue::assertNotPushed(CrawlExternalSite::class);
    }

    public function test_do_this_later_sets_nothing_up_and_the_choices_stay_available(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->post($this->url('mode.choose', $workspace, $business), ['mode' => 'none'])->assertRedirect($this->url('show', $workspace, $business));

        $this->get($this->url('show', $workspace, $business))->assertOk()->assertSee('data-role="chose-later"', false)->assertSee('Build my website')->assertSee('Connect existing website');
        $this->assertSame('none', $business->fresh()->website_mode);
    }

    public function test_the_external_overview_answers_what_to_fix_first_with_precise_labels_and_honest_coverage(): void
    {
        [$business, $workspace] = $this->crawledExternalBusiness();

        $this->get($this->url('show', $workspace, $business))->assertRedirect($this->url('external.overview', $workspace, $business));
        $html = $this->get($this->url('external.overview', $workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('External website', $html);
        $this->assertStringContainsString('What should I fix first?', $html);
        $this->assertStringContainsString('data-role="fix-first-item"', $html);
        $this->assertStringContainsString('View affected pages', $html);
        $this->assertStringContainsString('View recommended fix', $html);
        foreach (['Pages audited', 'Critical issues', 'High priority', 'Other actionable', 'Broken internal links', 'Technical indexability'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        $this->assertStringContainsString('No obvious indexing block detected', $html);
        $this->assertStringContainsString('Not verified', $html, 'Google indexing is not verified without Search Console.');
        $this->assertStringNotContainsString('Search engines can find your site', $html);
        $this->assertStringContainsString('Latest completed audit', $html);
        $this->assertStringContainsString('Check again now', $html);
        $this->assertStringContainsString('Settings', $html);
        $this->assertDoesNotMatchRegularExpression('/SEO score|health score|\d+\s*\/\s*100/i', strip_tags($html), 'No invented universal score.');

        // 10 addresses found, 6 audited: the rest is explained, not left as a gap.
        $this->assertStringContainsString('We found 10 addresses and audited 6 web pages', $html);
        $this->assertStringContainsString('1 file or technical address that is not a web page', $html);
        $this->assertStringContainsString('1 page robots.txt asks crawlers to skip', $html);
        $this->assertStringContainsString('1 address that redirects to another page', $html);
        $this->assertStringContainsString('1 page that answered with an error', $html);

        // The most severe, most booking-relevant issue leads.
        $this->assertStringContainsString('Links to pages that do not work', $html);
        $this->assertStringContainsString('Not measured', $html);
        $this->assertStringContainsString('Core Web Vitals', $html);
    }

    public function test_there_is_no_hosted_only_control_anywhere_in_the_external_module(): void
    {
        [$business, $workspace] = $this->crawledExternalBusiness();
        $forbidden = ['Publish', 'Change template', 'Website look', 'Connect domain', 'Connect a domain', 'Rebuild', 'Regenerate', 'Edit page', '>Fix<', 'Apply fix'];

        foreach (['external.overview', 'external.audit', 'external.plan', 'external.pages', 'external.settings'] as $name) {
            $html = $this->get($this->url($name, $workspace, $business))->assertOk()->getContent();

            foreach ($forbidden as $word) {
                $this->assertStringNotContainsString($word, $html, "$name must not show [$word].");
            }

            $this->assertStringContainsString('data-role="external-nav"', $html);
            $this->assertSame(['overview', 'audit', 'plan', 'pages', 'settings'], $this->navKeys($html));
        }
    }

    /** @return list<string> */
    private function navKeys(string $html): array
    {
        preg_match_all('/data-nav="([a-z]+)"/', $html, $m);

        return $m[1];
    }

    public function test_the_audit_lists_grouped_issues_with_exact_urls_evidence_a_fix_and_how_it_is_verified(): void
    {
        [$business, $workspace] = $this->crawledExternalBusiness();

        $html = $this->get($this->url('external.audit', $workspace, $business))->assertOk()->getContent();

        foreach (['title_duplicate', 'broken_internal_link', 'h1_missing', 'noindex_page', 'robots_blocks_pages'] as $rule) {
            $this->assertStringContainsString('data-rule="'.$rule.'"', $html, $rule);
        }
        foreach (['What the problem is.', 'Why it matters.', 'Affected URLs and what was observed', 'How to fix it', 'How MotionGrove will verify the fix.', 'Copy recommendation', 'View page'] as $part) {
            $this->assertStringContainsString($part, $html, $part);
        }
        $this->assertStringContainsString('target="_blank" rel="noopener noreferrer"', $html);
        $this->assertStringContainsString('MotionGrove cannot edit your website', $html);

        // Broken links name BOTH the page carrying the link and the destination with the observed result.
        $this->assertStringContainsString('The link &quot;See the old schedule&quot; on this page leads to https://studio-fixture.example/old-page, which answered with HTTP 404.', $html);
        $this->assertStringContainsString('/classes', $html);

        // Real filters: a category narrows the list.
        $filtered = $this->get($this->url('external.audit', $workspace, $business, ['category' => 'headings']))->assertOk()->getContent();
        $this->assertStringContainsString('data-rule="h1_missing"', $filtered);
        $this->assertStringNotContainsString('data-rule="broken_internal_link"', $filtered);

        $severity = $this->get($this->url('external.audit', $workspace, $business, ['severity' => 'medium']))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-severity="low"', $severity);
        $this->assertStringContainsString('data-severity="medium"', $severity);

        $search = $this->get($this->url('external.audit', $workspace, $business, ['q' => '/birthday']))->assertOk()->getContent();
        $this->assertStringContainsString('data-rule="noindex_page"', $search);
        $this->assertStringNotContainsString('data-rule="broken_internal_link"', $search);

        $kind = $this->get($this->url('external.audit', $workspace, $business, ['kind' => 'opportunity']))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-kind="problem"', $kind);
    }

    public function test_a_group_description_is_never_borrowed_from_one_of_its_pages(): void
    {
        [$business, $workspace] = $this->crawledExternalBusiness();
        $crawl = ExternalSiteCrawl::query()->where('business_id', $business->id)->firstOrFail();

        // The first-version bug: the group's text was the FIRST page's sentence, shown above every page ("17 main headings").
        $groups = collect(app(\App\Library\ExternalSite\ExternalWebsiteReader::class)->issueGroups($crawl))->keyBy('rule_key');
        $h1 = $groups['h1_multiple'];

        $this->assertSame(\App\Library\ExternalSite\Audit\ExternalAuditCatalog::rule('h1_multiple')['what'], $h1['what'], 'The group says what the RULE says.');
        $this->assertStringNotContainsString('2 H1', $h1['what']);
        $this->assertStringContainsString('This page contains 2 H1 headings.', $h1['rows'][0]['observed'], 'The count lives on the page row it belongs to.');
    }

    public function test_the_pages_screen_lists_audited_pages_with_filters_sorting_and_separate_not_audited_addresses(): void
    {
        [$business, $workspace] = $this->crawledExternalBusiness();

        $html = $this->get($this->url('external.pages', $workspace, $business))->assertOk()->getContent();

        $this->assertSame(6, substr_count($html, 'data-role="page-row"'), 'Only audited HTML pages: not the 404, the redirect, the PDF or the robots-disallowed page.');
        foreach (['HTTP', 'Indexing', 'Canonical', 'Issues', 'Last successful crawl', 'Details', 'Open page'] as $column) {
            $this->assertStringContainsString($column, $html, $column);
        }
        $this->assertStringContainsString('Marked noindex', $html, '/birthday is marked noindex');
        $this->assertStringContainsString('No obvious indexing block', $html);
        $this->assertStringContainsString('Addresses found but not audited', $html);
        $this->assertStringContainsString('/old-page', $html);
        $this->assertStringContainsString('/private/secret', $html);
        $this->assertStringContainsString('/brochure.pdf', $html);

        $noindex = $this->get($this->url('external.pages', $workspace, $business, ['indexing' => 'noindex']))->assertOk()->getContent();
        $this->assertSame(1, substr_count($noindex, 'data-role="page-row"'));

        $searched = $this->get($this->url('external.pages', $workspace, $business, ['q' => '/about', 'sort' => 'url']))->assertOk()->getContent();
        $this->assertSame(1, substr_count($searched, 'data-role="page-row"'));

        foreach (['severity', 'issues', 'title', 'url', 'status'] as $sort) {
            $this->get($this->url('external.pages', $workspace, $business, ['sort' => $sort]))->assertOk();
        }
    }

    public function test_a_page_detail_separates_what_was_observed_from_what_is_recommended(): void
    {
        [$business, $workspace] = $this->crawledExternalBusiness();
        $crawl = ExternalSiteCrawl::query()->where('business_id', $business->id)->firstOrFail();
        $classes = $crawl->pages()->where('url', FixtureSite::BASE.'/classes')->firstOrFail();

        $detail = $this->get($this->url('external.page', $workspace, $business, ['pageId' => $classes->id]))->assertOk()->getContent();

        foreach (['What we observed', 'Address requested', 'Final address', 'HTTP status', 'Indexing assessment', 'Meta description', 'Canonical', 'Heading outline', 'Structured data', 'Links to your own pages from this page', 'Pages that link to this page', 'Recommendations for this page'] as $part) {
            $this->assertStringContainsString($part, $detail, $part);
        }
        $this->assertStringContainsString('See the old schedule', $detail);
        $this->assertStringContainsString('Broken (HTTP 404)', $detail);
        $this->assertStringContainsString('JavaScript was not run', $detail);
        $this->assertStringContainsString('Copy recommendation', $detail);
        $this->assertLessThan(strpos($detail, 'Recommendations for this page'), strpos($detail, 'What we observed'), 'Observations come before, and apart from, recommendations.');
    }

    public function test_a_page_that_was_never_audited_has_no_page_findings(): void
    {
        [$business, $workspace] = $this->crawledExternalBusiness();
        $crawl = ExternalSiteCrawl::query()->where('business_id', $business->id)->firstOrFail();
        $dead = $crawl->pages()->where('url', FixtureSite::BASE.'/old-page')->firstOrFail();

        $html = $this->get($this->url('external.page', $workspace, $business, ['pageId' => $dead->id]))->assertOk()->getContent();

        $this->assertStringContainsString('This address was not audited', $html);
        $this->assertStringNotContainsString('No title', $html);
        $this->assertStringContainsString('Your website answered with HTTP 404', $html);
    }

    public function test_the_improvement_plan_has_the_required_parts_for_every_item_and_keeps_the_kinds_apart(): void
    {
        [$business, $workspace] = $this->crawledExternalBusiness();

        $html = $this->get($this->url('external.plan', $workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('Website improvement plan', $html);
        $this->assertStringContainsString('Start here', $html);
        foreach (['Confirmed technical problems', 'SEO improvements'] as $section) {
            $this->assertStringContainsString($section, $html, $section);
        }
        $items = substr_count($html, 'data-role="plan-item"');
        $this->assertGreaterThan(5, $items);
        foreach (['proposed-change', 'business-relevance', 'plan-urls', 'plan-steps', 'plan-verify', 'tracking-form', 'copy-brief'] as $part) {
            $this->assertSame($items, substr_count($html, 'data-role="'.$part.'"'), "Every plan item has [$part].");
        }
        $this->assertStringContainsString('Copy the whole plan', $html);
        $this->assertStringContainsString('cannot change your website', $html);
        $this->assertStringContainsString('No traffic, ranking or revenue is predicted', $html);
        $this->assertDoesNotMatchRegularExpression('/\bFix now\b|\bApply fix\b/i', $html, 'No fake action buttons.');
        $this->assertLessThan(strpos($html, 'SEO improvements'), strpos($html, 'Confirmed technical problems'), 'Confirmed problems come before improvements.');
    }

    public function test_plan_items_for_booking_and_home_pages_come_before_equal_priority_items_elsewhere(): void
    {
        $plan = new \App\Library\ExternalSite\ExternalImprovementPlan();
        $business = new \App\Models\Business(['name' => 'Studio']);
        $crawl = new ExternalSiteCrawl(['start_url' => 'https://x.example/']);
        $row = fn (string $importance, string $path) => ['id' => 1, 'page_id' => 1, 'page_url' => 'https://x.example'.$path, 'path' => $path, 'importance' => $importance, 'priority' => 'medium', 'confidence' => 'confirmed', 'observed' => 'o', 'values' => [], 'related' => [], 'proposal' => null, 'group_key' => '', 'finding_key' => 'k', 'status' => null];
        $group = fn (string $rule, string $importance, string $path, int $pages) => ['rule_key' => $rule, 'title' => $rule, 'category' => 'metadata', 'category_label' => 'M', 'kind' => 'improvement', 'kind_label' => 'K', 'priority' => 'medium', 'priority_label' => 'Medium', 'confidence' => 'confirmed', 'site_level' => false, 'what' => 'w', 'why' => 'y', 'proposal' => 'p', 'steps' => ['s'], 'verify' => 'v', 'effort' => 'quick', 'row_count' => $pages, 'page_count' => $pages, 'importance_weight' => 2, 'booking_critical' => false, 'clusters' => [], 'rows' => array_fill(0, $pages, $row($importance, $path))];

        $built = $plan->build([$group('many_blog_pages', 'standard', '/blog/a', 30), $group('one_booking_page', 'key', '/book', 1)], $business, $crawl, collect());

        $this->assertSame(['one_booking_page', 'many_blog_pages'], array_column($built['start_here'], 'rule_key'), 'One booking-page issue outranks thirty blog-page issues of the same priority.');
    }

    public function test_tracking_an_item_is_saved_per_business_and_only_for_a_real_rule(): void
    {
        [$business, $workspace] = $this->crawledExternalBusiness();
        $key = \App\Models\ExternalSitePlanItem::keyFor('description_missing');

        $this->post($this->url('external.plan.update', $workspace, $business, ['itemKey' => $key]), ['rule_key' => 'description_missing', 'status' => 'waiting', 'note' => 'sent to our designer'])->assertRedirect();

        $item = \App\Models\ExternalSitePlanItem::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertSame('waiting', $item->status);
        $this->assertSame('sent to our designer', $item->note);
        $this->assertNull($item->resolved_in_crawl_id, 'The owner saying so confirms nothing.');

        $html = $this->get($this->url('external.plan', $workspace, $business))->assertOk()->getContent();
        $this->assertStringContainsString('sent to our designer', $html);
        $this->assertStringContainsString('data-status="waiting"', $html);

        // A made-up rule, or a key that is not that rule's hash, creates nothing.
        $this->post($this->url('external.plan.update', $workspace, $business, ['itemKey' => $key]), ['rule_key' => 'not_a_rule', 'status' => 'done'])->assertNotFound();
        $this->post($this->url('external.plan.update', $workspace, $business, ['itemKey' => str_repeat('a', 40)]), ['rule_key' => 'description_missing', 'status' => 'done'])->assertNotFound();
        $this->post($this->url('external.plan.update', $workspace, $business, ['itemKey' => $key]), ['rule_key' => 'description_missing', 'status' => 'bogus'])->assertSessionHasErrors('status');
        $this->assertSame(1, \App\Models\ExternalSitePlanItem::query()->count());
    }

    public function test_the_status_endpoint_reports_a_running_check_without_any_page_reload(): void
    {
        [$business, $workspace] = $this->crawledExternalBusiness();
        Queue::fake();
        DB::table('external_site_crawls')->where('business_id', $business->id)->update(['created_at' => now()->subHour()]);

        $idle = $this->getJson($this->url('external.status', $workspace, $business))->assertOk()->json();
        $this->assertFalse($idle['active']);
        $this->assertSame('completed', $idle['status']);

        $this->postJson($this->url('external.crawl', $workspace, $business))->assertOk()->assertJsonPath('state', 'queued')->assertJsonPath('active', true);
        ExternalSiteCrawl::query()->where('business_id', $business->id)->where('status', 'queued')->update(['status' => 'running', 'stage' => 'crawling', 'progress_discovered' => 12, 'progress_checked' => 7, 'heartbeat_at' => now()]);

        $running = $this->getJson($this->url('external.status', $workspace, $business))->assertOk()->json();
        $this->assertTrue($running['active']);
        $this->assertSame(12, $running['discovered']);
        $this->assertSame(7, $running['checked']);
        $this->assertSame('Checking pages', $running['stage_label']);
        $this->assertNotSame($idle['token'], $running['token']);
        $this->assertArrayNotHasKey('findings', $running);
    }

    public function test_while_a_new_check_runs_the_previous_report_stays_visible_and_is_labelled_as_such(): void
    {
        [$business, $workspace] = $this->crawledExternalBusiness();
        $previous = ExternalSiteCrawl::query()->where('business_id', $business->id)->firstOrFail();
        DB::table('external_site_crawls')->where('id', $previous->id)->update(['created_at' => now()->subHour()]);
        Queue::fake();
        $this->post($this->url('external.crawl', $workspace, $business))->assertRedirect();
        ExternalSiteCrawl::query()->where('business_id', $business->id)->where('status', 'queued')->update(['status' => 'running', 'stage' => 'crawling', 'progress_discovered' => 9, 'progress_checked' => 4, 'heartbeat_at' => now()]);

        foreach (['external.overview', 'external.audit', 'external.plan', 'external.pages'] as $screen) {
            $html = $this->get($this->url($screen, $workspace, $business))->assertOk()->getContent();

            $this->assertStringContainsString('data-role="status-running"', $html, $screen);
            $this->assertStringContainsString('Showing results from', $html, $screen);
            $this->assertStringContainsString('A new check is running', $html, $screen);
            $this->assertStringContainsString('Checking pages', $html, $screen);
            $this->assertStringContainsString('Cancel check', $html, $screen);
            $this->assertStringNotContainsString('data-role="check-again"', $html, 'No second check can be started while one runs: '.$screen);
        }

        $overview = $this->get($this->url('external.overview', $workspace, $business))->getContent();
        $this->assertStringContainsString('data-role="fix-first"', $overview, 'The previous results are still there.');
        $this->assertStringContainsString('<span data-live="checked">4</span>', $overview);
    }

    public function test_a_crawler_block_is_explained_as_access_not_as_an_outage(): void
    {
        Queue::fake();
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $site = new \Tests\Support\ExternalSite\FakeSite(app(FixtureExternalSiteTransport::class), app(\App\Library\ExternalSite\Fixtures\FixtureHostResolver::class), 'guarded.example');
        $site->healthy()->blocked('/', 403);

        $this->post($this->url('mode.choose', $workspace, $business), ['mode' => 'external', 'website_url' => 'guarded.example'])->assertRedirect();
        app(ExternalSiteAuditRunner::class)->run(ExternalSiteCrawl::query()->where('business_id', $business->id)->firstOrFail());

        $html = $this->get($this->url('external.overview', $workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('Our crawler was turned away', $html);
        $this->assertStringContainsString('says nothing about whether your website works for visitors', $html);
        $this->assertStringNotContainsString('cannot see your website', $html);
        $this->assertStringNotContainsString('Visitors and search engines cannot', $html);
        $this->assertStringContainsString('data-fault="crawler"', $html);
        $this->assertStringContainsString('Check again now', $html, 'A safe retry is offered.');
        $this->assertStringNotContainsString('data-role="fix-first"', $html);
    }

    public function test_the_settings_screen_has_connection_controls_history_and_site_management(): void
    {
        [$business, $workspace] = $this->crawledExternalBusiness();

        $html = $this->get($this->url('external.settings', $workspace, $business))->assertOk()->getContent();

        foreach (['Website connection', 'Current website address', 'Normalised address', 'Effective address after redirects', 'Last connection result', 'Test connection now', 'Audit controls', 'Crawl state', 'Crawl budget', 'Audit frequency', 'Last completed audit', 'Audit history', 'Stop monitoring this website', 'Build a website with MotionGrove'] as $part) {
            $this->assertStringContainsString($part, $html, $part);
        }
        $this->assertStringContainsString('Website reachable', $html);
        $this->assertStringContainsString('Up to 40 pages per check', $html);
        $this->assertStringContainsString('never signs in', $html);
        $this->assertSame(1, substr_count($html, 'data-role="check-row"'));
    }

    public function test_testing_the_connection_reports_the_real_result_and_never_takes_a_url(): void
    {
        [$business, $workspace] = $this->crawledExternalBusiness();

        $this->post($this->url('external.settings.test', $workspace, $business), ['website_url' => 'http://169.254.169.254/'])
            ->assertRedirect($this->url('external.settings', $workspace, $business))
            ->assertSessionHas('status', 'success');

        $this->assertNotContains('http://169.254.169.254/', app(FixtureExternalSiteTransport::class)->requestedUrls());
        $this->assertStringContainsString('could be reached', (string) session('message'));
    }

    public function test_stopping_monitoring_cancels_a_running_check_and_keeps_the_history(): void
    {
        [$business, $workspace] = $this->crawledExternalBusiness();
        $crawls = ExternalSiteCrawl::query()->where('business_id', $business->id)->count();
        ExternalSiteCrawl::query()->create(['business_id' => $business->id, 'start_url' => FixtureSite::BASE.'/', 'host' => FixtureSite::HOST, 'status' => 'running', 'trigger' => 'manual', 'active_lock' => $business->id, 'heartbeat_at' => now()]);

        $this->post($this->url('external.stop', $workspace, $business))->assertRedirect($this->url('show', $workspace, $business));

        $this->assertSame('none', $business->fresh()->website_mode);
        $this->assertSame($crawls + 1, ExternalSiteCrawl::query()->where('business_id', $business->id)->count(), 'No history is deleted.');
        $this->assertSame(0, ExternalSiteCrawl::query()->where('business_id', $business->id)->whereIn('status', ['queued', 'running'])->count());
    }

    public function test_recrawl_is_requested_without_a_url_and_is_throttled_and_deduplicated(): void
    {
        [$business, $workspace] = $this->crawledExternalBusiness();
        Queue::fake();

        $this->post($this->url('external.crawl', $workspace, $business), ['website_url' => 'http://169.254.169.254/'])
            ->assertRedirect()->assertSessionHas('status', 'info');
        Queue::assertNotPushed(CrawlExternalSite::class);

        DB::table('external_site_crawls')->where('business_id', $business->id)->update(['created_at' => now()->subHour()]);
        $this->post($this->url('external.crawl', $workspace, $business))->assertRedirect()->assertSessionHas('status', 'success');
        Queue::assertPushed(CrawlExternalSite::class, 1);

        // The request never named a URL: the new crawl is of the stored address only.
        $this->assertSame('https://studio-fixture.example/', ExternalSiteCrawl::query()->where('business_id', $business->id)->latest('id')->value('start_url'));

        // Clicking again (or a second person clicking) while it runs hands back the same check.
        $this->post($this->url('external.crawl', $workspace, $business))->assertRedirect()->assertSessionHas('status', 'info');
        $this->post($this->url('external.crawl', $workspace, $business))->assertRedirect();
        Queue::assertPushed(CrawlExternalSite::class, 1);
        $this->assertSame(1, ExternalSiteCrawl::query()->where('business_id', $business->id)->whereIn('status', ['queued', 'running'])->count());
    }

    public function test_changing_the_address_starts_a_new_check_keeps_the_history_and_shows_none_of_the_old_results(): void
    {
        [$business, $workspace] = $this->crawledExternalBusiness();
        Queue::fake();
        DB::table('external_site_crawls')->where('business_id', $business->id)->update(['created_at' => now()->subHour()]);
        $oldCrawl = ExternalSiteCrawl::query()->where('business_id', $business->id)->firstOrFail();
        (new \Tests\Support\ExternalSite\FakeSite(app(FixtureExternalSiteTransport::class), app(\App\Library\ExternalSite\Fixtures\FixtureHostResolver::class), 'brandnew.example'))->healthy();

        $this->post($this->url('external.settings.update', $workspace, $business), ['website_url' => 'brandnew.example'])->assertRedirect($this->url('external.settings', $workspace, $business));

        $business = $business->fresh();
        $this->assertSame('external', $business->website_mode);
        $this->assertStringContainsString('brandnew.example', (string) $business->website_url);
        $this->assertSame('brandnew.example', $business->canonical_domain, 'The canonical Business record follows the address.');
        $this->assertSame(1, ExternalSiteCrawl::query()->where('business_id', $business->id)->where('trigger', 'url_change')->count());
        $this->assertSame('completed', $oldCrawl->fresh()->status, 'The old audit is untouched.');

        $overview = $this->get($this->url('external.overview', $workspace, $business))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-role="fix-first"', $overview, 'No result of the old address appears under the new one.');
        $this->assertStringNotContainsString('studio-fixture.example/old-page', $overview);

        $settings = $this->get($this->url('external.settings', $workspace, $business))->assertOk()->getContent();
        $this->assertStringContainsString('studio-fixture.example', $settings, 'The old address stays in the history.');
        $this->assertStringContainsString('(earlier address)', $settings);

        $this->post($this->url('external.settings.update', $workspace, $business), ['website_url' => 'http://localhost/'])->assertSessionHas('status', 'error');
        $this->assertStringContainsString('brandnew.example', (string) $business->fresh()->website_url);
    }

    public function test_an_address_that_redirects_to_a_different_website_offers_to_use_it_instead_of_failing_silently(): void
    {
        Queue::fake();
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $site = new \Tests\Support\ExternalSite\FakeSite(app(FixtureExternalSiteTransport::class), app(\App\Library\ExternalSite\Fixtures\FixtureHostResolver::class), 'oldbrand.example');
        $site->redirect('/', 'https://newbrand.example/');
        app(\App\Library\ExternalSite\Fixtures\FixtureHostResolver::class)->set('newbrand.example', ['93.184.216.34']);

        $this->post($this->url('mode.choose', $workspace, $business), ['mode' => 'external', 'website_url' => 'oldbrand.example'])->assertRedirect()
            ->assertSessionHas('message');
        $this->assertStringContainsString('redirects to a different website', (string) session('message'));
        app(ExternalSiteAuditRunner::class)->run(ExternalSiteCrawl::query()->where('business_id', $business->id)->firstOrFail());

        $html = $this->get($this->url('external.settings', $workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('Redirects to another website', $html);
        $this->assertStringContainsString('data-role="use-redirect-target"', $html);
        $this->assertStringContainsString('Use newbrand.example as my website address', $html);
    }

    public function test_a_business_that_builds_with_motiongrove_never_sees_the_external_screens(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->post($this->url('mode.choose', $workspace, $business), ['mode' => 'hosted']);

        foreach (['external.overview', 'external.audit', 'external.plan', 'external.pages', 'external.settings'] as $name) {
            $this->get($this->url($name, $workspace, $business))->assertRedirect($this->url('show', $workspace, $business));
        }
    }

    public function test_another_business_cannot_read_this_businesss_external_audit(): void
    {
        [$business, $workspace] = $this->crawledExternalBusiness();
        $crawl = ExternalSiteCrawl::query()->where('business_id', $business->id)->firstOrFail();
        $page = $crawl->pages()->firstOrFail();

        [$otherCustomer, $other, $otherWorkspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($otherCustomer);
        $this->post($this->url('mode.choose', $otherWorkspace, $other), ['mode' => 'external', 'website_url' => 'studio-fixture.example']);

        // My page id through THEIR business resolves to nothing; my business through their session is a 404.
        $this->get($this->url('external.page', $otherWorkspace, $other, ['pageId' => $page->id]))->assertNotFound();
        $this->get($this->url('external.overview', $workspace, $business))->assertNotFound();
    }

    public function test_a_core_style_member_without_the_website_capability_is_refused(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer, []);

        $this->get($this->url('external.overview', $workspace, $business))->assertStatus(401);
        $this->post($this->url('mode.choose', $workspace, $business), ['mode' => 'none'])->assertStatus(401);
    }

    public function test_a_hosted_business_with_an_existing_website_is_unchanged(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->createWebsite($business);

        // No stored mode, but a Website record exists: it is hosted, with no choice screen and no redirect.
        $response = $this->get($this->url('show', $workspace, $business));
        $this->assertNotSame(404, $response->getStatusCode());
        $response->assertDontSee('Use my existing website');
    }

    public function test_an_external_business_cannot_start_the_hosted_setup_by_address(): void
    {
        [$business, $workspace] = $this->crawledExternalBusiness();

        $this->get($this->url('setup.start', $workspace, $business))->assertRedirect($this->url('external.overview', $workspace, $business));

        $this->assertSame(0, \App\Models\Website::where('business_id', $business->id)->count(), 'no hosted shell is created');
        $this->assertSame(0, \App\Models\QuestionnaireResponse::where('business_id', $business->id)->count(), 'no setup session is created');
        $this->assertSame('external', $business->fresh()->website_mode);
    }

    public function test_an_external_business_never_reaches_the_hosted_settings_redesign(): void
    {
        [$business, $workspace] = $this->crawledExternalBusiness();

        // The hosted Studio (and its Settings tab) is not an external screen: it hands the owner to the external overview.
        $this->get(route('customer.workspaces.businesses.website.studio.show', [$workspace->uid, $business->uid, 'settings']))
            ->assertRedirect($this->url('external.overview', $workspace, $business));

        $html = $this->get($this->url('external.settings', $workspace, $business))->assertOk()->getContent();

        foreach (['data-testid="studio-look"', 'data-testid="settings-domain"', 'data-testid="settings-template"', 'data-testid="settings-domain-card"', 'Your website\'s look', 'Change template or rebuild', 'Manage your website'] as $hostedOnly) {
            $this->assertStringNotContainsString($hostedOnly, $html, $hostedOnly);
        }
    }
}

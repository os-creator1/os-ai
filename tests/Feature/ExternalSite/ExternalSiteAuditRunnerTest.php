<?php

namespace Tests\Feature\ExternalSite;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Jobs\ExternalSite\CrawlExternalSite;
use App\Library\ExternalSite\ExternalSiteAuditRunner;
use App\Library\ExternalSite\ExternalSiteAuditSource;
use App\Library\ExternalSite\ExternalSiteConfig;
use App\Library\ExternalSite\ExternalSiteCrawlManager;
use App\Library\ExternalSite\ExternalSiteException;
use App\Library\ExternalSite\Fixtures\FixtureExternalSiteTransport;
use App\Library\ExternalSite\Fixtures\FixtureSite;
use App\Library\Seo\Audit\HostedRevisionAuditSource;
use App\Library\Seo\SeoAuditEvaluator;
use App\Library\Seo\SeoAuditRuleRegistry;
use App\Library\Seo\SeoPublishedContent;
use App\Library\Seo\SeoPublishedPage;
use App\Models\Business;
use App\Models\ExternalSiteCrawl;
use App\Models\ExternalSitePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * External Website Audit Mode V1: a queued crawl of the fixture external site is
 * stored and audited by the ONE SeoAuditEvaluator; the manager throttles and
 * bounds requests; a refused URL fails with a code only.
 */
class ExternalSiteAuditRunnerTest extends TestCase
{
    use CreatesCustomerContextFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['external_site_audit.driver' => 'fake', 'external_site_audit.request_delay_ms' => 0]);
        $this->app->forgetInstance(ExternalSiteConfig::class);
        $this->app->forgetInstance(FixtureExternalSiteTransport::class);
    }

    private function externalBusiness(string $url = FixtureSite::BASE): Business
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);
        DB::table('businesses')->where('id', $business->id)->update(['website_mode' => 'external', 'website_url' => $url]);

        return $business->fresh();
    }

    private function crawlNow(Business $business, string $trigger = 'manual'): ExternalSiteCrawl
    {
        Queue::fake();
        $result = app(ExternalSiteCrawlManager::class)->request($business, $trigger);
        $crawl = $result['crawl'];
        app(ExternalSiteAuditRunner::class)->run($crawl);

        return $crawl->fresh();
    }

    /** @return list<string> */
    private function ruleKeys(ExternalSiteCrawl $crawl): array
    {
        return $crawl->findings()->pluck('rule_key')->all();
    }

    public function test_the_fixture_site_is_crawled_stored_and_audited_by_the_one_evaluator(): void
    {
        $crawl = $this->crawlNow($this->externalBusiness());

        $this->assertSame('completed', $crawl->status);
        $this->assertSame('indexable', $crawl->indexability);
        $this->assertSame(7, $crawl->pages_fetched);
        $this->assertSame(1, $crawl->broken_links);
        $this->assertSame(1, $crawl->critical_count);

        $keys = $this->ruleKeys($crawl);
        foreach ([
            SeoAuditRuleRegistry::PAGE_NOT_REACHABLE, SeoAuditRuleRegistry::DUPLICATE_SEO_TITLE, SeoAuditRuleRegistry::META_DESCRIPTION_BLANK,
            SeoAuditRuleRegistry::H1_MISSING, SeoAuditRuleRegistry::H1_MULTIPLE, SeoAuditRuleRegistry::CANONICAL_MISSING,
            SeoAuditRuleRegistry::OPEN_GRAPH_MISSING, SeoAuditRuleRegistry::PAGE_MARKED_NOINDEX, SeoAuditRuleRegistry::SEO_TITLE_OVER_RECOMMENDED,
            SeoAuditRuleRegistry::BROKEN_INTERNAL_LINK, SeoAuditRuleRegistry::ASSET_MISSING_ALT,
        ] as $expected) {
            $this->assertContains($expected, $keys, $expected);
        }

        $this->assertNotContains(SeoAuditRuleRegistry::STRUCTURED_DATA_MISSING, $keys, 'The home page has structured data.');
        $this->assertSame(2, collect($keys)->filter(fn ($k) => $k === SeoAuditRuleRegistry::DUPLICATE_SEO_TITLE)->count(), 'Both pages sharing the title are flagged.');

        // Every finding's words come from the registry: the stored row has no sentence.
        $this->assertEqualsCanonicalizing(['id', 'crawl_id', 'page_id', 'rule_key', 'severity', 'facts', 'created_at'], \Illuminate\Support\Facades\Schema::getColumnListing('external_site_findings'));
    }

    public function test_disallowed_pages_and_foreign_links_never_reach_storage(): void
    {
        $crawl = $this->crawlNow($this->externalBusiness());
        $urls = ExternalSitePage::query()->where('crawl_id', $crawl->id)->pluck('url')->all();

        $this->assertNotContains(FixtureSite::BASE.'/private/secret', $urls);
        $this->assertNotContains('https://facebook.com/clay-kids', $urls);
        $this->assertContains(FixtureSite::BASE.'/extra', $urls);
    }

    public function test_hosted_and_external_sources_feed_the_same_rules_to_the_same_result(): void
    {
        $hosted = new SeoPublishedContent(1, 1, [
            new SeoPublishedPage('a', 'a', true, 'Alpha', 'Same title', null, false, []),
            new SeoPublishedPage('b', 'b', false, 'Beta', 'Same title', 'A reasonable description that is long enough to count as a real summary of the page.', true, []),
        ], [], '');
        $evaluator = app(SeoAuditEvaluator::class);

        $fromHosted = collect($evaluator->evaluate($hosted))->map(fn ($d) => $d->ruleKey)->sort()->values()->all();

        $business = $this->externalBusiness();
        $crawl = ExternalSiteCrawl::query()->create(['business_id' => $business->id, 'start_url' => FixtureSite::BASE, 'host' => FixtureSite::HOST, 'status' => 'completed', 'trigger' => 'manual']);
        foreach ([['/a', 'Same title', null, 0], ['/b', 'Same title', 'A reasonable description that is long enough to count as a real summary of the page.', 1]] as [$path, $title, $description, $noindex]) {
            ExternalSitePage::query()->create([
                'crawl_id' => $crawl->id, 'business_id' => $business->id, 'url_hash' => sha1($path), 'url' => FixtureSite::BASE.$path, 'http_status' => 200,
                'title' => $title, 'meta_description' => $description, 'noindex' => (bool) $noindex, 'h1_count' => 1, 'canonical_url' => FixtureSite::BASE.$path, 'has_open_graph' => true, 'has_json_ld' => true,
            ]);
        }
        $fromExternal = collect($evaluator->evaluateFacts(ExternalSiteAuditSource::factsFor($crawl->pages()->orderBy('id')->get())))->map(fn ($d) => $d->ruleKey)->sort()->values()->all();

        $this->assertSame($fromHosted, $fromExternal, 'One rule set judges both: the same content gives the same findings whichever source supplied it.');
        $this->assertContains(SeoAuditRuleRegistry::DUPLICATE_SEO_TITLE, $fromExternal);
    }

    public function test_a_hosted_source_never_supplies_external_only_facts_so_those_rules_cannot_fire_on_a_hosted_site(): void
    {
        $facts = HostedRevisionAuditSource::factsFor(new SeoPublishedContent(1, 1, [new SeoPublishedPage('a', 'a', true, 'Alpha', 'T', 'D', false, [])], [], 'Biz'));

        $this->assertNull($facts->pages[0]->httpStatus);
        $this->assertNull($facts->pages[0]->h1Count);
        $this->assertNull($facts->pages[0]->hasCanonical);
        $this->assertNull($facts->pages[0]->hasOpenGraph);
        $this->assertNull($facts->pages[0]->hasStructuredData);

        $external = collect(app(SeoAuditEvaluator::class)->evaluateFacts($facts))->map(fn ($d) => $d->ruleKey)->all();
        foreach (SeoAuditRuleRegistry::externalRuleKeys() as $key) {
            $this->assertNotContains($key, $external);
        }
    }

    public function test_there_is_exactly_one_evaluator_and_one_registry(): void
    {
        $files = collect(glob(dirname(__DIR__, 3).'/app/Library/**/*.php') ?: [])->merge(glob(dirname(__DIR__, 3).'/app/Library/*/*/*.php') ?: []);
        $audit = $files->filter(fn (string $f) => preg_match('/Audit.*(Evaluator|RuleRegistry|Rules)\.php$/', basename($f)) === 1)->map(fn ($f) => basename($f))->unique()->sort()->values()->all();
        $external = $files->filter(fn (string $f) => preg_match('/External.*(Evaluator|Rule|Engine)/', basename($f)) === 1)->all();

        $this->assertSame(['SeoAuditEvaluator.php', 'SeoAuditRuleRegistry.php'], $audit);
        $this->assertSame([], $external, 'No ExternalSeoEvaluator / ExternalSeoRules / ExternalWebsiteAuditEngine may exist.');

        foreach (glob(dirname(__DIR__, 3).'/app/Library/ExternalSite/*.php') as $file) {
            $this->assertStringNotContainsString("'seo_title_blank'", file_get_contents($file), basename($file).' must not re-implement rules.');
        }
    }

    public function test_a_refused_url_fails_the_crawl_with_a_code_only(): void
    {
        foreach (['http://127.0.0.1/', 'http://localhost/', 'http://[::1]/', 'http://169.254.169.254/'] as $url) {
            $business = $this->externalBusiness();
            DB::table('businesses')->where('id', $business->id)->update(['website_url' => $url]);
            $crawl = $this->crawlNow($business->fresh());

            $this->assertSame('failed', $crawl->status, $url);
            $this->assertContains($crawl->failure_code, ['ip_literal_not_allowed', 'host_not_allowed'], $url);
            $this->assertSame(0, ExternalSitePage::query()->where('crawl_id', $crawl->id)->count());
        }

        $this->assertSame([], app(FixtureExternalSiteTransport::class)->requests, 'A refused address is never even attempted.');
    }

    public function test_a_second_run_of_a_finished_crawl_does_nothing(): void
    {
        $crawl = $this->crawlNow($this->externalBusiness());
        $before = ExternalSitePage::query()->where('crawl_id', $crawl->id)->count();
        $requests = count(app(FixtureExternalSiteTransport::class)->requests);

        app(ExternalSiteAuditRunner::class)->run($crawl);

        $this->assertSame($before, ExternalSitePage::query()->where('crawl_id', $crawl->id)->count());
        $this->assertCount($requests, app(FixtureExternalSiteTransport::class)->requests);
    }

    public function test_the_manager_dispatches_one_job_throttles_manual_requests_and_requires_a_url(): void
    {
        Queue::fake();
        $business = $this->externalBusiness();
        $manager = app(ExternalSiteCrawlManager::class);

        $first = $manager->request($business);
        $this->assertSame('queued', $first['state']);
        Queue::assertPushed(CrawlExternalSite::class, 1);

        // A second request while one is active returns it and queues nothing.
        $again = $manager->request($business);
        $this->assertSame('already_running', $again['state']);
        $this->assertSame($first['crawl']->id, $again['crawl']->id);
        Queue::assertPushed(CrawlExternalSite::class, 1);

        // Once finished, a MANUAL request inside the throttle window is refused with a wait time.
        $first['crawl']->forceFill(['status' => 'completed'])->save();
        $throttled = $manager->request($business);
        $this->assertSame('throttled', $throttled['state']);
        $this->assertGreaterThan(0, $throttled['retry_after_minutes']);
        Queue::assertPushed(CrawlExternalSite::class, 1);

        // A scheduled crawl is not subject to the manual throttle.
        $this->assertSame('queued', $manager->request($business, 'scheduled')['state']);

        $this->expectException(ExternalSiteException::class);
        $none = $this->externalBusiness('');
        $manager->request($none);
    }

    public function test_a_crawl_stuck_for_too_long_is_failed_so_it_cannot_block_the_business(): void
    {
        Queue::fake();
        $business = $this->externalBusiness();
        $stuck = ExternalSiteCrawl::query()->create(['business_id' => $business->id, 'start_url' => FixtureSite::BASE, 'host' => FixtureSite::HOST, 'status' => 'running', 'trigger' => 'manual']);
        DB::table('external_site_crawls')->where('id', $stuck->id)->update(['created_at' => now()->subHours(2)]);

        $result = app(ExternalSiteCrawlManager::class)->request($business, 'scheduled');

        $this->assertSame('queued', $result['state']);
        $this->assertSame('failed', $stuck->fresh()->status);
        $this->assertSame('stale', $stuck->fresh()->failure_code);
    }

    public function test_only_the_latest_crawls_are_kept(): void
    {
        $business = $this->externalBusiness();
        config(['external_site_audit.retained_crawls' => 2]);
        $this->app->forgetInstance(ExternalSiteConfig::class);

        for ($i = 0; $i < 4; $i++) {
            ExternalSiteCrawl::query()->where('business_id', $business->id)->update(['created_at' => now()->subHour()]);
            $this->crawlNow($business, 'scheduled');
        }

        $this->assertSame(2, ExternalSiteCrawl::query()->where('business_id', $business->id)->count());
    }
}

<?php

namespace Tests\Feature\Seo;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Seo\SeoAuditRunStatus;
use App\Enums\Seo\SeoAuditSeverity;
use App\Enums\Seo\SeoIndexabilityState;
use App\Events\Website\WebsitePublished;
use App\Jobs\Seo\RunSeoAuditForRevision;
use App\Library\Seo\SeoAuditPageReader;
use App\Library\Seo\SeoAuditRuleRegistry;
use App\Library\Seo\SeoAuditRunner;
use App\Models\SeoAuditFinding;
use App\Models\SeoAuditRun;
use App\Models\Website;
use App\Models\WebsiteRevision;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Feature\Seo\Concerns\CreatesSeoFixtures;
use Tests\TestCase;

/**
 * Contract 18 Sub-slice 18G §8.7/§15.G — the Technical / Website SEO audit.
 *
 * Every rule is proved against a positive AND a negative fixture revision, the
 * audit is proved read-only and idempotent, pruning is proved to keep exactly
 * the latest five, and the platform's own limitations are proved NOT to
 * appear as customer findings.
 */
class SeoAuditTest extends TestCase
{
    use RefreshDatabase;
    use CreatesSeoFixtures;

    private function runner(): SeoAuditRunner
    {
        return app(SeoAuditRunner::class);
    }

    /**
     * Publish a revision and audit it. Returns [$business, $website, $run].
     *
     * @param  array<int, array<string, mixed>>  $pages
     * @param  array<int, array<string, mixed>>  $assets
     */
    private function audit(array $pages, array $assets = []): array
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $website = $this->publishWebsite($business, $pages, $assets);

        $run = $this->runner()->runForRevision(
            (int) $business->id,
            (int) $website->id,
            (int) $website->published_revision_id,
        );

        return [$business, $website, $run];
    }

    /** @return array<int, string> the rule keys the run produced, in order */
    private function ruleKeys(SeoAuditRun $run): array
    {
        return SeoAuditFinding::query()
            ->where('seo_audit_run_id', $run->id)
            ->orderBy('id')
            ->pluck('rule_key')
            ->all();
    }

    private function goodPage(string $uid = 'page-good'): array
    {
        return $this->snapshotPage($uid, 'Home', [
            'seo_title' => 'A perfectly reasonable title',
            'meta_description' => 'A meta description that is comfortably longer than the recommended minimum length for search results.',
            'noindex' => false,
        ]);
    }

    // -----------------------------------------------------------------
    // Every closed rule, positive and negative.
    // -----------------------------------------------------------------

    public function test_a_clean_page_produces_no_findings_at_all(): void
    {
        [, , $run] = $this->audit([$this->goodPage()]);

        $this->assertSame(SeoAuditRunStatus::Completed, $run->status);
        $this->assertSame(1, $run->page_count);
        $this->assertSame([], $this->ruleKeys($run));
        $this->assertSame(0, $run->totalFindings());
    }

    public function test_seo_title_blank_fires_only_when_the_seo_title_is_blank(): void
    {
        [, , $blank] = $this->audit([$this->snapshotPage('p1', 'Home', [
            'seo_title' => '   ',
            'meta_description' => str_repeat('a', 80),
        ])]);

        $this->assertContains(SeoAuditRuleRegistry::SEO_TITLE_BLANK, $this->ruleKeys($blank));
        $this->assertNotContains(SeoAuditRuleRegistry::SEO_TITLE_BLANK, $this->ruleKeys($this->audit([$this->goodPage()])[2]));
    }

    public function test_seo_title_over_recommended_fires_only_above_the_configured_maximum(): void
    {
        [, , $over] = $this->audit([$this->snapshotPage('p1', 'Home', [
            'seo_title' => str_repeat('x', 61),
            'meta_description' => str_repeat('a', 80),
        ])]);
        $this->assertContains(SeoAuditRuleRegistry::SEO_TITLE_OVER_RECOMMENDED, $this->ruleKeys($over));

        // Exactly at the threshold is fine: the rule is "> 60".
        [, , $at] = $this->audit([$this->snapshotPage('p1', 'Home', [
            'seo_title' => str_repeat('x', 60),
            'meta_description' => str_repeat('a', 80),
        ])]);
        $this->assertNotContains(SeoAuditRuleRegistry::SEO_TITLE_OVER_RECOMMENDED, $this->ruleKeys($at));
    }

    public function test_meta_description_blank_and_short_fire_on_their_own_boundaries(): void
    {
        [, , $blank] = $this->audit([$this->snapshotPage('p1', 'Home', ['seo_title' => 'Fine title'])]);
        $this->assertContains(SeoAuditRuleRegistry::META_DESCRIPTION_BLANK, $this->ruleKeys($blank));
        $this->assertNotContains(SeoAuditRuleRegistry::META_DESCRIPTION_SHORT, $this->ruleKeys($blank));

        [, , $short] = $this->audit([$this->snapshotPage('p1', 'Home', [
            'seo_title' => 'Fine title',
            'meta_description' => str_repeat('a', 69),
        ])]);
        $this->assertContains(SeoAuditRuleRegistry::META_DESCRIPTION_SHORT, $this->ruleKeys($short));

        [, , $ok] = $this->audit([$this->snapshotPage('p1', 'Home', [
            'seo_title' => 'Fine title',
            'meta_description' => str_repeat('a', 70),
        ])]);
        $this->assertNotContains(SeoAuditRuleRegistry::META_DESCRIPTION_SHORT, $this->ruleKeys($ok));
        $this->assertNotContains(SeoAuditRuleRegistry::META_DESCRIPTION_BLANK, $this->ruleKeys($ok));
    }

    public function test_duplicate_seo_title_and_meta_description_fire_on_each_sharing_page(): void
    {
        $seo = ['seo_title' => 'Shared title', 'meta_description' => str_repeat('b', 80)];

        [, , $run] = $this->audit([
            $this->snapshotPage('p1', 'One', $seo),
            $this->snapshotPage('p2', 'Two', $seo),
        ]);

        $keys = $this->ruleKeys($run);
        $this->assertSame(2, count(array_filter($keys, fn ($k) => $k === SeoAuditRuleRegistry::DUPLICATE_SEO_TITLE)));
        $this->assertSame(2, count(array_filter($keys, fn ($k) => $k === SeoAuditRuleRegistry::DUPLICATE_META_DESCRIPTION)));

        $finding = SeoAuditFinding::query()->where('rule_key', SeoAuditRuleRegistry::DUPLICATE_SEO_TITLE)->first();
        $this->assertSame(1, $finding->facts['shared_by'], 'One other page shares it.');

        // Distinct values on both pages: no duplicate findings.
        [, , $distinct] = $this->audit([
            $this->snapshotPage('p1', 'One', ['seo_title' => 'Title one', 'meta_description' => str_repeat('c', 80)]),
            $this->snapshotPage('p2', 'Two', ['seo_title' => 'Title two', 'meta_description' => str_repeat('d', 80)]),
        ]);
        $this->assertNotContains(SeoAuditRuleRegistry::DUPLICATE_SEO_TITLE, $this->ruleKeys($distinct));
        $this->assertNotContains(SeoAuditRuleRegistry::DUPLICATE_META_DESCRIPTION, $this->ruleKeys($distinct));
    }

    public function test_page_marked_noindex_fires_only_for_a_page_the_customer_marked(): void
    {
        [, , $marked] = $this->audit([$this->snapshotPage('p1', 'Hidden', [
            'seo_title' => 'Fine title',
            'meta_description' => str_repeat('a', 80),
            'noindex' => true,
        ])]);
        $this->assertContains(SeoAuditRuleRegistry::PAGE_MARKED_NOINDEX, $this->ruleKeys($marked));

        $this->assertNotContains(SeoAuditRuleRegistry::PAGE_MARKED_NOINDEX, $this->ruleKeys($this->audit([$this->goodPage()])[2]));
    }

    public function test_asset_missing_alt_is_one_site_level_finding_with_a_count(): void
    {
        [, , $run] = $this->audit([$this->goodPage()], [
            ['uid' => 'a1', 'alt_text' => null],
            ['uid' => 'a2', 'alt_text' => '  '],
            ['uid' => 'a3', 'alt_text' => 'A described image'],
        ]);

        $finding = SeoAuditFinding::query()->where('rule_key', SeoAuditRuleRegistry::ASSET_MISSING_ALT)->sole();

        $this->assertNull($finding->page_uid, 'It is a site-level finding.');
        $this->assertSame(2, $finding->facts['asset_count']);

        // All assets described: no finding.
        [, , $clean] = $this->audit([$this->goodPage()], [['uid' => 'a1', 'alt_text' => 'Described']]);
        $this->assertNotContains(SeoAuditRuleRegistry::ASSET_MISSING_ALT, $this->ruleKeys($clean));
    }

    public function test_severity_comes_from_the_registry_and_the_counters_agree(): void
    {
        [, , $run] = $this->audit([$this->snapshotPage('p1', 'Home', ['seo_title' => null, 'meta_description' => null])]);

        // seo_title_blank = info, meta_description_blank = warning.
        $this->assertSame(1, $run->info_count);
        $this->assertSame(1, $run->warning_count);
        $this->assertSame(0, $run->critical_count);
        $this->assertSame(2, $run->totalFindings());

        foreach (SeoAuditFinding::query()->get() as $finding) {
            $this->assertSame(
                SeoAuditRuleRegistry::severityFor((string) $finding->rule_key),
                $finding->severity,
                'A stored severity must be the registry\'s, never the caller\'s.'
            );
        }
    }

    // -----------------------------------------------------------------
    // Registry-owned text only.
    // -----------------------------------------------------------------

    public function test_only_registry_rule_keys_can_ever_be_stored(): void
    {
        [, , $run] = $this->audit([
            $this->snapshotPage('p1', 'One', ['seo_title' => str_repeat('x', 80)]),
            $this->snapshotPage('p2', 'Two', ['seo_title' => str_repeat('x', 80)]),
        ], [['uid' => 'a1', 'alt_text' => null]]);

        foreach ($this->ruleKeys($run) as $key) {
            $this->assertTrue(SeoAuditRuleRegistry::has($key), "[{$key}] is not a registry rule.");
        }
    }

    public function test_no_finding_text_echoes_page_content(): void
    {
        $secret = 'CONFIDENTIAL-BODY-PHRASE-9271';

        [$business] = $this->audit([
            $this->snapshotPage('p1', $secret, [
                'seo_title' => $secret . ' ' . str_repeat('x', 80),
                'meta_description' => $secret,
            ], [
                ['type' => 'text', 'data' => ['heading' => $secret, 'body' => $secret]],
            ]),
        ]);

        $run = SeoAuditRun::query()->sole();

        foreach (SeoAuditFinding::query()->get() as $finding) {
            $rendered = SeoAuditRuleRegistry::describe((string) $finding->rule_key, $finding->facts);

            $this->assertStringNotContainsString($secret, $rendered, 'A finding must never echo page text.');
            $this->assertStringNotContainsString($secret, json_encode($finding->facts), 'Facts must never carry page text.');
        }

        // And nothing anywhere in the stored rows carries it either.
        $this->assertStringNotContainsString($secret, SeoAuditFinding::query()->get()->toJson());
        $this->assertStringNotContainsString($secret, $run->toJson());
    }

    public function test_the_registry_refuses_an_unknown_rule_an_unknown_fact_and_a_non_scalar_fact(): void
    {
        $this->assertFalse(SeoAuditRuleRegistry::has('invented_rule'));

        try {
            SeoAuditRuleRegistry::describe('invented_rule', []);
            $this->fail('An unknown rule key must be refused.');
        } catch (\InvalidArgumentException) {
        }

        try {
            SeoAuditRuleRegistry::validateFacts(SeoAuditRuleRegistry::ASSET_MISSING_ALT, ['not_declared' => 1]);
            $this->fail('An undeclared fact key must be refused.');
        } catch (\InvalidArgumentException) {
        }

        try {
            SeoAuditRuleRegistry::validateFacts(SeoAuditRuleRegistry::ASSET_MISSING_ALT, ['asset_count' => ['an', 'array']]);
            $this->fail('A non-scalar fact must be refused.');
        } catch (\InvalidArgumentException) {
        }
    }

    // -----------------------------------------------------------------
    // Platform limitations are status, never findings.
    // -----------------------------------------------------------------

    public function test_platform_limitations_never_become_findings(): void
    {
        [, , $run] = $this->audit([$this->goodPage()]);

        $forbidden = ['canonical', 'json_ld', 'jsonld', 'structured_data', 'schema_markup', 'sitemap', 'platform_noindex', 'https', 'robots'];

        foreach (SeoAuditRuleRegistry::ruleKeys() as $key) {
            foreach ($forbidden as $word) {
                $this->assertStringNotContainsString(
                    $word,
                    $key,
                    "The registry must not contain a platform-limitation rule [{$key}] (Contract 18 §8.7 G-2/G-3)."
                );
            }
        }

        $this->assertCount(8, SeoAuditRuleRegistry::ruleKeys(), 'The v1 rule set is exactly the eight §8.7 rules.');
        $this->assertSame([], $this->ruleKeys($run));
    }

    public function test_indexability_is_reported_as_a_status_for_a_published_and_an_unpublished_site(): void
    {
        [, $published] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->publishWebsite($published, [$this->goodPage()]);

        $reader = app(SeoAuditPageReader::class);

        $this->assertSame(
            SeoIndexabilityState::PlatformPathNotIndexable,
            $reader->read($published)->indexability,
            'A published platform-path site is not indexable — a status, not a finding.'
        );

        [, $unpublished] = $this->entitledTenant(WorkspacePlanTier::Growth);

        $this->assertSame(SeoIndexabilityState::NoPublishedWebsite, $reader->read($unpublished)->indexability);
    }

    // -----------------------------------------------------------------
    // Read-only, no network.
    // -----------------------------------------------------------------

    public function test_the_audit_reads_the_published_revision_without_mutating_any_website_table(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $website = $this->publishWebsite($business, [
            $this->snapshotPage('p1', 'Home', ['seo_title' => null, 'meta_description' => null]),
        ], [['uid' => 'a1', 'alt_text' => null]]);

        $before = $this->dbFingerprint($this->seoProtectedTables());

        $this->runner()->runForRevision((int) $business->id, (int) $website->id, (int) $website->published_revision_id);

        $this->assertSame(
            $before,
            $this->dbFingerprint($this->seoProtectedTables()),
            'The audit must not write a single byte to any Website/Business/Location table.'
        );
        $this->assertSame(1, SeoAuditRun::query()->count(), 'It did run.');
    }

    public function test_the_audit_makes_no_http_request(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        [, , $run] = $this->audit([$this->snapshotPage('p1', 'Home', ['seo_title' => null])]);

        $this->assertNotNull($run);
        Http::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // Idempotency (§8.7).
    // -----------------------------------------------------------------

    public function test_auditing_the_same_revision_twice_converges_on_one_run(): void
    {
        [$business, $website, $first] = $this->audit([$this->snapshotPage('p1', 'Home', ['seo_title' => null])]);

        $second = $this->runner()->runForRevision((int) $business->id, (int) $website->id, (int) $website->published_revision_id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, SeoAuditRun::query()->count());
        $this->assertSame(
            1,
            SeoAuditFinding::query()->where('rule_key', SeoAuditRuleRegistry::SEO_TITLE_BLANK)->count(),
            'Findings must not be duplicated by a re-run.'
        );
    }

    public function test_a_duplicate_job_for_one_revision_is_safe(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $website = $this->publishWebsite($business, [$this->snapshotPage('p1', 'Home', ['seo_title' => null])]);

        $job = new RunSeoAuditForRevision((int) $business->id, (int) $website->id, (int) $website->published_revision_id);

        $job->handle($this->runner());
        $job->handle($this->runner());
        $job->handle($this->runner());

        $this->assertSame(1, SeoAuditRun::query()->count());
    }

    public function test_a_duplicate_website_published_delivery_is_safe(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $website = $this->publishWebsite($business, [$this->snapshotPage('p1', 'Home', ['seo_title' => null])]);

        $event = new WebsitePublished((int) $website->id, (int) $website->published_revision_id, (int) $business->id);

        // The real listener, twice, with the sync queue actually running the job.
        $listener = app(\App\Listeners\Seo\QueueSeoAuditOnWebsitePublished::class);
        $listener->handle($event);
        $listener->handle($event);

        $this->assertSame(1, SeoAuditRun::query()->count());
    }

    public function test_a_foreign_revision_can_never_be_audited_through_another_business(): void
    {
        [, $mine] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $myWebsite = $this->publishWebsite($mine, [$this->goodPage('mine')]);

        [, $theirs] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $theirWebsite = $this->publishWebsite($theirs, [$this->goodPage('theirs')]);

        // My Website id paired with THEIR revision id: nothing at all.
        $run = $this->runner()->runForRevision(
            (int) $mine->id,
            (int) $myWebsite->id,
            (int) $theirWebsite->published_revision_id,
        );

        $this->assertNull($run);
        $this->assertSame(0, SeoAuditRun::query()->count());
    }

    // -----------------------------------------------------------------
    // Publishing stays authoritative (§8.7: the listener must not fail a publish).
    // -----------------------------------------------------------------

    public function test_a_failing_audit_dispatch_never_fails_the_listener(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $website = $this->publishWebsite($business, [$this->goodPage()]);

        $this->mock(Dispatcher::class, function ($mock): void {
            $mock->shouldReceive('dispatch')->andThrow(new RuntimeException('the queue is down'));
        });

        $listener = app(\App\Listeners\Seo\QueueSeoAuditOnWebsitePublished::class);

        // No exception escapes, so nothing can reach the publishing request.
        $listener->handle(new WebsitePublished(
            (int) $website->id,
            (int) $website->published_revision_id,
            (int) $business->id,
        ));

        $this->assertSame(0, SeoAuditRun::query()->count(), 'Nothing was audited, and that is fine.');
        $this->assertNotNull(Website::query()->find($website->id), 'The publish is untouched.');
    }

    public function test_a_publish_still_succeeds_when_the_audit_listener_throws(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Growth);

        // A listener that throws outright, registered exactly like the real one.
        Event::listen(WebsitePublished::class, function (): void {
            throw new RuntimeException('SEO analysis exploded');
        });

        $website = $this->publishWebsite($business, [$this->goodPage()]);

        $this->assertNotNull($website->published_revision_id);
        $this->assertSame(
            1,
            WebsiteRevision::query()->where('website_id', $website->id)->count(),
            'The published revision survived a throwing listener.'
        );
    }

    public function test_the_listener_queues_the_revision_that_was_published_not_the_current_one(): void
    {
        Queue::fake();

        [, $business] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $website = $this->publishWebsite($business, [$this->goodPage()]);
        $publishedRevisionId = (int) $website->published_revision_id;

        // A newer revision becomes live afterwards.
        $newer = WebsiteRevision::create([
            'website_id' => $website->id,
            'version_number' => 2,
            'snapshot' => ['schema_version' => 1, 'website' => [], 'pages' => [], 'assets' => []],
            'schema_version' => 1,
            'created_by' => $business->customer_id,
        ]);
        $website->update(['published_revision_id' => $newer->id]);

        app(\App\Listeners\Seo\QueueSeoAuditOnWebsitePublished::class)
            ->handle(new WebsitePublished((int) $website->id, $publishedRevisionId, (int) $business->id));

        Queue::assertPushed(
            RunSeoAuditForRevision::class,
            fn (RunSeoAuditForRevision $job): bool => $job->websiteRevisionId === $publishedRevisionId,
        );
    }

    // -----------------------------------------------------------------
    // Retention (§8.7): latest 5 per Website.
    // -----------------------------------------------------------------

    public function test_only_the_latest_five_runs_are_retained_and_the_sixth_prunes_only_the_oldest(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $website = $this->publishWebsite($business, [$this->goodPage()]);

        $runIds = [];

        // Six revisions of the same Website, audited in order.
        for ($i = 1; $i <= 6; $i++) {
            $revision = WebsiteRevision::create([
                'website_id' => $website->id,
                'version_number' => $i + 1,
                'snapshot' => [
                    'schema_version' => 1,
                    'website' => [],
                    'pages' => [$this->snapshotPage('p' . $i, 'Page ' . $i, ['seo_title' => null])],
                    'assets' => [],
                ],
                'schema_version' => 1,
                'created_by' => $business->customer_id,
            ]);

            $run = $this->runner()->runForRevision((int) $business->id, (int) $website->id, (int) $revision->id);
            $runIds[$i] = (int) $run->id;

            if ($i === 5) {
                $this->assertSame(5, SeoAuditRun::query()->count(), 'Five runs fit inside the window.');
            }
        }

        $surviving = SeoAuditRun::query()->orderBy('id')->pluck('id')->all();

        $this->assertCount(5, $surviving, 'Exactly five runs are retained.');
        $this->assertSame([$runIds[2], $runIds[3], $runIds[4], $runIds[5], $runIds[6]], $surviving);
        $this->assertNotContains($runIds[1], $surviving, 'Only the oldest was pruned.');
        $this->assertContains($runIds[6], $surviving, 'The run just written is never pruned.');

        // Pruning deletes findings with their run, and nothing else.
        $this->assertSame(
            0,
            SeoAuditFinding::query()->where('seo_audit_run_id', $runIds[1])->count(),
            'The pruned run took its findings with it.'
        );
        $this->assertSame(
            7,
            WebsiteRevision::query()->where('website_id', $website->id)->count(),
            'Pruning SEO history must never touch Website revisions.'
        );
    }

    public function test_pruning_is_scoped_to_one_website(): void
    {
        [, $mine] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $myWebsite = $this->publishWebsite($mine, [$this->goodPage('mine')]);
        $this->runner()->runForRevision((int) $mine->id, (int) $myWebsite->id, (int) $myWebsite->published_revision_id);

        [, $other] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $otherWebsite = $this->publishWebsite($other, [$this->goodPage('other')]);

        for ($i = 1; $i <= 6; $i++) {
            $revision = WebsiteRevision::create([
                'website_id' => $otherWebsite->id,
                'version_number' => $i + 1,
                'snapshot' => ['schema_version' => 1, 'website' => [], 'pages' => [$this->goodPage('o' . $i)], 'assets' => []],
                'schema_version' => 1,
                'created_by' => $other->customer_id,
            ]);
            $this->runner()->runForRevision((int) $other->id, (int) $otherWebsite->id, (int) $revision->id);
        }

        $this->assertSame(1, SeoAuditRun::query()->where('website_id', $myWebsite->id)->count(), 'Another Website\'s pruning must not touch mine.');
        $this->assertSame(5, SeoAuditRun::query()->where('website_id', $otherWebsite->id)->count());
    }

    // -----------------------------------------------------------------
    // Query budget (§11.4) — independent of pages and findings.
    // -----------------------------------------------------------------

    public function test_the_audit_query_count_does_not_grow_with_pages_or_findings(): void
    {
        $one = $this->countAuditQueries(1);
        $many = $this->countAuditQueries(25);

        $this->assertSame(
            $one,
            $many,
            "Auditing 25 pages used {$many} queries and 1 page used {$one}: the audit must not issue a query per page or per finding."
        );
    }

    public function test_the_audit_read_model_query_count_does_not_grow_with_findings(): void
    {
        [, $small] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $smallSite = $this->publishWebsite($small, [$this->snapshotPage('p1', 'One', ['seo_title' => null])]);
        $this->runner()->runForRevision((int) $small->id, (int) $smallSite->id, (int) $smallSite->published_revision_id);

        [, $big] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $pages = [];
        for ($i = 0; $i < 25; $i++) {
            $pages[] = $this->snapshotPage('p' . $i, 'Page ' . $i, ['seo_title' => null]);
        }
        $bigSite = $this->publishWebsite($big, $pages);
        $this->runner()->runForRevision((int) $big->id, (int) $bigSite->id, (int) $bigSite->published_revision_id);

        $reader = app(SeoAuditPageReader::class);

        $smallCount = count($this->capturedQueries(fn () => $reader->read($small)));
        $bigCount = count($this->capturedQueries(fn () => $reader->read($big)));

        $this->assertSame($smallCount, $bigCount, 'The audit read model must not issue a query per finding.');
        $this->assertLessThanOrEqual(14, $bigCount, 'Contract §11.4 ceiling for a section index.');
    }

    private function countAuditQueries(int $pageCount): int
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Growth);

        $pages = [];
        for ($i = 0; $i < $pageCount; $i++) {
            // Every page is deliberately faulty, so findings scale with pages.
            $pages[] = $this->snapshotPage('p' . $i, 'Page ' . $i, ['seo_title' => null, 'meta_description' => null]);
        }

        $website = $this->publishWebsite($business, $pages);

        $queries = $this->capturedQueries(function () use ($business, $website): void {
            $this->runner()->runForRevision((int) $business->id, (int) $website->id, (int) $website->published_revision_id);
        });

        $this->assertGreaterThan(0, SeoAuditFinding::query()->count());

        return count($queries);
    }

    // -----------------------------------------------------------------
    // Failure is recorded honestly.
    // -----------------------------------------------------------------

    public function test_an_unusable_snapshot_records_a_failed_run_rather_than_inventing_findings(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $website = $this->publishWebsite($business, [$this->goodPage()]);

        // A revision whose snapshot is not a usable document.
        DB::table('website_revisions')->where('id', $website->published_revision_id)->update(['snapshot' => json_encode('not-an-object')]);

        $run = $this->runner()->runForRevision((int) $business->id, (int) $website->id, (int) $website->published_revision_id);

        $this->assertNotNull($run);
        $this->assertSame(SeoAuditRunStatus::Failed, $run->status);
        $this->assertSame(0, $run->page_count);
        $this->assertSame(0, $run->totalFindings());
        $this->assertSame(0, SeoAuditFinding::query()->count());
    }

    public function test_a_run_is_immutable_and_has_no_updated_at(): void
    {
        [, , $run] = $this->audit([$this->goodPage()]);

        $this->assertNull(SeoAuditRun::UPDATED_AT);
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('seo_audit_runs', 'updated_at'));
        $this->assertNotNull($run->created_at);
    }

    public function test_the_severity_enum_matches_the_stored_column(): void
    {
        [, , $run] = $this->audit([$this->snapshotPage('p1', 'Home', ['seo_title' => null, 'meta_description' => null])]);

        $severities = SeoAuditFinding::query()->pluck('severity')->all();

        foreach ($severities as $severity) {
            $this->assertInstanceOf(SeoAuditSeverity::class, $severity);
        }
    }
}

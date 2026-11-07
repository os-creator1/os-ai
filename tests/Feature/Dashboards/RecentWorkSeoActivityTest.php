<?php

namespace Tests\Feature\Dashboards;

use App\Library\Dashboard\RecentWorkReader;
use App\Library\Seo\Rank\Provider\SeoRankProvider;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Seo\Rank\Concerns\CreatesRankFixtures;
use Tests\Feature\Seo\Rank\Concerns\CreatesRankObservations;
use Tests\TestCase;

/**
 * "MotionGrove at work": the three SEO activity sources of RecentWorkReader — grouped rank checks, completed website audits and
 * verified published articles. Uses the SEO fixtures only (never combined with the Dashboard fixtures).
 */
class RecentWorkSeoActivityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRankFixtures;
    use CreatesRankObservations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rankSetUp();
    }

    /** @return array<int, \App\Library\Dashboard\RecentWorkItem> */
    private function items(Business $business, ?int $actor = null): array
    {
        return app(RecentWorkReader::class)->recent($business->fresh(), 10, $actor);
    }

    private function byKey(array $items, string $prefix): array
    {
        return array_values(array_filter($items, fn ($i) => str_starts_with($i->key, $prefix)));
    }

    private function article(Business $business, array $overrides = []): string
    {
        $uid = (string) Str::uuid();
        DB::table('website_articles')->insert(array_merge([
            'uid' => $uid, 'business_id' => $business->id,
            'website_id' => DB::table('websites')->where('business_id', $business->id)->value('id'),
            'title' => 'How to pick a photo booth', 'slug' => 's-' . Str::random(8), 'status' => 'published',
            'published_at' => now()->subHours(5), 'created_at' => now()->subDays(9), 'updated_at' => now()->subDays(9),
        ], $overrides));

        return $uid;
    }

    public function test_rank_checks_are_grouped_per_day_and_trigger_with_the_proven_keyword_count(): void
    {
        [$owner, $business] = $this->rankTenant();
        $a = $this->track($owner, $business, $this->keyword($owner, $business, 'photo booth rental'));
        $b = $this->track($owner, $business, $this->keyword($owner, $business, 'wedding photo booth'));
        $at = now()->subHours(3);
        // Two keywords, two check types each, same day: ONE item, two keywords (not four, not eight).
        foreach ([$a, $b] as $t) {
            $this->obs($t, 'organic', 5, $at);
            $this->obs($t, 'local', 3, $at);
        }

        $items = $this->byKey($this->items($business), 'rank_check');

        $this->assertCount(1, $items);
        $this->assertSame('rank_check_scheduled', $items[0]->key);
        $this->assertSame('Scheduled Google ranking check for 2 keywords', $items[0]->text);
        $this->assertSame('customer.workspaces.businesses.seo.keywords.index', $items[0]->routeName);
        $this->assertSame(['view_seo'], $items[0]->permissions);
        $this->assertSame($at->getTimestamp(), $items[0]->at->getTimestamp(), 'The honest completion time.');
        $this->assertDoesNotMatchRegularExpression('/improv|better|rose|climb|provider|\$|task/i', $items[0]->text);
    }

    public function test_manual_checks_are_distinct_and_unfinished_or_failed_runs_are_not_activity(): void
    {
        [$owner, $business] = $this->rankTenant();
        $t = $this->track($owner, $business, $this->keyword($owner, $business));
        $this->obs($t, 'organic', 4, now()->subHours(2));
        DB::table('seo_rank_check_runs')->where('seo_rank_target_id', $t->id)->update(['trigger' => 'manual']);
        $this->obs($t, 'local', 2, now()->subDay());
        DB::table('seo_rank_check_runs')->where('seo_rank_target_id', $t->id)->where('check_type', 'local')->update(['state' => 'submitted', 'completed_at' => null]);

        $items = $this->byKey($this->items($business), 'rank_check');

        $this->assertCount(1, $items);
        $this->assertSame('rank_check_manual', $items[0]->key);
        $this->assertSame('Manual Google ranking check for 1 keyword', $items[0]->text);
    }

    public function test_audits_show_only_proven_results_and_say_when_coverage_was_partial(): void
    {
        [, $business] = $this->rankTenant();
        $website = (int) DB::table('websites')->where('business_id', $business->id)->value('id');
        $run = fn (string $status, int $rev, $at) => DB::table('seo_audit_runs')->insert([
            'uid' => (string) Str::uuid(), 'business_id' => $business->id, 'website_id' => $website, 'website_revision_id' => $rev,
            'rule_set_version' => 1, 'status' => $status, 'critical_count' => 1, 'warning_count' => 2, 'info_count' => 0, 'created_at' => $at,
        ]);
        $run('completed', 1, now()->subHours(6));
        $run('failed', 2, now()->subHours(1));
        $crawl = fn (string $status, $finished) => DB::table('external_site_crawls')->insert([
            'uid' => (string) Str::uuid(), 'business_id' => $business->id, 'start_url' => 'https://x.example/', 'host' => 'x.example',
            'status' => $status, 'critical_count' => 0, 'warning_count' => 1, 'info_count' => 1, 'finished_at' => $finished, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $crawl('completed', now()->subHours(4));
        $crawl('partial', now()->subHours(2));
        $crawl('running', null);
        $crawl('failed', now()->subMinutes(30));
        $crawl('queued', null);

        $texts = array_map(fn ($i) => $i->key . '|' . $i->text, $this->byKey($this->items($business), '') );
        $audit = array_values(array_filter($texts, fn ($t) => str_contains($t, 'audit')));

        $this->assertContains('seo_audit_completed|Website SEO audit completed: 3 issues found', $audit);
        $this->assertContains('external_audit_completed|External website audit completed: 2 issues found', $audit);
        $this->assertContains('external_audit_partial|External website audit finished with partial coverage: 2 issues found', $audit);
        $this->assertCount(3, $audit, 'Failed, running and queued audits are not results.');
        $this->assertSame([], array_filter($audit, fn ($t) => preg_match('/fixed|resolved|improved/i', $t)));
    }

    public function test_only_verified_published_articles_appear_with_the_published_time_and_no_automation_claim(): void
    {
        [, $business] = $this->rankTenant();
        $uid = $this->article($business, ['source' => 'ai_draft', 'ai_generated' => true]);
        $this->article($business, ['title' => 'A draft', 'status' => 'draft', 'published_at' => null]);
        $this->article($business, ['title' => 'Scheduled one', 'status' => 'scheduled', 'published_at' => null, 'scheduled_at' => now()->addDay()]);
        $this->article($business, ['title' => 'Future dated', 'status' => 'published', 'published_at' => now()->addDay()]);
        $this->article($business, ['title' => 'Archived', 'status' => 'archived']);

        $items = $this->byKey($this->items($business), 'article');

        $this->assertCount(1, $items);
        $this->assertSame('Blog article published: How to pick a photo booth', $items[0]->text);
        $this->assertSame('customer.workspaces.businesses.seo.content.articles.preview', $items[0]->routeName);
        $this->assertSame([$uid], $items[0]->routeParameters);
        $this->assertSame(['view_seo'], $items[0]->permissions);
        $this->assertEqualsWithDelta(now()->subHours(5)->getTimestamp(), $items[0]->at->getTimestamp(), 2, 'published_at, not creation time.');
        $this->assertDoesNotMatchRegularExpression('/automatic|auto|MotionGrove/i', $items[0]->text);
    }

    public function test_another_business_never_contributes(): void
    {
        [$owner, $business] = $this->rankTenant();
        [$owner2, $other] = $this->rankTenant();
        $this->obs($this->track($owner2, $other, $this->keyword($owner2, $other)), 'organic', 3, now()->subHour());
        $this->article($other);
        DB::table('external_site_crawls')->insert(['uid' => (string) Str::uuid(), 'business_id' => $other->id, 'start_url' => 'https://o.example/', 'host' => 'o.example', 'status' => 'completed', 'critical_count' => 0, 'warning_count' => 0, 'info_count' => 0, 'finished_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame([], $this->items($business));
        $this->assertNotSame([], $this->items($other));
    }

    public function test_location_bound_rank_checks_and_articles_follow_the_actors_reach(): void
    {
        [$owner, $business] = $this->rankTenant();
        $location = (int) DB::table('business_locations')->where('business_id', $business->id)->value('id');
        $keyword = $this->keyword($owner, $business, 'north site phrase');
        $target = $this->track($owner, $business, $keyword);
        DB::table('seo_keywords')->where('id', $keyword->id)->update(['business_location_id' => $location]);
        $this->obs($target, 'organic', 4, now()->subHour());
        $this->article($business, ['business_location_id' => $location]);

        $this->mock(LocationAccessGuard::class, fn ($m) => $m->shouldReceive('accessibleLocationIdsForBusiness')->andReturn([]));
        $this->assertSame([], $this->items($business, (int) $owner->user_id), 'No reach: neither the rank check nor the article.');
        $this->assertCount(2, $this->items($business), 'Business-wide readers (no actor) are unchanged.');

        $this->mock(LocationAccessGuard::class, fn ($m) => $m->shouldReceive('accessibleLocationIdsForBusiness')->andReturn([$location]));
        $this->assertCount(2, $this->items($business, (int) $owner->user_id), 'With reach, both are shown.');
    }

    public function test_reading_activity_never_calls_a_provider_or_queues_work(): void
    {
        [$owner, $business] = $this->rankTenant();
        $this->obs($this->track($owner, $business, $this->keyword($owner, $business)), 'organic', 4, now()->subHour());
        $this->article($business);
        Queue::fake();
        $this->app->instance(SeoRankProvider::class, \Mockery::mock(SeoRankProvider::class));

        $this->assertNotSame([], $this->items($business, (int) $owner->user_id));
        Queue::assertNothingPushed();
    }
}

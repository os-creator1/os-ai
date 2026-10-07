<?php

namespace Tests\Feature\Seo\Content;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Growth\GrowthRuleOutcomeStatus as S;
use App\Enums\Seo\ArticleStatus;
use App\Library\Ai\Contracts\AiCompletionClient;
use App\Library\Ai\Providers\FakeAiCompletionClient;
use App\Library\Growth\GrowthFactSnapshotBuilder;
use App\Library\Growth\GrowthRuleRegistry;
use App\Library\Growth\Rules\ContentRules;
use App\Library\Seo\Content\ArticleFreshness;
use App\Models\CatalogItem;
use App\Models\WebsiteArticle;
use App\Models\WebsiteRevision;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Seo\Content\Concerns\CreatesContentFixtures;
use Tests\TestCase;

/**
 * SEO Content Engine V1 — the things that happen without a person on the page: the scheduler that publishes at the
 * owner's chosen time (re-checking first), deterministic freshness, and the facts Growth Center reads.
 */
class ArticleOperationsTest extends TestCase
{
    use CreatesContentFixtures;
    use RefreshDatabase;

    private function due(WebsiteArticle $article, $business): WebsiteArticle
    {
        $scheduled = $this->manager()->schedule((int) $business->customer_id, $business, $article, now()->addDay());
        WebsiteArticle::whereKey($scheduled->id)->update(['scheduled_at' => now()->subMinute()]);

        return $scheduled->fresh();
    }

    // ------------------------------------------------------------------ scheduled publishing

    public function test_the_command_publishes_due_articles_and_leaves_everything_else_alone(): void
    {
        [, $business] = $this->photoBoothContentTenant();

        $due = $this->due($this->draftArticle($business), $business);
        $future = $this->manager()->schedule((int) $business->customer_id, $business, $this->draftArticle($business, ['title' => 'Future booth planning', 'primary_topic' => 'future planning']), now()->addDays(2));
        $draft = $this->draftArticle($business, ['title' => 'Plain draft about booths', 'primary_topic' => 'plain draft']);
        $archived = $this->manager()->archive((int) $business->customer_id, $business, $this->draftArticle($business, ['title' => 'Archived note about booths', 'primary_topic' => 'archived note']));

        $this->artisan('articles:publish-due')->assertExitCode(0);

        $this->assertSame(ArticleStatus::Published, $due->fresh()->status);
        $this->assertNotNull($due->fresh()->published_at);
        $this->assertNull($due->fresh()->scheduled_at);
        $this->assertSame(ArticleStatus::Scheduled, $future->fresh()->status);
        $this->assertSame(ArticleStatus::Draft, $draft->fresh()->status);
        $this->assertSame(ArticleStatus::Archived, $archived->fresh()->status);

        $this->artisan('articles:publish-due')->assertExitCode(0);
        $this->assertSame(1, WebsiteArticle::query()->published()->count(), 'idempotent');
    }

    public function test_an_article_that_no_longer_passes_goes_back_to_draft_instead_of_publishing(): void
    {
        [, $business] = $this->photoBoothContentTenant();
        $article = $this->due($this->draftArticle($business), $business);

        // Edited (outside the manager) into something that could not be published today.
        WebsiteArticle::whereKey($article->id)->update(['body' => 'Our award-winning team has 20 years of experience.']);

        $result = $this->manager()->publishDue();

        $this->assertSame(['published' => 0, 'returned_to_draft' => 1], $result);
        $this->assertSame(ArticleStatus::Draft, $article->fresh()->status);
        $this->assertNull($article->fresh()->scheduled_at);
    }

    public function test_the_scheduler_runs_the_command_every_minute(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->command, 'articles:publish-due'));

        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events->first()->expression);
        $this->assertTrue($events->first()->withoutOverlapping);
    }

    // ------------------------------------------------------------------ freshness

    public function test_freshness_flags_old_articles_and_a_review_clears_it(): void
    {
        [, $business] = $this->photoBoothContentTenant();
        $article = $this->publishedArticle($business);
        $freshness = app(ArticleFreshness::class);

        $this->assertSame([], $freshness->forBusiness($business), 'a new article is fresh');

        $later = now()->addDays(ArticleFreshness::OLD_AFTER_DAYS + 5);
        $rows = $freshness->forBusiness($business, $later);
        $this->assertSame([ArticleFreshness::REASON_OLD], $rows[0]['reasons']);
        $this->assertSame($article->uid, $rows[0]['article_uid']);
        $this->assertStringNotContainsString('rank', strtolower(implode(' ', $rows[0]['messages'])));

        $this->travel(ArticleFreshness::OLD_AFTER_DAYS + 5)->days();
        $this->manager()->markReviewed($business, $article->fresh());
        $this->assertSame([], $freshness->forBusiness($business), 'reviewing resets the clock without rewriting anything');
    }

    public function test_freshness_notices_a_changed_package_and_a_vanished_supported_page(): void
    {
        [, $business, , $website] = $this->photoBoothContentTenant();
        $article = $this->publishedArticle($business, ['body' => $this->goodBody(self::PAGE_WEDDING) . "\nThe Signature package suits most weddings.\n"]);
        $freshness = app(ArticleFreshness::class);
        $this->assertSame([], $freshness->forBusiness($business));

        $this->travel(2)->days();
        CatalogItem::where('business_id', $business->id)->where('name', 'Signature')->update(['price_minor' => 99900, 'updated_at' => now()]);

        $rows = $freshness->forBusiness($business);
        $this->assertContains(ArticleFreshness::REASON_CATALOG_CHANGED, $rows[0]['reasons']);
        $this->assertStringContainsString('Signature', implode(' ', $rows[0]['messages']));

        // The supported page leaves the published website.
        $revision = WebsiteRevision::find($website->published_revision_id);
        $snapshot = $revision->snapshot;
        $snapshot['pages'] = array_values(array_filter($snapshot['pages'], fn ($p) => $p['uid'] !== self::PAGE_WEDDING));
        $revision->update(['snapshot' => $snapshot]);

        $this->assertContains(ArticleFreshness::REASON_SUPPORTED_PAGE_CHANGED, $freshness->forBusiness($business)[0]['reasons']);
        $this->assertSame(ArticleStatus::Published, $article->fresh()->status, 'freshness never rewrites or unpublishes anything');
    }

    // ------------------------------------------------------------------ Growth Center facts

    public function test_growth_sees_uncovered_topics_as_facts_and_rules_judge_them_without_promises(): void
    {
        [, $business] = $this->photoBoothContentTenant(withBlueprint: true);
        $fake = new FakeAiCompletionClient();
        $this->app->instance(AiCompletionClient::class, $fake);
        Http::preventStrayRequests();

        $snapshot = app(GrowthFactSnapshotBuilder::class)->build($business->fresh());
        $set = $snapshot->set('content');

        $this->assertTrue($set->isAvailable());
        $this->assertTrue($set->get('website_published'));
        $this->assertGreaterThan(5, $set->get('topics_not_covered')['count']);
        $this->assertLessThanOrEqual(3, count($set->get('topics_not_covered')['titles']));
        $this->assertSame(0, $set->get('article_count'));

        $topics = ContentRules::topicsNotCovered();
        $outcome = $topics->evaluate($snapshot);
        $this->assertSame(S::Finding, $outcome->status);
        $this->assertStringContainsString('no article yet', $topics->headline($outcome->findings[0]->evidence));
        $definition = $topics->definition();
        $this->assertStringContainsString('does not guarantee', $definition->expected);
        $this->assertSame('seo.content', $definition->target);

        // No articles yet: the article-based rules have nothing to judge.
        foreach ([ContentRules::stale()] as $rule) {
            $this->assertSame(S::Insufficient, $rule->evaluate($snapshot)->status, $rule->definition()->key);
        }

        $this->assertSame(0, $fake->callCount(), 'Growth reads no AI');
        $this->assertSame(0, WebsiteArticle::count(), 'and writes no articles or recommendations');
    }

    public function test_growth_flags_a_stale_article_and_all_rules_are_registered(): void
    {
        [, $business] = $this->photoBoothContentTenant(withBlueprint: true);
        $article = $this->publishedArticle($business);
        WebsiteArticle::whereKey($article->id)->update(['published_at' => now()->subDays(500), 'content_updated_at' => now()->subDays(500)]);

        $snapshot = app(GrowthFactSnapshotBuilder::class)->build($business->fresh());
        $this->assertSame(1, $snapshot->set('content')->get('stale')['count']);

        $stale = ContentRules::stale()->evaluate($snapshot);
        $this->assertSame(S::Finding, $stale->status);
        $this->assertSame(1, $stale->findings[0]->evidence['count']);
        $this->assertSame([$article->title], $stale->findings[0]->evidence['titles']);

        $keys = collect(app(GrowthRuleRegistry::class)->all())->map(fn ($rule) => $rule->definition()->key)->all();
        foreach (['content.topics_not_covered:v1', 'content.article_stale:v1'] as $key) {
            $this->assertContains($key, $keys);
        }
    }

    public function test_growth_content_facts_are_per_business_and_need_the_seo_module(): void
    {
        [, $a] = $this->photoBoothContentTenant(withBlueprint: true);
        [, $core] = $this->photoBoothContentTenant(WorkspacePlanTier::Core);
        $this->publishedArticle($a);

        $builder = app(GrowthFactSnapshotBuilder::class);

        $this->assertSame(1, $builder->build($a->fresh())->set('content')->get('article_count'));
        $this->assertFalse($builder->build($core->fresh())->set('content')->isAvailable(), 'Core has no SeoModule: no content facts at all');
    }
}

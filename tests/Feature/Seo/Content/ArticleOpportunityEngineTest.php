<?php

namespace Tests\Feature\Seo\Content;

use App\Library\Ai\Contracts\AiCompletionClient;
use App\Library\Ai\Providers\FakeAiCompletionClient;
use App\Library\Seo\Content\ArticleCannibalizationGuard;
use App\Library\Seo\Content\ArticleContentPlan;
use App\Library\Seo\Content\ArticleOpportunityEngine;
use App\Library\Seo\Content\ArticleSiteInventory;
use App\Library\Seo\SeoKeywordManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Seo\Content\Concerns\CreatesContentFixtures;
use Tests\TestCase;

/**
 * SEO Content Engine V1 — deterministic opportunities for the Chicago Photo Booth fixture: useful topics that
 * SUPPORT the money pages, never compete with them; honest coverage status; tenancy; no AI, no volume data.
 */
class ArticleOpportunityEngineTest extends TestCase
{
    use CreatesContentFixtures;
    use RefreshDatabase;

    private function titles(array $opportunities): array
    {
        return array_column($opportunities, 'title');
    }

    private function find(array $opportunities, string $title): array
    {
        $found = collect($opportunities)->firstWhere('title', $title);
        $this->assertNotNull($found, "missing opportunity: {$title}; have: " . implode(' | ', array_column($opportunities, 'title')));

        return $found;
    }

    public function test_the_photo_booth_niche_yields_the_required_topics_supporting_the_right_pages(): void
    {
        [, $business] = $this->photoBoothContentTenant(withBlueprint: true);
        $list = app(ArticleOpportunityEngine::class)->forBusiness($business);

        $this->assertGreaterThan(8, count($list));
        $this->assertLessThanOrEqual(ArticleOpportunityEngine::MAX, count($list));

        $cost = $this->find($list, 'How much does a photo booth rental cost in Chicago?');
        $this->assertSame(self::PAGE_PACKAGES, $cost['supports_page_uid']);
        $this->assertSame('cost', $cost['search_intent']);
        $this->assertSame('niche_blueprint', $cost['source']);
        $this->assertSame('not_covered', $cost['status']);
        $this->assertNull($cost['article_uid']);
        $this->assertNotSame('', $cost['why']);

        $this->assertSame(self::PAGE_360, $this->find($list, '360 photo booth vs traditional photo booth')['supports_page_uid']);
        $this->assertSame(self::PAGE_WEDDING, $this->find($list, 'Wedding photo booth ideas')['supports_page_uid']);
        $this->assertSame(self::PAGE_CORPORATE, $this->find($list, 'Are photo booths worth it for corporate events?')['supports_page_uid']);
        $this->find($list, 'How much room does a photo booth need?');
    }

    public function test_no_opportunity_competes_with_a_money_page_and_no_fake_metrics_exist(): void
    {
        [, $business] = $this->photoBoothContentTenant(withBlueprint: true);
        $list = app(ArticleOpportunityEngine::class)->forBusiness($business);
        $pages = app(ArticleSiteInventory::class)->pages($business);
        $guard = app(ArticleCannibalizationGuard::class);

        foreach ($list as $opportunity) {
            $this->assertFalse($guard->check($business, [$opportunity['title']], null, $pages)->conflictsWithPage(), 'cannibalizes a page: ' . $opportunity['title']);
            $this->assertEqualsCanonicalizing(
                ['key', 'title', 'primary_topic', 'search_intent', 'supports_page_uid', 'supports_page_title', 'why', 'status', 'article_uid', 'internal_links', 'source', 'cluster'],
                array_keys($opportunity),
                'no volume / CPC / competition keys',
            );
        }

        $this->assertNotContains('Photo booth rental chicago', $this->titles($list));
    }

    public function test_internal_link_targets_are_live_pages_of_this_business_only(): void
    {
        [, $business] = $this->photoBoothContentTenant(withBlueprint: true);
        $cost = $this->find(app(ArticleOpportunityEngine::class)->forBusiness($business), 'How much does a photo booth rental cost in Chicago?');

        $uids = array_column($cost['internal_links'], 'uid');
        $this->assertContains(self::PAGE_PACKAGES, $uids);
        $this->assertContains(self::PAGE_CHICAGO, $uids, 'the Chicago location page');
        $this->assertContains(self::PAGE_CONTACT, $uids);
        $this->assertNotContains(self::PAGE_NOINDEX, $uids, 'never a noindex page');
        $this->assertLessThanOrEqual(7, count($cost['internal_links']));
    }

    public function test_status_follows_the_articles_started_from_an_opportunity(): void
    {
        [, $business] = $this->photoBoothContentTenant(withBlueprint: true);
        $engine = app(ArticleOpportunityEngine::class);
        $opportunity = $this->find($engine->forBusiness($business), 'How much room does a photo booth need?');

        $article = $this->draftArticle($business, [
            'title' => $opportunity['title'], 'primary_topic' => $opportunity['primary_topic'],
            'opportunity_key' => $opportunity['key'], 'supports_page_uid' => $opportunity['supports_page_uid'],
        ]);

        $now = $this->find($engine->forBusiness($business), $opportunity['title']);
        $this->assertSame('draft', $now['status']);
        $this->assertSame($article->uid, $now['article_uid']);

        $this->manager()->publish((int) $business->customer_id, $business, $article);
        $this->assertSame('published', $this->find($engine->forBusiness($business), $opportunity['title'])['status']);

        $this->manager()->archive((int) $business->customer_id, $business, $article->fresh());
        $this->assertSame('not_covered', $this->find($engine->forBusiness($business), $opportunity['title'])['status'], 'an archived article no longer covers the topic');
    }

    public function test_an_article_written_by_hand_still_counts_as_covering_the_topic(): void
    {
        [, $business] = $this->photoBoothContentTenant(withBlueprint: true);

        $this->draftArticle($business, ['title' => 'Wedding photo booth ideas', 'primary_topic' => 'wedding photo booth ideas']);

        $this->assertSame('draft', $this->find(app(ArticleOpportunityEngine::class)->forBusiness($business), 'Wedding photo booth ideas')['status']);
    }

    public function test_another_business_articles_never_change_this_business_list(): void
    {
        [, $a] = $this->photoBoothContentTenant(withBlueprint: true);
        $before = app(ArticleOpportunityEngine::class)->forBusiness($a);

        [, $b] = $this->photoBoothContentTenant();
        $this->publishedArticle($b, ['title' => 'Wedding photo booth ideas', 'primary_topic' => 'wedding photo booth ideas']);

        $this->assertSame($before, app(ArticleOpportunityEngine::class)->forBusiness($a));
    }

    public function test_a_business_without_a_blueprint_gets_a_short_generic_set_from_its_own_services(): void
    {
        [, $business] = $this->photoBoothContentTenant();
        $list = app(ArticleOpportunityEngine::class)->forBusiness($business);

        $this->assertNotEmpty($list);
        $this->assertLessThanOrEqual(6, count($list));
        $this->assertSame(['generic'], array_values(array_unique(array_column($list, 'source'))));
        $this->find($list, 'How much does Wedding Photo Booth Rental cost?');
    }

    public function test_tracked_informational_keywords_become_opportunities_and_plain_service_keywords_do_not(): void
    {
        [$customer, $business] = $this->photoBoothContentTenant();
        $keywords = app(SeoKeywordManager::class);
        $keywords->create((int) $customer->user_id, $business, 'photo booth cost chicago');
        $keywords->create((int) $customer->user_id, $business, 'photo booth rental chicago');

        $list = app(ArticleOpportunityEngine::class)->forBusiness($business);
        $keyword = $this->find($list, 'Photo booth cost chicago');

        $this->assertSame('keyword', $keyword['source']);
        $this->assertSame('cost', $keyword['search_intent']);
        $this->assertNotContains('Photo booth rental chicago', $this->titles($list), 'a plain service keyword belongs to a page, not an article');
    }

    public function test_no_published_website_means_no_opportunities_and_no_ai_or_provider_call_is_ever_made(): void
    {
        $fake = new FakeAiCompletionClient();
        $this->app->instance(AiCompletionClient::class, $fake);
        Http::preventStrayRequests();

        [, $empty] = $this->entitledTenant();
        $this->assertSame([], app(ArticleOpportunityEngine::class)->forBusiness($empty));

        [, $business] = $this->photoBoothContentTenant(withBlueprint: true);
        app(ArticleOpportunityEngine::class)->forBusiness($business);
        app(ArticleContentPlan::class)->forBusiness($business);

        $this->assertSame(0, $fake->callCount());
    }

    public function test_the_content_plan_groups_articles_by_the_page_they_support_and_lists_the_gaps(): void
    {
        [, $business] = $this->photoBoothContentTenant(withBlueprint: true);
        $this->draftArticle($business, ['title' => 'Wedding photo booth ideas', 'primary_topic' => 'wedding photo booth ideas', 'supports_page_uid' => self::PAGE_WEDDING]);

        $plan = app(ArticleContentPlan::class)->forBusiness($business);

        $wedding = collect($plan['clusters'])->first(fn ($c) => $c['page']['uid'] === self::PAGE_WEDDING);
        $this->assertNotNull($wedding);
        $this->assertSame(['Wedding photo booth ideas'], array_column($wedding['articles'], 'title'));

        $unsupported = array_column($plan['unsupported_pages'], 'uid');
        $this->assertNotContains(self::PAGE_WEDDING, $unsupported);
        $this->assertContains(self::PAGE_CORPORATE, $unsupported);
        $this->assertNotContains(self::PAGE_NOINDEX, $unsupported);
    }
}

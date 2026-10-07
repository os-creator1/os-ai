<?php

namespace Tests\Feature\Seo\Content;

use App\Enums\Seo\SeoRankRunState;
use App\Library\Seo\Content\ArticleRankSignals;
use App\Library\Seo\Rank\Provider\FakeSeoRankProvider;
use App\Library\Seo\Rank\Provider\SeoRankProvider;
use App\Library\Seo\Rank\SeoRankTargetManager;
use App\Library\Seo\SeoKeywordManager;
use App\Models\SeoKeyword;
use App\Models\SeoRankCheckRun;
use App\Models\SeoRankLocation;
use App\Models\SeoRankObservation;
use App\Models\SeoRankTarget;
use App\Models\WebsiteArticle;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Seo\Content\Concerns\CreatesContentFixtures;
use Tests\TestCase;

/**
 * SEO Content Engine V1 — rank-based content facts from observations rank tracking ALREADY stored. Publishing an
 * article never creates a rank target; no provider call is made; wording never claims causation.
 */
class ArticleRankSignalsTest extends TestCase
{
    use CreatesContentFixtures;
    use RefreshDatabase;

    private const CHICAGO = 1016367;

    protected function setUp(): void
    {
        parent::setUp();

        FakeSeoRankProvider::reset();
        $this->app->singleton(SeoRankProvider::class, fn () => new FakeSeoRankProvider());
        config(['seo.rank_tracking.enabled' => true]);

        $location = new SeoRankLocation();
        $location->forceFill(['provider' => 'dataforseo', 'location_code' => self::CHICAGO, 'location_name' => 'Chicago,Illinois,United States', 'country_iso' => 'US', 'location_type' => 'City'])->save();
        Http::preventStrayRequests();
    }

    /** A published article whose target topic is also a tracked keyword, tracked in Chicago. */
    private function trackedArticle(string $topic, array $positions): array
    {
        [$customer, $business] = $this->photoBoothContentTenant();
        $article = $this->publishedArticle($business, ['title' => 'Photo booth pricing explained', 'primary_topic' => $topic]);

        $keyword = app(SeoKeywordManager::class)->create((int) $customer->user_id, $business, $topic);
        $target = app(SeoRankTargetManager::class)->track((int) $customer->user_id, $business, $keyword->uid, self::CHICAGO);

        // Oldest first.
        foreach (array_values($positions) as $i => $position) {
            $this->observe($target, $position, now()->subDays((count($positions) - $i) * 7));
        }

        return [$business, $article, $target];
    }

    private function observe(SeoRankTarget $target, ?int $position, CarbonInterface $at): void
    {
        $run = new SeoRankCheckRun();
        $run->forceFill([
            'business_id' => $target->business_id, 'seo_rank_target_id' => $target->id, 'check_type' => 'organic', 'trigger' => 'scheduled',
            'idempotency_key' => 'obs:' . Str::uuid(), 'state' => SeoRankRunState::Completed->value, 'provider' => 'dataforseo', 'depth' => 100, 'completed_at' => $at,
        ])->save();

        $observation = new SeoRankObservation();
        $observation->forceFill([
            'business_id' => $target->business_id, 'seo_rank_target_id' => $target->id, 'seo_rank_check_run_id' => $run->id, 'check_type' => 'organic',
            'status' => $position !== null ? 'found' : 'not_found', 'position' => $position, 'result_path' => $position !== null ? '/blog' : null,
            'depth_checked' => 100, 'provider' => 'dataforseo', 'search_location_code' => $target->search_location_code, 'device' => 'mobile', 'checked_at' => $at,
        ])->save();
    }

    public function test_an_article_near_page_one_is_surfaced_with_modest_wording(): void
    {
        [$business, $article] = $this->trackedArticle('photo booth pricing', [12]);

        $signals = app(ArticleRankSignals::class)->forBusiness($business);

        $this->assertCount(1, $signals);
        $this->assertSame(ArticleRankSignals::KIND_NEAR_PAGE_ONE, $signals[0]['kind']);
        $this->assertSame($article->uid, $signals[0]['article_uid']);
        $this->assertSame(12, $signals[0]['position']);
        $this->assertStringContainsString('may help', $signals[0]['message']);
        $this->assertDoesNotMatchRegularExpression('/will (increase|improve|raise|boost)|guarantee/i', $signals[0]['message']);
    }

    public function test_a_material_decline_is_reported_without_claiming_a_cause(): void
    {
        [$business] = $this->trackedArticle('photo booth pricing', [4, 14]);

        $signals = app(ArticleRankSignals::class)->forBusiness($business);

        $this->assertSame(ArticleRankSignals::KIND_DECLINED, $signals[0]['kind']);
        $this->assertSame(4, $signals[0]['previous']);
        $this->assertSame(14, $signals[0]['position']);
        $this->assertStringNotContainsString('because', strtolower($signals[0]['message']));
    }

    public function test_a_decline_on_an_old_article_is_stale_and_declined(): void
    {
        [$business, $article] = $this->trackedArticle('photo booth pricing', [5, 16]);
        WebsiteArticle::whereKey($article->id)->update(['published_at' => now()->subDays(500), 'content_updated_at' => now()->subDays(500)]);

        $this->assertSame(ArticleRankSignals::KIND_STALE_AND_DECLINED, app(ArticleRankSignals::class)->forBusiness($business)[0]['kind']);
    }

    public function test_a_top_ranking_article_is_a_positive_fact_not_a_recommendation(): void
    {
        [$business, $article] = $this->trackedArticle('photo booth pricing', [3]);
        $service = app(ArticleRankSignals::class);

        $this->assertSame([], $service->forBusiness($business), 'nothing to fix');
        $this->assertSame([$article->uid], array_column($service->performingWell($business), 'article_uid'));
    }

    public function test_no_tracked_keyword_means_no_signal_and_publishing_never_creates_a_target(): void
    {
        [, $business] = $this->photoBoothContentTenant();
        $this->publishedArticle($business, ['primary_topic' => 'photo booth pricing']);

        $this->assertSame([], app(ArticleRankSignals::class)->forBusiness($business));
        $this->assertSame(0, SeoRankTarget::count(), 'publishing consumes no rank-tracking target');
        $this->assertSame(0, SeoKeyword::count(), 'and creates no keyword');
    }

    public function test_another_business_observations_never_leak_in(): void
    {
        [$a] = $this->trackedArticle('photo booth pricing', [12]);
        [, $other] = $this->photoBoothContentTenant();
        $this->publishedArticle($other, ['title' => 'Photo booth pricing explained too', 'primary_topic' => 'photo booth pricing']);

        $this->assertSame([], app(ArticleRankSignals::class)->forBusiness($other));
        $this->assertCount(1, app(ArticleRankSignals::class)->forBusiness($a));
    }
}

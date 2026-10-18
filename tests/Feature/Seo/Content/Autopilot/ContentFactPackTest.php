<?php

namespace Tests\Feature\Seo\Content\Autopilot;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Ai\Contracts\AiCompletionClient;
use App\Library\Ai\Providers\FakeAiCompletionClient;
use App\Library\Business\BusinessKnowledgeProfileManager;
use App\Library\Seo\Content\ArticleClaimGuard;
use App\Library\Seo\Content\Autopilot\ContentFactPack;
use App\Library\Seo\Content\Autopilot\ContentProfile;
use App\Models\BusinessKnowledgeProfile;
use App\Models\ContentAutopilotSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Seo\Content\Concerns\CreatesContentFixtures;
use Tests\TestCase;

/**
 * Content Autopilot, Slice 2 — the canonical Content Fact Pack, the Content Profile, and owner-confirmed claims.
 */
class ContentFactPackTest extends TestCase
{
    use CreatesContentFixtures;
    use RefreshDatabase;

    private FakeAiCompletionClient $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeAiCompletionClient();
        $this->app->instance(AiCompletionClient::class, $this->fake);
        Http::preventStrayRequests();
    }

    private function knowledge(): BusinessKnowledgeProfileManager
    {
        return app(BusinessKnowledgeProfileManager::class);
    }

    public function test_the_pack_composes_the_existing_facts_and_never_calls_a_model(): void
    {
        [$customer, $business] = $this->photoBoothContentTenant(withBlueprint: true);
        $pack = app(ContentFactPack::class)->forBusiness($business);

        $this->assertSame('Jazmin Photo Booth Co.', $pack['business_name']);
        $this->assertNotEmpty($pack['services']);
        $this->assertContains('Chicago', $pack['service_areas']);
        foreach (['Essential', 'Signature', 'Luxe'] as $name) {
            $this->assertContains($name, array_column($pack['packages'], 'name'));
        }
        $this->assertSame(['differentiators', 'ideal_customers', 'customer_problems', 'brand_voice', 'proof_points', 'allowed_claims', 'prohibited_claims', 'avoid_topics', 'common_questions', 'emphasis', 'google_categories', 'tracked_phrases', 'existing_articles', 'fact_hash'], array_values(array_diff(array_keys($pack), ['business_name', 'services', 'packages', 'service_areas', 'faqs', 'site_summary', 'niche_faq_topics'])));
        $this->assertArrayNotHasKey('testimonials', $pack, 'review content stays out of Autopilot V1');
        $this->assertArrayNotHasKey('reviews', $pack);
        $this->assertSame(0, $this->fake->callCount());
    }

    public function test_the_hash_is_stable_changes_with_a_fact_and_ignores_articles(): void
    {
        [$customer, $business] = $this->photoBoothContentTenant(withBlueprint: true);
        $facts = app(ContentFactPack::class);

        $first = $facts->forBusiness($business);
        $this->assertSame($first['fact_hash'], $facts->forBusiness($business)['fact_hash']);

        $this->draftArticle($business, ['title' => 'A brand new article']);
        $withArticle = $facts->forBusiness($business);
        $this->assertCount(1, $withArticle['existing_articles']);
        $this->assertSame($first['fact_hash'], $withArticle['fact_hash'], 'a new article is not a new fact about the Business');

        $this->knowledge()->updateFields($business, ['differentiators' => ['Same-day setup']], 'manual_edit', (int) $customer->user_id, true);
        $this->assertNotSame($first['fact_hash'], $facts->forBusiness($business)['fact_hash']);
        $this->assertSame(['Same-day setup'], $facts->forBusiness($business)['differentiators']);
    }

    public function test_claim_bearing_facts_are_citable_only_once_the_owner_has_confirmed_them(): void
    {
        [$customer, $business] = $this->photoBoothContentTenant(withBlueprint: true);
        $actor = (int) $customer->user_id;
        $facts = app(ContentFactPack::class);

        // Imported / unverified: stored, but never stated as a fact.
        $this->knowledge()->updateFields($business, ['years_operating' => 12, 'credentials' => [['label' => 'Licensed and insured', 'verified' => true]]], 'imported', $actor, false);
        $pack = $facts->forBusiness($business);
        $this->assertSame([], $pack['proof_points']);
        $this->assertSame([], $pack['allowed_claims']);

        // Confirmed by the owner.
        $this->knowledge()->updateFields($business, ['years_operating' => 12, 'credentials' => [['label' => 'Licensed and insured', 'verified' => true]]], 'manual_edit', $actor, true, ['years_operating', 'credentials']);
        $pack = $facts->forBusiness($business);
        $this->assertSame(12, $pack['proof_points']['years_operating']);
        $this->assertSame(['Licensed and insured'], $pack['proof_points']['credentials']);
        $this->assertSame(['years' => [12]], $pack['allowed_claims']);
        $this->assertArrayNotHasKey('guarantee', $pack['proof_points'], 'a guarantee is never offered as a fact');
    }

    public function test_the_claim_guard_allows_only_the_confirmed_years(): void
    {
        [$customer, $business] = $this->photoBoothContentTenant(withBlueprint: true);
        $guard = app(ArticleClaimGuard::class);
        $kinds = fn (string $text) => array_column($guard->hardFindings($business, $text), 'kind');

        $this->assertSame(['years_in_business'], $kinds('We have 12 years of experience.'), 'not provided yet');

        $this->knowledge()->updateFields($business, ['years_operating' => 12], 'manual_edit', (int) $customer->user_id, true);

        $this->assertSame([], $kinds('We have 12 years of experience with weddings.'));
        $this->assertSame([], $kinds('With over 10 years of experience we know venues well.'));
        $this->assertSame(['years_in_business'], $kinds('We have 20 years of experience.'), 'more than the owner said');
        $this->assertSame(['years_in_business'], $kinds('Serving Chicago since 2009.'), 'a founding year is never allowed this way');
    }

    public function test_the_content_profile_normalizes_bounds_and_completes_even_when_empty(): void
    {
        [$customer, $business] = $this->photoBoothContentTenant(withBlueprint: true);
        $profile = app(ContentProfile::class);
        $actor = (int) $customer->user_id;

        $this->assertFalse($profile->isCompleted($business));
        $this->assertSame(['common_questions', 'emphasis', 'avoid_topics'], array_values(array_intersect(['common_questions', 'emphasis', 'avoid_topics'], $profile->gaps($business))));

        $profile->save($business, $actor, [
            'common_questions' => "  How much is it?  \n\nhow much is it?\nDo you travel?",
            'emphasis' => ['Weddings'],
            'avoid_topics' => "politics",
        ]);

        $this->assertSame(['How much is it?', 'Do you travel?'], $profile->get($business)['common_questions']);
        $this->assertSame(['Weddings'], $profile->get($business)['emphasis']);
        $this->assertTrue($profile->isCompleted($business));
        $this->assertNotContains('common_questions', $profile->gaps($business));

        // Skipping (an empty save) is a valid answer and clears nothing it was not given.
        $profile->save($business, $actor, []);
        $this->assertSame([], $profile->get($business)['common_questions']);
        $this->assertSame(1, ContentAutopilotSetting::query()->count(), 'one row per Business');
    }

    public function test_limits_are_enforced(): void
    {
        [$customer, $business] = $this->photoBoothContentTenant(withBlueprint: true);
        $profile = app(ContentProfile::class);

        $this->expectException(ValidationException::class);
        $profile->save($business, (int) $customer->user_id, ['common_questions' => implode("\n", array_map(fn ($i) => "Question {$i}?", range(1, ContentProfile::MAX_QUESTIONS + 1)))]);
    }

    public function test_differentiators_are_written_through_the_knowledge_profile_not_copied(): void
    {
        [$customer, $business] = $this->photoBoothContentTenant(withBlueprint: true);
        $profile = app(ContentProfile::class);

        $this->assertContains('differentiators', $profile->gaps($business));
        $profile->save($business, (int) $customer->user_id, ['differentiators' => "Same-day setup\nLocally owned"]);

        $this->assertSame(['Same-day setup', 'Locally owned'], BusinessKnowledgeProfile::query()->where('business_id', $business->id)->first()->differentiators);
        $this->assertNotContains('differentiators', $profile->gaps($business), 'asked only while empty');
        $this->assertArrayNotHasKey('differentiators', (array) ContentAutopilotSetting::query()->first()->profile, 'the authority is the Knowledge Profile');
    }
}

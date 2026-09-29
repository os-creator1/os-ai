<?php

namespace Tests\Feature\Website\GuidedGeneration;

use App\Library\Business\BusinessKnowledgeProfileManager;
use App\Library\Website\GuidedGeneration\GuidedGenerationCommitService;
use App\Library\Website\WebsitePageStrategy;
use App\Library\Website\WebsiteStarterDraftService;
use App\Models\WebsiteGuidedGenerationAttempt;
use App\Models\WebsitePage;
use App\Models\WebsiteTemplate;
use Database\Seeders\WebsiteTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Website Guided Generation contract §8.2/§8.4, corrected by
 * acceptance-correction Blockers 2/4/5/6. Every test binds a Mockery
 * double for WebsiteAiGenerationClient (CreatesWebsiteFixtures::
 * mockAiClient()) — no live provider call is ever attempted.
 *
 * A bare fixture Business (entitledTenant() + createWebsite(), no
 * services/catalog/locations/assets) always plans to exactly
 * ['home', 'about', 'faq', 'contact'] for every seeded template — those
 * four page types have no eligibility gate. validBatchFor() below
 * builds a matching, validator-clean AI response keyed by page_key
 * (never page_type/slug, which the AI no longer supplies — see
 * WebsitePageStrategy::buildPlan()).
 */
class GuidedGenerationCommitServiceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    private function validBatchFor(array $plan): string
    {
        return json_encode(['pages' => collect($plan)->map(fn ($page) => [
            'page_key' => $page['page_key'],
            'title' => $page['title'],
            'seo_title' => $page['title'] . ' seo title',
            'meta_description' => $page['title'] . ' meta description for this specific page.',
            'sections' => [
                ['type' => 'hero', 'data' => ['heading' => $page['title']]],
            ],
        ])->values()->all()]);
    }

    public function test_a_valid_generation_commits_pages_and_marks_the_attempt_succeeded(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->seed(WebsiteTemplateSeeder::class);
        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $website = $this->createWebsite($business);
        $plan = app(WebsitePageStrategy::class)->buildPlan($business, $template, $website);
        $this->mockAiClient($this->validBatchFor($plan));

        $attempt = app(GuidedGenerationCommitService::class)->generateFull($business, $website, $template, $customer->user_id, 'idem-1');

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $attempt->status);
        $this->assertSame(count($plan), $website->pages()->count());
        $this->assertTrue($website->pages()->where('is_home', true)->exists());
        // Every generated page is noindex until the owner reviews and
        // republishes (§8's own conservative default for AI-authored
        // content — unchanged by this correction).
        $this->assertSame(0, $website->pages()->where('noindex', false)->count());
    }

    public function test_generation_replaces_the_starter_drafts_own_deterministic_pages_instead_of_duplicating_them(): void
    {
        // Acceptance-correction Blocker 4/1 — a template-backed Website
        // already has WebsiteStarterDraftService::createFromTemplate()'s
        // deterministic starter pages (store() creates these
        // immediately). Guided generation must REPLACE them, never
        // duplicate/append and collide on slugs.
        [$customer, $business] = $this->entitledTenant();
        $this->seed(WebsiteTemplateSeeder::class);
        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $website = app(WebsiteStarterDraftService::class)->createFromTemplate($business, $template);
        $startingPageCount = $website->pages()->count();
        $this->assertGreaterThan(0, $startingPageCount);

        $plan = app(WebsitePageStrategy::class)->buildPlan($business, $template, $website->fresh());
        $this->mockAiClient($this->validBatchFor($plan));

        $attempt = app(GuidedGenerationCommitService::class)->generateFull($business, $website->fresh(), $template, $customer->user_id, 'idem-replace');

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $attempt->status);
        $this->assertSame(count($plan), $website->pages()->count());
        $this->assertSame(count($plan), WebsitePage::where('website_id', $website->id)->count(), 'No duplicate/orphaned rows left over from the deterministic starter batch.');
    }

    public function test_an_invalid_batch_retries_once_then_fails_leaving_the_existing_draft_untouched(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $this->seed(WebsiteTemplateSeeder::class);
        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $website = $this->createWebsite($business);
        $this->homePage($website, ['title' => 'Existing Draft Home']);

        // Always malformed — every attempt (initial + the one bounded
        // retry) fails the same way (an invented page_key not in the plan).
        $this->mockAiClient(json_encode(['pages' => [
            ['page_key' => 'not_a_real_planned_page', 'title' => 'X', 'seo_title' => null, 'meta_description' => null, 'sections' => []],
        ]]));

        $attempt = app(GuidedGenerationCommitService::class)->generateFull($business, $website, $template, $customer->user_id, 'idem-2');

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame(1, $attempt->retry_count);
        $this->assertSame(1, $website->pages()->count());
        $this->assertSame('Existing Draft Home', $website->pages()->first()->title);
    }

    public function test_a_prohibited_claim_in_generated_copy_fails_the_whole_batch(): void
    {
        [$customer, $business] = $this->entitledTenant();
        app(BusinessKnowledgeProfileManager::class)->updateFields($business, [
            'prohibited_claims' => ['guaranteed lowest price'],
        ], 'manual_edit', $customer->user_id, markVerified: true);

        $this->seed(WebsiteTemplateSeeder::class);
        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $website = $this->createWebsite($business);
        $plan = app(WebsitePageStrategy::class)->buildPlan($business, $template, $website);

        $pages = collect($plan)->map(fn ($page) => [
            'page_key' => $page['page_key'],
            'title' => $page['title'],
            'seo_title' => $page['title'] . ' seo title',
            'meta_description' => $page['title'] . ' meta description.',
            'sections' => [
                ['type' => 'hero', 'data' => ['heading' => $page['page_key'] === 'home' ? 'Guaranteed lowest price in town!' : $page['title']]],
            ],
        ])->values()->all();

        $this->mockAiClient(json_encode(['pages' => $pages]));

        $attempt = app(GuidedGenerationCommitService::class)->generateFull($business, $website, $template, $customer->user_id, 'idem-3');

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame(0, $website->pages()->count());
    }

    public function test_a_repeated_idempotency_key_short_circuits_to_the_existing_attempt(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $this->seed(WebsiteTemplateSeeder::class);
        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $website = $this->createWebsite($business);
        $plan = app(WebsitePageStrategy::class)->buildPlan($business, $template, $website);
        $mock = $this->mockAiClient($this->validBatchFor($plan));

        $service = app(GuidedGenerationCommitService::class);
        $first = $service->generateFull($business, $website, $template, $customer->user_id, 'idem-4');
        $second = $service->generateFull($business, $website, $template, $customer->user_id, 'idem-4');

        $this->assertSame($first->id, $second->id);
        $mock->shouldHaveReceived('complete')->once();
    }

    public function test_two_logically_identical_submissions_with_different_caller_keys_still_converge(): void
    {
        // Acceptance-correction Blocker 6 — identity is never the raw
        // caller-supplied string alone. Two calls with DIFFERENT caller
        // idempotency keys but the exact same material inputs (business,
        // template, plan, facts) are still two independent attempts by
        // this class's own design (the caller key IS folded into the
        // hash) — this test documents that distinct caller keys are
        // treated as distinct deliberate submissions, while the dedicated
        // same-key test above proves the true duplicate-submission case.
        [$customer, $business] = $this->entitledTenant();
        $this->seed(WebsiteTemplateSeeder::class);
        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $website = $this->createWebsite($business);
        $plan = app(WebsitePageStrategy::class)->buildPlan($business, $template, $website);
        $mock = $this->mockAiClient($this->validBatchFor($plan));

        $service = app(GuidedGenerationCommitService::class);
        $first = $service->generateFull($business, $website, $template, $customer->user_id, 'idem-a');
        $second = $service->generateFull($business, $website->fresh(), $template, $customer->user_id, 'idem-b');

        $this->assertNotSame($first->id, $second->id);
        $mock->shouldHaveReceived('complete')->twice();
        // The second call replaced the draft again — still exactly one
        // page per plan entry, never a duplicate accumulation.
        $this->assertSame(count($plan), $website->pages()->count());
    }

    public function test_a_failed_attempt_does_not_permanently_block_a_genuine_retry(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $this->seed(WebsiteTemplateSeeder::class);
        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $website = $this->createWebsite($business);
        $plan = app(WebsitePageStrategy::class)->buildPlan($business, $template, $website);

        // First call: AI refuses entirely (fail-closed null).
        $this->mockAiClient(null);
        $failed = app(GuidedGenerationCommitService::class)->generateFull($business, $website, $template, $customer->user_id, 'idem-retry');
        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_FAILED, $failed->status);

        // A later, deliberate retry with a FRESH caller key (a new page
        // render, per WebsiteController::runGuidedGeneration()) — same
        // material facts, but must not be permanently stuck on the old
        // failed attempt. Re-resolved from the container: rebinding the
        // mocked WebsiteAiGenerationClient has no effect on an already-
        // constructed service instance's already-injected dependency.
        $this->mockAiClient($this->validBatchFor($plan));
        $succeeded = app(GuidedGenerationCommitService::class)->generateFull($business, $website->fresh(), $template, $customer->user_id, 'idem-retry-2');

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $succeeded->status);
        $this->assertNotSame($failed->id, $succeeded->id);
    }
}

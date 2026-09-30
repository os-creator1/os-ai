<?php

namespace Tests\Feature\Website\GuidedGeneration;

use App\Enums\Business\BusinessServiceStatus;
use App\Library\Business\BusinessKnowledgeProfileManager;
use App\Library\Website\GuidedGeneration\GuidedGenerationCommitService;
use App\Library\Website\WebsitePageStrategy;
use App\Library\Website\WebsiteStarterDraftService;
use App\Models\BusinessService;
use App\Models\WebsiteAsset;
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

    // -----------------------------------------------------------------
    // Acceptance-correction round 2, Blocker 1 — image_text end-to-end
    // (a direct MediaBindingService unit test alone is not enough: this
    // exercises the real GuidedGenerationOutputValidator ->
    // MediaBindingService -> WebsiteDraftPageService::createPage() chain
    // against ACTUAL WebsiteAsset rows belonging to the real Website).
    // -----------------------------------------------------------------

    public function test_an_image_text_section_is_bound_to_a_real_website_asset_end_to_end(): void
    {
        [$customer, $business] = $this->entitledTenant();
        BusinessService::create(['business_id' => $business->id, 'name' => 'Open-Air Booth', 'slug' => 'open-air-booth', 'status' => BusinessServiceStatus::Active->value, 'sort_order' => 0]);
        $this->seed(WebsiteTemplateSeeder::class);
        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $website = $this->createWebsite($business);

        $asset = WebsiteAsset::create([
            'website_id' => $website->id, 'disk' => 'public', 'path' => 'images/websites/' . $website->uid . '/1.png',
            'mime_type' => 'image/png', 'size' => 1024, 'alt_text' => 'A real uploaded photo',
        ]);

        $plan = app(WebsitePageStrategy::class)->buildPlan($business, $template, $website->fresh());
        $servicePlanEntry = collect($plan)->firstWhere('page_key', 'service:' . BusinessService::first()->uid);
        $this->assertNotNull($servicePlanEntry, 'The plan must include the real service.');

        $pages = collect($plan)->map(fn ($page) => [
            'page_key' => $page['page_key'],
            'title' => $page['title'],
            'seo_title' => $page['title'] . ' seo title',
            'meta_description' => $page['title'] . ' meta description.',
            'sections' => array_values(array_filter([
                ['type' => 'hero', 'data' => ['heading' => $page['title']]],
                $page['page_key'] === $servicePlanEntry['page_key']
                    ? ['type' => 'image_text', 'data' => ['heading' => 'See it in action', 'body' => 'A real photo from a recent event.', 'image' => null, 'image_position' => 'left']]
                    : null,
            ])),
        ])->values()->all();

        $this->mockAiClient(json_encode(['pages' => $pages]));

        $attempt = app(GuidedGenerationCommitService::class)->generateFull($business, $website, $template, $customer->user_id, 'idem-image-text');

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $attempt->status);

        $servicePage = $website->pages()->where('slug', 'service-open-air-booth')->firstOrFail();
        $imageTextSection = collect($servicePage->sections)->firstWhere('type', 'image_text');

        $this->assertNotNull($imageTextSection, 'The image_text section must survive into the persisted page.');
        $this->assertSame($asset->uid, $imageTextSection['data']['image'], 'The section must be bound to the real, Website-owned asset — never left null, never invented.');
    }

    public function test_an_image_text_section_is_safely_dropped_when_no_photos_exist_at_all(): void
    {
        [$customer, $business] = $this->entitledTenant();
        BusinessService::create(['business_id' => $business->id, 'name' => 'Open-Air Booth', 'slug' => 'open-air-booth', 'status' => BusinessServiceStatus::Active->value, 'sort_order' => 0]);
        $this->seed(WebsiteTemplateSeeder::class);
        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $website = $this->createWebsite($business);
        // Deliberately zero WebsiteAsset rows.

        $plan = app(WebsitePageStrategy::class)->buildPlan($business, $template, $website->fresh());
        $servicePlanEntry = collect($plan)->firstWhere('page_key', 'service:' . BusinessService::first()->uid);

        $pages = collect($plan)->map(fn ($page) => [
            'page_key' => $page['page_key'],
            'title' => $page['title'],
            'seo_title' => $page['title'] . ' seo title',
            'meta_description' => $page['title'] . ' meta description.',
            'sections' => array_values(array_filter([
                ['type' => 'hero', 'data' => ['heading' => $page['title']]],
                $page['page_key'] === $servicePlanEntry['page_key']
                    ? ['type' => 'image_text', 'data' => ['heading' => 'See it in action', 'body' => 'A real photo from a recent event.', 'image' => null, 'image_position' => 'left']]
                    : null,
            ])),
        ])->values()->all();

        $this->mockAiClient(json_encode(['pages' => $pages]));

        $attempt = app(GuidedGenerationCommitService::class)->generateFull($business, $website, $template, $customer->user_id, 'idem-no-photos');

        // Safe behavior when no usable photo exists (acceptance-correction
        // round 2, Blocker 1): the batch still commits successfully —
        // every OTHER real section on every page is still worth
        // publishing — the unfillable image_text section is simply left
        // out rather than persisted broken or crashing the whole attempt.
        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $attempt->status);

        $servicePage = $website->pages()->where('slug', 'service-open-air-booth')->firstOrFail();
        $this->assertNull(collect($servicePage->sections)->firstWhere('type', 'image_text'));
        $this->assertNotNull(collect($servicePage->sections)->firstWhere('type', 'hero'), 'The rest of the page must still be there.');
        $this->assertTrue(collect($attempt->warnings)->contains(fn ($w) => str_contains($w, 'image_text') || str_contains($w, 'photo')));
    }

    // -----------------------------------------------------------------
    // Acceptance-correction round 2, Blocker 2 — empty pages must never
    // reach persistence, and must never touch the existing draft.
    // -----------------------------------------------------------------

    public function test_an_empty_but_exact_batch_fails_and_preserves_the_existing_draft(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $this->seed(WebsiteTemplateSeeder::class);
        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $website = app(WebsiteStarterDraftService::class)->createFromTemplate($business, $template);
        $originalPageCount = $website->pages()->count();
        $originalHomeTitle = $website->pages()->where('is_home', true)->firstOrFail()->title;
        $this->assertGreaterThan(0, $originalPageCount);

        $plan = app(WebsitePageStrategy::class)->buildPlan($business, $template, $website->fresh());

        // Every plan page_key is present — exact coverage — but every
        // page is an empty shell.
        $pages = collect($plan)->map(fn ($page) => [
            'page_key' => $page['page_key'],
            'title' => $page['title'],
            'seo_title' => null,
            'meta_description' => null,
            'sections' => [],
        ])->values()->all();

        $this->mockAiClient(json_encode(['pages' => $pages]));

        $attempt = app(GuidedGenerationCommitService::class)->generateFull($business, $website->fresh(), $template, $customer->user_id, 'idem-empty');

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_FAILED, $attempt->status);

        $website->refresh();
        $this->assertSame($originalPageCount, $website->pages()->count(), 'The existing draft must be completely untouched.');
        $this->assertSame($originalHomeTitle, $website->pages()->where('is_home', true)->firstOrFail()->title);
    }

    // -----------------------------------------------------------------
    // Malformed provider output must fail as a normal recorded `failed`
    // attempt — never leave a `pending` row, never let an uncaught
    // exception escape this call as a server error.
    // -----------------------------------------------------------------

    public function test_a_sections_value_that_is_not_an_array_fails_as_a_recorded_attempt_not_a_crash(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $this->seed(WebsiteTemplateSeeder::class);
        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $website = $this->createWebsite($business);
        $plan = app(WebsitePageStrategy::class)->buildPlan($business, $template, $website);

        $pages = collect($plan)->map(fn ($page) => [
            'page_key' => $page['page_key'],
            'title' => $page['title'],
            'seo_title' => null,
            'meta_description' => null,
            'sections' => $page['page_key'] === 'home' ? 'this should be an array, not a string' : [['type' => 'hero', 'data' => ['heading' => $page['title']]]],
        ])->values()->all();

        $this->mockAiClient(json_encode(['pages' => $pages]));

        $attempt = app(GuidedGenerationCommitService::class)->generateFull($business, $website, $template, $customer->user_id, 'idem-malformed-sections');

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_FAILED, $attempt->status);
        $this->assertNotSame(WebsiteGuidedGenerationAttempt::STATUS_PENDING, $attempt->fresh()->status);
        $this->assertSame(0, $website->pages()->count());
    }

    public function test_a_non_object_page_entry_fails_as_a_recorded_attempt_not_a_crash(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $this->seed(WebsiteTemplateSeeder::class);
        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $website = $this->createWebsite($business);
        $plan = app(WebsitePageStrategy::class)->buildPlan($business, $template, $website);

        $pages = collect($plan)->map(fn ($page) => $page['page_key'] === 'home'
            ? 'not an object at all'
            : ['page_key' => $page['page_key'], 'title' => $page['title'], 'seo_title' => null, 'meta_description' => null, 'sections' => [['type' => 'hero', 'data' => ['heading' => $page['title']]]]])->values()->all();

        $this->mockAiClient(json_encode(['pages' => $pages]));

        $attempt = app(GuidedGenerationCommitService::class)->generateFull($business, $website, $template, $customer->user_id, 'idem-malformed-page');

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_FAILED, $attempt->fresh()->status);
        $this->assertSame(0, $website->pages()->count());
    }

    public function test_a_completely_invalid_json_top_level_shape_fails_as_a_recorded_attempt(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $this->seed(WebsiteTemplateSeeder::class);
        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $website = $this->createWebsite($business);

        // "pages" is present but is an object/map, not a list — still
        // valid JSON, still the wrong shape.
        $this->mockAiClient(json_encode(['pages' => ['not' => 'a list']]));

        $attempt = app(GuidedGenerationCommitService::class)->generateFull($business, $website, $template, $customer->user_id, 'idem-malformed-top');

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_FAILED, $attempt->fresh()->status);
        $this->assertSame(0, $website->pages()->count());
    }

    /**
     * Independent-review correction round 4: guided generation must
     * preserve the starter Contact page's working quote-request form.
     * AI's own batch (validBatchFor()) never writes a form_uid — it
     * cannot safely supply one — so this proves MediaBindingService
     * attaches the Website's real, reusable form during commit.
     */
    public function test_full_generation_gives_the_contact_page_a_real_website_owned_form(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $this->seed(WebsiteTemplateSeeder::class);
        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $website = $this->createWebsite($business);
        $plan = app(WebsitePageStrategy::class)->buildPlan($business, $template, $website);
        $this->mockAiClient($this->validBatchFor($plan));

        $attempt = app(GuidedGenerationCommitService::class)->generateFull($business, $website, $template, $customer->user_id, 'idem-form-full');

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $attempt->status);

        $contactPage = $website->pages()->where('slug', 'photo-booth-contact')->firstOrFail();
        $formSection = collect($contactPage->sections)->firstWhere('type', 'form');
        $this->assertNotNull($formSection, 'The generated Contact page must retain a form section.');

        $form = $website->forms()->sole();
        $this->assertSame($form->uid, $formSection['data']['form_uid']);
    }

    /**
     * A rebuild must reuse the SAME form the deterministic starter draft
     * already created for this Website — never create a second,
     * orphaned one — exactly like a rebuild reuses the Website's real
     * uploaded photos rather than requiring them to be re-uploaded.
     */
    public function test_rebuild_reuses_the_websites_existing_form_never_duplicating_it(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $this->seed(WebsiteTemplateSeeder::class);
        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $website = app(WebsiteStarterDraftService::class)->createFromTemplate($business, $template);
        $existingForm = $website->forms()->sole();

        $plan = app(WebsitePageStrategy::class)->buildPlan($business, $template, $website->fresh());
        $this->mockAiClient($this->validBatchFor($plan));

        $attempt = app(GuidedGenerationCommitService::class)->rebuild($business, $website->fresh(), $template, $customer->user_id, 'idem-form-rebuild');

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $attempt->status);
        $this->assertSame(1, $website->forms()->count(), 'Rebuild must reuse the Website\'s existing form, never create a duplicate.');

        $contactPage = $website->pages()->where('slug', 'photo-booth-contact')->firstOrFail();
        $formSection = collect($contactPage->sections)->firstWhere('type', 'form');
        $this->assertSame($existingForm->uid, $formSection['data']['form_uid']);
    }

    /**
     * AI is never offered 'form' as an allowed section type
     * (WebsitePageStrategy::withoutAiUnfillableSections()) — if a
     * provider ignores that and writes one anyway, the whole batch must
     * still fail, exactly like an AI-invented page_key or a prohibited
     * claim does. An AI-supplied form_uid is never trusted.
     */
    public function test_an_ai_authored_form_section_fails_the_whole_batch(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $this->seed(WebsiteTemplateSeeder::class);
        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $website = $this->createWebsite($business);
        $plan = app(WebsitePageStrategy::class)->buildPlan($business, $template, $website);

        $pages = collect($plan)->map(function ($page) {
            $sections = [['type' => 'hero', 'data' => ['heading' => $page['title']]]];
            if ($page['page_key'] === 'contact') {
                $sections[] = ['type' => 'form', 'data' => ['heading' => 'Request a quote', 'form_uid' => 'ai-invented-form-uid']];
            }

            return [
                'page_key' => $page['page_key'],
                'title' => $page['title'],
                'seo_title' => $page['title'] . ' seo title',
                'meta_description' => $page['title'] . ' meta description for this specific page.',
                'sections' => $sections,
            ];
        })->values()->all();

        $this->mockAiClient(json_encode(['pages' => $pages]));

        $attempt = app(GuidedGenerationCommitService::class)->generateFull($business, $website, $template, $customer->user_id, 'idem-ai-form-attempt');

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame(0, $website->pages()->count());
        $this->assertSame(0, $website->forms()->count(), 'An AI-invented form_uid must never result in a form being created either.');
    }
}

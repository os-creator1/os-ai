<?php

namespace Tests\Feature\Website;

use App\Enums\Business\BusinessServiceStatus;
use App\Enums\Catalog\CatalogItemLifecycleState;
use App\Enums\Website\WebsiteAssetPurpose;
use App\Library\Ai\AiModelRouter;
use App\Library\Ai\Contracts\AiCompletionClient;
use App\Library\Ai\Providers\FakeAiCompletionClient;
use App\Library\Website\GuidedGeneration\GuidedWebsiteGenerationClient;
use App\Library\Website\WebsiteAiDraftGenerator;
use App\Library\Website\WebsitePageStrategy;
use App\Models\AiUsageLedgerEntry;
use App\Models\BusinessBackdrop;
use App\Models\BusinessLocation;
use App\Models\BusinessService;
use App\Models\CatalogItem;
use App\Models\Website;
use App\Models\WebsiteAsset;
use Database\Seeders\PhotoboothWebsiteSetupQuestionnaireSeeder;
use Database\Seeders\WebsiteTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Independent-review correction round 3 (items 3 and 4) — the earlier
 * envelope test only ever checked router CONFIGURATION and the plan
 * builder's own page counts. Neither proves what a real call site
 * actually asks the provider for, nor that the real serialized request
 * for the true maximum accepted questionnaire genuinely fits the
 * configured budget. This suite binds FakeAiCompletionClient (the
 * established test double for App\Library\Ai\Contracts\AiCompletionClient
 * — see AiGatewayTest) so the REAL AiGateway/AiUsageLedgerManager pipeline
 * runs end to end and the REAL AiCompletionRequest each call site
 * produces can be inspected directly, alongside the REAL persisted
 * AiUsageLedgerEntry.idempotency_key. Never calls a real provider.
 */
class WebsiteAiEnvelopeCaptureTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.openai.active' => true]);
        config(['ai.enforce_budgets_for_existing_categories' => false]);

        $this->fakeClient = new FakeAiCompletionClient();
        $this->app->instance(AiCompletionClient::class, $this->fakeClient);
    }

    private FakeAiCompletionClient $fakeClient;

    /**
     * Item 3 — guided full-site generation is the one call site that
     * genuinely needs the route's full 8,000-output-token envelope, and
     * must carry the caller's own stable idempotency key straight
     * through to the ledger, never a meaningless fresh uuid.
     */
    public function test_guided_generation_call_site_requests_the_full_envelope_with_its_own_stable_idempotency_key(): void
    {
        [, $business] = $this->entitledTenant();
        $this->fakeClient->setDefaultResult(\App\Library\Ai\AiCompletionResult::success(
            content: json_encode(['pages' => []]),
            providerModel: 'gpt-4o-mini',
            inputTokens: 100,
            outputTokens: 20,
        ));

        $plan = [['page_key' => 'home', 'page_type' => 'home', 'is_home' => true, 'allowed_section_types' => ['hero'], 'entity' => null]];

        app(GuidedWebsiteGenerationClient::class)->generate($business, $plan, null, 'my-stable-guided-key:retry0');

        $this->assertCount(1, $this->fakeClient->requests());
        $this->assertSame(GuidedWebsiteGenerationClient::MAX_OUTPUT_TOKENS, $this->fakeClient->requests()[0]->maxOutputTokens);
        $this->assertSame(8_000, $this->fakeClient->requests()[0]->maxOutputTokens);

        $entry = AiUsageLedgerEntry::latest('id')->first();
        $this->assertNotNull($entry);
        $this->assertSame('my-stable-guided-key:retry0', $entry->idempotency_key);
    }

    /**
     * Item 3 — the OLD draft generator shares the same route/category but
     * must never inherit its 8,000-token ceiling: its own schema caps it
     * at a handful of short pages.
     */
    public function test_draft_generator_call_site_requests_only_800_output_tokens(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->fakeClient->setDefaultResult(\App\Library\Ai\AiCompletionResult::success(
            content: json_encode(['pages' => [['title' => 'Home', 'is_home' => true, 'sections' => [$this->section('hero')]]]]),
            providerModel: 'gpt-4o-mini',
            inputTokens: 100,
            outputTokens: 20,
        ));

        app(WebsiteAiDraftGenerator::class)->generate($website);

        $this->assertNotEmpty($this->fakeClient->requests());
        $this->assertSame(800, $this->fakeClient->requests()[0]->maxOutputTokens);
    }

    /**
     * Item 3 — "Improve with AI" must ask for a small ceiling, never the
     * shared route's full envelope, and must carry a real, non-empty
     * durable idempotency key (item 2) all the way to the ledger.
     */
    public function test_improve_call_site_requests_a_small_output_ceiling_with_a_real_idempotency_key(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->seed(WebsiteTemplateSeeder::class);
        $this->seed(PhotoboothWebsiteSetupQuestionnaireSeeder::class);
        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern']);

        $this->fakeClient->setDefaultResult(\App\Library\Ai\AiCompletionResult::success(
            content: json_encode(['body' => 'Improved body']),
            providerModel: 'gpt-4o-mini',
            inputTokens: 50,
            outputTokens: 10,
        ));

        $this->post(route('customer.workspaces.businesses.website.setup.custom-section.improve', [$workspace->uid, $business->uid]), [
            'items' => [['name' => 'Section', 'body' => 'My body']],
            'answers_revision' => 1,
        ])->assertSessionHas('status', 'success');

        $this->assertCount(1, $this->fakeClient->requests());
        $this->assertLessThanOrEqual(400, $this->fakeClient->requests()[0]->maxOutputTokens);
        $this->assertLessThan(GuidedWebsiteGenerationClient::MAX_OUTPUT_TOKENS, $this->fakeClient->requests()[0]->maxOutputTokens, 'Improve must never ask for anywhere near the full-site envelope.');

        $entry = AiUsageLedgerEntry::latest('id')->first();
        $this->assertNotNull($entry);
        $this->assertNotEmpty($entry->idempotency_key);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $entry->idempotency_key, 'The Improve idempotency key must be the documented stable sha256 hash, never a random uuid.');
    }

    /**
     * Item 4 — builds a TRUE maximum accepted Photobooth questionnaire's
     * worth of canonical records (the real aggregate maximums
     * QuestionnaireAnswerValidator/WebsitePageStrategy actually allow:
     * 60 services across the two service steps, 30 packages, 20
     * qualifying locations, long descriptions, a full custom section),
     * serializes the REAL request GuidedWebsiteGenerationClient produces
     * for it, and runs it through the SAME conservative estimator the
     * gateway itself uses (AiModelRouter::estimateInputTokens) — proving
     * the real request, not merely the config, fits the route's 12,000-
     * input-token ceiling.
     */
    public function test_the_true_maximum_questionnaire_serializes_within_the_input_token_envelope(): void
    {
        [, $business] = $this->entitledTenant(['name' => 'Snap Booth Photography Company']);
        $this->seed(WebsiteTemplateSeeder::class);
        $this->seed(PhotoboothWebsiteSetupQuestionnaireSeeder::class);
        $website = $this->createWebsite($business, ['template_key' => 'photo_booth_modern']);
        $template = \App\Models\WebsiteTemplate::findActiveOrFail('photo_booth_modern');

        $longDescription = str_repeat('This is a genuinely long, real service description with real words. ', 40); // ~2800 chars, exceeds MAX_ITEM_DESCRIPTION

        // The two service-contributing questionnaire steps together allow
        // up to 60 real BusinessService rows (QuestionnaireAnswerValidator
        // ::MAX_REPEATABLE_ITEMS = 30 each).
        for ($i = 0; $i < 60; $i++) {
            BusinessService::create([
                'business_id' => $business->id, 'name' => "Photo Booth Package Type Number {$i}",
                'slug' => "service-{$i}", 'description' => $longDescription,
                'status' => BusinessServiceStatus::Active->value, 'sort_order' => $i,
            ]);
        }

        // The packages step allows up to 30.
        for ($i = 0; $i < 30; $i++) {
            CatalogItem::create([
                'business_id' => $business->id, 'type' => 'package', 'name' => "Premium Package Option {$i}",
                'description' => $longDescription, 'price_minor' => 50000, 'currency_code' => 'USD', 'position' => $i,
            ]);
        }

        // 20 real, independently-qualifying locations (2+ signals each).
        for ($i = 0; $i < 20; $i++) {
            BusinessLocation::create([
                'business_id' => $business->id, 'name' => "Location {$i}", 'service_mode' => 'storefront',
                'address_line_1' => "{$i} Main Street", 'city' => "City{$i}", 'region' => 'IL',
                'public_address' => true, 'service_area_cities' => ["City{$i}", "Suburb{$i}A", "Suburb{$i}B"],
                'is_primary' => false,
            ]);
        }

        BusinessBackdrop::create(['business_id' => $business->id, 'name' => 'Gold Sequin', 'availability' => true]);

        for ($i = 0; $i < 6; $i++) {
            WebsiteAsset::create([
                'website_id' => $website->id, 'disk' => 'public', 'path' => "images/websites/x/{$i}.png",
                'mime_type' => 'image/png', 'size' => 100, 'purpose' => WebsiteAssetPurpose::Gallery->value,
            ]);
        }

        $customSection = ['title' => 'Red Carpet Experience', 'layout' => 'stacked', 'body' => str_repeat('A polished editorial paragraph. ', 100), 'images' => []];

        $plan = app(WebsitePageStrategy::class)->buildPlan($business, $template, $website, $customSection);
        $aiPlan = WebsitePageStrategy::withoutAiUnfillableSections($plan);

        $this->assertLessThanOrEqual(WebsitePageStrategy::MAX_TOTAL_PAGES, count($plan), 'Precondition: the plan itself must already be capped.');

        $this->fakeClient->setDefaultResult(\App\Library\Ai\AiCompletionResult::success(
            content: json_encode(['pages' => []]),
            providerModel: 'gpt-4o-mini',
            inputTokens: 100,
            outputTokens: 20,
        ));

        app(GuidedWebsiteGenerationClient::class)->generate($business, $aiPlan, null, 'max-questionnaire-proof');

        $this->assertCount(1, $this->fakeClient->requests());
        $realMessages = $this->fakeClient->requests()[0]->messages;

        $estimatedInputTokens = app(AiModelRouter::class)->estimateInputTokens($realMessages);

        $this->assertLessThanOrEqual(
            12_000,
            $estimatedInputTokens,
            "The real serialized request for the true maximum questionnaire must fit the route's 12,000-input-token ceiling; estimated {$estimatedInputTokens}."
        );
    }

    /**
     * Item 4 — a representative (not merely theoretical-schema-maximum)
     * valid AI response for the CAPPED page plan must itself fit the
     * 8,000-output-token limit: builds one page-content object per
     * planned page using realistic sizes matching what the prompt
     * actually instructs the model to write (a title, a bounded
     * seo_title/meta_description, and 2 modestly-sized sections per
     * page), then applies the SAME conservative estimator to the whole
     * serialized response.
     */
    public function test_a_representative_response_for_the_capped_plan_fits_the_output_token_envelope(): void
    {
        [, $business] = $this->entitledTenant();
        $this->seed(WebsiteTemplateSeeder::class);
        $website = $this->createWebsite($business, ['template_key' => 'photo_booth_modern']);
        $template = \App\Models\WebsiteTemplate::findActiveOrFail('photo_booth_modern');

        for ($i = 0; $i < 60; $i++) {
            BusinessService::create([
                'business_id' => $business->id, 'name' => "Service {$i}", 'slug' => "service-{$i}",
                'status' => BusinessServiceStatus::Active->value, 'sort_order' => $i,
            ]);
        }
        for ($i = 0; $i < 6; $i++) {
            WebsiteAsset::create([
                'website_id' => $website->id, 'disk' => 'public', 'path' => "images/websites/x/{$i}.png",
                'mime_type' => 'image/png', 'size' => 100, 'purpose' => WebsiteAssetPurpose::Gallery->value,
            ]);
        }

        $plan = app(WebsitePageStrategy::class)->buildPlan($business, $template, $website);
        $this->assertLessThanOrEqual(WebsitePageStrategy::MAX_TOTAL_PAGES, count($plan));

        $representativePages = array_map(fn (array $page) => [
            'page_key' => $page['page_key'],
            'title' => $page['title'] . ' — A Realistic Page Title',
            'seo_title' => 'A genuinely descriptive SEO title under seventy characters',
            'meta_description' => str_repeat('A real one-sentence page summary. ', 4),
            'sections' => [
                ['type' => 'text', 'data' => ['heading' => 'A Real Heading', 'body' => str_repeat('Realistic marketing copy for this section. ', 15)]],
                ['type' => 'text', 'data' => ['heading' => 'A Second Heading', 'body' => str_repeat('More realistic marketing copy. ', 15)]],
            ],
        ], $plan);

        $responseJson = json_encode(['pages' => $representativePages]);
        $estimatedOutputTokens = app(AiModelRouter::class)->estimateInputTokens([['role' => 'assistant', 'content' => $responseJson]]);

        $this->assertLessThanOrEqual(
            8_000,
            $estimatedOutputTokens,
            "A representative response for the capped page plan must fit the 8,000-output-token limit; estimated {$estimatedOutputTokens} for " . count($plan) . ' pages.'
        );
    }

    private function section(string $type): array
    {
        return match ($type) {
            'hero' => ['type' => 'hero', 'data' => ['heading' => 'Welcome']],
            'text' => ['type' => 'text', 'data' => ['body' => 'Some text.']],
            default => ['type' => $type, 'data' => []],
        };
    }
}

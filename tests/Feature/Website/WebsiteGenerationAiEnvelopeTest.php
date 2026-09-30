<?php

namespace Tests\Feature\Website;

use App\Enums\Business\BusinessServiceStatus;
use App\Library\Ai\AiModelRouter;
use App\Library\Ai\Enums\AiModelRoute;
use App\Library\Ai\Enums\AiUsageCategory;
use App\Library\Website\WebsitePageStrategy;
use App\Models\BusinessService;
use Database\Seeders\WebsiteTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Independent-review correction round 2 — guided website generation asks
 * for one response containing an entire multi-page website as JSON;
 * `routine`'s shared 4,000 input / 800 output token envelope (sized for a
 * single short draft) could never actually fit that shape, so production
 * generation could never succeed against it. Proves the new, dedicated
 * `website_generation` route's real, final configured values, and that
 * WebsitePageStrategy bounds a plan's own page count so a questionnaire
 * cannot grow the prompt past what this envelope can afford.
 *
 * Never calls a real AI provider — this suite is entirely about the
 * config/routing layer and the deterministic plan builder, neither of
 * which ever reaches a provider.
 */
class WebsiteGenerationAiEnvelopeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    public function test_website_generation_resolves_its_own_dedicated_route_not_the_shared_routine_envelope(): void
    {
        $router = app(AiModelRouter::class);

        $this->assertSame(AiModelRoute::WebsiteGeneration, $router->defaultRouteFor(AiUsageCategory::WebsiteGeneration));
    }

    /**
     * The exact final configured values this report cites: 12,000 input /
     * 8,000 output tokens at gpt-4o-mini's configured $0.15 / $0.60 per
     * million token prices -> $0.0066 per call, $0.0132 for one call plus
     * its one bounded corrective retry.
     */
    public function test_the_website_generation_envelopes_exact_configured_cost_ceiling(): void
    {
        $config = config('ai.routes.website_generation');

        $this->assertSame(12_000, $config['max_input_tokens']);
        $this->assertSame(8_000, $config['max_output_tokens']);
        $this->assertSame(150_000, $config['input_price_microusd_per_mtok']);
        $this->assertSame(600_000, $config['output_price_microusd_per_mtok']);

        $worstCaseInputMicrousd = (int) ceil(12_000 * $config['input_price_microusd_per_mtok'] / 1_000_000);
        $worstCaseOutputMicrousd = (int) ceil(8_000 * $config['output_price_microusd_per_mtok'] / 1_000_000);
        $worstCaseTotalMicrousd = $worstCaseInputMicrousd + $worstCaseOutputMicrousd;

        $this->assertSame(1_800, $worstCaseInputMicrousd);
        $this->assertSame(4_800, $worstCaseOutputMicrousd);
        $this->assertSame(6_600, $worstCaseTotalMicrousd, 'One full-site call costs $0.0066 at the configured rates.');
        $this->assertSame(13_200, $worstCaseTotalMicrousd * 2, 'One call plus its one bounded corrective retry costs $0.0132.');

        // The route's own cap must comfortably afford the worst case
        // (with the small framing-token margin this route's own config
        // comment documents) — a legitimate maximum-size request is never
        // refused as "too expensive" by its own route's cap.
        $this->assertGreaterThanOrEqual($worstCaseTotalMicrousd, $config['max_request_cost_microusd']);
    }

    public function test_the_website_generation_envelope_never_raised_limits_for_unrelated_categories(): void
    {
        $router = app(AiModelRouter::class);

        $this->assertSame(AiModelRoute::Routine, $router->defaultRouteFor(AiUsageCategory::CampaignMessageDraft));
        $this->assertSame(AiModelRoute::Routine, $router->defaultRouteFor(AiUsageCategory::AgencyProspectReply));
        $this->assertSame(AiModelRoute::Routine, $router->defaultRouteFor(AiUsageCategory::CooDiagnosis));

        $routine = $router->config(AiModelRoute::Routine);
        $this->assertSame(800, $routine['max_output_tokens'], 'The shared routine envelope must stay exactly as it was for every other category.');
    }

    /**
     * A questionnaire whose PER-STEP repeatable-group limits are each
     * individually reasonable (booth_types + services_event_types, each
     * up to QuestionnaireAnswerValidator::MAX_REPEATABLE_ITEMS = 30) could
     * still aggregate into 60+ real BusinessService rows. Proves the plan
     * itself is reduced deterministically to a bounded page count BEFORE
     * AI is ever asked for anything — never merely relying on the
     * per-step limits alone.
     */
    public function test_an_oversized_service_count_is_reduced_to_the_deterministic_page_cap_before_generation(): void
    {
        [, $business] = $this->entitledTenant();
        $this->seed(WebsiteTemplateSeeder::class);
        $website = $this->createWebsite($business);
        $template = \App\Models\WebsiteTemplate::findActiveOrFail('photo_booth_modern');

        for ($i = 0; $i < WebsitePageStrategy::MAX_SERVICE_DETAIL_PAGES + 15; $i++) {
            BusinessService::create([
                'business_id' => $business->id,
                'name' => "Service {$i}",
                'slug' => "service-{$i}",
                'status' => BusinessServiceStatus::Active->value,
                'sort_order' => $i,
            ]);
        }

        $plan = app(WebsitePageStrategy::class)->buildPlan($business, $template, $website);
        $serviceDetailPages = collect($plan)->where('page_type', 'service_detail');

        $this->assertSame(WebsitePageStrategy::MAX_SERVICE_DETAIL_PAGES, $serviceDetailPages->count(), 'The plan must be reduced deterministically to the configured cap, never left unbounded.');
    }
}

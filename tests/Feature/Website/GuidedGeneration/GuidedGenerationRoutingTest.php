<?php

namespace Tests\Feature\Website\GuidedGeneration;

use App\Library\Website\WebsitePageStrategy;
use App\Models\Website;
use App\Models\WebsiteGuidedGenerationAttempt;
use App\Models\WebsitePage;
use App\Models\WebsiteTemplate;
use Database\Seeders\WebsiteTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Acceptance-correction Blocker 1: proves the REAL customer-facing
 * `POST .../website/generate` and `POST .../website/rebuild` routes —
 * not just the underlying service in isolation — actually invoke
 * GuidedGenerationCommitService for a template-backed Website, and
 * that the legacy WebsiteAiDraftGenerator path stays intact and
 * exclusively reachable ONLY for a non-template (`design`-based)
 * Website. WebsiteAiGenerationTest.php covers the legacy path's own
 * behavior in full; this file only proves WHICH path a request reaches.
 */
class GuidedGenerationRoutingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    private function planFor(Website $website, WebsiteTemplate $template, $business): array
    {
        return app(WebsitePageStrategy::class)->buildPlan($business, $template, $website);
    }

    private function guidedBatchFor(array $plan): string
    {
        return json_encode(['pages' => collect($plan)->map(fn ($page) => [
            'page_key' => $page['page_key'],
            'title' => $page['title'],
            'seo_title' => $page['title'] . ' | Guided',
            'meta_description' => $page['title'] . ' guided meta description.',
            'sections' => [['type' => 'hero', 'data' => ['heading' => $page['title']]]],
        ])->values()->all()]);
    }

    public function test_generate_route_uses_guided_generation_for_a_template_backed_website(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->seed(WebsiteTemplateSeeder::class);
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.store', [$workspace->uid, $business->uid]), [
            'template_key' => 'photo_booth_modern',
        ])->assertRedirect();

        $website = Website::where('business_id', $business->id)->firstOrFail();
        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $plan = $this->planFor($website, $template, $business);

        $this->mockAiClient($this->guidedBatchFor($plan));

        $response = $this->post(route('customer.workspaces.businesses.website.generate', [$workspace->uid, $business->uid]), [
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertRedirect(route('customer.workspaces.businesses.website.pages.index', [$workspace->uid, $business->uid]));
        $this->assertSame('success', session('status'));

        // The AI-authored seo_title ("... | Guided") only exists if the
        // GUIDED pipeline actually ran — the legacy WebsiteAiDraftGenerator
        // has no concept of page_key/seo_title-from-AI at all.
        $this->assertDatabaseHas('website_pages', [
            'website_id' => $website->id,
            'seo_title' => 'Home | Guided',
        ]);
        $this->assertSame(count($plan), WebsitePage::where('website_id', $website->id)->count());

        $attempt = WebsiteGuidedGenerationAttempt::where('website_id', $website->id)->firstOrFail();
        $this->assertSame(WebsiteGuidedGenerationAttempt::MODE_FULL_GENERATION, $attempt->mode);
        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $attempt->status);
    }

    public function test_generate_route_still_uses_the_legacy_generator_for_a_non_template_website(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        // 'blank' — the legacy WebsiteAiDraftGenerator only ever runs
        // "before any pages exist on this Website" (its own precondition,
        // unchanged by this correction); a non-blank starter design
        // creates a Home page immediately, which would refuse it for a
        // reason unrelated to which generator handled the request.
        $this->post(route('customer.workspaces.businesses.website.store', [$workspace->uid, $business->uid]), [
            'design' => 'blank',
        ])->assertRedirect();

        $website = Website::where('business_id', $business->id)->firstOrFail();
        $this->assertNull($website->template_key);
        $this->assertSame(0, $website->pages()->count());

        // An old-shape payload (page_type/is_home/slug — no page_key at
        // all) is exactly what WebsiteAiDraftGenerator expects and the
        // guided pipeline would reject outright (no plan page_key
        // matches). Its success here proves the LEGACY generator handled
        // this request.
        $this->mockAiClient(json_encode(['pages' => [
            ['title' => 'Home', 'is_home' => true, 'sections' => [$this->section('hero')]],
        ]]));

        $response = $this->post(route('customer.workspaces.businesses.website.generate', [$workspace->uid, $business->uid]));

        $response->assertRedirect(route('customer.workspaces.businesses.website.pages.index', [$workspace->uid, $business->uid]));
        $this->assertSame('success', session('status'));
        $this->assertSame(0, WebsiteGuidedGenerationAttempt::where('website_id', $website->id)->count(), 'The legacy path must never write a guided-generation attempt row.');
    }

    public function test_rebuild_route_uses_guided_generation_and_replaces_the_draft(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->seed(WebsiteTemplateSeeder::class);
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.store', [$workspace->uid, $business->uid]), [
            'template_key' => 'photo_booth_modern',
        ])->assertRedirect();

        $website = Website::where('business_id', $business->id)->firstOrFail();
        $originalPageCount = $website->pages()->count();
        $this->assertGreaterThan(0, $originalPageCount);

        $template = WebsiteTemplate::findActiveOrFail('photo_booth_editorial');
        $plan = $this->planFor($website->fresh(), $template, $business);
        $this->mockAiClient($this->guidedBatchFor($plan));

        $response = $this->post(route('customer.workspaces.businesses.website.rebuild', [$workspace->uid, $business->uid]), [
            'template_key' => 'photo_booth_editorial',
            'confirm_rebuild' => '1',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertRedirect(route('customer.workspaces.businesses.website.pages.index', [$workspace->uid, $business->uid]));
        $this->assertSame('success', session('status'));

        $website->refresh();
        $this->assertSame('photo_booth_editorial', $website->template_key);
        $this->assertSame(count($plan), $website->pages()->count());
        $this->assertDatabaseHas('website_pages', [
            'website_id' => $website->id,
            'seo_title' => 'Home | Guided',
        ]);

        $attempt = WebsiteGuidedGenerationAttempt::where('website_id', $website->id)
            ->where('mode', WebsiteGuidedGenerationAttempt::MODE_REBUILD)
            ->firstOrFail();
        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $attempt->status);
    }

    public function test_a_failed_guided_generation_leaves_the_existing_draft_untouched_via_the_real_route(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->seed(WebsiteTemplateSeeder::class);
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.store', [$workspace->uid, $business->uid]), [
            'template_key' => 'photo_booth_modern',
        ])->assertRedirect();

        $website = Website::where('business_id', $business->id)->firstOrFail();
        $originalPageCount = $website->pages()->count();
        $originalHomeTitle = $website->pages()->where('is_home', true)->firstOrFail()->title;

        // Always malformed (an invented page_key) — the retry doesn't help.
        $this->mockAiClient(json_encode(['pages' => [
            ['page_key' => 'not_a_real_page', 'title' => 'X', 'seo_title' => null, 'meta_description' => null, 'sections' => []],
        ]]));

        $response = $this->post(route('customer.workspaces.businesses.website.generate', [$workspace->uid, $business->uid]));

        $response->assertRedirect(route('customer.workspaces.businesses.website.pages.index', [$workspace->uid, $business->uid]));
        $this->assertSame('error', session('status'));

        $website->refresh();
        $this->assertSame($originalPageCount, $website->pages()->count());
        $this->assertSame($originalHomeTitle, $website->pages()->where('is_home', true)->firstOrFail()->title);
    }
}

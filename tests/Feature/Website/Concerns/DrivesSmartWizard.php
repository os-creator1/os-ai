<?php

namespace Tests\Feature\Website\Concerns;

use App\Library\Website\WebsiteAiGenerationClient;
use App\Models\QuestionnaireResponse;
use Database\Seeders\PhotoboothWebsiteSetupQuestionnaireV2Seeder;
use Database\Seeders\WebsiteTemplateSeeder;
use Illuminate\Testing\TestResponse;

/**
 * Drives the v2 (screen-grouped) Photo Booth setup over HTTP, exactly as
 * the browser does: one POST per SCREEN, namespaced `s[<stepKey>][...]`
 * fields on multi-step screens and plain `value` / `items` on single-step
 * ones. Requires CreatesWebsiteFixtures.
 */
trait DrivesSmartWizard
{
    protected function seedSmartWizard(): void
    {
        $this->seed(WebsiteTemplateSeeder::class);
        // v1 first (fresh database), then v2 supersedes it: new setups start on v2.
        $this->seed(PhotoboothWebsiteSetupQuestionnaireV2Seeder::class);
    }

    protected function wizardUrl(object $workspace, object $business, string $route, array $extra = []): string
    {
        return route('customer.workspaces.businesses.website.' . $route, array_merge([$workspace->uid, $business->uid], $extra));
    }

    protected function startV2Setup(object $workspace, object $business): void
    {
        $this->post($this->wizardUrl($workspace, $business, 'setup.template'), ['template_key' => 'photo_booth_modern']);
    }

    protected function activeResponse(object $business): QuestionnaireResponse
    {
        return QuestionnaireResponse::where('business_id', $business->id)->where('status', 'in_progress')->sole();
    }

    protected function postScreen(object $workspace, object $business, string $screenKey, array $payload): TestResponse
    {
        return $this->post(
            $this->wizardUrl($workspace, $business, 'setup.autosave', [$screenKey]),
            $payload + ['answers_revision' => $this->activeResponse($business)->answers_revision],
        );
    }

    /**
     * The default answer for every v2 screen, keyed by the screen's first
     * step key. `$overrides` replaces a screen's whole payload.
     *
     * @return array<string, array>
     */
    protected function v2ScreenPayloads(): array
    {
        return [
            'business_name' => ['s' => [
                'business_name' => ['value' => 'Snap Booth Co'],
                'phone' => ['value' => '6305550100'],
                'email' => ['value' => 'hello@snapbooth.test'],
                'primary_cta' => ['value' => 'quote_request'],
            ]],
            'service_area_cities' => ['value' => ['Manhattan, NY', 'Brooklyn', 'Queens']],
            'booth_types' => ['s' => [
                'booth_types' => ['items' => [['name' => 'Open Air Booth', 'description' => 'Our flagship booth.']]],
                'services_event_types' => ['items' => [['name' => 'Weddings']]],
            ]],
            'packages' => ['items' => [[
                'name' => 'Essential Booth', 'price' => '699.00', 'currency_code' => 'USD',
                'description' => 'The basics.', 'features' => ['Unlimited prints', 'Props included'], 'featured' => '1',
            ]]],
            'backdrops' => ['s' => [
                'backdrops' => ['items' => []],
                'gallery' => ['value' => '0'],
            ]],
            'testimonials' => ['items' => []],
            'about_story' => ['s' => [
                'about_story' => ['value' => 'We throw the best photo booth parties in town.'],
                'brand_personality' => ['value' => 'playful'],
            ]],
            'faq_items' => ['s' => [
                'faq_items' => ['items' => []],
                'custom_section' => ['items' => []],
            ]],
            'contact_form_fields' => ['value' => ['name', 'phone', 'email', 'message']],
        ];
    }

    /**
     * Walks every screen. Returns the in_progress response on the review screen.
     *
     * @param  array<string, array>  $overrides  screen first-step key => full payload
     */
    protected function completeV2Setup(object $workspace, object $business, array $overrides = []): QuestionnaireResponse
    {
        $this->startV2Setup($workspace, $business);

        foreach ($this->v2ScreenPayloads() as $screenKey => $payload) {
            $this->postScreen($workspace, $business, $screenKey, $overrides[$screenKey] ?? $payload);
        }

        return $this->activeResponse($business);
    }

    /**
     * A Website AI client that writes a distinct page for every planned
     * page_key — service-area pages get their own copy (unique wording per
     * area) so they pass the anti-doorway validator.
     */
    protected function bindDistinctAiClient(): void
    {
        $mock = \Mockery::mock(WebsiteAiGenerationClient::class);
        $mock->shouldReceive('lastCallWasBudgetExhausted')->andReturn(false);
        $mock->shouldReceive('lastRefusalReason')->andReturn(null);
        $mock->shouldReceive('complete')->andReturnUsing(function (array $messages) {
            $user = collect($messages)->firstWhere('role', 'user')['content'] ?? '{}';
            $payload = json_decode(substr($user, (int) strpos($user, '{')), true) ?? [];

            $pages = collect($payload['plan'] ?? [])->map(function (array $page) {
                $area = $page['entity']['area'] ?? null;
                $title = $area !== null ? 'Serving ' . $area : ($page['entity']['name'] ?? ucwords(str_replace(['_', ':'], ' ', explode(':', $page['page_key'])[0])));
                $sections = [['type' => 'hero', 'data' => ['heading' => $title, 'subheading' => null, 'background_image' => null, 'primary_cta' => null, 'secondary_cta' => null]]];

                if (($page['page_type'] ?? null) === 'packages') {
                    $sections[] = ['type' => 'services', 'data' => ['heading' => 'Packages', 'items' => array_map(
                        fn (array $package) => ['name' => $package['name'], 'description' => $package['description'] ?? null, 'price_label' => $package['price_label'] ?? null],
                        $page['entity']['packages'] ?? []
                    )]];
                }

                if ($area !== null) {
                    // Genuinely different copy per page: a long, page-specific passage.
                    $unique = substr(hash('sha256', $page['page_key'] . 'a'), 0, 40) . ' ' . substr(hash('sha256', $page['page_key'] . 'b'), 0, 40) . ' ' . substr(hash('sha256', $page['page_key'] . 'c'), 0, 40);
                    $sections[] = ['type' => 'text', 'data' => ['heading' => 'Photo booths for ' . $area, 'body' => 'We bring our booths to ' . $area . '. ' . $unique]];
                }

                return [
                    'page_key' => $page['page_key'],
                    'title' => $title,
                    'seo_title' => 'Snap Booth | ' . $page['page_key'],
                    'meta_description' => 'Content for ' . $page['page_key'] . '.',
                    'sections' => $sections,
                ];
            })->values()->all();

            return json_encode(['pages' => $pages]);
        });
        $this->app->instance(WebsiteAiGenerationClient::class, $mock);
    }
}

<?php

namespace Tests\Feature\Website\GuidedGeneration;

use App\Library\Website\GuidedGeneration\GuidedGenerationCommitService;
use App\Library\Website\GuidedGeneration\GuidedGenerationOutputValidator;
use App\Library\Website\GuidedGeneration\GuidedWebsiteGenerationClient;
use App\Library\Website\GuidedGeneration\InternalLinkResolver;
use App\Library\Website\WebsitePageStrategy;
use App\Models\WebsiteGuidedGenerationAttempt;
use App\Models\WebsiteTemplate;
use Database\Seeders\WebsiteTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * The prompt never gave the AI the planned page addresses, so it wrote /contact, /booking ... and every batch was
 * rejected (pages.N.sections.M.buttons.0.url). The AI now receives the exact paths; a generic alias with exactly one
 * planned target is corrected; anything else stays and is rejected. Fake AI responses only.
 */
class GuidedInternalLinkPathsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    private const ALLOWED = ['hero', 'text', 'services', 'faq', 'cta', 'contact_details'];

    private function plan(): array
    {
        $entry = fn (string $key, string $type, ?string $slug, string $title, bool $home = false) => ['page_key' => $key, 'page_type' => $type, 'is_home' => $home, 'slug' => $slug, 'title' => $title, 'allowed_section_types' => self::ALLOWED, 'entity' => null];

        return [
            $entry('home', 'home', null, 'Home', true),
            $entry('services_overview', 'services_overview', 'photo-booth-services', 'Services'),
            $entry('packages', 'packages', 'photo-booth-packages', 'Packages'),
            $entry('service_a', 'service_detail', 'service-photo-booth-rental', 'Photo booth rental'),
            $entry('about', 'about', 'photo-booth-about', 'About'),
            $entry('faq', 'faq', 'photo-booth-faq', 'FAQ'),
            $entry('gallery', 'gallery', 'gallery', 'Gallery'),
            $entry('contact', 'contact', 'photo-booth-contact', 'Contact'),
        ];
    }

    private function hero(?string $primaryUrl = null): array
    {
        return ['type' => 'hero', 'data' => ['heading' => 'Heading', 'subheading' => null, 'primary_cta' => $primaryUrl === null ? null : ['label' => 'Go', 'url' => $primaryUrl], 'secondary_cta' => null]];
    }

    private function cta(string $url): array
    {
        return ['type' => 'cta', 'data' => ['heading' => 'Ready?', 'body' => null, 'buttons' => [['label' => 'Contact us', 'url' => $url]]]];
    }

    private function batch(array $plan, array $overrides = []): array
    {
        return collect($plan)->map(fn ($page) => [
            'page_key' => $page['page_key'], 'title' => $page['title'], 'seo_title' => $page['title'] . ' | Kaleb Hill', 'meta_description' => 'All about the ' . $page['title'] . ' page.',
            'sections' => $overrides[$page['page_key']] ?? array_values(array_filter([$this->hero(), in_array('cta', $page['allowed_section_types'], true) ? $this->cta('/' . collect($plan)->firstWhere('page_type', 'contact')['slug']) : null])),
        ])->values()->all();
    }

    private function errorsFor(array $pages, array $plan): string
    {
        try {
            app(GuidedGenerationOutputValidator::class)->validate($pages, $plan);
        } catch (ValidationException $e) {
            return json_encode($e->errors());
        }

        return '';
    }

    public function test_the_ai_receives_every_valid_page_path_and_each_pages_own_path(): void
    {
        [, $business] = $this->entitledTenant();
        $client = app(GuidedWebsiteGenerationClient::class);
        $method = new \ReflectionMethod($client, 'buildMessages');
        $method->setAccessible(true);

        [$system, $user] = $method->invoke($client, $business, $this->plan());

        $this->assertStringContainsString('/ /photo-booth-services /photo-booth-packages /service-photo-booth-rental /photo-booth-about /photo-booth-faq /gallery /photo-booth-contact', $system['content']);
        $this->assertStringContainsString('Never invent or guess a path such as /contact', $system['content']);
        $plan = json_decode($user['content'], true)['plan'];
        $this->assertSame('/', $plan[0]['path']);
        $this->assertSame('/photo-booth-contact', collect($plan)->firstWhere('page_key', 'contact')['path']);
    }

    public function test_an_eight_page_batch_with_valid_links_passes_including_a_link_to_home(): void
    {
        $plan = $this->plan();
        $pages = $this->batch($plan, ['about' => [$this->hero('/'), $this->cta('/'), $this->cta('/photo-booth-contact')]]);

        $this->assertCount(8, $pages);
        $this->assertSame('', $this->errorsFor($pages, $plan));
    }

    public function test_a_generic_contact_alias_is_corrected_to_the_planned_contact_slug_for_buttons_and_hero_ctas(): void
    {
        $plan = $this->plan();
        $pages = $this->batch($plan, ['home' => [$this->hero('/contact'), $this->cta('/booking'), $this->cta('/Contact-Us/')]]);

        $fixed = InternalLinkResolver::correct($pages, $plan);

        $this->assertSame('/photo-booth-contact', $fixed[0]['sections'][0]['data']['primary_cta']['url']);
        $this->assertSame('/photo-booth-contact', $fixed[0]['sections'][1]['data']['buttons'][0]['url']);
        $this->assertSame('/photo-booth-contact', $fixed[0]['sections'][2]['data']['buttons'][0]['url']);
        $this->assertSame('', $this->errorsFor($fixed, $plan));
    }

    public function test_unknown_or_ambiguous_internal_destinations_are_not_guessed_and_are_rejected(): void
    {
        $plan = $this->plan();
        // Two contact pages make "/contact" ambiguous; "/shop" is not in the plan at all; "/about" has a page, "/faq" too.
        $ambiguous = array_merge($plan, [['page_key' => 'contact2', 'page_type' => 'contact', 'is_home' => false, 'slug' => 'second-contact', 'title' => 'Contact 2', 'allowed_section_types' => self::ALLOWED, 'entity' => null]]);

        foreach (['/shop', '/contact' => '/contact'] as $key => $url) {
            $usePlan = $url === '/contact' ? $ambiguous : $plan;
            $pages = InternalLinkResolver::correct($this->batch($usePlan, ['home' => [$this->hero($url), $this->cta($url)]]), $usePlan);

            $this->assertSame($url, $pages[0]['sections'][0]['data']['primary_cta']['url'], 'left unchanged');
            $errors = $this->errorsFor($pages, $usePlan);
            $this->assertStringContainsString('pages.0.sections.0.primary_cta.url', $errors, 'hero CTA rejected');
            $this->assertStringContainsString('pages.0.sections.1.buttons.0.url', $errors, 'section button rejected');
        }
    }

    public function test_external_tel_and_mailto_links_are_not_touched(): void
    {
        $plan = $this->plan();
        $pages = $this->batch($plan, ['home' => [$this->hero('https://example.com/contact'), $this->cta('tel:+15555550100')]]);

        $fixed = InternalLinkResolver::correct($pages, $plan);

        $this->assertSame('https://example.com/contact', $fixed[0]['sections'][0]['data']['primary_cta']['url']);
        $this->assertSame('tel:+15555550100', $fixed[0]['sections'][1]['data']['buttons'][0]['url']);
    }

    private function seededSite(): array
    {
        [$customer, $business] = $this->entitledTenant();
        $this->seed(WebsiteTemplateSeeder::class);
        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $website = $this->createWebsite($business);
        $this->homePage($website, ['title' => 'Existing Draft Home']);

        return [$customer, $business, $template, $website];
    }

    public function test_a_corrected_alias_survives_the_whole_commit_and_an_unknown_link_changes_nothing(): void
    {
        [$customer, $business, $template, $website] = $this->seededSite();
        $plan = app(WebsitePageStrategy::class)->buildPlan($business, $template, $website);
        $contactSlug = collect($plan)->firstWhere('page_type', 'contact')['slug'];
        $json = fn (string $url) => json_encode(['pages' => $this->batch($plan, ['home' => [$this->hero($url), $this->cta($url)]])]);

        // 1) Unknown destination on both tries: rejected, the existing draft is untouched.
        $this->mockAiClient($json('/no-such-page'));
        $failed = app(GuidedGenerationCommitService::class)->generateFull($business, $website, $template, $customer->user_id, 'idem-bad');
        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_FAILED, $failed->status);
        $this->assertSame(['Existing Draft Home'], $website->pages()->pluck('title')->all());

        // 2) The generic alias is corrected and the committed pages carry the real planned path.
        $this->mockAiClient($json('/contact'));
        $ok = app(GuidedGenerationCommitService::class)->generateFull($business, $website->fresh(), $template, $customer->user_id, 'idem-good');
        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $ok->status, (string) $ok->failure_reason);
        $home = $website->pages()->where('is_home', true)->firstOrFail();
        $this->assertSame('/' . $contactSlug, $home->sections[0]['data']['primary_cta']['url']);
        $this->assertSame('/' . $contactSlug, $home->sections[1]['data']['buttons'][0]['url']);
    }
}

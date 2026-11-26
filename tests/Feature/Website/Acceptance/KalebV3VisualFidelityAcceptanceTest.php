<?php

namespace Tests\Feature\Website\Acceptance;

use App\Enums\Business\BusinessServiceStatus;
use App\Library\Website\Design\SectionRole;
use App\Library\Website\Design\WebsiteDesigns;
use App\Library\Website\WebsiteAiGenerationClient;
use App\Models\BusinessBackdrop;
use App\Models\BusinessService;
use App\Models\CatalogItem;
use App\Models\Customer;
use App\Models\Website;
use App\Models\WebsiteAsset;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\Feature\Website\Concerns\DrivesSmartWizard;
use Tests\Feature\Website\Concerns\PublishesGeneratedSites;
use Tests\TestCase;

/**
 * The opt-in "Emerald Gold" Photo Booth design (template photo_booth_emerald): the Kaleb Hill v3 visual language rendered by the
 * existing Website renderer from the site's own validated sections. Drives the owner's real rebuild + publish routes (fake AI:
 * the Photo Booth rebuild never needs it) and reads the real public pages. Nothing here is Kaleb-specific text.
 *
 * Set KV3_DUMP=/dir to also write the rendered public pages there (used for the screenshot evidence).
 */
class KalebV3VisualFidelityAcceptanceTest extends TestCase
{
    use CreatesWebsiteFixtures;
    use DrivesSmartWizard;
    use PublishesGeneratedSites;
    use RefreshDatabase;

    private const DOMAIN = 'recovery-booths.test';

    private const AREAS = [
        ['name' => 'Hattiesburg, MS', 'details' => ['venues' => ['The Hattiesburg Saenger Theater'], 'logistics' => 'About 20 minutes from Petal; we load in two hours early.']],
        ['name' => 'Laurel, MS', 'details' => ['venues' => ['The Landrum Center'], 'logistics' => 'About 45 minutes from Petal; parking is behind the hall.']],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSmartWizard();
        config(['ai.website_content_quality' => true]);
    }

    private function enrich(Website $website, $business, $customer, bool $full = true): void
    {
        if (! $full) {
            return;
        }

        foreach (['Weddings' => 'A wedding reception with a mirror booth, prints for every guest and an attendant.', 'Corporate Events' => 'Branded prints and digital sharing for company parties and conferences.', 'Parties' => 'Birthdays, graduations and reunions with props and instant prints.'] as $name => $description) {
            BusinessService::create(['business_id' => $business->id, 'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name), 'kind' => BusinessService::KIND_EVENT, 'description' => $description, 'status' => BusinessServiceStatus::Active->value, 'sort_order' => 100]);
        }
        CatalogItem::create(['business_id' => $business->id, 'type' => 'package', 'name' => 'Magic Mirror Experience', 'description' => 'Two hours with an attendant, custom print design and digital sharing.', 'position' => 0, 'created_by_user_id' => $customer->user_id]);
        $backdrop = BusinessBackdrop::create(['business_id' => $business->id, 'name' => 'Gold Sequin', 'availability' => true, 'position' => 0]);
        $backdrop->images()->create(['disk' => 'public', 'path' => 'images/backdrops/gold.jpg', 'mime_type' => 'image/jpeg', 'size' => 10, 'alt_text' => 'Gold sequin backdrop', 'position' => 0]);

        foreach (range(1, 6) as $n) {
            WebsiteAsset::create(['website_id' => $website->id, 'path' => "images/websites/kv3/{$n}.jpg", 'filename' => "{$n}.jpg", 'mime_type' => 'image/jpeg', 'size_bytes' => 10, 'purpose' => 'gallery', 'sort_order' => $n, 'alt_text' => "Photo booth at event {$n}"]);
        }

        app(\App\Library\Business\BusinessKnowledgeProfileManager::class)->updateFields($business, [
            'testimonials' => [['quote' => 'The mirror booth was the hit of our reception.', 'author_name' => 'Dana R.'], ['quote' => 'Friendly, fun and easy to book.', 'author_name' => 'Chris T.']],
            'differentiators' => ['An attendant stays with the booth for the whole event'],
        ], 'manual_edit', (int) $customer->user_id, true);
    }

    /** Rebuilds with the given template and publishes; returns the public home HTML. */
    private function publishWithTemplate(string $templateKey, bool $full, string $tag): array
    {
        [$website, $business] = $this->publishedSite('booth', $full, $full, true, $full ? ['An attendant stays with the booth for the whole event'] : []);
        $customer = Customer::query()->where('user_id', $business->customer_id)->firstOrFail();
        $workspace = Workspace::query()->findOrFail($business->workspace_id);
        $this->authenticateAsCustomer($customer);
        $this->enrich($website, $business, $customer, $full);

        $mock = \Mockery::mock(WebsiteAiGenerationClient::class);
        $mock->shouldNotReceive('complete');
        $mock->shouldReceive('lastCallWasBudgetExhausted')->andReturn(false);
        $mock->shouldReceive('lastRefusalReason')->andReturn(null);
        $this->app->instance(WebsiteAiGenerationClient::class, $mock);

        $args = [$workspace->uid, $business->uid];
        $this->post(route('customer.workspaces.businesses.website.rebuild', $args), ['template_key' => $templateKey, 'confirm_rebuild' => '1'])->assertRedirect();
        $publishUrl = route('customer.workspaces.businesses.website.publish', $args);
        $this->post($publishUrl)->assertRedirect();
        app('cache')->flush();

        $html = $this->get('http://' . self::DOMAIN . '/')->assertOk()->getContent();

        if (($dir = getenv('KV3_DUMP')) && is_dir($dir)) {
            file_put_contents("{$dir}/{$tag}-home.html", $html);
            foreach (['gallery', 'backdrops'] as $slug) {
                file_put_contents("{$dir}/{$tag}-{$slug}.html", $this->get('http://' . self::DOMAIN . '/' . $slug)->getContent());
            }
        }

        return [$website, $html, $business, $workspace, $publishUrl];
    }

    public function test_the_emerald_design_is_opt_in_and_the_four_canonical_designs_are_unchanged(): void
    {
        $this->assertCount(4, WebsiteDesigns::all(), 'Emerald Gold never joins the four canonical designs');
        $this->assertArrayNotHasKey('photo_booth_emerald', WebsiteDesigns::all());
        $this->assertSame('emerald', WebsiteDesigns::forTemplateKey('photo_booth_emerald')->key);
        $this->assertSame('emerald', WebsiteDesigns::resolve('photo_booth_editorial', ['header_variant' => 'emerald'])->key, 'a published theme resolves its frozen design');
        $this->assertSame('editorial', WebsiteDesigns::resolve('photo_booth_editorial', [])->key);
        $this->assertSame([], WebsiteDesigns::forTemplateKey('photo_booth_editorial')->roleTones);
    }

    public function test_kaleb_reference_renders_its_own_ordered_home_from_confirmed_business_data(): void
    {
        $this->assertReferenceFamily('photo_booth_reference_kaleb', 'reference-kaleb', 'modern');
    }

    public function test_booths_reference_renders_its_own_ordered_home_from_confirmed_business_data(): void
    {
        $this->assertReferenceFamily('photo_booth_reference_booths', 'reference-booths', 'modern');
    }

    public function test_greenbelt_reference_renders_its_own_ordered_home_from_confirmed_business_data(): void
    {
        $this->assertReferenceFamily('photo_booth_reference_greenbelt', 'reference-greenbelt', 'conversion');
    }

    private function assertReferenceFamily(string $template, string $variant, string $base): void
    {
        [, $html] = $this->publishWithTemplate($template, true, $variant);

        $this->assertStringContainsString('wd wd-' . $variant . ' wd-' . $base, $html);
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringContainsString('data-section="image_text"', $html);
        $this->assertStringContainsString('data-section="testimonials"', $html);
        $this->assertStringContainsString('data-section="faq"', $html);
        $this->assertStringContainsString('data-section="cta"', $html);
        $this->assertSame(1, substr_count($html, 'data-section="cta"'));

        // The design must not group every service block before the story:
        // the authored image/text section stays between hero and booths/events.
        $hero = strpos($html, 'data-section="hero"');
        $story = strpos($html, 'data-section="image_text"');
        $services = strpos($html, 'data-section="services"');
        $this->assertNotFalse($hero);
        $this->assertNotFalse($story);
        $this->assertNotFalse($services);
        $this->assertLessThan($story, $hero);
        $this->assertLessThan($services, $story);
        $this->assertStringContainsString('data-wd-menu-toggle', $html);

        if ($variant === 'reference-greenbelt') {
            $this->assertStringNotContainsString('Google reviews', $html,
                'Manually confirmed testimonials must not be passed off as Google-sourced reviews');
        }

        // Missing/undeployed Forms-module lead forms must not be advertised
        // as a functional Check Availability form on the live site.
        $this->assertStringNotContainsString('forms_module_deployment_uid', $html);

        foreach (['award-winning', 'as seen on', 'guaranteed ranking'] as $invented) {
            $this->assertStringNotContainsString($invented, mb_strtolower(strip_tags($html)));
        }
    }

    public function test_section_roles_come_from_the_section_itself_and_packages_are_never_a_role(): void
    {
        $role = fn (string $heading, array $items = [['name' => 'x']]) => SectionRole::of(['type' => 'services', 'data' => ['heading' => $heading, 'items' => $items]]);

        $this->assertSame('events', $role('Events we cover'));
        $this->assertSame('process', $role('How booking works'));
        $this->assertSame('advantages', $role('Why choose Harbor Booths'));
        $this->assertSame('areas', $role('Areas we serve'));
        $this->assertSame('booths', $role('Our photo booths'));
        $this->assertNull($role('Packages', [['name' => 'x', 'catalog_item_uid' => 'p1']]));
        $this->assertNull($role('Events we cover', [['name' => 'x', 'catalog_item_uid' => 'p1']]));
        $this->assertNull($role('Something else'));
        $this->assertSame('areas', SectionRole::of(['type' => 'services', 'data' => ['heading' => 'Anything', 'role' => 'areas', 'items' => [['name' => 'x']]]]));
        $this->assertNull(SectionRole::of(['type' => 'text', 'data' => ['heading' => 'Events we cover']]));
    }

    public function test_a_full_photo_booth_site_renders_the_kaleb_v3_home_structure_from_its_own_data(): void
    {
        [$website, $html, $business, $workspace] = $this->publishWithTemplate('photo_booth_emerald', true, 'emerald');

        $this->assertMatchesRegularExpression('#<body class="website-body\s+wd wd-emerald#', $html);
        $this->assertSame(1, substr_count($html, '<h1'), 'one main heading');

        // 1. deep-green editorial split hero: eyebrow, local H1, photo column and two CTAs
        $this->assertStringContainsString('wd-hero-editorial-split', $html);
        $this->assertStringContainsString('wd-hero-eyebrow', $html);
        $this->assertMatchesRegularExpression('#<h1 class="wd-hero-title">[^<]*Petal#', $html, 'the H1 names the confirmed primary market');
        preg_match('#<section class="wd-hero.*?</section>#s', $html, $hero);
        $this->assertSame(2, preg_match_all('#class="wd-btn wd-btn-(primary|secondary) wd-btn-lg"#', $hero[0]), 'a primary and a secondary hero call to action');
        $this->assertStringContainsString('wd-hero-media', $html);

        // 3. event cards (real event types only), 5. booking process, 6. service areas
        $this->assertMatchesRegularExpression('#data-role="events"#', $html);
        $this->assertSame(3, substr_count($html, 'wd-card-plate'), 'one plate per real event type, never a made-up photo');
        foreach (['Weddings', 'Corporate Events', 'Parties'] as $event) {
            $this->assertStringContainsString($event, $html);
        }
        $this->assertStringContainsString('data-role="process"', $html);
        $this->assertStringContainsString('wd-step-num', $html);
        $this->assertDoesNotMatchRegularExpression('#<h3[^>]*>\s*\d+\.\s#', $html, 'the generated "1. " prefix is replaced by the numbered marker');
        $this->assertStringContainsString('data-role="areas"', $html);
        $this->assertStringContainsString('Hattiesburg', $html);

        // 7. confirmed reviews only, verbatim
        $this->assertStringContainsString('The mirror booth was the hit of our reception.', $html);
        $this->assertStringContainsString('Chris T.', $html);

        // 8. FAQ, ONE closing call to action, compact footer
        $this->assertStringContainsString('website-faq-item', $html);
        $this->assertSame(1, substr_count($html, 'data-section="cta"'), 'exactly one closing call to action band');
        $this->assertStringContainsString('wd-footer-compact', $html);
        preg_match('#<footer class="wd-footer.*?</footer>#s', $html, $footer);
        $this->assertLessThanOrEqual(24, substr_count($footer[0], '<li>'), 'a compact footer, not a link dump');

        // navigation, header CTA, mobile menu and SEO metadata are the shared, unchanged ones
        $this->assertStringContainsString('data-wd-menu-toggle', $html);
        $this->assertStringContainsString('data-testid="header-cta"', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://' . self::DOMAIN . '/">', $html);
        $this->assertStringContainsString('LocalBusiness', $html);
        $this->assertStringContainsString('<meta name="description"', $html);

        // honesty: nothing invented
        $lower = strtolower(strip_tags($html));
        foreach (['award-winning', 'five-star', 'years of experience', 'licensed and insured', 'as seen on'] as $invented) {
            $this->assertStringNotContainsString($invented, $lower);
        }

        // the shared components carry no business text of the reference
        foreach ([...glob(resource_path('views/public/website/components/*.blade.php')), ...glob(resource_path('views/public/website/design/*.blade.php'))] as $file) {
            $this->assertStringNotContainsStringIgnoringCase('kaleb', (string) file_get_contents($file), basename($file) . ' has no Kaleb-specific text');
        }

        // inner pages keep working under the same design
        $this->get('http://' . self::DOMAIN . '/gallery')->assertOk()->assertSee('wd-emerald', false);
    }

    public function test_classic_gold_sites_are_untouched_by_the_new_design(): void
    {
        [, $html] = $this->publishWithTemplate('photo_booth_editorial', true, 'editorial');

        $this->assertStringContainsString('wd-editorial', $html);
        $this->assertStringNotContainsString('wd-emerald', $html);
        $this->assertStringNotContainsString('wd-band-role-', $html);
        $this->assertStringNotContainsString('wd-card-plate', $html);
        $this->assertStringNotContainsString('wd-step-num', $html);
        $this->assertStringNotContainsString('wd-hero-eyebrow', $html);
        $this->assertStringContainsString('wd-footer-centered', $html);
    }

    public function test_a_sparse_client_omits_the_blocks_it_has_no_facts_for_and_leaves_no_empty_band(): void
    {
        [, $html] = $this->publishWithTemplate('photo_booth_emerald', false, 'emerald-sparse');

        $this->assertStringContainsString('wd-hero-editorial-split', $html);
        $this->assertStringNotContainsString('data-role="areas"', $html, 'no service areas without confirmed ones');
        $this->assertStringNotContainsString('website-testimonials', $html, 'no reviews without confirmed ones');
        $this->assertStringNotContainsString('wd-card-plate', $html, 'no event cards without real event types');
        $this->assertStringContainsString('data-role="process"', $html, 'the booking process is generic, true to every client');
        $this->assertSame(1, substr_count($html, 'data-section="cta"'));
        $this->assertSame(0, preg_match('#<div class="wd-band[^"]*"[^>]*>\s*<div class="website-container wd-container">\s*</div>\s*</div>#', $html), 'no empty band');
    }

    public function test_a_published_site_keeps_its_frozen_design_when_the_draft_template_changes(): void
    {
        [$website, , $business, $workspace] = $this->publishWithTemplate('photo_booth_emerald', true, 'emerald-frozen');

        $website->fresh()->update(['template_key' => 'photo_booth_editorial']);
        app('cache')->flush();

        $this->get('http://' . self::DOMAIN . '/')->assertOk()->assertSee('wd-emerald', false)->assertDontSee('wd-editorial', false);
    }

    public function test_a_compact_footer_folds_extra_pages_into_more_and_never_drops_them_and_inner_heroes_stay_single_column(): void
    {
        [$website, , , , $publishUrl] = $this->publishWithTemplate('photo_booth_emerald', true, 'emerald-more');

        // Eight owner-created pages beyond the standard ones: each stays reachable from the footer.
        foreach (range(1, 8) as $n) {
            $website->pages()->create(['uid' => (string) \Illuminate\Support\Str::uuid(), 'title' => "Special Offer {$n}", 'slug' => "special-offer-{$n}", 'is_home' => false, 'sort_order' => 100 + $n, 'sections' => [['type' => 'text', 'data' => ['heading' => "Offer {$n}", 'body' => "Details of offer {$n}."]]]]);
        }
        $this->post($publishUrl)->assertRedirect();
        app('cache')->flush();

        $page = $this->get('http://' . self::DOMAIN . '/')->assertOk()->getContent();
        preg_match('#<footer class="wd-footer.*?</footer>#s', $page, $footer);
        $this->assertStringContainsString('<li class="wd-footer-more">', $footer[0]);
        foreach (range(1, 8) as $n) {
            $this->assertStringContainsString("Special Offer {$n}", $footer[0], "page {$n} keeps a footer link");
        }

        // The two-column split is for the home hero only; an inner page's hero is a single column (the stylesheet says so explicitly).
        $css = (string) file_get_contents(public_path('css/website-design.css'));
        $this->assertStringContainsString('.wd-emerald .wd-hero-editorial-split:not(.wd-hero-inner-page) .wd-hero-grid { grid-template-columns: 52% 48%; }', $css);
        $this->assertMatchesRegularExpression('#class="wd-hero wd-hero-editorial-split[^"]*wd-hero-inner-page#', $this->get('http://' . self::DOMAIN . '/gallery')->getContent());
    }
}

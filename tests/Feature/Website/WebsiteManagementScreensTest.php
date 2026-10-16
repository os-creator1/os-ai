<?php

namespace Tests\Feature\Website;

use App\Library\Website\WebsiteFormPresets;
use App\Library\Website\WebsiteStarterDraftService;
use App\Models\BusinessLocation;
use App\Models\CatalogItem;
use App\Models\Website;
use App\Models\WebsiteForm;
use App\Models\WebsiteTemplate;
use Database\Seeders\WebsiteTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * The Website management screens (Pages / Photos / Forms / Domains, New page, Studio Packages): every
 * "Back to Website" control returns to the main Website screen (never the Pages list), the shared tab strip is
 * present with the right tab active, and the form/package actions point at real, correctly scoped routes.
 */
class WebsiteManagementScreensTest extends TestCase
{
    use CreatesWebsiteFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WebsiteTemplateSeeder::class);
    }

    /** @return array{0: \App\Models\Business, 1: \App\Models\Workspace, 2: Website} */
    private function site(): array
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $website = app(WebsiteStarterDraftService::class)->createShellFromTemplate($business, WebsiteTemplate::findActiveOrFail('photo_booth_modern'));
        $this->homePage($website);

        return [$business, $workspace, $website];
    }

    private function ids($business, $workspace): array
    {
        return [$workspace->uid, $business->uid];
    }

    public function test_every_management_screen_links_back_to_the_main_website_screen_not_the_pages_list(): void
    {
        [$business, $workspace, $website] = $this->site();
        $ids = $this->ids($business, $workspace);
        $home = route('customer.workspaces.businesses.website.show', $ids);
        $page = $website->pages()->first();

        $urls = [
            'pages' => route('customer.workspaces.businesses.website.pages.index', $ids),
            'photos' => route('customer.workspaces.businesses.website.photos.index', $ids),
            'forms' => route('customer.workspaces.businesses.website.forms.index', $ids),
            'domains' => route('customer.workspaces.businesses.website.domains.index', $ids),
            'new page' => route('customer.workspaces.businesses.website.pages.create', $ids),
            'edit page' => route('customer.workspaces.businesses.website.pages.edit', [...$ids, $page->uid]),
        ];

        foreach ($urls as $label => $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertSame(1, substr_count($html, 'data-testid="back-to-website"'), "{$label}: exactly one back link");
            $this->assertMatchesRegularExpression('#href="' . preg_quote($home, '#') . '"[^>]*data-testid="back-to-website"#', $html, "{$label}: points at the Website screen");
            $this->assertStringNotContainsString('Back to pages', $html, $label);
        }
    }

    public function test_the_tab_strip_marks_the_current_screen(): void
    {
        [$business, $workspace] = $this->site();
        $ids = $this->ids($business, $workspace);

        foreach (['photos' => 'Photos', 'forms' => 'Forms', 'domains' => 'Domains'] as $route => $label) {
            $html = $this->get(route("customer.workspaces.businesses.website.{$route}.index", $ids))->assertOk()->getContent();
            $this->assertMatchesRegularExpression('#class="is-active"\s+aria-current="page"\s*>\s*' . $label . '#', $html, $label);
        }
    }

    public function test_domains_without_a_custom_domain_show_the_connect_card_that_posts_to_the_existing_endpoint(): void
    {
        [$business, $workspace] = $this->site();
        $html = $this->get(route('customer.workspaces.businesses.website.domains.index', $this->ids($business, $workspace)))->assertOk()->getContent();

        $this->assertStringContainsString('Use your own domain', $html);
        $this->assertStringContainsString('action="' . route('customer.workspaces.businesses.website.domains.store', $this->ids($business, $workspace)) . '"', $html);
        $this->assertStringContainsString('Not published yet', $html, 'never claims a draft is live');
    }

    public function test_an_unused_form_offers_add_to_a_page_and_the_editor_preselects_it_without_saving(): void
    {
        [$business, $workspace, $website] = $this->site();
        $ids = $this->ids($business, $workspace);
        $location = BusinessLocation::query()->where('business_id', $business->id)->first()
            ?? BusinessLocation::create(['business_id' => $business->id, 'name' => 'Main', 'service_mode' => 'storefront', 'country_code' => 'US']);
        $form = $website->forms()->create([
            'business_id' => $business->id,
            'type' => WebsiteForm::TYPE_QUOTE_REQUEST,
            'name' => 'Photo Booth Quote Request',
            'fields' => WebsiteFormPresets::photoBoothQuoteRequest(),
            'submit_label' => 'Request a quote',
            'location_id' => $location->id,
        ]);
        $page = $website->pages()->first();
        $before = $page->fresh()->sections;

        $html = $this->get(route('customer.workspaces.businesses.website.forms.index', $ids))->assertOk()->getContent();
        $this->assertStringContainsString('data-testid="form-not-on-site"', $html);
        $this->assertStringContainsString('add_form=' . $form->uid, $html);

        $editor = $this->get(route('customer.workspaces.businesses.website.pages.edit', [...$ids, $page->uid, 'add_form' => $form->uid]))->assertOk()->getContent();
        $this->assertStringContainsString('data-testid="add-form-notice"', $editor);
        $this->assertSame($before, $page->fresh()->sections, 'opening the editor never modifies the page');

        // A form uid that is not this Website's is ignored, never echoed into the editor.
        $other = $this->get(route('customer.workspaces.businesses.website.pages.create', [...$ids, 'add_form' => 'not-a-form']))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-testid="add-form-notice"', $other);
    }

    public function test_the_packages_tab_links_to_the_canonical_catalog_create_and_edit_screens(): void
    {
        [$business, $workspace] = $this->site();
        $ids = $this->ids($business, $workspace);
        $url = route('customer.workspaces.businesses.website.studio.show', [...$ids, 'packages']);

        $empty = $this->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('No packages yet', $empty);
        $this->assertStringContainsString(route('customer.workspaces.businesses.catalog.create', $ids), $empty);

        $item = CatalogItem::create(['business_id' => $business->id, 'type' => 'package', 'name' => 'Signature', 'position' => 0, 'featured' => true]);
        $html = $this->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('Signature', $html);
        $this->assertStringContainsString('Featured', $html);
        $this->assertStringContainsString(route('customer.workspaces.businesses.catalog.edit', [...$ids, $item->uid]), $html);
    }

    public function test_the_pages_checklist_reflects_real_state_and_groups_service_and_location_pages(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        app(WebsiteStarterDraftService::class)->create($business, 'clean');
        $website = Website::where('business_id', $business->id)->firstOrFail();
        $this->subPage($website, 'service-mirror-booth', ['title' => 'Mirror booth']);
        $this->subPage($website, 'serving-charlotte', ['title' => 'Serving Charlotte']);
        $ids = [$workspace->uid, $business->uid];
        $url = route('customer.workspaces.businesses.website.pages.index', $ids);

        $html = $this->get($url)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#data-checklist-key="photos" data-done="0"#', $html);
        $this->assertMatchesRegularExpression('#data-checklist-key="quote_form" data-done="0"#', $html);
        $this->assertMatchesRegularExpression('#\d of 6 done#', $html);
        $this->assertStringContainsString('Service pages', $html);
        $this->assertStringContainsString('Location pages', $html);
        $this->assertStringContainsString('Homepage', $html);
        $this->assertSame($website->pages()->count(), substr_count($html, 'data-testid="page-row"'));
        $this->assertSame(1, substr_count($html, 'data-testid="back-to-website"'));
        $this->assertStringContainsString(route('customer.workspaces.businesses.website.preview', [...$ids, $website->pages()->first()->uid]), $html);

        // A real upload completes the Photos row; nothing else changes by itself.
        $this->post(route('customer.workspaces.businesses.website.assets.store', $ids), ['image' => $this->fakeImageUpload('a.png'), 'alt_text' => 'Booth at an event'])->assertRedirect();
        $after = $this->get($url)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#data-checklist-key="photos" data-done="1"#', $after);
        $this->assertMatchesRegularExpression('#data-checklist-key="quote_form" data-done="0"#', $after);
    }

    public function test_the_pages_screen_is_not_reachable_from_another_customers_business(): void
    {
        [$business, $workspace] = $this->site();
        [$other] = $this->entitledTenant();
        $this->authenticateAsCustomer($other);

        $this->get(route('customer.workspaces.businesses.website.pages.index', [$workspace->uid, $business->uid]))->assertNotFound();
    }
}

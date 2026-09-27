<?php

namespace Tests\Feature\Website;

use App\Enums\Business\BusinessIndustry;
use App\Enums\Business\BusinessServiceStatus;
use App\Enums\Catalog\CatalogItemType;
use App\Library\Business\BusinessKnowledgeProfileManager;
use App\Library\Website\WebsiteDraftPageService;
use App\Library\Website\WebsitePublisher;
use App\Library\Website\WebsiteStarterDraftService;
use App\Models\BusinessService;
use App\Models\CatalogItem;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

class WebsiteStarterDraftTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    public function test_setup_offers_general_designs_even_without_a_niche(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $business->update(['industry' => null, 'description' => 'Helpful local service.']);
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.website.setup', [$workspace->uid, $business->uid]))
            ->assertOk()
            ->assertSee('Snap Booth Co')
            ->assertSee('Helpful local service.')
            ->assertSee('Clean')
            ->assertSee('Bold')
            ->assertSee('Premium')
            ->assertSee('Start with a blank website');

        $this->post(route('customer.workspaces.businesses.website.store', [$workspace->uid, $business->uid]), [
            'design' => 'clean',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, Website::where('business_id', $business->id)->firstOrFail()->pages()->count());
    }

    public function test_photo_booth_design_creates_unpublished_home_and_service_pages_from_saved_facts(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $business->update(['description' => 'We bring booths to events.', 'email' => 'hello@example.test']);
        BusinessService::create([
            'business_id' => $business->id,
            'name' => 'Photo booth rental',
            'description' => 'Props and prints included.',
            'status' => BusinessServiceStatus::Active,
            'sort_order' => 0,
        ]);
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.store', [$workspace->uid, $business->uid]), [
            'design' => 'premium',
        ])->assertRedirect(route('customer.workspaces.businesses.website.pages.index', [$workspace->uid, $business->uid]))
            ->assertSessionHasNoErrors();

        $website = Website::where('business_id', $business->id)->firstOrFail();
        $this->assertSame('premium', $website->theme['header_variant']);
        $this->assertNull($website->published_revision_id);
        $this->assertCount(2, $website->pages);
        $page = $website->pages->firstWhere('is_home', true);
        $this->assertTrue($page->is_home);
        $this->assertSame('Snap Booth Co', $page->sections[0]['data']['heading']);
        $this->assertSame('mailto:hello@example.test', $page->sections[0]['data']['primary_cta']['url']);
        $this->assertSame('Photo booth rental', $page->sections[1]['data']['items'][0]['name']);
        $this->assertSame('Props and prints included.', $page->sections[1]['data']['items'][0]['description']);
        $servicePage = $website->pages->firstWhere('slug', 'photo-booth-services');
        $this->assertSame('Services', $servicePage->title);
        $this->assertTrue($servicePage->noindex);
        $this->assertSame('Photo booth services', $servicePage->sections[0]['data']['heading']);
        $this->get(route('customer.workspaces.businesses.website.pages.edit', [$workspace->uid, $business->uid, $page->uid]))
            ->assertOk()
            ->assertSee('var initialSections', false);
        $this->get(route('customer.workspaces.businesses.website.pages.index', [$workspace->uid, $business->uid]))
            ->assertOk()
            ->assertSee('Hidden from search');

        $this->post(route('customer.workspaces.businesses.website.store', [$workspace->uid, $business->uid]), [
            'design' => 'bold',
        ])->assertRedirect();
        $this->assertSame(1, Website::where('business_id', $business->id)->count());
        $this->assertSame(2, $website->pages()->count());
    }

    public function test_unverified_profile_claims_do_not_enter_the_starter_draft(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $profiles = app(BusinessKnowledgeProfileManager::class);
        $profiles->updateFields($business, [
            'differentiators' => ['Best in the world'],
            'testimonials' => [['quote' => 'Perfect!', 'author_name' => 'Jane', 'author_title' => null]],
        ], 'imported', $customer->user_id);

        $website = app(WebsiteStarterDraftService::class)->create($business, 'clean');
        $this->assertSame(1, $website->pages()->count());
        $sections = $website->pages()->firstOrFail()->sections;
        $this->assertNotContains('testimonials', array_column($sections, 'type'));
        $this->assertNotContains('text', array_column($sections, 'type'));

        $profiles->updateFields($business, ['differentiators' => ['Family owned']], 'manual_edit', $customer->user_id, markVerified: true);
        $this->assertSame($website->id, app(WebsiteStarterDraftService::class)->create($business, 'premium')->id);
    }

    public function test_confirmed_testimonials_and_saved_catalog_items_are_reused(): void
    {
        [$customer, $business] = $this->entitledTenant();
        CatalogItem::create([
            'business_id' => $business->id,
            'type' => CatalogItemType::Package,
            'name' => 'Reception package',
            'description' => 'Three hours of coverage.',
            'price_minor' => 25000,
            'currency_code' => 'USD',
            'position' => 1,
            'created_by_user_id' => $customer->user_id,
        ]);
        app(BusinessKnowledgeProfileManager::class)->updateFields($business, [
            'testimonials' => [['quote' => 'Guests loved it!', 'author_name' => 'Jane', 'author_title' => null]],
        ], 'manual_edit', $customer->user_id, markVerified: true);

        $sections = app(WebsiteStarterDraftService::class)->create($business, 'bold')->pages()->firstOrFail()->sections;
        $catalogSection = collect($sections)->first(fn ($section) => ($section['data']['heading'] ?? null) === 'Packages & products');
        $reviewSection = collect($sections)->firstWhere('type', 'testimonials');

        $this->assertSame('Reception package', $catalogSection['data']['items'][0]['name']);
        $this->assertSame('Guests loved it!', $reviewSection['data']['items'][0]['quote']);
    }

    public function test_blank_start_creates_no_page_or_design_theme(): void
    {
        [, $business] = $this->entitledTenant();

        $website = app(WebsiteStarterDraftService::class)->create($business, 'blank');

        $this->assertNull($website->theme);
        $this->assertSame(0, $website->pages()->count());
    }

    public function test_photo_booth_packages_page_appears_only_when_real_catalog_items_exist(): void
    {
        [$customer, $business] = $this->entitledTenant();
        CatalogItem::create([
            'business_id' => $business->id,
            'type' => CatalogItemType::Package,
            'name' => 'Wedding booth package',
            'description' => 'Printed guest photos and props.',
            'position' => 0,
            'created_by_user_id' => $customer->user_id,
        ]);

        $website = app(WebsiteStarterDraftService::class)->create($business, 'clean');

        $this->assertSame(['Home', 'Packages'], $website->pages()->orderBy('id')->pluck('title')->all());
        $packages = $website->pages()->where('slug', 'photo-booth-packages')->firstOrFail();
        $this->assertTrue($packages->noindex);
        $this->assertSame('Wedding booth package', $packages->sections[1]['data']['items'][0]['name']);
    }

    public function test_a_non_photo_booth_business_keeps_the_single_page_start(): void
    {
        [, $business] = $this->entitledTenant();
        $business->update(['industry' => BusinessIndustry::Other]);
        BusinessService::create([
            'business_id' => $business->id,
            'name' => 'Consultation',
            'status' => BusinessServiceStatus::Active,
            'sort_order' => 0,
        ]);

        $website = app(WebsiteStarterDraftService::class)->create($business->fresh(), 'clean');

        $this->assertSame(['Home'], $website->pages()->pluck('title')->all());
    }

    public function test_navigation_uses_draft_pages_in_preview_and_only_published_pages_on_the_public_site(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        BusinessService::create([
            'business_id' => $business->id,
            'name' => 'Open air booth',
            'status' => BusinessServiceStatus::Active,
            'sort_order' => 0,
        ]);
        $website = app(WebsiteStarterDraftService::class)->create($business, 'bold');
        $home = $website->pages()->where('is_home', true)->firstOrFail();
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.website.preview', [$workspace->uid, $business->uid, $home->uid]))
            ->assertOk()
            ->assertSee('aria-label="Site pages"', false)
            ->assertSee(route('customer.workspaces.businesses.website.preview', [$workspace->uid, $business->uid, $website->pages()->where('slug', 'photo-booth-services')->firstOrFail()->uid]), false);

        app(WebsitePublisher::class)->publish($website, $customer->user_id);
        $servicesUrl = route('public.website.page', [$website->public_id, 'photo-booth-services']);
        $this->get(route('public.website.home', $website->public_id))
            ->assertOk()
            ->assertSee($servicesUrl, false);
        $this->get($servicesUrl)
            ->assertOk()
            ->assertSee('aria-current="page"', false)
            ->assertSee('noindex, follow');

        app(WebsiteDraftPageService::class)->createPage($website, [
            'title' => 'Gallery',
            'slug' => 'gallery',
            'is_home' => false,
            'sections' => [['type' => 'hero', 'data' => ['heading' => 'Our gallery']]],
        ]);

        $this->get(route('customer.workspaces.businesses.website.preview', [$workspace->uid, $business->uid, $home->uid]))
            ->assertOk()->assertSee('Gallery');
        $this->get(route('public.website.home', $website->public_id))
            ->assertOk()->assertDontSee('Gallery');
    }

    public function test_uploaded_photo_is_offered_in_the_page_editor_without_copying_an_id(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = app(WebsiteStarterDraftService::class)->create($business, 'clean');
        $page = $website->pages()->firstOrFail();
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.assets.store', [$workspace->uid, $business->uid]), [
            'image' => $this->fakeImageUpload('venue.png'),
            'alt_text' => 'Guests posing at the venue',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $asset = $website->assets()->sole();
        try {
            $this->get(route('customer.workspaces.businesses.website.pages.edit', [$workspace->uid, $business->uid, $page->uid]))
                ->assertOk()
                ->assertSee('Describe the photo')
                ->assertSee('Guests posing at the venue')
                ->assertDontSee('Available asset UIDs');
        } finally {
            $this->delete(route('customer.workspaces.businesses.website.assets.destroy', [$workspace->uid, $business->uid, $asset->uid]))
                ->assertRedirect()->assertSessionHasNoErrors();
        }
    }
}

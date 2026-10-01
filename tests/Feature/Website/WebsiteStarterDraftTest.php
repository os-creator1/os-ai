<?php

namespace Tests\Feature\Website;

use App\Enums\Business\BusinessIndustry;
use App\Enums\Business\BusinessServiceStatus;
use App\Enums\Catalog\CatalogItemType;
use App\Library\Business\BusinessKnowledgeProfileManager;
use App\Library\Website\WebsiteDraftPageService;
use App\Library\Website\WebsitePublisher;
use App\Library\Website\WebsiteStarterDraftService;
use App\Models\BusinessLocation;
use App\Models\BusinessService;
use App\Models\CatalogItem;
use App\Models\Website;
use App\Models\WebsiteFormSubmission;
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
        // Home, Services, and the always-created About/Contact pages — no
        // FAQ, since no Knowledge Profile fact is confirmed yet (only a
        // bare saved BusinessService and email exist).
        $this->assertCount(4, $website->pages);
        $this->assertNull($website->pages->firstWhere('slug', 'photo-booth-faq'));
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
        $this->assertSame(4, $website->pages()->count());
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
        // Home, plus the always-created About/Contact pages — no unverified
        // claim reaches any of them, and FAQ is never created at all since
        // no Knowledge Profile fact is confirmed.
        $this->assertSame(3, $website->pages()->count());
        $this->assertNull($website->pages()->where('slug', 'photo-booth-faq')->first());
        $sections = $website->pages()->where('is_home', true)->firstOrFail()->sections;
        $this->assertNotContains('testimonials', array_column($sections, 'type'));
        $this->assertNotContains('text', array_column($sections, 'type'));
        $aboutSections = $website->pages()->where('slug', 'photo-booth-about')->firstOrFail()->sections;
        $this->assertStringNotContainsString('Best in the world', json_encode($aboutSections));

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

    public function test_faq_credentials_answer_only_states_the_verified_credentials(): void
    {
        [$customer, $business] = $this->entitledTenant();
        app(BusinessKnowledgeProfileManager::class)->updateFields($business, [
            'credentials' => [
                ['label' => 'Licensed Contractor', 'verified' => true],
                ['label' => 'Pending Certification', 'verified' => false],
            ],
            'years_operating' => 5,
        ], 'manual_edit', $customer->user_id, markVerified: true);

        $website = app(WebsiteStarterDraftService::class)->create($business, 'clean');

        $faqSections = $website->pages()->where('slug', 'photo-booth-faq')->firstOrFail()->sections;
        $faqItems = collect($faqSections)->firstWhere('type', 'faq')['data']['items'];
        $answer = collect($faqItems)->firstWhere('question', 'What credentials do you have?');

        $this->assertNotNull($answer);
        $this->assertStringContainsString('Licensed Contractor', $answer['answer']);
        $this->assertStringNotContainsString('Pending Certification', $answer['answer']);

        // The About page's "Our promise" section shares the exact same
        // saved credentials — the same verified-only rule applies there.
        $aboutSections = $website->pages()->where('slug', 'photo-booth-about')->firstOrFail()->sections;
        $this->assertStringContainsString('Licensed Contractor', json_encode($aboutSections));
        $this->assertStringNotContainsString('Pending Certification', json_encode($aboutSections));
    }

    /**
     * A verified credential proves only that the owner confirmed
     * holding that exact saved LABEL — never that the label itself is a
     * license, insurance policy, or certification. A label like
     * "Chamber of Commerce Member" must never turn into an affirmative
     * licensing/insurance/certification claim.
     */
    public function test_faq_credentials_question_never_infers_licensing_insurance_or_certification(): void
    {
        [$customer, $business] = $this->entitledTenant();
        app(BusinessKnowledgeProfileManager::class)->updateFields($business, [
            'credentials' => [
                ['label' => 'Chamber of Commerce Member', 'verified' => true],
            ],
            'years_operating' => 5,
        ], 'manual_edit', $customer->user_id, markVerified: true);

        $faqSections = app(WebsiteStarterDraftService::class)->create($business, 'clean')
            ->pages()->where('slug', 'photo-booth-faq')->firstOrFail()->sections;
        $faqItems = collect($faqSections)->firstWhere('type', 'faq')['data']['items'];
        $answer = collect($faqItems)->firstWhere('question', 'What credentials do you have?');

        $this->assertNotNull($answer);
        $this->assertStringContainsString('Chamber of Commerce Member', $answer['answer']);
        $this->assertStringNotContainsString('Yes', $answer['answer']);
        $lowerAnswer = strtolower($answer['answer']);
        $this->assertStringNotContainsString('licensed', $lowerAnswer);
        $this->assertStringNotContainsString('insured', $lowerAnswer);
        $this->assertStringNotContainsString('certified', $lowerAnswer);
        $this->assertNull(collect($faqItems)->firstWhere('question', 'Are you licensed, insured, or certified?'));
    }

    public function test_faq_omits_the_credentials_question_when_no_saved_credential_is_verified(): void
    {
        [$customer, $business] = $this->entitledTenant();
        app(BusinessKnowledgeProfileManager::class)->updateFields($business, [
            'credentials' => [
                ['label' => 'Pending Certification', 'verified' => false],
            ],
            'years_operating' => 5,
        ], 'manual_edit', $customer->user_id, markVerified: true);

        $faqSections = app(WebsiteStarterDraftService::class)->create($business, 'clean')
            ->pages()->where('slug', 'photo-booth-faq')->firstOrFail()->sections;
        $faqItems = collect($faqSections)->firstWhere('type', 'faq')['data']['items'];

        $this->assertNull(collect($faqItems)->firstWhere('question', 'What credentials do you have?'));
        $this->assertStringNotContainsString('Pending Certification', json_encode($faqSections));
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

        // Packages exists because a real catalog item does; About/Contact
        // are always created for a Photo Booth business; FAQ is not,
        // since no Knowledge Profile fact is confirmed.
        $this->assertSame(['Home', 'Packages', 'About', 'Contact'], $website->pages()->orderBy('id')->pluck('title')->all());
        $packages = $website->pages()->where('slug', 'photo-booth-packages')->firstOrFail();
        $this->assertTrue($packages->noindex);
        $this->assertSame('Wedding booth package', $packages->sections[1]['data']['items'][0]['name']);
    }

    public function test_home_is_a_concise_preview_of_the_same_services_the_dedicated_page_shows_in_full(): void
    {
        [, $business] = $this->entitledTenant();
        $longDescription = str_repeat('Real detail about this booth option. ', 8);
        foreach (range(1, 5) as $i) {
            BusinessService::create([
                'business_id' => $business->id,
                'name' => "Booth option {$i}",
                'slug' => "booth-option-{$i}",
                'description' => $longDescription,
                'status' => BusinessServiceStatus::Active,
                'sort_order' => $i,
            ]);
        }

        $website = app(WebsiteStarterDraftService::class)->create($business, 'clean');
        $home = $website->pages()->where('is_home', true)->firstOrFail();
        $servicesPage = $website->pages()->where('slug', 'photo-booth-services')->firstOrFail();

        $homeItems = collect($home->sections)->firstWhere('type', 'services')['data']['items'];
        $fullItems = collect($servicesPage->sections)->firstWhere('type', 'services')['data']['items'];

        // Same underlying records (same name, same order), but Home is a
        // concise preview: fewer items, and each one truncated shorter
        // than the dedicated page's full description of that exact
        // saved record.
        $this->assertCount(3, $homeItems);
        $this->assertCount(5, $fullItems);
        $this->assertSame($longDescription, $fullItems[0]['description']);
        $this->assertNotSame($fullItems[0]['description'], $homeItems[0]['description']);
        $this->assertTrue(strlen($homeItems[0]['description']) < strlen($fullItems[0]['description']));
        $this->assertSame($fullItems[0]['name'], $homeItems[0]['name']);
    }

    public function test_home_is_a_concise_preview_of_the_same_packages_the_dedicated_page_shows_in_full(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $longDescription = str_repeat('Real detail about what this package includes. ', 6);
        foreach (range(1, 5) as $i) {
            CatalogItem::create([
                'business_id' => $business->id,
                'type' => CatalogItemType::Package,
                'name' => "Package option {$i}",
                'description' => $longDescription,
                'position' => $i,
                'created_by_user_id' => $customer->user_id,
            ]);
        }

        $website = app(WebsiteStarterDraftService::class)->create($business, 'clean');
        $home = $website->pages()->where('is_home', true)->firstOrFail();
        $packagesPage = $website->pages()->where('slug', 'photo-booth-packages')->firstOrFail();

        $homeItems = collect($home->sections)->first(fn ($section) => ($section['data']['heading'] ?? null) === 'Packages & products')['data']['items'];
        $fullItems = collect($packagesPage->sections)->firstWhere('type', 'services')['data']['items'];

        $this->assertCount(3, $homeItems);
        $this->assertCount(5, $fullItems);
        $this->assertSame($longDescription, $fullItems[0]['description']);
        $this->assertNotSame($fullItems[0]['description'], $homeItems[0]['description']);
        $this->assertTrue(strlen($homeItems[0]['description']) < strlen($fullItems[0]['description']));
    }

    public function test_a_page_specific_detail_added_to_the_services_page_survives_and_never_reaches_home(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        BusinessService::create([
            'business_id' => $business->id,
            'name' => 'Mirror booth',
            'status' => BusinessServiceStatus::Active,
            'sort_order' => 0,
        ]);
        $website = app(WebsiteStarterDraftService::class)->create($business, 'clean');
        $servicesPage = $website->pages()->where('slug', 'photo-booth-services')->firstOrFail();
        $home = $website->pages()->where('is_home', true)->firstOrFail();
        $this->authenticateAsCustomer($customer);

        $ownerDetail = 'We bring a floral backdrop, a vintage prop chest, and a strip-print station to every event.';
        $sections = $servicesPage->sections;
        $sections[] = ['type' => 'text', 'data' => ['heading' => 'What to expect', 'body' => $ownerDetail]];

        $this->put(route('customer.workspaces.businesses.website.pages.update', [$workspace->uid, $business->uid, $servicesPage->uid]), [
            'title' => $servicesPage->title,
            'slug' => $servicesPage->slug,
            'is_home' => 0,
            'noindex' => 1,
            'sections' => $sections,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $lastSection = collect($servicesPage->fresh()->sections)->last();
        $this->assertSame('text', $lastSection['type']);
        $this->assertSame($ownerDetail, $lastSection['data']['body']);
        $this->assertTrue($servicesPage->fresh()->noindex);

        // Never leaked onto Home...
        $this->assertStringNotContainsString($ownerDetail, json_encode($home->fresh()->sections));

        // ...and re-running the (idempotent) starter-draft creation call
        // does not reset or duplicate the page-specific edit.
        $again = app(WebsiteStarterDraftService::class)->create($business, 'bold');
        $this->assertSame($website->id, $again->id);
        $this->assertSame($ownerDetail, collect($servicesPage->fresh()->sections)->last()['data']['body']);
    }

    public function test_the_services_page_editor_explains_how_to_add_real_detail(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        BusinessService::create([
            'business_id' => $business->id,
            'name' => 'Mirror booth',
            'status' => BusinessServiceStatus::Active,
            'sort_order' => 0,
        ]);
        $website = app(WebsiteStarterDraftService::class)->create($business, 'clean');
        $servicesPage = $website->pages()->where('slug', 'photo-booth-services')->firstOrFail();
        $home = $website->pages()->where('is_home', true)->firstOrFail();
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.website.pages.edit', [$workspace->uid, $business->uid, $servicesPage->uid]))
            ->assertOk()
            ->assertSee('booth types, backdrops, props, and extras', false)
            ->assertSee('sections below', false)
            ->assertSee('they never change Home or your saved Business service/catalog records', false)
            // The item editor is below this alert, not above it, and this
            // draft may predate the Home-preview/full-page split, so the
            // guidance must not claim a specific Home behavior that isn't
            // guaranteed for every draft.
            ->assertDontSee('Description above', false)
            ->assertDontSee('Home only shows a short preview', false);

        // Page-specific: the same guidance never shows while editing Home.
        $this->get(route('customer.workspaces.businesses.website.pages.edit', [$workspace->uid, $business->uid, $home->uid]))
            ->assertOk()
            ->assertDontSee('booth types, backdrops, props, and extras', false);
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

    public function test_the_pages_checklist_guides_booth_detail_and_photo_reuse(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        BusinessService::create([
            'business_id' => $business->id,
            'name' => 'Mirror booth',
            'status' => BusinessServiceStatus::Active,
            'sort_order' => 0,
        ]);
        app(WebsiteStarterDraftService::class)->create($business, 'clean');
        $this->authenticateAsCustomer($customer);

        $pages = route('customer.workspaces.businesses.website.pages.index', [$workspace->uid, $business->uid]);
        $this->get($pages)->assertOk()
            ->assertSee('booth types, backdrops, props, and extras', false)
            ->assertDontSee('Add Gallery photos');

        // Once real photos are selected for the Gallery page, the
        // checklist points to reusing them, and each non-Gallery page
        // in the list offers to do so in one action.
        $website = Website::where('business_id', $business->id)->firstOrFail();
        $this->post(route('customer.workspaces.businesses.website.assets.store', [$workspace->uid, $business->uid]), [
            'image' => $this->fakeImageUpload('booth.png'),
            'alt_text' => 'Booth at a real event',
        ])->assertRedirect();
        $asset = $website->assets()->sole();
        $this->post(route('customer.workspaces.businesses.website.gallery.store', [$workspace->uid, $business->uid]), [
            'asset_uids' => [$asset->uid],
        ])->assertRedirect();

        $this->get($pages)->assertOk()->assertSee('Add Gallery photos');
    }

    public function test_photo_booth_guidance_only_promises_an_active_public_location(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        app(WebsiteStarterDraftService::class)->create($business, 'clean');
        $location = BusinessLocation::create([
            'business_id' => $business->id,
            'service_mode' => 'storefront',
            'country_code' => 'US',
            'city' => 'Privateville',
            'public_address' => false,
        ]);
        $location->is_primary = true;
        $location->save();
        $this->authenticateAsCustomer($customer);

        $pages = route('customer.workspaces.businesses.website.pages.index', [$workspace->uid, $business->uid]);
        $this->get($pages)->assertOk()->assertSee('no active public location is available');

        $location->public_address = true;
        $location->save();
        $this->get($pages)->assertOk()->assertSee('public location in Privateville');
    }

    public function test_about_faq_and_contact_pages_are_reachable_via_navigation_and_the_contact_pages_form_works(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        // Forms V1: a form carries its Location, and a form with none accepts nothing.
        \App\Models\BusinessLocation::create(['business_id' => $business->id, 'name' => 'Main', 'service_mode' => 'storefront', 'country_code' => 'US']);
        $business->update(['phone' => '+15550001234']);
        app(BusinessKnowledgeProfileManager::class)->updateFields($business, [
            'years_operating' => 5,
            'pricing_method' => 'package_tiers',
        ], 'manual_edit', $customer->user_id, markVerified: true);

        $website = app(WebsiteStarterDraftService::class)->create($business, 'clean');
        $this->authenticateAsCustomer($customer);
        app(WebsitePublisher::class)->publish($website, $customer->user_id);

        // Every core page the owner never had to build by hand is
        // reachable from the public site's own navigation — the same
        // data-driven nav every other page already uses, so no
        // dedicated wiring was needed for these three.
        $home = $this->get(route('public.website.home', $website->public_id))->assertOk();
        foreach (['photo-booth-about', 'photo-booth-faq', 'photo-booth-contact'] as $slug) {
            $url = route('public.website.page', [$website->public_id, $slug]);
            $home->assertSee($url, false);
            $this->get($url)->assertOk();
        }

        // The auto-created quote-request form embedded on the Contact
        // page actually accepts a real submission end to end.
        $contactPage = $website->pages()->where('slug', 'photo-booth-contact')->firstOrFail();
        $form = $website->forms()->sole();
        $submitUrl = route('public.website.form.submit', [$website->public_id, $form->uid, $contactPage->uid]);

        $this->post($submitUrl, [
            'name' => 'Jane Visitor',
            'phone' => '5551234567',
        ])->assertRedirect();

        $this->assertSame(1, WebsiteFormSubmission::where('website_form_id', $form->id)->count());
    }
}

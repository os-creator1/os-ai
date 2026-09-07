<?php

namespace Tests\Feature\Website;

use App\Library\Website\WebsiteSectionValidator;
use App\Models\WebsiteRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Website Generation + Hosting Slice A contract §37.6 (Components).
 *
 * WebsiteSectionValidator is the single source of truth for section
 * shape validation (used by both WebsiteDraftPageService's draft save
 * and, with allowAssetReferences=false, AI generation). These tests
 * exercise it directly at the unit level, and confirm the same rules
 * apply end-to-end through the pages.store HTTP route and through a
 * full save+publish round trip (order preservation into the immutable
 * WebsiteRevision snapshot).
 */
class WebsiteComponentValidationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    private const ALL_TYPES = [
        'hero', 'text', 'image_text', 'services',
        'testimonials', 'faq', 'cta', 'contact_details',
    ];

    private function validator(): WebsiteSectionValidator
    {
        return app(WebsiteSectionValidator::class);
    }

    // ---------------------------------------------------------------
    // (a) only the 8 allowlisted types are accepted
    // ---------------------------------------------------------------

    public function test_unknown_section_types_are_rejected(): void
    {
        foreach (['video', 'map', 'custom_html'] as $badType) {
            try {
                $this->validator()->validate([['type' => $badType, 'data' => []]], []);
                $this->fail("Expected ValidationException for unknown section type '{$badType}'.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('sections.0.type', $e->errors());
            }
        }
    }

    public function test_all_eight_allowlisted_types_validate_when_well_formed(): void
    {
        $validAssetUids = ['asset-uid-1'];

        foreach (self::ALL_TYPES as $type) {
            $overrides = $type === 'image_text' ? ['image' => 'asset-uid-1'] : [];
            $sections = [$this->section($type, $overrides)];

            $result = $this->validator()->validate($sections, $validAssetUids);

            $this->assertSame($type, $result[0]['type']);
        }
    }

    // ---------------------------------------------------------------
    // (b) malformed `data` shape throws for EACH of the 8 types
    // ---------------------------------------------------------------

    public function test_each_section_type_rejects_malformed_data_missing_a_required_key(): void
    {
        $validAssetUids = ['asset-uid-1'];

        $missingKeyByType = [
            'hero' => 'heading',
            'text' => 'body',
            'image_text' => 'body',
            'services' => 'items',
            'testimonials' => 'items',
            'faq' => 'items',
            'cta' => 'buttons',
            'contact_details' => 'show_phone',
        ];

        foreach ($missingKeyByType as $type => $missingKey) {
            $baseOverrides = $type === 'image_text' ? ['image' => 'asset-uid-1'] : [];
            $section = $this->section($type, $baseOverrides);
            unset($section['data'][$missingKey]);

            try {
                $this->validator()->validate([$section], $validAssetUids);
                $this->fail("Expected ValidationException for '{$type}' missing '{$missingKey}'.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey("sections.0.{$missingKey}", $e->errors(), "Missing-key error not reported for type '{$type}'.");
            }
        }
    }

    // ---------------------------------------------------------------
    // (c) per-type length/count limits, read from WebsiteSectionValidator
    // ---------------------------------------------------------------

    public function test_hero_heading_enforces_max_120_characters(): void
    {
        $withinLimit = $this->section('hero', ['heading' => str_repeat('a', 120)]);
        $result = $this->validator()->validate([$withinLimit], []);
        $this->assertSame(str_repeat('a', 120), $result[0]['data']['heading']);

        $overLimit = $this->section('hero', ['heading' => str_repeat('a', 121)]);

        try {
            $this->validator()->validate([$overLimit], []);
            $this->fail('Expected ValidationException for a 121-character hero heading.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('sections.0.heading', $e->errors());
        }
    }

    public function test_services_items_enforces_max_12_items(): void
    {
        $twelveItems = array_fill(0, 12, ['name' => 'Service', 'description' => null, 'price_label' => null, 'image' => null]);
        $ok = $this->section('services', ['items' => $twelveItems]);
        $result = $this->validator()->validate([$ok], []);
        $this->assertCount(12, $result[0]['data']['items']);

        $thirteenItems = array_fill(0, 13, ['name' => 'Service', 'description' => null, 'price_label' => null, 'image' => null]);
        $tooMany = $this->section('services', ['items' => $thirteenItems]);

        try {
            $this->validator()->validate([$tooMany], []);
            $this->fail('Expected ValidationException for 13 services items.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('sections.0.items', $e->errors());
        }
    }

    public function test_cta_buttons_enforces_min_1_max_2(): void
    {
        $zeroButtons = $this->section('cta', ['buttons' => []]);

        try {
            $this->validator()->validate([$zeroButtons], []);
            $this->fail('Expected ValidationException for 0 cta buttons.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('sections.0.buttons', $e->errors());
        }

        $threeButtons = $this->section('cta', ['buttons' => [
            ['label' => 'One', 'url' => 'https://example.test/1'],
            ['label' => 'Two', 'url' => 'https://example.test/2'],
            ['label' => 'Three', 'url' => 'https://example.test/3'],
        ]]);

        try {
            $this->validator()->validate([$threeButtons], []);
            $this->fail('Expected ValidationException for 3 cta buttons.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('sections.0.buttons', $e->errors());
        }

        $twoButtons = $this->section('cta', ['buttons' => [
            ['label' => 'One', 'url' => 'https://example.test/1'],
            ['label' => 'Two', 'url' => 'https://example.test/2'],
        ]]);
        $result = $this->validator()->validate([$twoButtons], []);
        $this->assertCount(2, $result[0]['data']['buttons']);
    }

    // ---------------------------------------------------------------
    // HTTP-level: the SAME rules apply end-to-end through pages.store
    // ---------------------------------------------------------------

    public function test_unknown_section_type_is_rejected_via_pages_store_route(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $response = $this->post(route('customer.workspaces.businesses.website.pages.store', [$workspace->uid, $business->uid]), [
            'title' => 'Home',
            'is_home' => true,
            'sections' => [['type' => 'video', 'data' => []]],
        ]);

        $response->assertSessionHasErrors('sections.0.type');
        $this->assertDatabaseMissing('website_pages', ['website_id' => $website->id]);
    }

    public function test_malformed_section_data_is_rejected_via_pages_store_route(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $brokenHero = $this->section('hero');
        unset($brokenHero['data']['heading']);

        $response = $this->post(route('customer.workspaces.businesses.website.pages.store', [$workspace->uid, $business->uid]), [
            'title' => 'Home',
            'is_home' => true,
            'sections' => [$brokenHero],
        ]);

        $response->assertSessionHasErrors('sections.0.heading');
        $this->assertDatabaseMissing('website_pages', ['website_id' => $website->id]);
    }

    // ---------------------------------------------------------------
    // (d) section order is preserved through a save+publish round trip
    // ---------------------------------------------------------------

    public function test_section_order_is_preserved_through_save_and_publish_round_trip(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $orderedSections = [
            $this->section('text'),
            $this->section('hero'),
            $this->section('faq'),
        ];

        $this->post(route('customer.workspaces.businesses.website.pages.store', [$workspace->uid, $business->uid]), [
            'title' => 'Home',
            'is_home' => true,
            'sections' => $orderedSections,
        ])->assertRedirect();

        $this->post(route('customer.workspaces.businesses.website.publish', [$workspace->uid, $business->uid]))
            ->assertRedirect();

        $revision = WebsiteRevision::latest('id')->firstOrFail();
        $types = array_column($revision->snapshot['pages'][0]['sections'], 'type');

        $this->assertSame(['text', 'hero', 'faq'], $types);
    }
}

<?php

namespace Tests\Feature\Catalog;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\CatalogItem;
use App\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Catalog\Concerns\CreatesCatalogHttpFixtures;
use Tests\TestCase;

/**
 * The "Add to catalog" dialog and the "Prices by location" cards: what the
 * dialog's JSON requests create (and refuse to create), and that every number
 * on a location card is the pricing resolver's own, for reachable Locations
 * only.
 */
class CatalogDesignScreensTest extends TestCase
{
    use CreatesCatalogHttpFixtures;
    use RefreshDatabase;

    private function ajax(string $method, string $url, array $data = [])
    {
        return $this->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])->{$method . 'Json'}($url, $data);
    }

    private function dollars(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '${PRICE}', 'status' => true]);
    }

    // ------------------------------------------------------- the dialog itself

    public function test_the_list_renders_the_dialog_with_a_working_non_javascript_fallback_link(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $this->dollars();
        $this->authenticateAs($owner);

        $create = $this->catalogRoute('create', $workspace, $business);
        $html = $this->get($this->catalogRoute('index', $workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('id="catalog-add-dialog"', $html);
        $this->assertStringContainsString('role="radiogroup" aria-label="Type"', $html);
        $this->assertStringContainsString('action="' . $this->catalogRoute('store', $workspace, $business) . '"', $html);
        $this->assertStringContainsString('data-symbol="$"', $html);
        // The business currency is pre-selected only because it is the business's own.
        $this->assertMatchesRegularExpression('/<a href="' . preg_quote($create, '/') . '"[^>]*data-catalog-add/', $html);

        // The full-page create URL still works.
        $this->get($create)->assertOk()->assertSee('data-role="catalog-create-form"', false);
    }

    public function test_a_package_with_a_fixed_price_is_created_and_appears_in_the_list(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $this->dollars();
        $this->authenticateAs($owner);

        $response = $this->ajax('post', $this->catalogRoute('store', $workspace, $business), [
            'type' => 'package', 'name' => 'Prom Night Booth', 'description' => '4 hours', 'pricing' => 'fixed', 'price' => '850.00', 'currency_code' => 'USD',
        ])->assertCreated()->assertJsonPath('redirect', $this->catalogRoute('index', $workspace, $business));

        $item = CatalogItem::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertSame('Prom Night Booth', $item->name);
        $this->assertSame(85000, $item->price_minor);
        $this->assertSame('USD', $item->currency_code);
        $this->assertSame($item->uid, $response->json('uid'));

        // The reload the dialog performs shows it, with the confirmation.
        $this->get($response->json('redirect'))
            ->assertOk()
            ->assertSee('Prom Night Booth')
            ->assertSee('USD 850.00')
            ->assertSee('added to your catalog.');
    }

    public function test_a_product_can_be_quote_only_and_is_never_given_a_price(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $this->dollars();
        $this->authenticateAs($owner);

        // Even if a forged/stale form still carries a price and currency.
        $this->ajax('post', $this->catalogRoute('store', $workspace, $business), [
            'type' => 'product', 'name' => 'Custom backdrop', 'pricing' => 'quote', 'price' => '99.00', 'currency_code' => 'USD',
        ])->assertCreated();

        $item = CatalogItem::query()->where('name', 'Custom backdrop')->firstOrFail();
        $this->assertSame('product', $item->type->value);
        $this->assertNull($item->price_minor);
        $this->assertNull($item->currency_code);

        $this->get($this->catalogRoute('index', $workspace, $business))->assertSee('Quote only');
    }

    public function test_a_quote_only_post_without_price_fields_is_also_quote_only(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $this->authenticateAs($owner);

        // What the dialog really sends: disabled controls are not submitted.
        $this->ajax('post', $this->catalogRoute('store', $workspace, $business), ['type' => 'product', 'name' => 'Backdrop', 'pricing' => 'quote'])->assertCreated();

        $this->assertNull(CatalogItem::query()->firstOrFail()->price_minor);
    }

    public function test_fixed_price_without_a_figure_is_refused_not_saved_as_quote_only(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $this->authenticateAs($owner);

        $this->ajax('post', $this->catalogRoute('store', $workspace, $business), [
            'type' => 'package', 'name' => 'A', 'pricing' => 'fixed', 'price' => '', 'currency_code' => 'USD',
        ])->assertStatus(422)->assertJsonValidationErrors('price');

        $this->assertSame(0, CatalogItem::query()->count());
    }

    public function test_pricing_and_currency_refusals_come_back_as_json_and_write_nothing(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $this->authenticateAs($owner);
        $url = $this->catalogRoute('store', $workspace, $business);

        $this->ajax('post', $url, ['type' => 'package', 'name' => 'A', 'pricing' => 'fixed', 'price' => '10.00', 'currency_code' => ''])
            ->assertStatus(422)
            ->assertJsonPath('errors.catalog.0', 'Set both a price and a currency, or leave both blank for a quote-only item.');

        foreach ([['10.999', 'USD'], ['10.50', 'JPY'], ['ten dollars', 'USD'], ['-5', 'USD'], ['10', 'XYZ']] as [$price, $currency]) {
            $this->ajax('post', $url, ['type' => 'package', 'name' => 'A', 'pricing' => 'fixed', 'price' => $price, 'currency_code' => $currency])
                ->assertStatus(422)->assertJsonValidationErrors('catalog');
        }

        $this->ajax('post', $url, ['type' => 'package', 'name' => str_repeat('n', 161), 'pricing' => 'quote'])
            ->assertStatus(422)->assertJsonPath('errors.catalog.0', 'Use a name of 1 to 160 characters.');
        $this->ajax('post', $url, ['type' => 'package', 'name' => '', 'pricing' => 'quote'])
            ->assertStatus(422)->assertJsonValidationErrors('name');
        $this->ajax('post', $url, ['type' => 'gadget', 'name' => 'A', 'pricing' => 'quote'])
            ->assertStatus(422)->assertJsonValidationErrors('type');
        $this->ajax('post', $url, ['type' => 'package', 'name' => 'A', 'pricing' => 'bogus'])
            ->assertStatus(422)->assertJsonValidationErrors('pricing');

        $this->assertSame(0, CatalogItem::query()->count());
    }

    public function test_cancelling_the_dialog_creates_nothing(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $this->authenticateAs($owner);

        // Cancel / close / backdrop / Escape are client-side only: no request is
        // sent, so opening the list (which renders the dialog) writes nothing.
        $this->get($this->catalogRoute('index', $workspace, $business))->assertOk()->assertSee('data-role="catalog-add-cancel"', false);

        $this->assertSame(0, CatalogItem::query()->count());
    }

    public function test_the_dialog_endpoint_keeps_the_gates_and_tenancy(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        [, $foreignBusiness, $foreignWorkspace] = $this->catalogTenant(WorkspacePlanTier::Core, 'Foreign Studio');
        $payload = ['type' => 'package', 'name' => 'Sneaky', 'pricing' => 'quote'];

        $this->authenticateAs($owner);
        $this->ajax('post', $this->catalogRoute('store', $foreignWorkspace, $foreignBusiness), $payload)->assertNotFound();

        $this->denyCatalogEntitlement($workspace);
        $this->ajax('post', $this->catalogRoute('store', $workspace, $business), $payload)->assertNotFound();

        $this->assertSame(0, CatalogItem::query()->count());
    }

    // ------------------------------------------------------ prices by location

    public function test_a_business_with_one_location_summarises_it_honestly(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $location = $this->catalogLocation($business, 'Primary Location');
        foreach (['A', 'B', 'C', 'D'] as $name) {
            $this->catalogItem($business, $name);
        }
        $this->authenticateAs($owner);

        $html = $this->get($this->catalogRoute('locations.index', $workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('Prices by location', $html);
        $this->assertStringContainsString('Choose what each location offers and what it charges.', $html);
        $this->assertMatchesRegularExpression('/All 4 items offered\s*&middot;\s*Same prices as your catalog/', $html);
        $this->assertStringContainsString('href="' . $this->catalogRoute('locations.show', $workspace, $business, [$location->uid]) . '"', $html);
        $this->assertStringContainsString('href="' . $this->catalogRoute('index', $workspace, $business) . '"', $html);
        // The existing authorized location-creation workflow.
        $this->assertStringContainsString('>Add location</a>', $html);
        $this->assertStringContainsString(route('customer.workspaces.businesses.locations.create', [$workspace->uid, $business->uid]), $html);
    }

    public function test_counts_follow_availability_and_overrides_per_location(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $downtown = $this->catalogLocation($business, 'Downtown');
        $airport = $this->catalogLocation($business, 'Airport');
        $empty = $this->catalogLocation($business, 'Closed Kiosk');
        $a = $this->catalogItem($business, 'A');
        $b = $this->catalogItem($business, 'B');
        $this->catalogItem($business, 'Quote', ['price_minor' => null, 'currency_code' => null]);
        $this->authenticateAs($owner);

        $this->post($this->catalogRoute('locations.price', $workspace, $business, [$downtown->uid, $a->uid]), ['price' => '10.00'])->assertRedirect();
        $this->post($this->catalogRoute('locations.price', $workspace, $business, [$downtown->uid, $b->uid]), ['price' => '20.00'])->assertRedirect();
        $this->post($this->catalogRoute('locations.enabled', $workspace, $business, [$airport->uid, $a->uid]), ['is_enabled' => 0])->assertRedirect();
        foreach ([$a, $b] as $item) {
            $this->post($this->catalogRoute('locations.enabled', $workspace, $business, [$empty->uid, $item->uid]), ['is_enabled' => 0]);
        }
        $this->post($this->catalogRoute('locations.enabled', $workspace, $business, [$empty->uid, CatalogItem::query()->where('name', 'Quote')->value('uid')]), ['is_enabled' => 0]);

        $text = preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($this->get($this->catalogRoute('locations.index', $workspace, $business))->assertOk()->getContent())));

        $this->assertStringContainsString('Downtown All 3 items offered · 2 items with their own prices', $text);
        $this->assertStringContainsString('Airport 2 of 3 items offered · Same prices as your catalog', $text);
        $this->assertStringContainsString('Closed Kiosk No items offered', $text);

        // The editor still resolves quote-only accurately, and shows the override.
        $this->get($this->catalogRoute('locations.show', $workspace, $business, [$downtown->uid]))
            ->assertOk()->assertSee('USD 10.00')->assertSee('Quote only')->assertSee('data-role="location-price-badge"', false);
    }

    public function test_a_catalog_with_no_items_says_so(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $this->catalogLocation($business, 'Primary Location');
        $this->authenticateAs($owner);

        $this->get($this->catalogRoute('locations.index', $workspace, $business))->assertOk()->assertSee('No items in your catalog yet');
    }

    public function test_a_restricted_actor_sees_only_their_locations_and_no_add_link(): void
    {
        [, $business, $workspace] = $this->catalogTenant();
        $downtown = $this->catalogLocation($business, 'Downtown Studio');
        $airport = $this->catalogLocation($business, 'Airport Kiosk');
        $this->catalogItem($business);
        $this->authenticateAs($this->staffGrantedOnly($workspace, $downtown));

        $response = $this->get($this->catalogRoute('locations.index', $workspace, $business))->assertOk();

        $response->assertSee('Downtown Studio')->assertSee('All 1 item offered');
        $response->assertDontSee('Airport Kiosk')->assertDontSee($airport->uid)->assertDontSee('catalog-add-location');
        $this->assertSame(1, substr_count($response->getContent(), 'data-location="'));
    }

    public function test_another_business_cannot_be_read_through_this_one(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        [, $foreignBusiness] = $this->catalogTenant(WorkspacePlanTier::Core, 'Foreign Studio');
        $foreignLocation = $this->catalogLocation($foreignBusiness, 'Foreign Place');
        $this->catalogItem($foreignBusiness, 'Foreign Item');
        $this->authenticateAs($owner);

        $this->get($this->catalogRoute('locations.index', $workspace, $business))->assertOk()->assertDontSee('Foreign Place');
        $this->get($this->catalogRoute('locations.show', $workspace, $business, [$foreignLocation->uid]))->assertNotFound();
        $this->assertSame(0, DB::table('catalog_item_location_overrides')->count());
    }
}

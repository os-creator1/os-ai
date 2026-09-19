<?php

namespace Tests\Feature\Catalog;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Catalog\CatalogItemManager;
use App\Library\Catalog\CatalogItemPricingResolver;
use App\Library\Catalog\CatalogMoney;
use App\Models\CatalogItemLocationOverride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Catalog\Concerns\CreatesCatalogHttpFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 16 §5.2, §12.E — Phase 1: per-Location enable/
 * disable, price override and effective price over real HTTP, proven to run
 * THROUGH `CatalogItemLocationOverrideManager` and
 * `CatalogItemPricingResolver`.
 *
 * The decisive evidence is the SPARSE INVARIANT (§5.2): "no row means enabled
 * at the Business-wide price", so turning an item back ON, or clearing an
 * override, must DELETE the row rather than persist a redundant default. Only
 * the manager does that; a controller writing override rows itself would leave
 * a `(enabled, no override)` row behind.
 */
class CatalogLocationOffersHttpTest extends TestCase
{
    use CreatesCatalogHttpFixtures;
    use RefreshDatabase;

    private function overrideRow(int $itemId, int $locationId): ?object
    {
        return DB::table('catalog_item_location_overrides')
            ->where('catalog_item_id', $itemId)
            ->where('business_location_id', $locationId)
            ->first();
    }

    // -------------------------------------------------------- visible Locations

    public function test_the_location_list_shows_only_locations_the_actor_can_reach(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $downtown = $this->catalogLocation($business, 'Downtown Studio');
        $airport = $this->catalogLocation($business, 'Airport Kiosk');

        $staff = $this->staffGrantedOnly($workspace, $downtown);
        $this->authenticateAs($staff);

        $response = $this->get($this->catalogRoute('locations.index', $workspace, $business))->assertOk();

        $response->assertSee('Downtown Studio')->assertSee($downtown->uid);

        // The inaccessible Location is not listed, not named, not linked, and
        // no total exists that could reveal it is there.
        $response->assertDontSee('Airport Kiosk')->assertDontSee($airport->uid);
        $this->assertSame(1, substr_count($response->getContent(), 'data-location="'));
    }

    public function test_an_owner_sees_every_location(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $this->catalogLocation($business, 'Downtown Studio');
        $this->catalogLocation($business, 'Airport Kiosk');
        $this->authenticateAs($owner);

        $response = $this->get($this->catalogRoute('locations.index', $workspace, $business))->assertOk();

        $response->assertSee('Downtown Studio')->assertSee('Airport Kiosk');
    }

    public function test_an_actor_with_no_location_grants_sees_an_honest_empty_state(): void
    {
        [, $business, $workspace] = $this->catalogTenant();
        $this->catalogLocation($business, 'Downtown Studio');

        // Reaches the Business, but holds Selected reach with no grants.
        $staff = $this->staffGrantedOnly($workspace, $this->catalogLocation($business, 'Granted Elsewhere'));
        DB::table('workspace_membership_locations')->delete();
        $this->authenticateAs($staff);

        $this->get($this->catalogRoute('locations.index', $workspace, $business))
            ->assertOk()
            ->assertSee('data-role="catalog-no-locations"', false)
            ->assertDontSee('Downtown Studio')
            ->assertDontSee('Granted Elsewhere');
    }

    // ---------------------------------------------------------- effective price

    public function test_the_effective_price_is_the_resolvers_answer(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $location = $this->catalogLocation($business, 'Downtown');
        $item = $this->catalogItem($business, 'Wedding Package', ['price_minor' => 250000]);
        $this->authenticateAs($owner);

        $resolved = app(CatalogItemPricingResolver::class)->resolve($business, $item, $location);
        $expected = CatalogMoney::format($resolved->priceMinor, $resolved->currencyCode);

        $this->assertSame('USD 2,500.00', $expected);

        $this->get($this->catalogRoute('locations.show', $workspace, $business, [$location->uid]))
            ->assertOk()
            ->assertSee($expected)
            ->assertDontSee('data-role="location-price-badge"', false);
    }

    public function test_a_location_price_override_changes_the_effective_price_only_at_that_location(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $downtown = $this->catalogLocation($business, 'Downtown');
        $airport = $this->catalogLocation($business, 'Airport');
        $item = $this->catalogItem($business, 'Wedding Package', ['price_minor' => 250000]);
        $this->authenticateAs($owner);

        $this->post($this->catalogRoute('locations.price', $workspace, $business, [$downtown->uid, $item->uid]), ['price' => '199.50'])
            ->assertRedirect();

        $this->assertSame(19950, $this->overrideRow($item->id, $downtown->id)->price_minor_override);

        $this->get($this->catalogRoute('locations.show', $workspace, $business, [$downtown->uid]))
            ->assertOk()
            ->assertSee('USD 199.50')
            ->assertSee('data-role="location-price-badge"', false);

        // The sibling Location and the Business-wide default are untouched.
        $this->get($this->catalogRoute('locations.show', $workspace, $business, [$airport->uid]))
            ->assertOk()
            ->assertSee('USD 2,500.00')
            ->assertDontSee('data-role="location-price-badge"', false);
        $this->assertSame(250000, $item->fresh()->price_minor);
        $this->assertNull($this->overrideRow($item->id, $airport->id));
    }

    public function test_a_blank_price_clears_the_override_and_the_sparse_row_disappears(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $location = $this->catalogLocation($business, 'Downtown');
        $item = $this->catalogItem($business);
        $this->authenticateAs($owner);

        $url = $this->catalogRoute('locations.price', $workspace, $business, [$location->uid, $item->uid]);

        $this->post($url, ['price' => '80.00'])->assertRedirect();
        $this->assertNotNull($this->overrideRow($item->id, $location->id));

        $this->post($url, ['price' => ''])->assertRedirect();

        $this->assertNull(
            $this->overrideRow($item->id, $location->id),
            'Clearing the last deviation must delete the row — the manager\'s sparse invariant, not a controller\'s write.'
        );
    }

    /**
     * The form never offers a price field for a quote-only item (asserted
     * below), so only a forged request can send one. It is refused — here by
     * CatalogMoney, which has no currency to scale a typed figure by — and
     * nothing is written. (Every override-specific rule the manager owns is
     * unreachable through this UI by construction, which is defence in depth,
     * not a gap: the manager still enforces them for any other caller.)
     */
    public function test_a_forged_price_for_a_quote_only_item_is_refused_and_writes_nothing(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $location = $this->catalogLocation($business, 'Downtown');
        $quoteOnly = $this->catalogItem($business, 'Custom Event', ['price_minor' => null, 'currency_code' => null]);
        $this->authenticateAs($owner);

        $this->post($this->catalogRoute('locations.price', $workspace, $business, [$location->uid, $quoteOnly->uid]), ['price' => '10.00'])
            ->assertSessionHasErrors('catalog');

        $this->assertNull($this->overrideRow($quoteOnly->id, $location->id));

        // A blank price on a quote-only item is a no-op the manager accepts.
        $this->post($this->catalogRoute('locations.price', $workspace, $business, [$location->uid, $quoteOnly->uid]), ['price' => ''])
            ->assertSessionHasNoErrors();

        $this->get($this->catalogRoute('locations.show', $workspace, $business, [$location->uid]))
            ->assertOk()
            ->assertSee('Add a business-wide price first');
    }

    // ------------------------------------------------------------ offered here

    public function test_disabling_an_item_at_a_location_goes_through_the_override_manager(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $location = $this->catalogLocation($business, 'Downtown');
        $item = $this->catalogItem($business);
        $this->authenticateAs($owner);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->post($this->catalogRoute('locations.enabled', $workspace, $business, [$location->uid, $item->uid]), ['is_enabled' => 0])
            ->assertRedirect();

        $queries = collect(DB::getQueryLog())->pluck('query')->map('strtolower');
        DB::disableQueryLog();

        $this->assertTrue(
            $queries->contains(fn (string $q) => str_contains($q, 'from `catalog_items`') && str_contains($q, 'for update')),
            'The override manager takes the authoritative catalog_items row lock before writing an override.'
        );

        $row = $this->overrideRow($item->id, $location->id);
        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row->is_enabled);
        $this->assertNull($row->price_minor_override);

        $this->get($this->catalogRoute('locations.show', $workspace, $business, [$location->uid]))
            ->assertOk()
            ->assertSee('Not offered here')
            ->assertSee('data-offered="no"', false)
            ->assertSee('Offer here');
    }

    public function test_enabling_again_deletes_the_row_because_no_row_means_enabled(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $location = $this->catalogLocation($business, 'Downtown');
        $item = $this->catalogItem($business);
        $this->authenticateAs($owner);

        $url = $this->catalogRoute('locations.enabled', $workspace, $business, [$location->uid, $item->uid]);

        $this->post($url, ['is_enabled' => 0])->assertRedirect();
        $this->assertNotNull($this->overrideRow($item->id, $location->id));

        $this->post($url, ['is_enabled' => 1])->assertRedirect();

        $this->assertNull(
            $this->overrideRow($item->id, $location->id),
            'Re-enabling with no price override is the canonical default: no row may persist.'
        );
        $this->assertSame(0, CatalogItemLocationOverride::query()->count());
    }

    public function test_a_disabled_item_keeps_its_location_price_for_when_it_is_offered_again(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $location = $this->catalogLocation($business, 'Downtown');
        $item = $this->catalogItem($business);
        $this->authenticateAs($owner);

        $this->post($this->catalogRoute('locations.price', $workspace, $business, [$location->uid, $item->uid]), ['price' => '80.00']);
        $this->post($this->catalogRoute('locations.enabled', $workspace, $business, [$location->uid, $item->uid]), ['is_enabled' => 0]);

        $row = $this->overrideRow($item->id, $location->id);
        $this->assertSame(0, (int) $row->is_enabled);
        $this->assertSame(8000, (int) $row->price_minor_override, 'Disabling must not discard the Location price.');

        $this->post($this->catalogRoute('locations.enabled', $workspace, $business, [$location->uid, $item->uid]), ['is_enabled' => 1]);

        $this->assertSame(8000, (int) $this->overrideRow($item->id, $location->id)->price_minor_override);
    }

    public function test_an_archived_item_is_not_listed_at_a_location(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $location = $this->catalogLocation($business, 'Downtown');
        $item = $this->catalogItem($business, 'Retired Bundle');
        app(CatalogItemManager::class)->archive($business, $item);
        $this->authenticateAs($owner);

        $this->get($this->catalogRoute('locations.show', $workspace, $business, [$location->uid]))
            ->assertOk()
            ->assertDontSee('Retired Bundle')
            ->assertSee('data-role="catalog-empty"', false);
    }

    // --------------------------------------------------- no controller writes

    public function test_the_actor_recorded_and_business_are_never_taken_from_the_request(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        [, $foreignBusiness] = $this->catalogTenant(WorkspacePlanTier::Core, 'Foreign Studio');
        $foreignLocation = $this->catalogLocation($foreignBusiness, 'Foreign Location');
        $location = $this->catalogLocation($business, 'Downtown');
        $item = $this->catalogItem($business);
        $this->authenticateAs($owner);

        // Extra, forged fields must be ignored: only is_enabled is read.
        $this->post($this->catalogRoute('locations.enabled', $workspace, $business, [$location->uid, $item->uid]), [
            'is_enabled' => 0,
            'business_location_id' => $foreignLocation->id,
            'catalog_item_id' => 999999,
            'price_minor_override' => 1,
        ])->assertRedirect();

        $row = $this->overrideRow($item->id, $location->id);
        $this->assertNotNull($row);
        $this->assertNull($row->price_minor_override, 'A forged price_minor_override must be ignored.');
        $this->assertSame(0, DB::table('catalog_item_location_overrides')->where('business_location_id', $foreignLocation->id)->count());
    }
}

<?php

namespace Tests\Feature\Catalog;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Catalog\CatalogItemLocationOverrideManager;
use App\Library\Catalog\CatalogItemManager;
use App\Library\Catalog\CatalogItemPricingResolver;
use App\Library\Catalog\CatalogLocationOfferReader;
use App\Library\Catalog\Exceptions\CatalogRuleException;
use App\Models\CatalogItem;
use App\Models\CatalogItemLocationOverride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Catalog\Concerns\CreatesCatalogHttpFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 16 §5.1, §5.2, §6 — the V1 completion regressions
 * that the sub-slice suites did not pin:
 *
 *  - query bounds on the per-Location offer page (it used to resolve every
 *    item with its own item/Location/override lookups);
 *  - the resolver's in-memory path refusing exactly what its loading path
 *    refuses;
 *  - a malformed currency refused by the manager, not just by the form;
 *  - Agency View As reaching only the viewed Business's catalog;
 *  - a priced item outside the Business currency being flagged in the UI.
 */
class CatalogV1CompletionTest extends TestCase
{
    use CreatesCatalogHttpFixtures;
    use RefreshDatabase;

    // ------------------------------------------------------------ query bounds

    /** @return list<string> */
    private function queriesFor(callable $request): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $queries = collect(DB::getQueryLog())->pluck('query')->all();
        DB::disableQueryLog();

        return $queries;
    }

    public function test_the_location_offer_page_query_count_does_not_grow_with_the_number_of_items(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $location = $this->catalogLocation($business, 'Downtown');
        $overrides = app(CatalogItemLocationOverrideManager::class);
        $this->authenticateAs($owner);
        $url = $this->catalogRoute('locations.show', $workspace, $business, [$location->uid]);

        for ($i = 1; $i <= 3; $i++) {
            $this->catalogItem($business, "Package {$i}");
        }
        $this->get($url)->assertOk(); // warm any per-process caches
        $small = count($this->queriesFor(fn () => $this->get($url)->assertOk()));

        for ($i = 4; $i <= 24; $i++) {
            $item = $this->catalogItem($business, "Package {$i}");
            // Mix disabled and overridden rows in: every kind of row, not just the default.
            if ($i % 3 === 0) {
                $overrides->setEnabled($business, $item, $location, false);
            } elseif ($i % 3 === 1) {
                $overrides->setPriceOverride($business, $item, $location, 4200 + $i);
            }
        }
        $large = count($this->queriesFor(fn () => $this->get($url)->assertOk()));

        $this->assertSame($small, $large, "The page ran {$small} queries for 3 items and {$large} for 24: it must not issue queries per item.");
    }

    public function test_the_offer_reader_reads_with_a_fixed_number_of_queries(): void
    {
        [, $business] = $this->catalogTenant();
        $location = $this->catalogLocation($business, 'Downtown');
        foreach (range(1, 15) as $i) {
            $this->catalogItem($business, "Package {$i}");
        }

        $queries = $this->queriesFor(fn () => app(CatalogLocationOfferReader::class)->rowsFor($business, $location));

        $this->assertLessThanOrEqual(2, count($queries), 'One read for the items, one for the Location overrides: ' . implode(' | ', $queries));
    }

    public function test_the_offer_reader_refuses_a_location_of_another_business(): void
    {
        [, $business] = $this->catalogTenant();
        [, $other] = $this->catalogTenant(WorkspacePlanTier::Core, 'Other Studio');
        $foreignLocation = $this->catalogLocation($other, 'Elsewhere');
        $this->catalogItem($business);

        $this->expectException(CatalogRuleException::class);

        app(CatalogLocationOfferReader::class)->rowsFor($business, $foreignLocation);
    }

    // -------------------------------------------------- resolver, in-memory path

    public function test_the_in_memory_resolver_path_applies_the_same_rules_as_the_loading_path(): void
    {
        [, $business] = $this->catalogTenant();
        $location = $this->catalogLocation($business, 'Downtown');
        $item = $this->catalogItem($business);
        $resolver = app(CatalogItemPricingResolver::class);

        // default price
        $this->assertSame(100000, $resolver->resolveLoaded($item, $location, null)->priceMinor);
        $this->assertEquals($resolver->resolve($business, $item, $location), $resolver->resolveLoaded($item, $location, null));

        // override price wins; disabled refuses
        $override = new CatalogItemLocationOverride([
            'catalog_item_id' => $item->id, 'business_location_id' => $location->id,
            'is_enabled' => true, 'price_minor_override' => 5000,
        ]);
        $this->assertSame(5000, $resolver->resolveLoaded($item, $location, $override)->priceMinor);

        $override->is_enabled = false;
        try {
            $resolver->resolveLoaded($item, $location, $override);
            $this->fail('A disabled item must not resolve.');
        } catch (CatalogRuleException $e) {
            $this->assertStringContainsString('not offered', $e->getMessage());
        }

        // archived refuses
        $archived = app(CatalogItemManager::class)->archive($business, $item);
        $this->expectException(CatalogRuleException::class);
        $resolver->resolveLoaded($archived, $location, null);
    }

    public function test_the_in_memory_resolver_path_refuses_a_foreign_location_and_a_mismatched_override(): void
    {
        [, $business] = $this->catalogTenant();
        [, $other] = $this->catalogTenant(WorkspacePlanTier::Core, 'Other Studio');
        $location = $this->catalogLocation($business, 'Downtown');
        $sibling = $this->catalogLocation($business, 'Airport');
        $foreign = $this->catalogLocation($other, 'Elsewhere');
        $item = $this->catalogItem($business);
        $resolver = app(CatalogItemPricingResolver::class);

        try {
            $resolver->resolveLoaded($item, $foreign, null);
            $this->fail('A Location of another Business must be refused.');
        } catch (CatalogRuleException) {
            // expected
        }

        // An override row for the SIBLING Location must not be applied to this one.
        $wrong = new CatalogItemLocationOverride([
            'catalog_item_id' => $item->id, 'business_location_id' => $sibling->id,
            'is_enabled' => false, 'price_minor_override' => null,
        ]);
        $this->expectException(CatalogRuleException::class);
        $resolver->resolveLoaded($item, $location, $wrong);
    }

    // ----------------------------------------------------------------- currency

    /** @return array<string, array{0: string}> */
    public static function malformedCurrencies(): array
    {
        return [
            'symbol in the middle' => ['U$D'],
            'digits' => ['123'],
            'two letters and a digit' => ['US1'],
            'non-ascii letters' => ['ÜSD'],
            'inner space' => ['U D'],
        ];
    }

    #[DataProvider('malformedCurrencies')]
    public function test_the_manager_refuses_a_malformed_currency_code(string $currency): void
    {
        [, $business] = $this->catalogTenant();

        $this->expectException(CatalogRuleException::class);

        app(CatalogItemManager::class)->create($business, [
            'type' => 'product', 'name' => 'Print', 'price_minor' => 1000, 'currency_code' => $currency,
        ]);
    }

    public function test_the_manager_normalizes_a_lowercase_currency_and_still_accepts_it(): void
    {
        [, $business] = $this->catalogTenant();

        $item = app(CatalogItemManager::class)->create($business, [
            'type' => 'product', 'name' => 'Print', 'price_minor' => 1000, 'currency_code' => 'usd',
        ]);

        $this->assertSame('USD', $item->currency_code);
    }

    public function test_an_item_priced_outside_the_business_currency_is_flagged_and_a_matching_one_is_not(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $business->forceFill(['currency_code' => 'USD'])->save();
        $this->catalogItem($business, 'Domestic Print', ['currency_code' => 'USD']);
        $foreign = $this->catalogItem($business, 'Overseas Print', ['currency_code' => 'EUR']);
        $this->authenticateAs($owner);

        $html = $this->get($this->catalogRoute('index', $workspace, $business))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-role="currency-mismatch"'));
        $this->assertMatchesRegularExpression(
            '/data-item="' . preg_quote($foreign->uid, '/') . '".*?data-role="currency-mismatch"/s',
            $html,
            'The flag is on the EUR item.'
        );
    }

    // ------------------------------------------------------------ Agency View As

    public function test_view_as_reaches_the_viewed_business_catalog_and_never_a_sibling_clients(): void
    {
        [$agency, $viewed, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Viewed Client', 'Northwind Agency');
        $managed = $this->createAgencyManagedClient($workspace, 'Sibling Client', 'Sibling Client Workspace');
        $sibling = $managed['clientBusiness'];
        $viewedItem = $this->catalogItem($viewed, 'Viewed Package');
        $siblingItem = $this->catalogItem($sibling, 'Sibling Package');
        $this->authenticateAs($agency);

        $this->startViewAs($workspace, $viewed)->assertRedirect(route('user.home'));

        $this->get($this->catalogRoute('index', $workspace, $viewed))
            ->assertOk()
            ->assertSee('Viewed Package')
            ->assertDontSee('Sibling Package');
        $this->get($this->catalogRoute('edit', $workspace, $viewed, [$viewedItem->uid]))->assertOk();

        // The sibling client's catalog is not reachable by Business-scoped
        // route, and a raw item uid of the sibling does not open through the
        // viewed Business either.
        $this->get($this->catalogRoute('index', $managed['clientWorkspace'], $sibling))->assertNotFound();
        $this->get($this->catalogRoute('edit', $workspace, $viewed, [$siblingItem->uid]))->assertNotFound();
        $this->post($this->catalogRoute('archive', $workspace, $viewed, [$siblingItem->uid]))->assertNotFound();

        $this->assertTrue(CatalogItem::findOrFail($siblingItem->id)->isActive());
    }
}

<?php

namespace Tests\Feature\Catalog;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\CatalogItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Catalog\Concerns\CreatesCatalogHttpFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 16 §12.E — Phase 1: the Business-wide catalog over
 * real HTTP, proven to run THROUGH `CatalogItemManager`.
 *
 * How "through the manager" is shown rather than asserted: several outcomes
 * below are ones ONLY the manager produces — its exact refusal wording, its
 * 160-character name limit, its merged price/currency invariant, its
 * complete-list reorder check, its idempotent archive — and a controller that
 * wrote `catalog_items` itself would produce none of them. The manager's
 * `SELECT ... FOR UPDATE` on the catalog row is also observed directly in the
 * query log.
 */
class CatalogItemsHttpTest extends TestCase
{
    use CreatesCatalogHttpFixtures;
    use RefreshDatabase;

    // ------------------------------------------------------------- listing

    public function test_the_catalog_lists_active_and_archived_items_and_an_empty_state(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $this->authenticateAs($owner);

        $this->get($this->catalogRoute('index', $workspace, $business))
            ->assertOk()
            ->assertSee('data-role="catalog-empty"', false);

        $active = $this->catalogItem($business, 'Wedding Package');
        $archived = $this->catalogItem($business, 'Old Bundle');
        app(\App\Library\Catalog\CatalogItemManager::class)->archive($business, $archived);

        $response = $this->get($this->catalogRoute('index', $workspace, $business))->assertOk();

        $response->assertSee('Wedding Package')->assertSee('Old Bundle')->assertSee('data-role="catalog-archived"', false);
        $response->assertSee('data-item="' . $active->uid . '"', false);
        $response->assertSee('USD 1,000.00');
    }

    // -------------------------------------------------------------- create

    public function test_creating_an_item_goes_through_the_manager_and_records_the_actor(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $this->authenticateAs($owner);

        $this->post($this->catalogRoute('store', $workspace, $business), [
            'type' => 'package',
            'name' => 'Deluxe Photo Booth',
            'description' => 'Four hours, unlimited prints.',
            'price' => '49.99',
            'currency_code' => 'usd',
        ])->assertRedirect();

        $item = CatalogItem::query()->where('business_id', $business->id)->firstOrFail();

        $this->assertSame('Deluxe Photo Booth', $item->name);
        $this->assertSame(4999, $item->price_minor, '"49.99" must reach the manager as whole minor units.');
        $this->assertSame('USD', $item->currency_code, 'The manager, not the form, normalizes the currency.');
        $this->assertSame($owner->user_id, (int) $item->created_by_user_id);
        $this->assertSame(0, $item->position);
        $this->assertTrue($item->isActive());
    }

    public function test_a_zero_decimal_currency_is_never_scaled_by_a_hundred(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $this->authenticateAs($owner);

        $this->post($this->catalogRoute('store', $workspace, $business), [
            'type' => 'product', 'name' => 'Tokyo Print', 'price' => '5000', 'currency_code' => 'JPY',
        ])->assertRedirect();

        $this->assertSame(5000, CatalogItem::query()->where('name', 'Tokyo Print')->value('price_minor'));
    }

    public function test_a_quote_only_item_needs_neither_price_nor_currency(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $this->authenticateAs($owner);

        $this->post($this->catalogRoute('store', $workspace, $business), [
            'type' => 'package', 'name' => 'Custom Event', 'price' => '', 'currency_code' => '',
        ])->assertRedirect();

        $item = CatalogItem::query()->where('name', 'Custom Event')->firstOrFail();
        $this->assertNull($item->price_minor);
        $this->assertNull($item->currency_code);
    }

    /**
     * The manager's OWN rules, surfaced as it worded them. A controller that
     * reimplemented these would have to match the wording exactly; the point
     * is that it does not try.
     */
    public function test_the_managers_refusals_are_shown_and_nothing_is_written(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $this->authenticateAs($owner);
        $url = $this->catalogRoute('store', $workspace, $business);

        $cases = [
            'name over the manager limit' => [
                ['type' => 'package', 'name' => str_repeat('n', 161), 'price' => '', 'currency_code' => ''],
                'Use a name of 1 to 160 characters.',
            ],
            'price without a currency' => [
                ['type' => 'package', 'name' => 'A', 'price' => '10.00', 'currency_code' => ''],
                'Set both a price and a currency, or leave both blank for a quote-only item.',
            ],
            'currency without a price' => [
                ['type' => 'package', 'name' => 'A', 'price' => '', 'currency_code' => 'USD'],
                'Set both a price and a currency, or leave both blank for a quote-only item.',
            ],
            'currency that is not three letters' => [
                ['type' => 'package', 'name' => 'A', 'price' => '', 'currency_code' => 'US'],
                'Set both a price and a currency, or leave both blank for a quote-only item.',
            ],
        ];

        foreach ($cases as $label => [$payload, $expected]) {
            $this->from($this->catalogRoute('create', $workspace, $business))
                ->post($url, $payload)
                ->assertRedirect($this->catalogRoute('create', $workspace, $business));

            $this->get($this->catalogRoute('create', $workspace, $business))->assertSee($expected);
            $this->assertSame(0, CatalogItem::query()->where('business_id', $business->id)->count(), "[{$label}] must write nothing.");
        }
    }

    public function test_prices_are_never_silently_rounded_or_guessed(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $this->authenticateAs($owner);
        $url = $this->catalogRoute('store', $workspace, $business);

        foreach ([
            'too many decimals for USD' => ['10.999', 'USD'],
            'decimals for a zero-decimal currency' => ['10.50', 'JPY'],
            'not a number' => ['ten dollars', 'USD'],
            'negative' => ['-5', 'USD'],
            'a currency that cannot be scaled safely' => ['10', 'XYZ'],
        ] as $label => [$price, $currency]) {
            $this->post($url, ['type' => 'package', 'name' => 'A', 'price' => $price, 'currency_code' => $currency])
                ->assertSessionHasErrors('catalog');

            $this->assertSame(0, CatalogItem::query()->count(), "[{$label}] must not create an item.");
        }
    }

    /**
     * Only the five editable fields can come from a form. Lifecycle, position
     * and ownership are not the form's to set, and the manager discards them.
     */
    public function test_a_forged_form_cannot_set_lifecycle_position_or_owner(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        [, $foreignBusiness] = $this->catalogTenant(WorkspacePlanTier::Core, 'Foreign Studio');
        $this->authenticateAs($owner);

        $this->post($this->catalogRoute('store', $workspace, $business), [
            'type' => 'package', 'name' => 'Forged', 'price' => '', 'currency_code' => '',
            'lifecycle_state' => 'archived',
            'archived_at' => now()->toDateTimeString(),
            'position' => 99,
            'business_id' => $foreignBusiness->id,
            'created_by_user_id' => 12345,
        ])->assertRedirect();

        $item = CatalogItem::query()->where('name', 'Forged')->firstOrFail();
        $this->assertSame($business->id, (int) $item->business_id);
        $this->assertTrue($item->isActive());
        $this->assertNull($item->archived_at);
        $this->assertSame(0, $item->position);
        $this->assertSame($owner->user_id, (int) $item->created_by_user_id);
        $this->assertSame(0, CatalogItem::query()->where('business_id', $foreignBusiness->id)->count());
    }

    // ---------------------------------------------------------------- edit

    public function test_the_edit_page_shows_the_current_values_as_a_plain_decimal(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $this->authenticateAs($owner);
        $item = $this->catalogItem($business, 'Wedding Package', ['price_minor' => 123456, 'description' => 'All day']);

        $this->get($this->catalogRoute('edit', $workspace, $business, [$item->uid]))
            ->assertOk()
            ->assertSee('value="Wedding Package"', false)
            ->assertSee('value="1234.56"', false)
            ->assertSee('value="USD"', false)
            ->assertSee('All day');
    }

    public function test_updating_goes_through_the_manager_which_locks_the_catalog_row(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $this->authenticateAs($owner);
        $item = $this->catalogItem($business);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->post($this->catalogRoute('update', $workspace, $business, [$item->uid]), [
            'type' => 'product', 'name' => 'Renamed', 'description' => 'New words', 'price' => '20.00', 'currency_code' => 'USD',
        ])->assertRedirect();

        $queries = collect(DB::getQueryLog())->pluck('query')->map('strtolower');
        DB::disableQueryLog();

        $this->assertTrue(
            $queries->contains(fn (string $q) => str_contains($q, 'from `catalog_items`') && str_contains($q, 'for update')),
            'The update must run through CatalogItemManager, which takes SELECT ... FOR UPDATE on the catalog row.'
        );

        $fresh = $item->fresh();
        $this->assertSame('Renamed', $fresh->name);
        $this->assertSame('product', $fresh->type->value);
        $this->assertSame(2000, $fresh->price_minor);
        $this->assertSame('New words', $fresh->description);
    }

    /**
     * The manager re-checks the invariant against the MERGED state, so blanking
     * the price while leaving the currency must be refused, not half-applied.
     */
    public function test_clearing_only_the_price_is_refused_by_the_manager_and_changes_nothing(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $this->authenticateAs($owner);
        $item = $this->catalogItem($business);

        $this->post($this->catalogRoute('update', $workspace, $business, [$item->uid]), [
            'type' => 'package', 'name' => $item->name, 'price' => '', 'currency_code' => 'USD',
        ])->assertSessionHasErrors('catalog');

        $this->assertSame(100000, $item->fresh()->price_minor);
    }

    // ------------------------------------------------- archive / reactivate

    public function test_archive_and_reactivate_go_through_the_manager_and_are_idempotent(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $this->authenticateAs($owner);
        $item = $this->catalogItem($business);

        $this->post($this->catalogRoute('archive', $workspace, $business, [$item->uid]))->assertRedirect();
        $this->assertTrue($item->fresh()->isArchived());
        $this->assertNotNull($item->fresh()->archived_at);

        // Asked twice: the manager's own idempotency, not an error.
        $this->post($this->catalogRoute('archive', $workspace, $business, [$item->uid]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->get($this->catalogRoute('index', $workspace, $business))
            ->assertOk()
            ->assertSee('data-role="catalog-empty"', false)
            ->assertSee('data-role="reactivate"', false);

        $this->post($this->catalogRoute('reactivate', $workspace, $business, [$item->uid]))->assertRedirect();
        $this->assertTrue($item->fresh()->isActive());
        $this->assertNull($item->fresh()->archived_at);

        $this->post($this->catalogRoute('reactivate', $workspace, $business, [$item->uid]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    // -------------------------------------------------------------- reorder

    public function test_reordering_submits_the_complete_order_and_the_manager_rewrites_positions(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $this->authenticateAs($owner);
        $a = $this->catalogItem($business, 'A');
        $b = $this->catalogItem($business, 'B');
        $c = $this->catalogItem($business, 'C');

        $this->assertSame([0, 1, 2], [$a->position, $b->position, $c->position]);

        $this->post($this->catalogRoute('reorder', $workspace, $business), ['order' => [$c->uid, $a->uid, $b->uid]])
            ->assertRedirect();

        $this->assertSame([1, 2, 0], [$a->fresh()->position, $b->fresh()->position, $c->fresh()->position]);
    }

    public function test_the_index_renders_reorder_controls_that_carry_the_complete_order(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $this->authenticateAs($owner);
        $a = $this->catalogItem($business, 'A');
        $b = $this->catalogItem($business, 'B');

        $html = $this->get($this->catalogRoute('index', $workspace, $business))->assertOk()->getContent();

        // The first row can only move down, the last only up; each button
        // carries BOTH uids, because the manager demands the complete list.
        $this->assertSame(1, substr_count($html, 'data-role="move-down"'));
        $this->assertSame(1, substr_count($html, 'data-role="move-up"'));
        $this->assertStringContainsString('value="' . $a->uid . '"', $html);
        $this->assertStringContainsString('value="' . $b->uid . '"', $html);
    }

    /**
     * The manager's complete-list check: a stale order (an item added in
     * another tab) is REFUSED, never half-applied.
     */
    public function test_a_stale_or_incomplete_order_is_refused_by_the_manager(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $this->authenticateAs($owner);
        $a = $this->catalogItem($business, 'A');
        $b = $this->catalogItem($business, 'B');
        $c = $this->catalogItem($business, 'C');

        foreach ([
            'omits an item' => [$b->uid, $a->uid],
            'names an item twice' => [$a->uid, $a->uid, $b->uid],
            'names an unknown uid' => [$a->uid, $b->uid, '00000000-0000-0000-0000-000000000000'],
        ] as $label => $order) {
            $this->post($this->catalogRoute('reorder', $workspace, $business), ['order' => $order])
                ->assertSessionHasErrors('catalog');

            $this->assertSame([0, 1, 2], [$a->fresh()->position, $b->fresh()->position, $c->fresh()->position], "[{$label}] must change nothing.");
        }
    }

    public function test_an_order_naming_another_businesses_item_is_refused_and_leaks_nothing(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        [, $foreignBusiness] = $this->catalogTenant(WorkspacePlanTier::Core, 'Foreign Studio');
        $mine = $this->catalogItem($business, 'Mine');
        $theirs = $this->catalogItem($foreignBusiness, 'Theirs');
        $this->authenticateAs($owner);

        $this->post($this->catalogRoute('reorder', $workspace, $business), ['order' => [$mine->uid, $theirs->uid]])
            ->assertSessionHasErrors('catalog');

        $this->assertSame(0, $theirs->fresh()->position);
        $this->assertSame(0, $mine->fresh()->position);
    }
}

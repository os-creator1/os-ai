<?php

namespace Tests\Feature\Catalog;

use App\Enums\Catalog\CatalogItemLifecycleState;
use App\Library\Catalog\CatalogItemManager;
use App\Library\Catalog\Exceptions\CatalogRuleException;
use App\Models\Business;
use App\Models\CatalogItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Implementation Contract 16 §12.B — `CatalogItemManager`: Business-wide
 * create/update, archive/reactivate, and deterministic reorder. No
 * controller, route, or view exists yet (Sub-slice E); this file exercises
 * the manager directly, exactly as §12.B specifies. Tenancy, the
 * `packages_products` capability and `EntitlementManager` are HTTP-layer
 * concerns this manager deliberately does not perform (§6) — what it DOES
 * enforce on its own is that a `CatalogItem` genuinely belongs to the
 * `Business` it is addressed through, never trusting the caller's copy of
 * the model.
 */
class CatalogItemManagerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private function manager(): CatalogItemManager
    {
        return app(CatalogItemManager::class);
    }

    private function business(): Business
    {
        $customer = $this->createCustomer();

        return $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
    }

    // -----------------------------------------------------------------
    // create() — canonical validation
    // -----------------------------------------------------------------

    public function test_create_accepts_a_valid_fixed_price_item(): void
    {
        $business = $this->business();

        $item = $this->manager()->create($business, [
            'type' => 'product',
            'name' => 'Deep Clean',
            'description' => 'A thorough clean.',
            'price_minor' => 12000,
            'currency_code' => 'usd',
        ], $business->customer_id);

        $this->assertSame((int) $business->id, (int) $item->business_id);
        $this->assertSame('product', $item->type->value);
        $this->assertSame('Deep Clean', $item->name);
        $this->assertSame(12000, $item->price_minor);
        // ISO currency handling per existing Business conventions: normalized upper-case.
        $this->assertSame('USD', $item->currency_code);
        $this->assertSame(0, $item->position);
        $this->assertSame(CatalogItemLifecycleState::Active, $item->lifecycle_state);
        $this->assertSame((int) $business->customer_id, (int) $item->created_by_user_id);
    }

    public function test_create_accepts_a_quote_only_item_with_no_price(): void
    {
        $business = $this->business();

        $item = $this->manager()->create($business, [
            'type' => 'package',
            'name' => 'Custom Wedding Package',
        ]);

        $this->assertNull($item->price_minor);
        $this->assertNull($item->currency_code);
        $this->assertNull($item->created_by_user_id);
    }

    public function test_create_refuses_an_invalid_type(): void
    {
        $business = $this->business();

        $this->expectException(CatalogRuleException::class);
        $this->manager()->create($business, ['type' => 'bundle', 'name' => 'X']);
    }

    public function test_create_refuses_a_blank_name(): void
    {
        $business = $this->business();

        $this->expectException(CatalogRuleException::class);
        $this->manager()->create($business, ['type' => 'product', 'name' => '   ']);
    }

    public function test_create_refuses_a_name_over_the_bound(): void
    {
        $business = $this->business();

        $this->expectException(CatalogRuleException::class);
        $this->manager()->create($business, ['type' => 'product', 'name' => str_repeat('a', 161)]);
    }

    public function test_create_accepts_a_name_at_exactly_the_bound(): void
    {
        $business = $this->business();

        $item = $this->manager()->create($business, ['type' => 'product', 'name' => str_repeat('a', 160)]);

        $this->assertSame(160, mb_strlen($item->name));
    }

    public function test_create_refuses_a_description_over_the_bound(): void
    {
        $business = $this->business();

        $this->expectException(CatalogRuleException::class);
        $this->manager()->create($business, [
            'type' => 'product',
            'name' => 'X',
            'description' => str_repeat('a', 5001),
        ]);
    }

    public function test_create_treats_a_blank_description_as_null(): void
    {
        $business = $this->business();

        $item = $this->manager()->create($business, ['type' => 'product', 'name' => 'X', 'description' => '   ']);

        $this->assertNull($item->description);
    }

    public function test_create_refuses_price_without_currency(): void
    {
        $business = $this->business();

        $this->expectException(CatalogRuleException::class);
        $this->manager()->create($business, ['type' => 'product', 'name' => 'X', 'price_minor' => 500]);
    }

    public function test_create_refuses_currency_without_price(): void
    {
        $business = $this->business();

        $this->expectException(CatalogRuleException::class);
        $this->manager()->create($business, ['type' => 'product', 'name' => 'X', 'currency_code' => 'USD']);
    }

    public function test_create_refuses_a_negative_price(): void
    {
        $business = $this->business();

        $this->expectException(CatalogRuleException::class);
        $this->manager()->create($business, ['type' => 'product', 'name' => 'X', 'price_minor' => -1, 'currency_code' => 'USD']);
    }

    public function test_create_refuses_a_negative_price_given_as_a_numeric_string(): void
    {
        $business = $this->business();

        $this->expectException(CatalogRuleException::class);
        $this->manager()->create($business, ['type' => 'product', 'name' => 'X', 'price_minor' => '-1', 'currency_code' => 'USD']);
    }

    public function test_create_refuses_a_non_numeric_price_string_instead_of_coercing_it_to_zero(): void
    {
        $business = $this->business();

        // (int) 'abc' silently coerces to 0 — a legitimate free price. The
        // canonical domain validator must refuse malformed input outright
        // rather than normalize it into a valid price.
        $this->expectException(CatalogRuleException::class);
        $this->manager()->create($business, ['type' => 'product', 'name' => 'X', 'price_minor' => 'abc', 'currency_code' => 'USD']);
    }

    public function test_create_refuses_a_decimal_price_string(): void
    {
        $business = $this->business();

        $this->expectException(CatalogRuleException::class);
        $this->manager()->create($business, ['type' => 'product', 'name' => 'X', 'price_minor' => '12.5', 'currency_code' => 'USD']);
    }

    public function test_create_refuses_a_boolean_price(): void
    {
        $business = $this->business();

        // (int) true coerces to 1 — again a silently-valid price the
        // validator must refuse instead of accepting.
        $this->expectException(CatalogRuleException::class);
        $this->manager()->create($business, ['type' => 'product', 'name' => 'X', 'price_minor' => true, 'currency_code' => 'USD']);
    }

    public function test_create_refuses_an_array_price(): void
    {
        $business = $this->business();

        $this->expectException(CatalogRuleException::class);
        $this->manager()->create($business, ['type' => 'product', 'name' => 'X', 'price_minor' => [500], 'currency_code' => 'USD']);
    }

    public function test_create_refuses_a_price_beyond_php_int_max(): void
    {
        $business = $this->business();

        $this->expectException(CatalogRuleException::class);
        $this->manager()->create($business, [
            'type' => 'product',
            'name' => 'X',
            'price_minor' => '99999999999999999999',
            'currency_code' => 'USD',
        ]);
    }

    public function test_create_refuses_the_first_integer_beyond_php_int_max(): void
    {
        $business = $this->business();

        // The equal-length boundary matters: comparing two digit strings with
        // PHP's numeric `>` can coerce them to a floating-point value once
        // the candidate is beyond PHP_INT_MAX. The domain validator must make
        // this decision lexically after the length check instead.
        $this->expectException(CatalogRuleException::class);
        $this->manager()->create($business, [
            'type' => 'product',
            'name' => 'X',
            'price_minor' => '9223372036854775808',
            'currency_code' => 'USD',
        ]);
    }

    public function test_create_accepts_a_valid_zero_price(): void
    {
        $business = $this->business();

        $item = $this->manager()->create($business, ['type' => 'product', 'name' => 'Free Sample', 'price_minor' => 0, 'currency_code' => 'USD']);

        $this->assertSame(0, $item->price_minor);
        $this->assertSame('USD', $item->currency_code);
    }

    public function test_create_accepts_a_price_given_as_a_digit_only_string(): void
    {
        $business = $this->business();

        $item = $this->manager()->create($business, ['type' => 'product', 'name' => 'X', 'price_minor' => '1500', 'currency_code' => 'USD']);

        $this->assertSame(1500, $item->price_minor);
    }

    public function test_create_refuses_a_currency_code_that_is_not_three_characters(): void
    {
        $business = $this->business();

        $this->expectException(CatalogRuleException::class);
        $this->manager()->create($business, ['type' => 'product', 'name' => 'X', 'price_minor' => 500, 'currency_code' => 'US']);
    }

    public function test_create_assigns_sequential_positions_per_business(): void
    {
        $business = $this->business();

        $first = $this->manager()->create($business, ['type' => 'product', 'name' => 'First']);
        $second = $this->manager()->create($business, ['type' => 'product', 'name' => 'Second']);

        $this->assertSame(0, $first->position);
        $this->assertSame(1, $second->position);
    }

    public function test_create_never_lets_lifecycle_state_or_archived_at_through_the_attributes_array(): void
    {
        $business = $this->business();

        $item = $this->manager()->create($business, [
            'type' => 'product',
            'name' => 'X',
            'lifecycle_state' => 'archived',
            'archived_at' => now()->toDateTimeString(),
        ]);

        $this->assertSame(CatalogItemLifecycleState::Active, $item->fresh()->lifecycle_state);
        $this->assertNull($item->fresh()->archived_at);
    }

    // -----------------------------------------------------------------
    // Mass-assignment guard — the model itself, independent of the manager
    // -----------------------------------------------------------------

    public function test_archived_state_cannot_be_mass_assigned_around_the_manager(): void
    {
        $business = $this->business();

        // A direct Eloquent create/fill, bypassing CatalogItemManager
        // entirely, still cannot set lifecycle_state/archived_at — Sub-slice
        // A excluded both from $fillable, mirroring BusinessLocation.
        $item = CatalogItem::create([
            'business_id' => $business->id,
            'type' => 'product',
            'name' => 'Direct Create',
            'lifecycle_state' => 'archived',
            'archived_at' => now(),
        ]);

        $this->assertSame(CatalogItemLifecycleState::Active, $item->fresh()->lifecycle_state);
        $this->assertNull($item->fresh()->archived_at);

        $item->fill(['lifecycle_state' => 'archived', 'archived_at' => now()])->save();

        $this->assertSame(CatalogItemLifecycleState::Active, $item->fresh()->lifecycle_state);
        $this->assertNull($item->fresh()->archived_at);
    }

    // -----------------------------------------------------------------
    // update() — merged-state validation, never trusting the caller's copy
    // -----------------------------------------------------------------

    public function test_update_changes_only_the_supplied_fields(): void
    {
        $business = $this->business();
        $item = $this->manager()->create($business, ['type' => 'product', 'name' => 'Original', 'price_minor' => 1000, 'currency_code' => 'USD']);

        $updated = $this->manager()->update($business, $item, ['name' => 'Renamed']);

        $this->assertSame('Renamed', $updated->name);
        $this->assertSame(1000, $updated->price_minor);
        $this->assertSame('USD', $updated->currency_code);
    }

    public function test_update_refuses_clearing_price_alone_leaving_currency_stale(): void
    {
        $business = $this->business();
        $item = $this->manager()->create($business, ['type' => 'product', 'name' => 'X', 'price_minor' => 1000, 'currency_code' => 'USD']);

        $this->expectException(CatalogRuleException::class);
        $this->manager()->update($business, $item, ['price_minor' => null]);
    }

    public function test_update_can_clear_both_price_and_currency_together(): void
    {
        $business = $this->business();
        $item = $this->manager()->create($business, ['type' => 'product', 'name' => 'X', 'price_minor' => 1000, 'currency_code' => 'USD']);

        $updated = $this->manager()->update($business, $item, ['price_minor' => null, 'currency_code' => null]);

        $this->assertNull($updated->price_minor);
        $this->assertNull($updated->currency_code);
    }

    public function test_update_re_derives_the_item_fresh_and_ignores_a_tampered_in_memory_copy(): void
    {
        $business = $this->business();
        $item = $this->manager()->create($business, ['type' => 'product', 'name' => 'Original', 'price_minor' => 1000, 'currency_code' => 'USD']);

        // Simulate a caller handing over a stale/tampered in-memory model —
        // the manager must re-load by id and never trust these fields.
        $tampered = clone $item;
        $tampered->business_id = 999999;
        $tampered->price_minor = 1;

        $updated = $this->manager()->update($business, $tampered, ['name' => 'Retitled']);

        $this->assertSame((int) $business->id, (int) $updated->business_id);
        $this->assertSame(1000, $updated->price_minor);
        $this->assertSame('Retitled', $updated->name);
    }

    public function test_update_refuses_a_catalog_item_belonging_to_a_different_business(): void
    {
        $businessA = $this->business();
        $businessB = $this->business();
        $itemB = $this->manager()->create($businessB, ['type' => 'product', 'name' => 'Belongs to B']);

        $this->expectException(CatalogRuleException::class);
        $this->manager()->update($businessA, $itemB, ['name' => 'Hijacked']);
    }

    public function test_archive_refuses_a_catalog_item_belonging_to_a_different_business(): void
    {
        $businessA = $this->business();
        $businessB = $this->business();
        $itemB = $this->manager()->create($businessB, ['type' => 'product', 'name' => 'Belongs to B']);

        $this->expectException(CatalogRuleException::class);
        $this->manager()->archive($businessA, $itemB);
    }

    // -----------------------------------------------------------------
    // archive() / reactivate() — round trip and idempotency
    // -----------------------------------------------------------------

    public function test_archive_then_reactivate_round_trips(): void
    {
        $business = $this->business();
        $item = $this->manager()->create($business, ['type' => 'product', 'name' => 'X']);

        $archived = $this->manager()->archive($business, $item);
        $this->assertTrue($archived->isArchived());
        $this->assertNotNull($archived->archived_at);

        $reactivated = $this->manager()->reactivate($business, $archived);
        $this->assertTrue($reactivated->isActive());
        $this->assertNull($reactivated->archived_at);
    }

    public function test_archive_is_idempotent(): void
    {
        $business = $this->business();
        $item = $this->manager()->create($business, ['type' => 'product', 'name' => 'X']);

        $this->manager()->archive($business, $item);
        $again = $this->manager()->archive($business, $item);

        $this->assertTrue($again->isArchived());
    }

    public function test_reactivate_is_idempotent(): void
    {
        $business = $this->business();
        $item = $this->manager()->create($business, ['type' => 'product', 'name' => 'X']);

        $again = $this->manager()->reactivate($business, $item);

        $this->assertTrue($again->isActive());
    }

    // -----------------------------------------------------------------
    // reorder() — exact set / duplicate / foreign Business / missing id
    // -----------------------------------------------------------------

    public function test_reorder_applies_the_exact_submitted_order(): void
    {
        $business = $this->business();
        $a = $this->manager()->create($business, ['type' => 'product', 'name' => 'A']);
        $b = $this->manager()->create($business, ['type' => 'product', 'name' => 'B']);
        $c = $this->manager()->create($business, ['type' => 'product', 'name' => 'C']);

        $this->manager()->reorder($business, [$c->uid, $a->uid, $b->uid]);

        $this->assertSame(0, $c->fresh()->position);
        $this->assertSame(1, $a->fresh()->position);
        $this->assertSame(2, $b->fresh()->position);
    }

    public function test_reorder_refuses_a_duplicate_id(): void
    {
        $business = $this->business();
        $a = $this->manager()->create($business, ['type' => 'product', 'name' => 'A']);
        $this->manager()->create($business, ['type' => 'product', 'name' => 'B']);

        $this->expectException(CatalogRuleException::class);
        $this->manager()->reorder($business, [$a->uid, $a->uid]);
    }

    public function test_reorder_refuses_a_missing_id(): void
    {
        $business = $this->business();
        $this->manager()->create($business, ['type' => 'product', 'name' => 'A']);
        $this->manager()->create($business, ['type' => 'product', 'name' => 'B']);

        $this->expectException(CatalogRuleException::class);
        $this->manager()->reorder($business, [(string) \Illuminate\Support\Str::uuid()]);
    }

    public function test_reorder_refuses_an_id_from_a_foreign_business(): void
    {
        $businessA = $this->business();
        $businessB = $this->business();
        $a = $this->manager()->create($businessA, ['type' => 'product', 'name' => 'A']);
        $foreign = $this->manager()->create($businessB, ['type' => 'product', 'name' => 'Foreign']);

        $this->expectException(CatalogRuleException::class);
        $this->manager()->reorder($businessA, [$a->uid, $foreign->uid]);
    }

    public function test_reorder_refuses_an_archived_item_in_the_submitted_list(): void
    {
        $business = $this->business();
        $a = $this->manager()->create($business, ['type' => 'product', 'name' => 'A']);
        $b = $this->manager()->create($business, ['type' => 'product', 'name' => 'B']);
        $this->manager()->archive($business, $b);

        $this->expectException(CatalogRuleException::class);
        $this->manager()->reorder($business, [$a->uid, $b->uid]);
    }

    public function test_reorder_does_not_require_naming_an_archived_item(): void
    {
        $business = $this->business();
        $a = $this->manager()->create($business, ['type' => 'product', 'name' => 'A']);
        $b = $this->manager()->create($business, ['type' => 'product', 'name' => 'B']);
        $this->manager()->archive($business, $b);

        $this->manager()->reorder($business, [$a->uid]);

        $this->assertSame(0, $a->fresh()->position);
    }

    // -----------------------------------------------------------------
    // §7 concurrency — row-lock behavior, practical proof
    // -----------------------------------------------------------------

    public function test_update_takes_an_exclusive_row_lock_before_writing(): void
    {
        $business = $this->business();
        $item = $this->manager()->create($business, ['type' => 'product', 'name' => 'X']);

        $sawLockingSelect = false;

        DB::listen(function ($query) use (&$sawLockingSelect) {
            if (str_contains(strtolower($query->sql), 'select') && str_contains(strtolower($query->sql), 'for update')) {
                $sawLockingSelect = true;
            }
        });

        $this->manager()->update($business, $item, ['name' => 'Y']);

        $this->assertTrue($sawLockingSelect, 'update() must take a SELECT ... FOR UPDATE lock per Contract 16 §7.');
    }

    public function test_reorder_takes_an_exclusive_row_lock_on_the_active_set_before_writing(): void
    {
        $business = $this->business();
        $a = $this->manager()->create($business, ['type' => 'product', 'name' => 'A']);

        $sawLockingSelect = false;

        DB::listen(function ($query) use (&$sawLockingSelect) {
            if (str_contains(strtolower($query->sql), 'select') && str_contains(strtolower($query->sql), 'for update')) {
                $sawLockingSelect = true;
            }
        });

        $this->manager()->reorder($business, [$a->uid]);

        $this->assertTrue($sawLockingSelect, 'reorder() must lock the active set per Contract 16 §7.');
    }

    public function test_archive_and_reactivate_take_an_exclusive_row_lock_before_writing(): void
    {
        $business = $this->business();
        $item = $this->manager()->create($business, ['type' => 'product', 'name' => 'X']);

        $lockingSelects = 0;

        DB::listen(function ($query) use (&$lockingSelects) {
            if (str_contains(strtolower($query->sql), 'select') && str_contains(strtolower($query->sql), 'for update')) {
                $lockingSelects++;
            }
        });

        $this->manager()->archive($business, $item);
        $this->manager()->reactivate($business, $item);

        $this->assertSame(2, $lockingSelects, 'Both archive() and reactivate() must each take their own row lock.');
    }
}

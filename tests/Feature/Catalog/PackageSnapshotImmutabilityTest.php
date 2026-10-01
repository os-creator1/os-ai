<?php

namespace Tests\Feature\Catalog;

use App\Library\Catalog\CatalogItemLocationOverrideManager;
use App\Library\Catalog\CatalogItemManager;
use App\Library\Catalog\Exceptions\CatalogRuleException;
use App\Library\Catalog\PackageSnapshotService;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\CatalogItem;
use App\Models\PackageSnapshot;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Implementation Contract 16 §5.3, §13, §14 — the immutable-snapshot seam as a
 * Proposal/Invoice/Booking module consumes it: take a snapshot, change
 * EVERYTHING about the catalog afterwards, and the snapshot is byte-for-byte
 * what it was. PackageSnapshotServiceTest already proves the price and the
 * Location-override cases; this file proves the rest of "never silently
 * changes" (name, description, disable, archive, hard delete), the model's
 * write-once guard, and the tenant-scoped API (`snapshotForBusiness`,
 * `findForBusiness`).
 */
class PackageSnapshotImmutabilityTest extends TestCase
{
    use CreatesBusinessTestData;
    use RefreshDatabase;

    private function business(string $currency = 'USD'): Business
    {
        return $this->createBusinessWithWorkspace($this->createCustomer(), array_merge($this->businessAttributes(), [
            'currency_code' => $currency,
        ]));
    }

    private function location(Business $business, string $name = 'Main Street'): BusinessLocation
    {
        return BusinessLocation::create([
            'business_id' => $business->id,
            'name' => $name,
            'service_mode' => 'storefront',
            'country_code' => 'US',
        ]);
    }

    private function item(Business $business, string $name = 'Wedding Package'): CatalogItem
    {
        return app(CatalogItemManager::class)->create($business, [
            'type' => 'package',
            'name' => $name,
            'description' => 'Four hours, props and an album.',
            'price_minor' => 250000,
            'currency_code' => 'USD',
        ]);
    }

    /** @return array<string, mixed> every captured commercial column */
    private function captured(PackageSnapshot $snapshot): array
    {
        $row = DB::table('package_snapshots')->where('id', $snapshot->id)->first(
            ['uid', 'business_id', 'catalog_item_id', 'business_location_id', 'name_at_snapshot',
                'description_at_snapshot', 'price_minor_at_snapshot', 'currency_code_at_snapshot', 'schema_version']
        );

        $this->assertNotNull($row, 'The snapshot row must still exist.');

        return (array) $row;
    }

    public function test_a_snapshot_survives_a_later_name_description_and_price_edit_and_a_location_price_override(): void
    {
        $business = $this->business();
        $location = $this->location($business);
        $item = $this->item($business);

        $snapshot = app(PackageSnapshotService::class)->snapshotForBusiness($business, $item, $location);
        $before = $this->captured($snapshot);

        app(CatalogItemManager::class)->update($business, $item, [
            'name' => 'Renamed Package',
            'description' => 'A completely different description.',
            'price_minor' => 999,
            'currency_code' => 'USD',
        ]);
        app(CatalogItemLocationOverrideManager::class)->setPriceOverride($business, $item, $location, 123456);

        $this->assertSame($before, $this->captured($snapshot));
        $this->assertSame('Wedding Package', $before['name_at_snapshot']);
        $this->assertSame('Four hours, props and an album.', $before['description_at_snapshot']);
        $this->assertSame(250000, (int) $before['price_minor_at_snapshot']);
    }

    public function test_a_snapshot_survives_the_item_being_disabled_at_the_location_and_then_archived(): void
    {
        $business = $this->business();
        $location = $this->location($business);
        $item = $this->item($business);

        $snapshot = app(PackageSnapshotService::class)->snapshotForBusiness($business, $item, $location);
        $before = $this->captured($snapshot);

        app(CatalogItemLocationOverrideManager::class)->setEnabled($business, $item, $location, false);
        app(CatalogItemManager::class)->archive($business, $item);

        $this->assertSame($before, $this->captured($snapshot));

        // The seam still answers for it by uid, with the original commercial data.
        $found = app(PackageSnapshotService::class)->findForBusiness($business, $snapshot->uid);
        $this->assertNotNull($found);
        $this->assertSame('Wedding Package', $found->name_at_snapshot);
        $this->assertSame(250000, $found->price_minor_at_snapshot);
        $this->assertSame('USD', $found->currency_code_at_snapshot);

        // And the archived item can no longer be snapshotted at all.
        $this->expectException(CatalogRuleException::class);
        app(PackageSnapshotService::class)->snapshotForBusiness($business, $item, $location);
    }

    public function test_a_catalog_item_with_a_snapshot_cannot_be_hard_deleted(): void
    {
        $business = $this->business();
        $location = $this->location($business);
        $item = $this->item($business);

        app(PackageSnapshotService::class)->snapshotForBusiness($business, $item, $location);

        // `restrictOnDelete` is the real invariant (Contract §5.3): the item a
        // past document was priced from can only ever be archived.
        try {
            DB::table('catalog_items')->where('id', $item->id)->delete();
            $this->fail('A catalog item referenced by a snapshot must not be deletable.');
        } catch (QueryException $e) {
            $this->assertSame(1, DB::table('package_snapshots')->where('catalog_item_id', $item->id)->count());
            $this->assertSame(1, DB::table('catalog_items')->where('id', $item->id)->count());
        }
    }

    public function test_the_model_refuses_to_update_or_delete_an_existing_snapshot(): void
    {
        $business = $this->business();
        $location = $this->location($business);
        $snapshot = app(PackageSnapshotService::class)->snapshotForBusiness($business, $this->item($business), $location);
        $before = $this->captured($snapshot);

        $loaded = PackageSnapshot::findOrFail($snapshot->id);
        $loaded->price_minor_at_snapshot = 1;

        try {
            $loaded->save();
            $this->fail('Saving a changed snapshot must throw.');
        } catch (LogicException) {
            // expected
        }

        try {
            PackageSnapshot::findOrFail($snapshot->id)->delete();
            $this->fail('Deleting a snapshot must throw.');
        } catch (LogicException) {
            // expected
        }

        $this->assertSame($before, $this->captured($snapshot));
    }

    public function test_every_snapshot_is_a_new_row_with_its_own_stable_uid(): void
    {
        $business = $this->business();
        $location = $this->location($business);
        $item = $this->item($business);
        $service = app(PackageSnapshotService::class);

        $first = $service->snapshotForBusiness($business, $item, $location);
        $second = $service->snapshotForBusiness($business, $item, $location);

        $this->assertNotSame($first->uid, $second->uid);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($first->uid, $service->findForBusiness($business, $first->uid)?->uid);
    }

    public function test_a_snapshot_cannot_reference_a_foreign_business_item(): void
    {
        $mine = $this->business();
        $myLocation = $this->location($mine);
        $theirs = $this->business();
        $theirLocation = $this->location($theirs);
        $theirItem = $this->item($theirs, 'Their Package');

        foreach ([[$theirItem, $myLocation], [$theirItem, $theirLocation]] as [$item, $location]) {
            try {
                app(PackageSnapshotService::class)->snapshotForBusiness($mine, $item, $location);
                $this->fail('A foreign Business item must be refused.');
            } catch (CatalogRuleException) {
                // expected
            }
        }

        $this->assertSame(0, PackageSnapshot::query()->count(), 'A refusal writes nothing.');
    }

    public function test_a_snapshot_cannot_pair_my_item_with_a_foreign_location(): void
    {
        $mine = $this->business();
        $myItem = $this->item($mine);
        $theirLocation = $this->location($this->business());

        $this->expectException(CatalogRuleException::class);

        try {
            app(PackageSnapshotService::class)->snapshotForBusiness($mine, $myItem, $theirLocation);
        } finally {
            $this->assertSame(0, PackageSnapshot::query()->count());
        }
    }

    public function test_a_caller_supplied_model_is_never_trusted_for_the_business_check(): void
    {
        $mine = $this->business();
        $myLocation = $this->location($mine);
        $theirs = $this->business();
        $theirItem = $this->item($theirs, 'Their Package');

        // A caller that rewrites its in-memory copy to look like mine.
        $forged = $theirItem->replicate();
        $forged->id = $theirItem->id;
        $forged->business_id = $mine->id;

        $this->expectException(CatalogRuleException::class);

        app(PackageSnapshotService::class)->snapshotForBusiness($mine, $forged, $myLocation);
    }

    public function test_find_for_business_is_tenant_scoped(): void
    {
        $mine = $this->business();
        $theirs = $this->business();
        $theirLocation = $this->location($theirs);
        $theirSnapshot = app(PackageSnapshotService::class)->snapshotForBusiness($theirs, $this->item($theirs), $theirLocation);
        $service = app(PackageSnapshotService::class);

        $this->assertNull($service->findForBusiness($mine, $theirSnapshot->uid), 'A foreign snapshot is not found.');
        $this->assertNull($service->findForBusiness($mine, 'does-not-exist'));
        $this->assertNotNull($service->findForBusiness($theirs, $theirSnapshot->uid));
    }
}

<?php

namespace Tests\Feature\Business\Concerns;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Business\BusinessLocationManager;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Customer;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;

/**
 * Customer Experience Slice 1A fixtures: a tenant on a given tier whose
 * Business has N active locations, created through the REAL canonical
 * boundary while that is within capacity, plus a raw seeding helper for the
 * over-capacity states a runtime path can never produce (the ones only a
 * migration backfill or a downgrade creates).
 */
trait CreatesLocationCapacityFixtures
{
    use CreatesCustomerContextFixtures;

    /**
     * @return array{0: Customer, 1: Business, 2: Workspace}
     */
    protected function locationTenant(WorkspacePlanTier $tier = WorkspacePlanTier::Core, int $activeLocations = 1): array
    {
        [$customer, $business, $workspace] = $this->tenant($tier);

        for ($i = 1; $i <= $activeLocations; $i++) {
            $this->locations()->createLocation($business, $this->locationAttributes("Branch {$i}"), (int) $customer->user_id);
        }

        return [$customer, $business->fresh(), $workspace];
    }

    protected function locations(): BusinessLocationManager
    {
        return app(BusinessLocationManager::class);
    }

    protected function entitlements(): EntitlementManager
    {
        return app(EntitlementManager::class);
    }

    protected function locationAttributes(string $name = 'Main Street'): array
    {
        return [
            'name' => $name,
            'service_mode' => 'storefront',
            'address_line_1' => '1 ' . $name,
            'city' => 'Springfield',
            'region' => 'IL',
            'postal_code' => '62701',
            'country_code' => 'US',
            'public_address' => true,
        ];
    }

    /**
     * Raw rows for states no runtime path can create (e.g. seven active
     * locations on a Core Business). Never used to prove a behaviour the
     * boundary itself is responsible for.
     */
    protected function seedRawActiveLocations(Business $business, int $count, string $prefix = 'Seeded'): void
    {
        $hasPrimary = DB::table('business_locations')->where('business_id', $business->id)->where('is_primary', true)->exists();

        for ($i = 1; $i <= $count; $i++) {
            DB::table('business_locations')->insert([
                'uid' => (string) Str::uuid(),
                'business_id' => $business->id,
                'name' => "{$prefix} {$i}",
                'service_mode' => 'storefront',
                'address_line_1' => "{$i} Seeded Way",
                'city' => 'Springfield',
                'region' => 'IL',
                'country_code' => 'US',
                'public_address' => false,
                'is_primary' => ! $hasPrimary && $i === 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    protected function grantComplimentaryLocations(Business $business, int $count): Business
    {
        return $this->entitlements()->setAdditionalLocationSlots($business, $count, $this->platformAdminId(), 'Slice 1A fixture grant.');
    }

    protected function activeCount(Business $business): int
    {
        return BusinessLocation::where('business_id', $business->id)->where('lifecycle_state', 'active')->count();
    }

    protected function locationNamed(Business $business, string $name): BusinessLocation
    {
        return BusinessLocation::where('business_id', $business->id)->where('name', $name)->firstOrFail();
    }
}

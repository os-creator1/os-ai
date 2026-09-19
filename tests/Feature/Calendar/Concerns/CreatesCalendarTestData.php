<?php

namespace Tests\Feature\Calendar\Concerns;

use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;

/**
 * Fixtures for Implementation Contract 15 Sub-slice A.
 *
 * Deliberately DB-level inserts for the Calendar tables themselves: this
 * sub-slice ships models with casts/relations only and no manager, so a
 * schema test must exercise the DDL rather than an application write path
 * that does not exist yet. The Business/Workspace/Location fixtures reuse
 * the repository's existing choke point (CreatesBusinessTestData).
 */
trait CreatesCalendarTestData
{
    use CreatesBusinessTestData;

    protected function calendarBusiness(): Business
    {
        return $this->createBusinessWithWorkspace($this->createCustomer(), $this->businessAttributes());
    }

    protected function calendarLocation(Business $business, array $overrides = []): BusinessLocation
    {
        return BusinessLocation::create(array_merge([
            'business_id' => $business->id,
            'service_mode' => 'storefront',
            'country_code' => 'US',
        ], $overrides));
    }

    /**
     * A plain staff User, unrelated to any Business owner, so a deletion
     * test proves the Calendar foreign key's own behaviour rather than a
     * cascade arriving through `businesses`.
     */
    protected function staffUser(): User
    {
        return User::create([
            'first_name' => 'Staff',
            'last_name' => 'Member',
            'email' => 'staff' . uniqid() . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
        ]);
    }

    protected function insertBookingType(BusinessLocation $location, array $overrides = []): int
    {
        return DB::table('booking_types')->insertGetId(array_merge([
            'uid' => (string) Str::uuid(),
            'public_booking_uuid' => (string) Str::uuid(),
            'business_location_id' => $location->id,
            'name' => 'Consultation',
            'duration_minutes' => 30,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    protected function insertContact(Business $business, BusinessLocation $location): int
    {
        // uid is not fillable — HasUid's creating hook writes it.
        $group = ContactGroups::create([
            'customer_id' => $business->customer_id,
            'business_id' => $business->id,
            'name' => 'Test Group ' . uniqid(),
            'status' => true,
        ]);

        return DB::table('contacts')->insertGetId([
            'uid' => (string) Str::uuid(),
            'customer_id' => $business->customer_id,
            'business_id' => $business->id,
            'location_id' => $location->id,
            'group_id' => $group->id,
            'phone' => '1415555' . random_int(1000, 9999),
            'status' => Contacts::STATUS_SUBSCRIBE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function insertAppointment(
        BusinessLocation $location,
        int $bookingTypeId,
        int $staffUserId,
        int $contactId,
        array $overrides = []
    ): int {
        return DB::table('appointments')->insertGetId(array_merge([
            'uid' => (string) Str::uuid(),
            'business_location_id' => $location->id,
            'booking_type_id' => $bookingTypeId,
            'staff_user_id' => $staffUserId,
            'contact_id' => $contactId,
            'status' => 'scheduled',
            'start_at' => now()->addDay(),
            'end_at' => now()->addDay()->addMinutes(30),
            'reschedule_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    protected function insertConnection(int $userId, array $overrides = []): int
    {
        return DB::table('external_calendar_connections')->insertGetId(array_merge([
            'uid' => (string) Str::uuid(),
            'user_id' => $userId,
            'provider' => 'google',
            'state' => 'pending',
            'sync_failure_count' => 0,
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}

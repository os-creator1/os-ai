<?php

namespace Tests\Feature\Business;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Business\OnboardingFirstMove;
use App\Library\Calendar\BookingTypeManager;
use App\Models\Business;
use App\Repositories\Contracts\BusinessLocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/**
 * Adaptive onboarding names the biggest unmet need in a fixed order (website → booking → contacts → Home) and counts
 * online booking as "in place" only when the Calendar's own readiness check says a customer can really book.
 */
class OnboardingFirstMoveTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;

    private function business(): Business
    {
        [, $business] = $this->bareTenant(WorkspacePlanTier::Growth, 'Orange Glam Booths', 'Orange Glam Account');
        app(BusinessLocationRepository::class)->upsertPrimary($business, [
            'name' => 'Main', 'service_mode' => 'service_area', 'city' => 'Orange', 'region' => 'CA', 'country_code' => 'US',
        ]);

        return $business;
    }

    private function bookingType(Business $business, bool $active = true): void
    {
        DB::table('booking_types')->insert([
            'uid' => (string) Str::uuid(), 'public_booking_uuid' => (string) Str::uuid(),
            'business_location_id' => (int) DB::table('business_locations')->where('business_id', $business->id)->value('id'),
            'name' => 'Consult', 'duration_minutes' => 30, 'is_active' => $active, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function contact(Business $business): void
    {
        $this->contactsAt($business, 1, '2026-01-01 00:00:00');
    }

    /** The Calendar's canonical readiness answer, stubbed: this test is about how onboarding USES it. */
    private function readiness(bool $ready): void
    {
        $this->mock(BookingTypeManager::class, fn ($m) => $m->shouldReceive('publicBookingReadiness')->andReturn(['ready' => $ready, 'reason' => $ready ? null : 'No one is assigned.']));
    }

    private function move(Business $business, string $choice = 'existing'): array
    {
        return app(OnboardingFirstMove::class)->for($business, ['website' => ['choice' => $choice]]);
    }

    public function test_no_booking_type_names_booking_as_the_need(): void
    {
        $business = $this->business();
        $this->readiness(true);

        $result = $this->move($business);

        $this->assertSame('booking', $result['need']['key']);
        $this->assertStringContainsString('no booking type', $result['need']['reason']);
        $this->assertNotContains('Online booking', $result['inPlace']);
    }

    public function test_active_but_unbookable_type_is_not_in_place_and_says_setup_is_incomplete(): void
    {
        $business = $this->business();
        $this->bookingType($business);
        $this->readiness(false);

        $result = $this->move($business);

        $this->assertNotContains('Online booking', $result['inPlace']);
        $this->assertSame('booking', $result['need']['key']);
        $this->assertSame('Finish your booking setup', $result['need']['title']);
        $this->assertStringContainsString('setup is incomplete', $result['need']['reason']);
    }

    public function test_fully_bookable_type_is_in_place(): void
    {
        $business = $this->business();
        $this->bookingType($business);
        $this->readiness(true);

        $result = $this->move($business);

        $this->assertContains('Online booking', $result['inPlace']);
        $this->assertNotSame('booking', $result['need']['key']);
    }

    public function test_website_and_contacts_present_but_booking_unready_still_needs_booking(): void
    {
        $business = $this->business();
        $this->bookingType($business);
        $this->contact($business);
        $this->readiness(false);

        $result = $this->move($business);

        $this->assertSame('booking', $result['need']['key']);
        $this->assertContains('An existing website you host yourself', $result['inPlace']);
        $this->assertContains('Customers already in MotionGrove', $result['inPlace']);
        $this->assertNotContains('Online booking', $result['inPlace']);
    }

    public function test_website_and_ready_booking_without_contacts_needs_contacts(): void
    {
        $business = $this->business();
        $this->bookingType($business);
        $this->readiness(true);

        $result = $this->move($business);

        $this->assertSame('contacts', $result['need']['key']);
        $this->assertContains('Online booking', $result['inPlace']);
    }
}

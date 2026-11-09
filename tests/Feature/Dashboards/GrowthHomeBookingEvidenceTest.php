<?php

namespace Tests\Feature\Dashboards;

use App\Enums\Entitlement\WorkspacePlanTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/**
 * Booking evidence on Growth Home. Appointments do carry a contact id, but "new contacts" (imports, manual entries, forms) is not an
 * enquiry population and Locations are scoped differently for the two, so NO contact-to-booking rate is derived; the move instead states
 * the booking-readiness facts Home already read, and says plainly what cannot be measured.
 */
class GrowthHomeBookingEvidenceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
        config(['opportunity.enabled' => true]);
    }

    private function bookingMove(\App\Models\Business $business): void
    {
        $this->recommendation($business, ['worker_key' => 'sales', 'type' => 'booking.type_not_ready:v1', 'title' => 'A booking type is not ready', 'priority_score' => 90]);
    }

    private function evidence(): string
    {
        $html = $this->home()->assertOk()->getContent();
        $this->assertSame(1, preg_match('#<details[^>]*data-role="growth-evidence".*?</details>#s', $html, $m));

        return html_entity_decode(strip_tags($m[0]), ENT_QUOTES);
    }

    public function test_a_ready_booking_type_is_reported_as_ready(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Ready Book Venue', 'Ready Book Account');
        $this->bookingMove($business);
        $this->authenticateAs($customer);

        $text = $this->evidence();

        $this->assertMatchesRegularExpression('/\d+ of \d+ active booking types? (is|are) ready to take bookings\./', $text);
        $this->assertStringContainsString('which of your new contacts went on to book', $text);
        $this->assertStringContainsString('no conversion rate is claimed', $text);
    }

    public function test_a_booking_type_that_cannot_take_bookings_is_named_with_its_real_reason(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Broken Book Venue', 'Broken Book Account');
        $this->bookingMove($business);
        DB::table('booking_type_staff')->delete();
        $this->authenticateAs($customer);

        $text = $this->evidence();

        $this->assertStringContainsString('0 of 1 active booking type is ready to take bookings.', $text);
        $this->assertStringContainsString('Next: finish setting up "', $text);
    }

    public function test_no_conversion_percentage_attribution_or_revenue_is_ever_claimed(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'No Rate Venue', 'No Rate Account');
        $this->bookingMove($business);
        $this->contactsAdded($business, 5, '2026-09-02');
        $this->authenticateAs($customer);

        $this->assertDoesNotMatchRegularExpression('/\d+\s*%|converted|conversion rate of|attributed|revenue|lost bookings|will (increase|bring)/i', $this->evidence());
    }

    public function test_core_home_has_no_growth_booking_evidence(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Core, 'Core Book Venue', 'Core Book Account');
        $this->bookingMove($business);
        $this->authenticateAs($customer);

        $this->assertStringNotContainsString('ready to take bookings.', $this->home()->assertOk()->getContent());
    }

    public function test_another_business_booking_types_never_appear(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Mine Book Venue', 'Mine Book Account');
        [, $other] = $this->tenant(WorkspacePlanTier::Growth, 'Theirs Book Venue', 'Theirs Book Account');
        $this->bookingMove($business);
        $otherLocations = DB::table('business_locations')->where('business_id', $other->id)->pluck('id');
        DB::table('booking_type_staff')->whereIn('booking_type_id', DB::table('booking_types')->whereIn('business_location_id', $otherLocations)->pluck('id'))->delete();
        $this->authenticateAs($customer);

        $this->assertStringNotContainsString('0 of', $this->evidence(), 'Only this Business\'s own types are counted.');
    }

    public function test_reading_home_creates_no_appointment_and_queues_nothing(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'No Side Venue', 'No Side Account');
        $this->bookingMove($business);
        $this->authenticateAs($customer);
        Queue::fake();
        $before = DB::table('appointments')->count();

        $this->home()->assertOk();

        Queue::assertNothingPushed();
        $this->assertSame($before, DB::table('appointments')->count());
    }
}

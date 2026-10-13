<?php

namespace Tests\Unit\Dashboard\Home;

use App\Library\Dashboard\Home\HomeSetupJourney;
use PHPUnit\Framework\TestCase;

/**
 * Home V2 STATE 1 — progress is counted from facts, never stored or invented.
 */
class HomeSetupJourneyTest extends TestCase
{
    private function facts(array $o = []): array
    {
        return $o + [
            'hasLocation' => true, 'calendar' => true, 'activeTypeCount' => 0, 'readyCount' => 0,
            'problemReason' => null, 'websiteStatus' => null, 'websiteApplicable' => true,
        ];
    }

    private function urls(): array
    {
        return ['locations' => '/l', 'bookingType' => '/bt', 'fixBooking' => '/fix', 'website' => '/w'];
    }

    public function test_a_new_business_has_incomplete_essentials_and_its_first_step_is_next(): void
    {
        $j = (new HomeSetupJourney())->build($this->facts(), $this->urls());

        $this->assertTrue($j['essentialIncomplete']);
        $this->assertSame('booking_type', $j['nextKey']);
        $this->assertSame(1, $j['done']);
        $this->assertSame(4, $j['total']);
    }

    public function test_a_business_that_can_take_bookings_is_out_of_setup_even_with_the_website_unlaunched(): void
    {
        $j = (new HomeSetupJourney())->build($this->facts(['activeTypeCount' => 1, 'readyCount' => 1, 'websiteStatus' => 'draft']), $this->urls());

        $this->assertFalse($j['essentialIncomplete'], 'The website is a growth step, never a reason to stay in setup.');
        $this->assertSame('website', $j['nextKey']);
    }

    public function test_a_booking_type_that_cannot_take_bookings_names_the_real_reason(): void
    {
        $j = (new HomeSetupJourney())->build($this->facts(['activeTypeCount' => 1, 'readyCount' => 0, 'problemReason' => 'No one is assigned.']), $this->urls());
        $step = collect($j['steps'])->firstWhere('key', 'bookable');

        $this->assertFalse($step['done']);
        $this->assertSame('No one is assigned.', $step['note']);
        $this->assertSame('/fix', $step['action']['url']);
    }

    public function test_without_calendar_there_are_no_booking_steps_to_fail(): void
    {
        $j = (new HomeSetupJourney())->build($this->facts(['calendar' => false]), $this->urls());

        $this->assertSame(['location', 'website'], array_column($j['steps'], 'key'));
        $this->assertFalse(collect($j['steps'])->contains(fn ($s) => $s['key'] === 'booking_type'));
    }

    public function test_a_step_the_actor_cannot_reach_carries_no_action(): void
    {
        $urls = $this->urls();
        $urls['bookingType'] = null;
        $j = (new HomeSetupJourney())->build($this->facts(), $urls);

        $this->assertNull(collect($j['steps'])->firstWhere('key', 'booking_type')['action']);
    }
}

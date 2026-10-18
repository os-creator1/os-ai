<?php

namespace Tests\Feature\GoogleAds\Attribution;

use App\Models\Appointment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Calendar\Concerns\CreatesCalendarHttpFixtures;
use Tests\Feature\GoogleAds\Attribution\Concerns\BuildsAttributionCookies;
use Tests\TestCase;

/** Surface 3 of 3: the public booking page. */
class BookingAttributionTest extends TestCase
{
    use BuildsAttributionCookies;
    use CreatesCalendarHttpFixtures;
    use RefreshDatabase;

    private $type;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-25 12:00:00 UTC');
        $this->bootCalendarHttpFixtures();
        $this->type = $this->bookingType();
        $this->type->staff()->attach($this->bookableStaff()->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function url(): string
    {
        return route('public.booking.show', [$this->type->public_booking_uuid]);
    }

    private function book(array $cookies = [], string $time = '10:00', array $headers = [])
    {
        return $this->withCookies($cookies)->withHeaders($headers)->post($this->url(), [
            'date' => '2027-03-01', 'time' => $time, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test', 'phone' => '+1 (415) 555-1234',
        ]);
    }

    public function test_the_public_booking_page_sets_cookies_for_a_tagged_arrival(): void
    {
        $tagged = $this->get($this->url().'?date=2027-03-01&gclid='.self::CLICK)->assertOk();
        $this->assertNotNull($tagged->getCookie('bos_at_first'));

        $this->assertNull($this->get($this->url().'?date=2027-03-01')->getCookie('bos_at_first'));
        $this->assertNull($this->withHeaders(['DNT' => '1'])->get($this->url().'?gclid='.self::CLICK.'&date=2027-03-01')->getCookie('bos_at_first'));
    }

    public function test_a_tagged_booking_records_attribution_for_the_resolved_business_and_location(): void
    {
        $this->book($this->touchCookies(['gclid' => self::CLICK], ['utm_source' => 'google']))->assertRedirect();

        $appointment = Appointment::query()->sole();
        $rows = $this->touchRows();

        $this->assertSame(['first', 'last'], array_map(fn ($r) => $r->touch_role, $rows));
        foreach ($rows as $row) {
            $this->assertSame('booking', $row->entry_surface);
            $this->assertSame('appointment', $row->subject_type);
            $this->assertSame((int) $appointment->id, (int) $row->subject_id);
            $this->assertSame((int) $this->business->id, (int) $row->business_id);
            $this->assertSame((int) $this->locationA->id, (int) $row->business_location_id);
            $this->assertSame((int) $appointment->contact_id, (int) $row->contact_id);
        }
    }

    public function test_a_hostile_cookie_cannot_choose_the_business(): void
    {
        $this->book(['bos_at_first' => $this->touchJson(['gclid' => self::CLICK, 'business_id' => 424242])])->assertRedirect();
        $this->assertSame((int) $this->business->id, (int) $this->touchRows()[0]->business_id);
    }

    public function test_an_untagged_booking_records_nothing(): void
    {
        $this->book()->assertRedirect();

        $this->assertSame(1, Appointment::query()->count());
        $this->assertCount(0, $this->touchRows());
    }

    public function test_a_refused_booking_and_opt_out_record_nothing(): void
    {
        $cookies = $this->touchCookies(['gclid' => self::CLICK]);

        $this->book($cookies, '10:15')->assertSessionHasErrors(); // not a bookable half hour
        $this->book($cookies, '10:00', ['Sec-GPC' => '1'])->assertRedirect();

        $this->assertSame(1, Appointment::query()->count());
        $this->assertCount(0, $this->touchRows());
    }
    public function test_the_reader_shows_a_booked_lead_with_its_booking_surface(): void
    {
        $this->book($this->touchCookies(['utm_source' => 'google']))->assertRedirect();

        $rows = app(\App\Library\GoogleAds\Attribution\LeadAttributionReader::class)
            ->page($this->business, now()->subDay(), now()->addDay())->items();

        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['booked']);
        $this->assertSame('booking', $rows[0]['entry_surface']);
        $this->assertSame(\App\Library\GoogleAds\Attribution\LeadAttributionLevel::CampaignTags, $rows[0]['level']);
        $this->assertSame('appointment', $rows[0]['subject']['type']);
    }
}

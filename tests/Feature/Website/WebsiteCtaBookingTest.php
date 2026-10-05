<?php

namespace Tests\Feature\Website;

use App\Library\Website\Design\WebsiteCtaResolver;
use App\Library\Website\WebsitePublisher;
use App\Library\Website\WebsiteStarterDraftService;
use App\Models\WebsitePage;
use App\Models\WebsiteTemplate;
use Database\Seeders\WebsiteTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Calendar\Concerns\CreatesCalendarHttpFixtures;
use Tests\TestCase;

/**
 * Website V1 final — the BOOKING branch of CTA resolution: a real, bookable
 * public booking page is the main button; the moment it stops being one (type
 * deactivated, nobody eligible to take the booking) the button falls back
 * instead of pointing at a page that would 404.
 */
class WebsiteCtaBookingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCalendarHttpFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WebsiteTemplateSeeder::class);
        $this->bootCalendarHttpFixtures();
        DB::table('businesses')->where('id', $this->business->id)->update(['phone' => '3125550188', 'name' => 'Luma Photo Booth Co']);
        $this->business = $this->business->fresh();
    }

    private function publishedSite(): \App\Models\Website
    {
        $website = app(WebsiteStarterDraftService::class)->createShellFromTemplate($this->business, WebsiteTemplate::findActiveOrFail('photo_booth_modern'));
        WebsitePage::create(['website_id' => $website->id, 'title' => 'Home', 'slug' => null, 'is_home' => true, 'sections' => [['type' => 'hero', 'data' => ['heading' => 'Hello', 'subheading' => null, 'background_image' => null, 'primary_cta' => null, 'secondary_cta' => null]]], 'sort_order' => 0, 'noindex' => false]);
        app(WebsitePublisher::class)->publish($website, $this->owner->user_id);

        return $website->fresh();
    }

    private function makeBookable(): \App\Models\BookingType
    {
        $type = $this->bookingType();
        $type->staff()->attach($this->bookableStaff()->id);

        return $type;
    }

    public function test_a_bookable_page_is_the_main_button_everywhere_in_the_header_and_hero(): void
    {
        $type = $this->makeBookable();
        $website = $this->publishedSite();

        $cta = app(WebsiteCtaResolver::class)->resolve($this->business, []);
        $this->assertSame('booking', $cta['kind']);
        $this->assertSame('Book now', $cta['label']);
        $this->assertSame(route('public.booking.show', $type->public_booking_uuid), $cta['url']);

        $html = $this->get(route('public.website.home', $website->public_id))->assertOk()->getContent();
        $this->assertStringContainsString('href="' . route('public.booking.show', $type->public_booking_uuid) . '" data-testid="header-cta">Book now', $html);
    }

    public function test_booking_beats_the_quote_form_which_beats_the_phone(): void
    {
        $this->makeBookable();
        $pages = [['uid' => 'c', 'slug' => 'photo-booth-contact', 'url' => 'https://example.test/contact', 'has_form' => true]];

        $this->assertSame('booking', app(WebsiteCtaResolver::class)->resolve($this->business, $pages)['kind']);
    }

    public function test_a_deactivated_booking_type_falls_back_at_once_even_on_an_already_published_page(): void
    {
        $type = $this->makeBookable();
        $website = $this->publishedSite();
        $url = route('public.website.home', $website->public_id);
        $this->assertStringContainsString('Book now', $this->get($url)->getContent());

        $type->update(['is_active' => false]);

        $html = $this->get($url)->assertOk()->getContent();
        $this->assertStringNotContainsString('Book now', $html);
        $this->assertStringNotContainsString(route('public.booking.show', $type->public_booking_uuid), $html, 'No link to a booking page that would 404.');
        $this->assertStringContainsString('href="tel:3125550188" data-testid="header-cta"', $html, 'The phone number takes over.');
    }

    public function test_a_booking_type_with_nobody_eligible_to_take_the_booking_is_never_offered(): void
    {
        $this->bookingType(); // active, but no staff attached

        $cta = app(WebsiteCtaResolver::class)->resolve($this->business, []);

        $this->assertSame('phone', $cta['kind']);
    }

    public function test_an_inactive_business_never_offers_booking(): void
    {
        $this->makeBookable();
        DB::table('businesses')->where('id', $this->business->id)->update(['status' => 'inactive']);

        $this->assertNotSame('booking', app(WebsiteCtaResolver::class)->resolve($this->business->fresh(), [])['kind'] ?? null);
    }
}

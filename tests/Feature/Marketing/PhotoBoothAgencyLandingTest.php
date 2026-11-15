<?php

namespace Tests\Feature\Marketing;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\AgencySite\AgencySiteBookingService;
use App\Library\AgencySite\AgencySiteManager;
use App\Library\AgencySite\NichePresets;
use App\Models\AgencySite;
use App\Models\BookingType;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AgencyBilling\Concerns\CreatesAgencySaasFixtures;
use Tests\TestCase;

/**
 * /photobooth served from the owner's own published White Label site (Photo Booth Business niche), side by side with
 * Daniel Black's /dogs site. Uses fictional fixtures only; no real account, booking or customer is touched.
 */
class PhotoBoothAgencyLandingTest extends TestCase
{
    use CreatesAgencySaasFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdminId();
        $this->ensureRequiredAppConfigRowsExist();
        $this->bindFakeAgencyStripe();
        config(['marketing.cta.book_demo_url' => 'https://calendar.example/motiongrove-demo']);
    }

    private function hrefs(string $html): array
    {
        preg_match_all('/<a\b[^>]*\bhref="([^"]*)"/i', $html, $m);

        return array_values(array_unique($m[1]));
    }

    private function text(string $html): string
    {
        return strip_tags((string) preg_replace('/<script.*?<\/script>|<style.*?<\/style>/s', '', $html));
    }

    /** @return array{0: Workspace, 1: \App\Models\Customer, 2: BookingType, 3: AgencySite} */
    private function agency(string $name, string $niche, bool $publish = true): array
    {
        [$owner, $business, $agency] = $this->tenant(WorkspacePlanTier::Agency, $name . ' Business', $name);
        app(\App\Library\Business\BusinessLocationManager::class)->upsertPrimaryLocation($business, ['service_mode' => 'storefront', 'country_code' => 'US']);
        app(\App\Library\Branding\AgencyWhiteLabelManager::class)->save((int) $owner->user_id, $agency, ['display_name' => $name, 'accent_color' => '#1a73e8', 'is_enabled' => '1']);
        $this->connectAgencyStripe($agency, (int) $owner->user_id);
        $this->publishedPlan($agency, (int) $owner->user_id, WorkspacePlanTier::Growth, '49.00', null, 'Studio');
        $type = app(AgencySiteBookingService::class)->create((int) $owner->user_id, $agency, ['name' => 'Intro call', 'duration_minutes' => 30, 'default_hours' => true]);

        $sites = app(AgencySiteManager::class);
        $sites->ensure((int) $owner->user_id, $agency);
        $sites->save((int) $owner->user_id, $agency, [
            'x.goal' => 'both', 'x.niche' => $niche, 'x.booking_type_uid' => $type->public_booking_uuid,
            'links.privacy' => 'https://example.test/privacy', 'links.terms' => 'https://example.test/terms',
        ]);

        return [$agency, $owner, $type, $publish ? $sites->publish((int) $owner->user_id, $agency) : AgencySite::query()->where('agency_workspace_id', $agency->id)->firstOrFail()];
    }

    public function test_photobooth_serves_its_own_published_site_and_dogs_still_serves_daniels(): void
    {
        [, , $dogType, $dogSite] = $this->agency('Daniel Black', NichePresets::DOG);
        [, , $boothType, $boothSite] = $this->agency('Motion Grove Photo Booth', NichePresets::PHOTO_BOOTH);
        config(['marketing.landing.dogs_site' => $dogSite->public_id, 'marketing.landing.photobooth_site' => $boothSite->public_id]);

        $booth = $this->get('/photobooth')->assertOk()->getContent();
        $dogs = $this->get('/dogs')->assertOk()->getContent();

        // Branding + photo booth sample data, and nothing from the other account.
        $this->assertStringContainsString('Motion Grove Photo Booth', $booth);
        $this->assertStringContainsString('Bright Frame Photo Booths', $booth);
        $this->assertStringContainsString('photo booth rental near me', $booth);
        $this->assertStringContainsString(route('agency.site.css', $boothSite->public_id), $booth);
        $this->assertDoesNotMatchRegularExpression('/Daniel Black|Maple Ridge|dog|puppy|Biscuit|kennel/i', $this->text($booth));
        $this->assertStringContainsString('Maple Ridge Dog Training', $dogs);
        $this->assertStringNotContainsString('Motion Grove Photo Booth', $dogs);
        $this->assertStringNotContainsString('Bright Frame', $dogs);

        // Call-only: no prices, plans, signup or trial; the original platform sample business is not carried over.
        $this->assertDoesNotMatchRegularExpression('/\$\d+|Get started|Book a demo|See pricing|Compare plans|free trial|Studio/i', $this->text($booth));
        $this->assertStringNotContainsString('Lumen Events', $booth);
        foreach ($this->hrefs($booth) as $href) {
            $this->assertStringNotContainsString('/signup', $href);
            $this->assertStringNotContainsString('/pricing', $href);
        }

        // Every primary CTA is this account's own calendar, never Daniel's or the platform demo calendar.
        $own = route('public.booking.show', $boothType->public_booking_uuid);
        preg_match_all('/<a href="([^"]*)" class="btn[^"]*"[^>]*>\s*Book a call/i', $booth, $buttons);
        $this->assertGreaterThanOrEqual(3, count($buttons[1]));
        $this->assertSame([$own], array_values(array_unique($buttons[1])));
        $this->assertStringNotContainsString($dogType->public_booking_uuid, $booth);
        $this->assertStringNotContainsString('calendar.example/motiongrove-demo', $booth);

        // Scoped links, call-only pricing redirect, noindex while privately accepted.
        $this->assertStringContainsString('href="' . url('photobooth/product') . '"', $booth);
        $this->get('/photobooth/product')->assertOk()->assertDontSee('/signup', false);
        $this->get('/photobooth/pricing')->assertRedirect($own);
        $this->assertStringContainsString('noindex', (string) $this->get('/photobooth')->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('<link rel="canonical" href="' . url('photobooth') . '">', $booth);
    }

    public function test_the_site_is_chosen_by_config_never_by_the_request(): void
    {
        [, , , $dogSite] = $this->agency('Daniel Black', NichePresets::DOG);
        [, , , $boothSite] = $this->agency('Motion Grove Photo Booth', NichePresets::PHOTO_BOOTH);
        config(['marketing.landing.dogs_site' => $dogSite->public_id, 'marketing.landing.photobooth_site' => $boothSite->public_id]);

        $html = $this->get('/photobooth?site=' . $dogSite->public_id . '&agency=1')->assertOk()->getContent();
        $this->assertStringContainsString('Bright Frame Photo Booths', $html);
        $this->assertStringNotContainsString('Maple Ridge', $html);

        config(['marketing.landing.photobooth_site' => 'not-a-uuid']);
        $this->get('/photobooth')->assertNotFound();
    }

    public function test_unpublished_draft_edits_are_invisible_until_published(): void
    {
        [$agency, $owner, , $site] = $this->agency('Motion Grove Photo Booth', NichePresets::PHOTO_BOOTH);
        config(['marketing.landing.photobooth_site' => $site->public_id]);
        $sites = app(AgencySiteManager::class);

        $sites->save((int) $owner->user_id, $agency, ['copy.home.hero.title' => 'DRAFT-ONLY headline']);
        $this->get('/photobooth')->assertOk()->assertDontSee('DRAFT-ONLY headline');

        $sites->publish((int) $owner->user_id, $agency);
        $this->get('/photobooth')->assertOk()->assertSee('DRAFT-ONLY headline');
    }

    public function test_an_unpublished_site_or_dead_booking_destination_fails_safely(): void
    {
        [$agency, $owner, $type, $site] = $this->agency('Motion Grove Photo Booth', NichePresets::PHOTO_BOOTH, false);
        config(['marketing.landing.photobooth_site' => $site->public_id]);
        $this->get('/photobooth')->assertNotFound();

        $site = app(AgencySiteManager::class)->publish((int) $owner->user_id, $agency);
        $this->get('/photobooth')->assertOk();

        BookingType::query()->whereKey($type->id)->delete();
        $this->get('/photobooth')->assertNotFound();
    }

    public function test_without_a_configured_site_photobooth_keeps_the_static_landing_page(): void
    {
        config(['marketing.landing.photobooth_site' => null]);

        $this->get('/photobooth')->assertOk()->assertSee('Lumen Events');
        $this->get('/photobooth/product')->assertNotFound();
    }

    public function test_the_preset_is_selectable_and_covers_every_illustration_field_without_dog_content(): void
    {
        $this->assertArrayHasKey(NichePresets::PHOTO_BOOTH, NichePresets::NICHES);

        $preset = NichePresets::for(NichePresets::PHOTO_BOOTH, 'Motion Grove Photo Booth');
        $this->assertEqualsCanonicalizing(array_keys(config('marketing.demo')), array_keys($preset['demo']));
        foreach ($preset['demo'] as $key => $value) {
            $this->assertSame(is_array(config('marketing.demo.' . $key)) ? count(config('marketing.demo.' . $key)) : null, is_array($value) ? count($value) : null, "demo.{$key} keeps its shape");
        }
        $this->assertDoesNotMatchRegularExpression('/dog|puppy|kennel|Biscuit|Maple Ridge/i', json_encode($preset));
        $this->assertDoesNotMatchRegularExpression('/\$\d|guarantee|#1|rank(s|ed)? first/i', json_encode($preset));
    }
}

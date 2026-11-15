<?php

namespace Tests\Feature\Marketing;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\AgencySite\AgencySiteBookingService;
use App\Library\AgencySite\AgencySiteManager;
use App\Library\AgencySite\NichePresets;
use App\Library\AgencySite\SiteSchema;
use App\Models\AgencySite;
use App\Models\AgencyWhiteLabelSetting;
use App\Models\BookingType;
use App\Models\Customer;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AgencyBilling\Concerns\CreatesAgencySaasFixtures;
use Tests\TestCase;

/**
 * /photobooth served from the owner's own published White Label site in the ORIGINAL MotionGrove edition: MotionGrove
 * name, logo, colours, wording and illustrations, minus pricing/signup, with the workspace's own calendar. Fictional
 * fixtures only; Daniel Black's /dogs site is exercised alongside it to prove isolation.
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

    /** The workspace of the owner's account: NO white-label branding record, exactly like workspace #14. */
    private function workspace(string $name): array
    {
        [$owner, $business, $agency] = $this->tenant(WorkspacePlanTier::Agency, $name . ' Business', $name);
        app(\App\Library\Business\BusinessLocationManager::class)->upsertPrimaryLocation($business, ['service_mode' => 'storefront', 'country_code' => 'US']);
        $type = app(AgencySiteBookingService::class)->create((int) $owner->user_id, $agency, ['name' => 'Intro call', 'duration_minutes' => 30, 'default_hours' => true]);

        return [$agency, $owner, $type];
    }

    /** @return array{0: Workspace, 1: Customer, 2: BookingType, 3: AgencySite} the owner's original-edition site, published */
    private function boothSite(bool $publish = true): array
    {
        [$agency, $owner, $type] = $this->workspace('Motion Grove Photo Booth');
        $this->artisan('agency-site:init-original', ['workspace_uid' => $agency->uid, '--booking-type' => $type->public_booking_uuid, '--apply' => true])->assertSuccessful();
        $site = AgencySite::query()->where('agency_workspace_id', $agency->id)->firstOrFail();

        return [$agency, $owner, $type, $publish ? app(AgencySiteManager::class)->publish((int) $owner->user_id, $agency) : $site];
    }

    private function dogSite(): array
    {
        [$owner, $business, $agency] = $this->tenant(WorkspacePlanTier::Agency, 'Daniel Business', 'Daniel Black');
        app(\App\Library\Business\BusinessLocationManager::class)->upsertPrimaryLocation($business, ['service_mode' => 'storefront', 'country_code' => 'US']);
        app(\App\Library\Branding\AgencyWhiteLabelManager::class)->save((int) $owner->user_id, $agency, ['display_name' => 'Daniel Black', 'accent_color' => '#1a73e8', 'is_enabled' => '1']);
        $type = app(AgencySiteBookingService::class)->create((int) $owner->user_id, $agency, ['name' => 'Intro call', 'duration_minutes' => 30, 'default_hours' => true]);
        $sites = app(AgencySiteManager::class);
        $sites->ensure((int) $owner->user_id, $agency);
        $sites->save((int) $owner->user_id, $agency, ['x.goal' => 'book_call', 'x.niche' => 'dog_business', 'x.booking_type_uid' => $type->public_booking_uuid, 'links.privacy' => 'https://example.test/privacy', 'links.terms' => 'https://example.test/terms']);

        return [$agency, $type, $sites->publish((int) $owner->user_id, $agency)];
    }

    public function test_the_init_command_is_a_dry_run_without_apply_and_never_overwrites_or_touches_branding(): void
    {
        [$agency, $owner, $type] = $this->workspace('Motion Grove Photo Booth');

        $this->artisan('agency-site:init-original', ['workspace_uid' => $agency->uid, '--booking-type' => $type->public_booking_uuid])->assertSuccessful();
        $this->assertNull(AgencySite::query()->where('agency_workspace_id', $agency->id)->first(), 'dry run writes nothing');

        $this->artisan('agency-site:init-original', ['workspace_uid' => $agency->uid])->assertFailed();
        $this->artisan('agency-site:init-original', ['workspace_uid' => $agency->uid, '--booking-type' => '00000000-0000-4000-8000-000000000000', '--apply' => true])->assertFailed();
        $this->assertNull(AgencySite::query()->where('agency_workspace_id', $agency->id)->first());

        $this->artisan('agency-site:init-original', ['workspace_uid' => $agency->uid, '--booking-type' => $type->public_booking_uuid, '--apply' => true])->assertSuccessful();
        $site = AgencySite::query()->where('agency_workspace_id', $agency->id)->firstOrFail();
        $this->assertSame(AgencySite::DRAFT, $site->status, 'initialising never publishes');
        $this->assertSame(0, AgencyWhiteLabelSetting::query()->where('agency_workspace_id', $agency->id)->count(), 'no branding record is created');
        $this->assertSame('Motion Grove Photo Booth', $agency->fresh()->name, 'the workspace is not renamed');

        $before = $site->config;
        $this->artisan('agency-site:init-original', ['workspace_uid' => $agency->uid, '--booking-type' => $type->public_booking_uuid, '--apply' => true])->assertFailed();
        $this->assertSame($before, $site->fresh()->config, 'an existing site is never overwritten');
    }

    public function test_photobooth_is_the_original_motiongrove_homepage_minus_pricing_with_the_accounts_own_calendar(): void
    {
        [$agency, , $type, $site] = $this->boothSite();
        [, $dogType, $dogSite] = $this->dogSite();
        config(['marketing.landing.photobooth_site' => $site->public_id, 'marketing.landing.dogs_site' => $dogSite->public_id]);

        $booth = $this->get('/photobooth')->assertOk()->getContent();
        $home = $this->get('/')->assertOk()->getContent();

        // Original identity: nothing of the workspace name or a niche preset is shown.
        $this->assertStringNotContainsString('Motion Grove Photo Booth', $booth);
        $this->assertStringNotContainsString('Bright Frame', $booth);
        $this->assertStringContainsString('Lumen Events', $booth, 'the original illustration data, not a niche preset');
        $this->assertStringContainsString('<h1 id="hero-h" class="hero-h1">More bookings.', $booth);
        foreach (['/marketing/css/marketing.css'] as $asset) {
            $this->assertStringContainsString($asset, $booth . $home);
        }
        $this->assertStringContainsString(asset('marketing/img/mg-wordmark.png'), $booth, 'the original logo');
        $this->assertStringNotContainsString('data:image/svg+xml', $booth, 'no generated lookalike logo');
        $this->assertStringContainsString('MotionGrove', $this->text($booth));

        // Editorial parity: same sections as the homepage except pricing, comparison and the plan-terms promise.
        $this->assertSame(substr_count($home, '<section') - 3, substr_count($booth, '<section'));
        foreach (['id="cap-h"', 'id="trust-h"', 'id="how-h"', 'id="cta-h"', 'id="hero-h"'] as $kept) {
            $this->assertStringContainsString($kept, $home);
            $this->assertStringContainsString($kept, $booth);
        }

        // No pricing / signup / trial anywhere.
        foreach ($this->hrefs($booth) as $href) {
            $this->assertStringNotContainsString('/pricing', $href);
            $this->assertStringNotContainsString('/register', $href);
            $this->assertStringNotContainsString('signup', strtolower($href));
        }
        $this->assertDoesNotMatchRegularExpression('/Get started|Book a demo|See pricing|Compare plans|\bsubscription\b|free trial|per month|\bsign up\b|\bplans?\b/i', $this->text($booth));
        $this->assertDoesNotMatchRegularExpression('/\$(97|297|497)\b/', $booth);

        // Every conversion CTA is the workspace's own calendar: not the platform demo calendar, not Daniel's.
        $own = route('public.booking.show', $type->public_booking_uuid);
        preg_match_all('/<a href="([^"]*)" class="btn(?: btn-p)?[^"]*"[^>]*>\s*Book a Call/', $booth, $buttons);
        $this->assertGreaterThanOrEqual(3, count($buttons[1]), 'header, hero and final CTA all read "Book a Call"');
        $this->assertSame([$own], array_values(array_unique($buttons[1])));
        $this->assertStringNotContainsString('calendar.example', $booth);
        $this->assertStringNotContainsString($dogType->public_booking_uuid, $booth);

        // Navigation stays inside the landing page; footer legal and support links remain the platform's real ones.
        $this->assertStringContainsString('href="' . url('photobooth/product') . '"', $booth);
        $this->get('/photobooth/product')->assertOk()->assertDontSee('/signup', false);
        $this->get('/photobooth/pricing')->assertRedirect($own);
        $this->assertStringContainsString('noindex', (string) $this->get('/photobooth')->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString(url('legal'), $booth, 'footer legal links remain available');

        $this->assertContains(route('login'), $this->hrefs($booth), 'Log in is MotionGrove’s own login');
        $this->assertStringNotContainsString('/w/' . $site->public_id . '/login', $booth);

        // The branding record of the workspace was never needed, created or changed.
        $this->assertSame(0, AgencyWhiteLabelSetting::query()->where('agency_workspace_id', $agency->id)->count());

        // /dogs still serves Daniel's own site, the normal homepage is untouched.
        $dogs = $this->get('/dogs')->assertOk()->getContent();
        $this->assertStringContainsString('Maple Ridge Dog Training', $dogs);
        $this->assertStringNotContainsString('Lumen Events', $dogs);
        $this->assertStringContainsString(route('public.booking.show', $dogType->public_booking_uuid), $dogs);
        $this->assertStringContainsString(url('pricing'), $home);
        $this->assertStringContainsString('Get started', $home);
    }

    public function test_editor_changes_reach_the_published_page_only_after_publishing(): void
    {
        [$agency, $owner, , $site] = $this->boothSite();
        config(['marketing.landing.photobooth_site' => $site->public_id]);
        $sites = app(AgencySiteManager::class);

        $sites->save((int) $owner->user_id, $agency, ['copy.home.hero.title' => 'DRAFT-ONLY headline', 'copy.home.hero.lead' => 'Edited lead text']);
        $this->get('/photobooth')->assertOk()->assertDontSee('DRAFT-ONLY headline')->assertDontSee('Edited lead text');

        $sites->publish((int) $owner->user_id, $agency);
        $this->get('/photobooth')->assertOk()->assertSee('DRAFT-ONLY headline')->assertSee('Edited lead text');

        $sites->save((int) $owner->user_id, $agency, ['copy.home.hero.title' => 'Second draft']);
        $this->get('/photobooth')->assertSee('DRAFT-ONLY headline')->assertDontSee('Second draft');

        // Hiding a section and changing a CTA label take effect too.
        $sites->save((int) $owner->user_id, $agency, ['x.home_hidden' => ['faq'], 'copy.home.hero.primary' => 'Book a Call']);
        $before = $this->get('/photobooth')->getContent();
        $sites->publish((int) $owner->user_id, $agency);
        $after = $this->get('/photobooth')->getContent();
        $this->assertSame(substr_count($before, '<section') - 1, substr_count($after, '<section'));
        $this->assertStringContainsString('Book a Call', $after);
    }

    public function test_the_editor_presents_original_defaults_and_the_photo_booth_preset_stays_optional(): void
    {
        [$agency, $owner, , $site] = $this->boothSite(false);
        $editor = app(\App\Library\AgencySite\SiteEditorPresenter::class)->present($agency, $site->fresh());
        $this->assertStringContainsString('More bookings.', json_encode($editor['groups']));
        $this->assertArrayHasKey(NichePresets::PHOTO_BOOTH, NichePresets::NICHES);
        $this->assertArrayNotHasKey('x.niche', AgencySiteManager::values($site->fresh()), 'the preset is never applied automatically');
        $this->assertSame(SiteSchema::EDITION_ORIGINAL, AgencySiteManager::values($site->fresh())['x.edition']);
    }

    public function test_the_site_is_chosen_by_config_never_by_the_request_and_fails_safely(): void
    {
        [, $owner, $type, $site] = $this->boothSite();
        [, , $dogSite] = $this->dogSite();
        config(['marketing.landing.photobooth_site' => $site->public_id, 'marketing.landing.dogs_site' => $dogSite->public_id]);

        $html = $this->get('/photobooth?site=' . $dogSite->public_id . '&agency=1')->assertOk()->getContent();
        $this->assertStringContainsString('Lumen Events', $html);
        $this->assertStringNotContainsString('Maple Ridge', $html);

        // A dead booking destination is a 404, never a page of dead buttons.
        BookingType::query()->whereKey($type->id)->delete();
        $this->get('/photobooth')->assertNotFound();

        config(['marketing.landing.photobooth_site' => 'not-a-uuid']);
        $this->get('/photobooth')->assertNotFound();
    }

    public function test_an_unpublished_site_is_a_404_and_without_config_the_static_page_remains(): void
    {
        [, , , $site] = $this->boothSite(false);
        config(['marketing.landing.photobooth_site' => $site->public_id]);
        $this->get('/photobooth')->assertNotFound();

        config(['marketing.landing.photobooth_site' => null]);
        $this->get('/photobooth')->assertOk()->assertSee('Lumen Events');
        $this->get('/photobooth/product')->assertNotFound();
    }

    public function test_another_workspace_cannot_serve_or_edit_this_site(): void
    {
        [, , , $site] = $this->boothSite();
        [$other, $otherOwner] = $this->workspace('Someone Else');

        $this->expectException(\App\Exceptions\Branding\AgencyWhiteLabelException::class);
        app(AgencySiteManager::class)->save((int) $otherOwner->user_id, Workspace::query()->findOrFail($site->agency_workspace_id), ['copy.home.hero.title' => 'Hijack']);
    }
}

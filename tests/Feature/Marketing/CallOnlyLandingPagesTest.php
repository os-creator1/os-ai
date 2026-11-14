<?php

namespace Tests\Feature\Marketing;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\AgencySite\AgencySiteBookingService;
use App\Library\AgencySite\AgencySiteManager;
use App\Models\AgencySite;
use App\Models\BookingType;
use App\Models\Customer;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AgencyBilling\Concerns\CreatesAgencySaasFixtures;
use Tests\TestCase;

/**
 * /photobooth (the official homepage, minus pricing and signup) and /dogs (the existing published dog-business
 * White Label site, call-only). Neither changes the normal homepage, /pricing or signup.
 */
class CallOnlyLandingPagesTest extends TestCase
{
    use CreatesAgencySaasFixtures;
    use RefreshDatabase;

    private const BOOKING = 'https://calendar.example/motiongrove-demo';

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdminId();
        $this->ensureRequiredAppConfigRowsExist();
        $this->bindFakeAgencyStripe();
    }

    private function normalise(string $html): string
    {
        return (string) preg_replace('/\?v=\d+/', '?v=X', $html);
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

    // ------------------------------------------------------------------ /photobooth

    public function test_the_normal_homepage_and_pricing_are_unchanged_by_the_landing_page(): void
    {
        config(['marketing.cta.book_demo_url' => self::BOOKING]);

        $homeBefore = $this->normalise($this->get('/')->assertOk()->getContent());
        $pricingBefore = $this->normalise($this->get('/pricing')->assertOk()->getContent());

        $this->get('/photobooth')->assertOk();

        $this->assertSame($homeBefore, $this->normalise($this->get('/')->getContent()), 'the homepage is byte-identical after serving the landing page');
        $this->assertSame($pricingBefore, $this->normalise($this->get('/pricing')->getContent()), '/pricing is byte-identical');
        $this->assertStringContainsString(url('pricing'), $homeBefore, 'the normal homepage still links pricing');
        $this->assertStringContainsString('Get started', $homeBefore);
        $this->assertMatchesRegularExpression('/\$\d+/', $pricingBefore, 'real prices still show on /pricing');
        $this->assertStringContainsString(route('register', ['plan' => 'core']), $pricingBefore, 'signup still reachable from /pricing');
        $this->assertNull(config('marketing.landing.dogs_site'), 'nothing was written to the marketing config');
    }

    public function test_photobooth_is_the_official_homepage_without_pricing_or_signup(): void
    {
        config(['marketing.cta.book_demo_url' => self::BOOKING]);

        $home = $this->get('/')->getContent();
        $landing = $this->get('/photobooth')->assertOk()->getContent();

        // Same design system: identical stylesheet and script, hero, brand sample data.
        foreach (['/marketing/css/marketing.css', '/marketing/js/marketing.js'] as $asset) {
            $this->assertStringContainsString($asset, $landing);
        }
        $this->assertStringContainsString('<h1 id="hero-h" class="hero-h1">More bookings.', $landing);
        $this->assertStringContainsString('Lumen Events', $landing, 'the original sample business is kept');
        $this->assertStringContainsString('photo booth rental near me', $landing, 'the original photo-booth examples are kept');
        $this->assertStringContainsString('mg-header', $landing);

        // Every other home section survives; only the sales tables and the plan-terms promise go.
        foreach (['id="cta-h"', 'class="hero"'] as $kept) {
            $this->assertStringContainsString($kept, $home);
            $this->assertStringContainsString($kept, $landing);
        }
        $this->assertSame(substr_count($home, '<section') - 3, substr_count($landing, '<section'), 'exactly the pricing, comparison and plan-terms sections are gone');

        // Nothing sells: no pricing, plans, comparison, signup or registration — in nav, body, footer or mobile layout.
        foreach ($this->hrefs($landing) as $href) {
            $this->assertStringNotContainsString('/pricing', $href);
            $this->assertStringNotContainsString('/register', $href);
            $this->assertStringNotContainsString('signup', strtolower($href));
        }
        $this->assertDoesNotMatchRegularExpression('/Get started|Book a demo|See pricing|Compare plans|\bsubscription\b|free trial|per month|\/ 28 days|\bsign up\b/i', $this->text($landing));
        $this->assertDoesNotMatchRegularExpression('/\bplans?\b/i', $this->text($landing));
        $this->assertDoesNotMatchRegularExpression('/\$(97|297|497)\b/', $landing);

        // Every conversion button is MotionGrove's booking flow.
        preg_match_all('/<a href="([^"]*)" class="btn(?: btn-p)?[^"]*"[^>]*>\s*Book a call/i', $landing, $buttons);
        $this->assertGreaterThanOrEqual(3, count($buttons[1]), 'header, hero and final call to action');
        $this->assertSame([self::BOOKING], array_values(array_unique($buttons[1])));
        $this->assertSame(1, preg_match('/<a href="' . preg_quote(self::BOOKING, '/') . '" class="btn btn-p hero-btn">Book a call/', $landing));
    }

    public function test_photobooth_seo_is_its_own_canonical_and_internal_links_work(): void
    {
        config(['marketing.cta.book_demo_url' => self::BOOKING]);
        $landing = $this->get('/photobooth')->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="' . url('photobooth') . '">', $landing);
        $this->assertStringContainsString('<meta property="og:url" content="' . url('photobooth') . '">', $landing);

        foreach (['product', 'solutions', 'how-it-works', 'customers'] as $page) {
            $this->assertStringContainsString('href="' . route("marketing.{$page}") . '"', $landing);
            $this->get(route("marketing.{$page}"))->assertOk();
        }
        $this->assertStringContainsString('href="' . url('photobooth') . '#faq"', $landing, 'in-page links stay on the landing page');
    }

    public function test_photobooth_is_not_served_without_a_working_booking_destination(): void
    {
        config(['marketing.cta.book_demo_url' => null]);

        $this->get('/photobooth')->assertNotFound();
        $this->get('/')->assertOk();
    }

    // ------------------------------------------------------------------ /dogs

    /** @return array{0: Workspace, 1: Customer, 2: BookingType, 3: AgencySite} a published dog-business Agency site with a plan on sale */
    private function dogAgency(string $name = 'Daniel Black', string $goal = 'both'): array
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
            'x.goal' => $goal, 'x.niche' => 'dog_business', 'x.booking_type_uid' => $type->public_booking_uuid,
            'links.privacy' => 'https://example.test/privacy', 'links.terms' => 'https://example.test/terms',
        ]);

        return [$agency, $owner, $type, $sites->publish((int) $owner->user_id, $agency)];
    }

    public function test_dogs_renders_the_existing_published_dog_site_as_a_call_only_page(): void
    {
        [$agency, $owner, $type, $site] = $this->dogAgency();
        config(['marketing.landing.dogs_site' => $site->public_id]);
        $booking = route('public.booking.show', $type->public_booking_uuid);

        $html = $this->get('/dogs')->assertOk()->getContent();

        $this->assertStringContainsString('Daniel Black', $html);
        $this->assertStringContainsString('Maple Ridge Dog Training', $html);
        $this->assertStringContainsString('More clients. More bookings.', $html);
        $this->assertStringContainsString(route('agency.site.css', $site->public_id), $html, 'the same shared marketing CSS, in the Agency colour');
        $this->assertStringContainsString('<link rel="canonical" href="' . url('dogs') . '">', $html);
        $this->assertDoesNotMatchRegularExpression('/wedding|photo ?booth|Lumen Events/i', $html);

        foreach ($this->hrefs($html) as $href) {
            $this->assertStringNotContainsString('/signup', $href);
            $this->assertStringNotContainsString('/pricing', $href);
        }
        $this->assertStringNotContainsString('Studio', $this->text($html));
        $this->assertStringNotContainsString('Get started', $html);
        $this->assertStringNotContainsString('Book a demo', $html);

        preg_match_all('/<a href="([^"]*)" class="btn[^"]*"[^>]*>\s*Book a call/i', $html, $buttons);
        $this->assertGreaterThanOrEqual(3, count($buttons[1]));
        $this->assertSame([$booking], array_values(array_unique($buttons[1])), "every conversion button is the Agency's own calendar");

        // Page links stay inside the landing page, and the old pricing URL never shows prices.
        $this->assertStringContainsString('href="' . url('dogs/product') . '"', $html);
        $this->get('/dogs/product')->assertOk()->assertDontSee('/signup', false);
        $this->get('/dogs/pricing')->assertRedirect($booking);
    }

    public function test_dogs_does_not_change_the_agencys_saved_mode_or_its_hosted_site(): void
    {
        [$agency, $owner, , $site] = $this->dogAgency();
        config(['marketing.landing.dogs_site' => $site->public_id]);

        $this->get('/dogs')->assertOk();

        $this->assertSame('both', AgencySiteManager::values($site->fresh())['x.goal'], 'the saved selling mode is untouched');
        $this->get(route('agency.site.page', [$site->public_id, 'pricing']))->assertOk()->assertSee('Studio', false);
        $this->assertStringNotContainsString('Maple Ridge', $this->get('/')->getContent());
        $this->assertStringNotContainsString('Maple Ridge', $this->get('/pricing')->getContent());
    }

    public function test_dogs_never_exposes_drafts_and_follows_publishing(): void
    {
        [$agency, $owner, , $site] = $this->dogAgency();
        config(['marketing.landing.dogs_site' => $site->public_id]);

        app(AgencySiteManager::class)->save((int) $owner->user_id, $agency, ['copy.home.hero.title' => 'DRAFT ONLY HEADLINE']);
        $this->assertStringNotContainsString('DRAFT ONLY HEADLINE', $this->get('/dogs')->getContent(), 'unpublished edits are never public');

        app(AgencySiteManager::class)->publish((int) $owner->user_id, $agency);
        $this->assertStringContainsString('DRAFT ONLY HEADLINE', $this->get('/dogs')->getContent());

        app(AgencySiteManager::class)->unpublish((int) $owner->user_id, $agency);
        $this->get('/dogs')->assertNotFound();
        $this->get('/dogs/product')->assertNotFound();
    }

    public function test_dogs_is_not_served_unless_configured_and_cannot_be_steered_by_the_request(): void
    {
        [, , , $dog] = $this->dogAgency('Daniel Black');
        [, , , $other] = $this->dogAgency('Other Agency');

        config(['marketing.landing.dogs_site' => null]);
        $this->get('/dogs')->assertNotFound();

        config(['marketing.landing.dogs_site' => 'not-a-uuid']);
        $this->get('/dogs')->assertNotFound();

        config(['marketing.landing.dogs_site' => $dog->public_id]);
        $html = $this->get('/dogs?site=' . $other->public_id . '&publicId=' . $other->public_id)->assertOk()->getContent();
        $this->assertStringContainsString('Daniel Black', $html);
        $this->assertStringNotContainsString('Other Agency', $html, 'another Agency is never reachable through /dogs');
        $this->assertStringNotContainsString($other->public_id, $html);
    }

    public function test_dogs_is_a_404_when_the_agencys_calendar_stops_working_and_blocks_signup_bypass(): void
    {
        [$agency, $owner, $type, $site] = $this->dogAgency();
        config(['marketing.landing.dogs_site' => $site->public_id]);
        $this->get('/dogs')->assertOk();

        // The landing page never offers signup, and the stored-mode signup route is not linked from it.
        $this->assertStringNotContainsString('/w/' . $site->public_id . '/signup', $this->get('/dogs')->getContent());

        app(\App\Library\Calendar\BookingTypeManager::class)->setActive($type, false);
        $this->get('/dogs')->assertNotFound();
    }
}

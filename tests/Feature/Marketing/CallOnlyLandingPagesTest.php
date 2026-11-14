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

    /** Internal (same-site) hrefs of a page, split into path and fragment. @return list<array{0: string, 1: string}> */
    private function internalLinks(string $html): array
    {
        $out = [];
        foreach ($this->hrefs($html) as $href) {
            if (str_starts_with($href, '#')) {
                $out[] = [rtrim(url('/'), '/') . '/__same__', substr($href, 1)];
            } elseif (str_starts_with($href, rtrim(url('/'), '/'))) {
                $parts = explode('#', $href, 2);
                $out[] = [$parts[0], $parts[1] ?? ''];
            }
        }

        return $out;
    }

    public function test_photobooth_seo_is_noindex_with_its_own_canonical_and_the_normal_site_is_unchanged(): void
    {
        config(['marketing.cta.book_demo_url' => self::BOOKING]);
        $response = $this->get('/photobooth')->assertOk();
        $landing = $response->getContent();

        $this->assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('<link rel="canonical" href="' . url('photobooth') . '">', $landing);
        $this->assertStringContainsString('<meta property="og:url" content="' . url('photobooth') . '">', $landing);

        $home = $this->get('/')->assertOk();
        $this->assertNull($home->headers->get('X-Robots-Tag'), 'the normal homepage stays indexable');
        $this->assertStringContainsString('<link rel="canonical" href="' . rtrim(route('marketing.home'), '/') . '/">', $home->getContent());
        $this->assertNull($this->get('/pricing')->headers->get('X-Robots-Tag'));
        $this->assertNull($this->get('/product')->headers->get('X-Robots-Tag'));
    }

    public function test_every_photobooth_navigation_link_stays_in_a_call_only_experience(): void
    {
        config(['marketing.cta.book_demo_url' => self::BOOKING]);
        $landing = $this->get('/photobooth')->assertOk()->getContent();   // desktop nav, mobile menu, both footers: one document
        $normalPages = [route('marketing.product'), route('marketing.solutions'), route('marketing.how-it-works'), route('marketing.customers'), url('pricing'), route('marketing.home')];
        $allowedOther = array_merge(['login', 'legal', 'support', 'docs/zoom'], array_keys((array) config('legal.documents')));
        $anchors = 0;
        $paths = [];

        foreach ($this->internalLinks($landing) as [$path, $fragment]) {
            $this->assertNotContains(rtrim($path, '/'), array_map(fn ($u) => rtrim($u, '/'), $normalPages), "{$path}: no link back to the normal site's sales pages");
            $this->assertStringNotContainsString('register', $path);
            $this->assertStringNotContainsString('signup', $path);

            if ($fragment !== '') {
                $anchors++;
                $this->assertStringContainsString('id="' . $fragment . '"', $landing, "#{$fragment} is not a dead anchor");
            }

            $slug = trim((string) parse_url($path, PHP_URL_PATH), '/');
            if ($slug !== '' && $slug !== 'photobooth' && $slug !== '__same__') {
                $this->assertContains($slug, $allowedOther, "{$path}: only login and the legal documents leave the landing page");
                $paths[$slug] = $path;
            }
        }

        $this->assertGreaterThanOrEqual(4, $anchors, 'navigation uses section anchors');
        foreach (['cap-h', 'how-h', 'trust-h', 'faq'] as $id) {
            $this->assertStringContainsString('href="' . url('photobooth') . '#' . $id . '"', $landing);
        }

        // The mobile menu and the phone footer carry the same call-only links.
        preg_match('/<details class="mg-menu.*?<\/details>/s', $landing, $menu);
        $this->assertNotEmpty($menu);
        foreach ($this->hrefs($menu[0]) as $href) {
            $this->assertStringNotContainsString('/pricing', $href);
            $this->assertStringNotContainsString('/register', $href);
            $this->assertStringNotContainsString('/product', $href);
        }

        // Everything that is linked works.
        foreach ($paths as $slug => $path) {
            $this->get($path)->assertOk();
        }
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

    public function test_every_dogs_navigation_link_stays_call_only_and_works(): void
    {
        [, , $type, $site] = $this->dogAgency();
        config(['marketing.landing.dogs_site' => $site->public_id]);
        $booking = route('public.booking.show', $type->public_booking_uuid);

        $queue = [url('dogs')];
        $seen = [];
        $followed = 0;

        while ($queue !== []) {
            $page = array_shift($queue);
            if (isset($seen[$page])) {
                continue;
            }
            $seen[$page] = true;

            $response = $this->get($page);
            if ($response->isRedirect()) {
                $this->assertSame($booking, $response->headers->get('Location'), "{$page} redirects only to the calendar");
                continue;
            }
            $this->assertSame(200, $response->getStatusCode(), "{$page} is a dead link");
            $this->assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'), "{$page}: noindex");
            $followed++;
            $html = $response->getContent();

            foreach ($this->hrefs($html) as $href) {
                $this->assertDoesNotMatchRegularExpression('#/(signup|register|pricing)(/|$|\\?)#', $href, "{$page} links to {$href}");

                if (str_starts_with($href, '#')) {
                    $this->assertStringContainsString('id="' . substr($href, 1) . '"', $html, "{$page}: {$href} is not a dead anchor");
                } elseif (str_starts_with($href, url('dogs'))) {
                    $queue[] = explode('#', $href, 2)[0];
                } elseif (str_starts_with($href, rtrim(url('/'), '/'))) {
                    $this->assertContains(explode('#', $href, 2)[0], [route('agency.site.login', $site->public_id), route('agency.site.css', $site->public_id), $booking], "{$page}: {$href} leaves the landing page");
                }
            }
        }

        $this->assertGreaterThanOrEqual(4, $followed, 'home plus the product, solutions and how-it-works pages');
        $this->assertArrayHasKey(url('dogs/product'), $seen);
        $this->get('/dogs/pricing')->assertRedirect($booking)->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}

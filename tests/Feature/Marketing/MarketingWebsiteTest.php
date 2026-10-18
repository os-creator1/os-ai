<?php

namespace Tests\Feature\Marketing;

use App\Library\Marketing\MarketingSite;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The public MotionGrove marketing website. None of these tests touch the database: every page is
 * a read-only render of config/marketing.php. The only application behaviour that must not change
 * (the signed-in root redirect and the signed-in pricing page) is asserted without a stored user.
 */
class MarketingWebsiteTest extends TestCase
{
    private const PAGES = ['/', '/product', '/solutions', '/how-it-works', '/customers', '/pricing'];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'marketing.enabled' => true,
            'marketing.hosts' => [],
            'marketing.show_placeholders' => false,
            'marketing.show_unverified_features' => false,
            'marketing.cta.book_demo_url' => null,
        ]);
    }

    private function html(string $path): string
    {
        $response = $this->get($path);
        $response->assertOk();

        return $response->getContent();
    }

    private function text(string $path): string
    {
        return html_entity_decode(strip_tags($this->html($path)), ENT_QUOTES | ENT_HTML5);
    }

    // ------------------------------------------------------------------ pages

    public function test_every_public_page_renders_for_a_guest_with_unique_seo_metadata(): void
    {
        $titles = [];

        foreach (self::PAGES as $path) {
            $html = $this->html($path);

            $this->assertSame(1, substr_count($html, '<h1'), "$path must have exactly one h1");
            $this->assertMatchesRegularExpression('#<title>[^<]+</title>#', $html);
            $this->assertMatchesRegularExpression('#<meta name="description" content="[^"]{40,}">#', $html);
            $this->assertStringContainsString('<link rel="canonical"', $html);
            $this->assertStringContainsString('property="og:title"', $html);
            $this->assertStringContainsString('<main id="main">', $html);

            preg_match('#<title>([^<]+)</title>#', $html, $m);
            $titles[] = $m[1];
        }

        $this->assertCount(count(self::PAGES), array_unique($titles), 'every page needs its own title');
    }

    public function test_the_logo_and_fonts_are_the_supplied_assets(): void
    {
        $html = $this->html('/');

        $this->assertStringContainsString('marketing/img/mg-wordmark.png', $html);
        $this->assertStringContainsString('Figtree', $html);
        foreach (['mg-wordmark.png', 'mg-wordmark-white.png', 'mg-mark.png'] as $file) {
            $this->assertFileExists(public_path('marketing/img/'.$file));
        }
    }

    public function test_the_homepage_follows_the_approved_section_order(): void
    {
        $html = $this->html('/');

        $order = ['hero-h', 'trust-h', 'prob-h', 'sys-h', 'cap-h', 'ppl-h', 'how-h', 'cmp-h', 'price-h', 'com-h', 'faq-h', 'cta-h'];
        $last = -1;

        foreach ($order as $id) {
            $pos = strpos($html, 'id="'.$id.'"');
            $this->assertNotFalse($pos, "missing section $id");
            $this->assertGreaterThan($last, $pos, "$id is out of order");
            $last = $pos;
        }
    }

    public function test_all_six_flow_steps_are_server_rendered_and_only_the_first_is_selected(): void
    {
        $html = $this->html('/');

        $this->assertSame(6, substr_count($html, 'class="flow-btn"'));
        $this->assertSame(6, substr_count($html, 'data-panel="'));
        $this->assertSame(1, substr_count($html, 'aria-pressed="true"'));
        $this->assertStringContainsString('A customer finds you.', $html);
        $this->assertStringContainsString('You see what’s working.', $html);
    }

    public function test_the_industry_picker_and_the_faq_are_crawlable_html(): void
    {
        $solutions = $this->html('/solutions');
        $this->assertSame(6, substr_count($solutions, 'class="pill"'));
        $this->assertStringContainsString('Fill your calendar without living in your inbox.', $solutions);
        $this->assertStringContainsString('A tidy pipeline from first call to signed.', $solutions);

        $home = $this->html('/');
        $this->assertStringContainsString('<summary', $home);
        $this->assertStringContainsString('Will MotionGrove help me get more customers?', $home);
        $this->assertStringContainsString('"@type":"FAQPage"', $home);
    }

    // ---------------------------------------------------------------- pricing

    public function test_public_prices_are_exactly_core_97_growth_297_agency_497_every_28_days(): void
    {
        foreach (['/', '/pricing'] as $path) {
            $text = $this->text($path);

            foreach (['$97', '$297', '$497'] as $price) {
                $this->assertStringContainsString($price, $text, "$price missing on $path");
            }
            $this->assertStringContainsString('28 days', $text);
        }

        $html = $this->html('/pricing');
        $this->assertSame(1, substr_count($html, 'Most popular'));
        $this->assertMatchesRegularExpression('#background: \#181413[^"]*"[^>]*>\s*<span class="chip"[^>]*>Most popular#', $html, 'Growth is the dark featured card');
    }

    public function test_the_private_offer_and_the_trial_are_never_public(): void
    {
        foreach (self::PAGES as $path) {
            $text = strtolower($this->text($path));

            $this->assertStringNotContainsString('$197', $text, $path);
            $this->assertDoesNotMatchRegularExpression('/free trial|start (your )?(free )?trial|28-day (free )?trial|trial/', $text, $path);
            $this->assertStringNotContainsString('sales call', $text, $path);
        }


        // The content file itself must not define any trial or private-offer wording as a value.
        $values = [];
        $config = config('marketing');
        array_walk_recursive($config, function ($v) use (&$values) {
            if (is_string($v)) {
                $values[] = strtolower($v);
            }
        });
        foreach ($values as $v) {
            $this->assertDoesNotMatchRegularExpression('/trial|\$197|private offer|sales call/', $v);
        }
    }

    public function test_plans_link_to_the_catalog_validated_signup_by_default_and_never_to_a_trial(): void
    {
        $site = $this->app->make(MarketingSite::class);

        if (Route::has('register')) {
            $this->assertSame(route('register', ['plan' => 'growth']), $site->planUrl('growth'));
            $this->assertSame(route('register', ['plan' => 'core']), $site->planUrl('core'));
        } else {
            $this->assertNotSame('', $site->planUrl('growth'));
        }

        config(['marketing.cta.plans.growth' => 'https://checkout.example.test/growth']);
        $this->assertSame('https://checkout.example.test/growth', $site->planUrl('growth'));
        $this->assertStringContainsString('https://checkout.example.test/growth', $this->html('/pricing'));
    }

    public function test_billing_terms_that_are_not_confirmed_are_not_published_as_facts(): void
    {
        foreach (['/', '/pricing'] as $path) {
            $text = $this->text($path);
            $this->assertStringNotContainsString('[Confirm', $text);
            $this->assertStringNotContainsString('confirm against the Terms', $text);
            $this->assertStringNotContainsString('Can I cancel', $text);
        }

        config(['marketing.show_placeholders' => true]);
        $this->assertStringContainsString('[Confirm cancellation terms', $this->text('/pricing'));
    }

    // -------------------------------------------------------------- book a demo

    public function test_without_a_working_booking_destination_no_book_a_demo_button_is_rendered(): void
    {
        foreach (self::PAGES as $path) {
            $this->assertStringNotContainsString('Book a demo', $this->text($path), $path);
            $this->assertStringNotContainsString('Talk to us', $this->text($path), $path);
        }
    }

    public function test_every_book_a_demo_button_shares_one_configured_destination(): void
    {
        config(['marketing.cta.book_demo_url' => 'https://calendly.example.test/motiongrove']);

        $destinations = [];
        foreach (self::PAGES as $path) {
            $html = $this->html($path);
            preg_match_all('#<a href="([^"]+)"[^>]*>\s*(?:Book a demo|Talk to us|Talk to our team|Ask us)#', $html, $m);
            $this->assertNotEmpty($m[1], "$path should offer a demo link");
            $destinations = array_merge($destinations, $m[1]);
        }

        $this->assertSame(['https://calendly.example.test/motiongrove'], array_values(array_unique($destinations)));
    }

    public function test_the_native_booking_page_is_used_only_when_it_exists_and_is_provisioned(): void
    {
        $site = $this->app->make(MarketingSite::class);

        // The native page ships on `main`; where this branch does not have it yet, stand in for its route name.
        if (! Route::has('demo.booking.show')) {
            Route::get('/book-demo', fn () => 'ok')->name('demo.booking.show');
            $this->app['router']->getRoutes()->refreshNameLookups();
        }

        // Not provisioned: no booking type and no Turnstile keys.
        config(['demo_booking.booking_type_uuid' => null, 'demo_booking.turnstile.site_key' => null, 'demo_booking.turnstile.secret_key' => null, 'demo_booking.turnstile.bypass' => false]);
        $this->assertNull($site->bookDemoUrl());

        // Booking type but no Turnstile: still refused by /book-demo, so still no button.
        config(['demo_booking.booking_type_uuid' => '11111111-1111-4111-8111-111111111111']);
        $this->assertNull($site->bookDemoUrl());

        // Fully provisioned.
        config(['demo_booking.turnstile.site_key' => 'site', 'demo_booking.turnstile.secret_key' => 'secret']);
        $this->assertSame(url('/book-demo'), $site->bookDemoUrl());
        $this->assertStringContainsString(url('/book-demo'), $this->html('/'));

        // An explicit external link takes precedence.
        config(['marketing.cta.book_demo_url' => 'https://calendly.example.test/x']);
        $this->assertSame('https://calendly.example.test/x', $site->bookDemoUrl());
    }

    public function test_unsafe_or_malformed_destinations_are_ignored(): void
    {
        $site = $this->app->make(MarketingSite::class);

        foreach (['javascript:alert(1)', 'ftp://example.test', '//evil.test', 'not a url', ''] as $bad) {
            config(['marketing.cta.book_demo_url' => $bad]);
            $this->assertNull($site->bookDemoUrl(), $bad);
        }
    }

    // --------------------------------------------------- placeholders & testimonials

    public function test_production_never_shows_bracketed_placeholders_and_hides_the_empty_story_section(): void
    {
        foreach (self::PAGES as $path) {
            $text = $this->text($path);
            $this->assertDoesNotMatchRegularExpression('/\[(Customer|Business|Category|City|VIDEO_ID|Setup specialist|Photo|Short quote)/', $text, $path);
        }

        $this->assertStringNotContainsString('id="vid-h"', $this->html('/'));
        $this->assertStringContainsString('Customer stories are coming soon.', $this->text('/customers'));
        $this->assertStringNotContainsString('[Customer logo]', $this->html('/'));
    }

    public function test_placeholders_are_clearly_labelled_and_the_video_buttons_are_inert(): void
    {
        config(['marketing.show_placeholders' => true]);

        $html = $this->html('/');
        // Five slots, each drawn once for desktop and once for the phone scroller.
        preg_match_all('/Video placeholder · \[(VIDEO_ID_\d)\]/', $html, $m);
        $this->assertCount(5, array_unique($m[1]));
        $this->assertSame(10, substr_count($html, 'class="play" disabled'));
        $this->assertStringContainsString('Jazmin Media client', $html);
        $this->assertStringContainsString('Stories marked “Jazmin Media client”', $html);
        $this->assertStringNotContainsString('youtube', strtolower(preg_replace('#Customer stories#i', '', strip_tags($html))), 'no YouTube is requested for placeholders');
    }

    public function test_a_real_video_is_click_to_load_with_a_real_thumbnail_and_a_plain_link_fallback(): void
    {
        $stories = config('marketing.testimonials');
        $stories[0] = array_merge($stories[0], [
            'youtubeId' => 'dQw4w9WgXcQ', 'customerName' => 'Alex Rivera', 'businessName' => 'Rivera Events',
            'category' => 'Event services', 'location' => 'Austin', 'quote' => 'It changed how we book.',
        ]);
        config(['marketing.testimonials' => $stories]);

        $html = $this->html('/');

        $this->assertStringContainsString('https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg', $html);
        $this->assertStringContainsString('data-video="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1"', $html);
        $this->assertStringContainsString('<button type="button" class="play" data-video-play', $html);
        $this->assertStringContainsString('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $html);
        $this->assertStringNotContainsString('<iframe', $html, 'the player must not load until the visitor clicks');
        $this->assertStringContainsString('Alex Rivera', $html);
        $this->assertStringContainsString('MotionGrove customer', $html);
        $this->assertStringNotContainsString('Stories marked “Jazmin Media client”', $html, 'no Jazmin item visible, so no disclaimer');
    }

    public function test_a_malformed_video_id_is_rejected_and_never_reaches_a_url(): void
    {
        $site = $this->app->make(MarketingSite::class);

        foreach (['', 'short', 'dQw4w9WgXcQ"><script>', '../../etc/passwd', 'dQw4w9WgXcQ extra'] as $bad) {
            $this->assertNull($site->youtubeId($bad), $bad);
        }
        $this->assertSame('dQw4w9WgXcQ', $site->youtubeId('dQw4w9WgXcQ'));
    }

    public function test_the_jazmin_disclaimer_renders_whenever_a_jazmin_story_is_visible(): void
    {
        $stories = config('marketing.testimonials');
        foreach ($stories as $i => $s) {
            $stories[$i] = array_merge($s, ['youtubeId' => 'abcdefghi'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'customerName' => 'Person '.$i]);
        }
        config(['marketing.testimonials' => $stories]);

        $this->assertStringContainsString('Stories marked “Jazmin Media client”', $this->html('/'));
        $this->assertStringContainsString('not evidence of results produced by the MotionGrove software', $this->html('/customers'));

        $stories[3]['visible'] = false;
        config(['marketing.testimonials' => $stories]);
        $this->assertStringNotContainsString('Stories marked “Jazmin Media client”', $this->html('/'));
    }

    // ----------------------------------------------------------------- content rules

    public function test_unverified_features_and_unapproved_claims_are_hidden_by_default(): void
    {
        $text = $this->text('/solutions').$this->text('/product');

        foreach (['Missed-call text back', 'Win-back messages', 'Review requests', 'Review request', 'Reviews afterwards', 'your domain'] as $claim) {
            $this->assertStringNotContainsString($claim, $text, $claim);
        }

        config(['marketing.show_unverified_features' => true]);
        $this->assertStringContainsString('Missed-call text back', $this->text('/solutions'));
    }

    public function test_ads_are_always_optional_and_sample_data_is_labelled(): void
    {
        $home = $this->text('/');
        $this->assertStringContainsString('SEO, ads — or both', $home);
        $this->assertStringContainsString('Ads are optional', $home);
        $this->assertStringContainsString('if you run ads', strtolower($home));
        $this->assertStringContainsString('Example workspace · sample data', $home);
        $this->assertStringContainsString('Example workspace · sample data', $this->html('/product').'');
    }

    public function test_social_help_and_legal_links_render_only_when_configured_and_valid(): void
    {
        $html = $this->html('/');
        foreach (['Privacy Policy', 'Terms of Service', 'Acceptable Use Policy', 'YouTube', 'Instagram', 'Help centre'] as $label) {
            $this->assertStringNotContainsString('>'.$label.'<', $html, $label.' must not render without a real URL');
        }

        config([
            'marketing.social.youtube' => 'https://www.youtube.com/@motiongrove',
            'marketing.social.instagram' => 'javascript:alert(1)',
            'marketing.links.privacy' => 'https://getmotiongrove.com/privacy',
            'marketing.links.help_centre' => 'https://help.example.test',
        ]);
        $html = $this->html('/');

        $this->assertStringContainsString('https://www.youtube.com/@motiongrove', $html);
        $this->assertStringNotContainsString('javascript:alert', $html);
        $this->assertStringNotContainsString('>Instagram<', $html);
        $this->assertStringContainsString('>Privacy Policy<', $html);
        $this->assertStringContainsString('https://help.example.test', $html);
    }

    public function test_customer_logos_stay_hidden_until_they_are_approved(): void
    {
        config(['marketing.trust_logos' => [['name' => 'Acme Events', 'image' => 'img/logos/acme.svg']]]);
        $this->assertStringNotContainsString('Acme Events', $this->html('/'));

        config(['marketing.sections.home.trust_logos' => true]);
        $this->assertStringContainsString('alt="Acme Events"', $this->html('/'));
    }

    // ------------------------------------------------- routing: nothing else is disturbed

    public function test_a_signed_in_customer_opening_the_root_still_goes_to_the_application(): void
    {
        $this->actingAs(new User)->get('/')->assertRedirect('login');
    }

    public function test_the_marketing_site_can_be_switched_off_and_the_legacy_root_redirect_remains(): void
    {
        config(['marketing.enabled' => false]);
        $this->get('/')->assertRedirect('login');
    }

    public function test_blank_hosts_mean_the_platform_host_only_and_other_hosts_get_no_marketing_page(): void
    {
        $site = $this->app->make(MarketingSite::class);
        config(['app.url' => 'https://getmotiongrove.com']);
        $platform = 'getmotiongrove.com';

        $this->assertTrue($site->servesRequest(\Illuminate\Http\Request::create('http://'.$platform.'/')));
        // A not-yet-Active customer domain, a stray subdomain of APP_URL and www all fall outside.
        foreach (['customer-site.example.test', 'app.'.$platform, 'www.'.$platform] as $host) {
            $this->assertFalse($site->servesRequest(\Illuminate\Http\Request::create('http://'.$host.'/')), $host);
        }

        config(['marketing.hosts' => ['www.'.$platform]]);
        $this->assertTrue($site->servesRequest(\Illuminate\Http\Request::create('http://www.'.$platform.'/product')));
        $this->assertFalse($site->servesRequest(\Illuminate\Http\Request::create('http://'.$platform.'/product')));

        config(['marketing.enabled' => false]);
        $this->assertFalse($site->servesRequest(\Illuminate\Http\Request::create('http://www.'.$platform.'/')));
    }

    public function test_the_plan_comparison_follows_the_catalog_packaging(): void
    {
        $text = $this->text('/pricing');
        $this->assertStringContainsString('AI website generation & hosting', $text);

        $plans = config('marketing.plans');
        $this->assertContains('AI website generation & hosting', $plans['core']['features']);
        $this->assertNotContains('AI website generation & hosting', $plans['growth']['features']);
    }

    public function test_the_site_answers_only_on_its_configured_hosts(): void
    {
        $platform = parse_url(config('app.url'), PHP_URL_HOST);

        config(['marketing.hosts' => [$platform]]);
        $this->get('/')->assertOk();

        config(['marketing.hosts' => ['marketing.example.test']]);
        $this->get('/')->assertRedirect('login');
    }

    public function test_application_routes_the_site_must_not_touch_are_still_registered(): void
    {
        foreach (['login', 'password.request', 'user.account.pricing', 'public.booking.show', 'public.website.home', 'public.robots'] as $name) {
            $this->assertTrue(Route::has($name), "route $name must still exist");
        }

        $this->assertSame(url('/pricing'), route('user.account.pricing'));
    }

    public function test_pricing_for_a_signed_in_customer_is_not_replaced_by_the_public_page(): void
    {
        $response = $this->actingAs(new User)->get('/pricing');

        $this->assertStringNotContainsString('Simple plans. Real help included.', (string) $response->getContent());
    }

    public function test_marketing_config_pricing_matches_the_documented_public_prices(): void
    {
        $plans = config('marketing.plans');

        $this->assertSame(['core' => 97, 'growth' => 297, 'agency' => 497], array_map(fn ($p) => $p['price'], $plans));
        $this->assertSame(['every_28_days'], array_values(array_unique(array_map(fn ($p) => $p['cycle'], $plans))));
        $this->assertSame(['growth'], array_keys(array_filter($plans, fn ($p) => $p['featured'])));
    }
}

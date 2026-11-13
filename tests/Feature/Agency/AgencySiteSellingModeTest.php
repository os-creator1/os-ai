<?php

namespace Tests\Feature\Agency;

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
 * White Label — the two public conversion modes, enforced on the server:
 *   self-service signup (pricing, plans, signup)   vs   "Book a call" only (no price, plan or signup anywhere).
 * Plus the draft-preview stylesheet and the industry niche layer.
 */
class AgencySiteSellingModeTest extends TestCase
{
    use CreatesAgencySaasFixtures;
    use RefreshDatabase;

    private const PAGES = ['home', 'product', 'solutions', 'how-it-works', 'customers'];

    private function story(): array
    {
        return ['x.testimonials' => [['customer_name' => 'Dana', 'business_name' => 'Dana Dogs', 'quote' => 'It keeps my bookings tidy.', 'approved' => true]]];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdminId();
        $this->ensureRequiredAppConfigRowsExist();
        $this->bindFakeAgencyStripe();
    }

    /** @return array{0: Workspace, 1: Customer, 2: BookingType, 3: \App\Models\AgencySaasPlan} an Agency selling one plan, with a working calendar */
    private function agency(string $name = 'Northwind Agency', string $color = '#1a73e8'): array
    {
        [$owner, $business, $agency] = $this->tenant(WorkspacePlanTier::Agency, $name . ' Business', $name);
        app(\App\Library\Business\BusinessLocationManager::class)->upsertPrimaryLocation($business, ['service_mode' => 'storefront', 'country_code' => 'US']);
        app(\App\Library\Branding\AgencyWhiteLabelManager::class)->save((int) $owner->user_id, $agency, ['display_name' => $name . ' Digital', 'accent_color' => $color, 'is_enabled' => '1']);
        $this->connectAgencyStripe($agency, (int) $owner->user_id);
        $plan = $this->publishedPlan($agency, (int) $owner->user_id, WorkspacePlanTier::Growth, '49.00', null, 'Studio');
        $type = app(AgencySiteBookingService::class)->create((int) $owner->user_id, $agency, ['name' => 'Intro call', 'duration_minutes' => 30, 'default_hours' => true]);

        return [$agency, $owner, $type, $plan];
    }

    private function save(Workspace $agency, Customer $owner, array $answers): AgencySite
    {
        $sites = app(AgencySiteManager::class);
        $sites->ensure((int) $owner->user_id, $agency);

        return $sites->save((int) $owner->user_id, $agency, $answers);
    }

    private function publish(Workspace $agency, Customer $owner): AgencySite
    {
        return app(AgencySiteManager::class)->publish((int) $owner->user_id, $agency);
    }

    private function legal(): array
    {
        return ['links.privacy' => 'https://northwind.example/privacy', 'links.terms' => 'https://northwind.example/terms'];
    }

    /** Every <a href> on a page. */
    private function hrefs(string $html): array
    {
        preg_match_all('/<a\b[^>]*\bhref="([^"]*)"/i', $html, $m);

        return array_values(array_unique($m[1]));
    }

    // ------------------------------------------------------------ call-only: nothing sells

    public function test_call_only_mode_removes_every_price_plan_and_signup_path_from_the_public_site(): void
    {
        [$agency, $owner, $type] = $this->agency();
        $booking = route('public.booking.show', $type->public_booking_uuid);
        $site = $this->save($agency, $owner, ['x.goal' => 'book_call', 'x.booking_type_uid' => $type->public_booking_uuid] + $this->story() + $this->legal());
        $this->publish($agency, $owner);

        foreach (self::PAGES as $page) {
            $html = $this->get(route($page === 'home' ? 'agency.site.home' : 'agency.site.page', $page === 'home' ? [$site->public_id] : [$site->public_id, $page]))->assertOk()->getContent();

            foreach ($this->hrefs($html) as $href) {
                $this->assertStringNotContainsString('/pricing', $href, "{$page}: no link to pricing");
                $this->assertStringNotContainsString('/signup', $href, "{$page}: no link to signup");
                $this->assertStringNotContainsString('/register', $href, "{$page}: no link to public registration");
            }

            $this->assertDoesNotMatchRegularExpression('/\$49|Compare plans|See pricing|Choose the plan|Get started|per month|\/ month|\bsign up\b|free trial/i', $html, "{$page}: no price, plan or signup wording");
            $this->assertStringNotContainsString('>Studio<', $html, "{$page}: the published plan is not shown");
            $this->assertStringNotContainsString('data-role="plans', $html);
            $this->assertStringNotContainsString('Book a demo', $html, "{$page}: the call-only label is never 'Book a demo'");
            $this->assertStringContainsString('Book a call', $html, "{$page}: conversion CTA is Book a call");
            $this->assertStringContainsString('href="' . $booking . '"', $html, "{$page}: CTA leads to the real calendar");
        }

        // The home sections that sell are gone, the rest of the product site is intact.
        $home = $this->get(route('agency.site.home', $site->public_id))->getContent();
        $this->assertStringNotContainsString('id="pricing"', $home);
        $this->assertStringContainsString('class="hero"', $home);
        $this->assertStringContainsString('Log in', $home, 'existing customers keep their login');
    }

    public function test_every_conversion_button_in_call_only_mode_is_the_booking_calendar(): void
    {
        [$agency, $owner, $type] = $this->agency();
        $booking = route('public.booking.show', $type->public_booking_uuid);
        // Even a stale answer saved while the Agency sold online ("signup", "pricing") cannot send anyone elsewhere.
        $this->save($agency, $owner, [
            'x.goal' => 'book_call', 'x.booking_type_uid' => $type->public_booking_uuid,
            'x.cta.hero' => 'signup', 'x.cta.final' => 'pricing', 'x.cta.product' => 'signup', 'x.cta.solutions' => 'pricing', 'x.cta.how' => 'signup',
            'copy.home.hero.primary' => 'Get started today',
        ] + $this->legal());
        // The editor refuses to publish a stale "signup" button on a call-only site...
        $this->authenticateAs($owner);
        $this->postJson(route('customer.workspaces.agency.site.publish', ['workspaceUid' => $agency->uid]))->assertStatus(422);

        // ...and the page itself never renders one: every button is the calendar.
        $home = $this->get(route('customer.workspaces.agency.site.preview', [$agency->uid, 'home']))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<a href="' . preg_quote($booking, '#') . '" class="btn btn-p hero-btn">Book a call#', $home);
        $this->assertDoesNotMatchRegularExpression('/Get started today/', $home);
        $this->assertStringNotContainsString('/signup', $home);
        $this->assertStringNotContainsString('/pricing', $home);
    }

    public function test_direct_requests_for_pricing_and_signup_cannot_bypass_call_only_mode(): void
    {
        [$agency, $owner, $type, $plan] = $this->agency();
        $booking = route('public.booking.show', $type->public_booking_uuid);
        $site = $this->save($agency, $owner, ['x.goal' => 'both', 'x.booking_type_uid' => $type->public_booking_uuid] + $this->legal());
        $this->publish($agency, $owner);

        // Self-service first, to prove these URLs really worked before the switch.
        $this->get(route('agency.site.page', [$site->public_id, 'pricing']))->assertOk()->assertSee('Studio');
        $this->get(route('agency.site.signup', [$site->public_id, $plan->uid]))->assertOk();

        $this->save($agency, $owner, ['x.goal' => 'book_call']);
        $this->publish($agency, $owner);

        $this->get(route('agency.site.page', [$site->public_id, 'pricing']))->assertRedirect($booking);
        $this->get(route('agency.site.signup', [$site->public_id, $plan->uid]))->assertNotFound();
        $this->post(route('agency.site.signup.store', [$site->public_id, $plan->uid]), [
            'first_name' => 'Sam', 'email' => 'sam@example.test', 'password' => 'password-123', 'password_confirmation' => 'password-123',
            'business_name' => 'Sam Dogs', 'accept' => '1',
        ])->assertNotFound();

        // Existing customers still reach the login.
        $this->get(route('agency.site.login', $site->public_id))->assertOk();
    }

    public function test_the_draft_preview_in_call_only_mode_redirects_pricing_and_shows_no_prices(): void
    {
        [$agency, $owner, $type] = $this->agency();
        $this->save($agency, $owner, ['x.goal' => 'book_call', 'x.booking_type_uid' => $type->public_booking_uuid]);
        $this->authenticateAs($owner);

        $this->get(route('customer.workspaces.agency.site.preview', [$agency->uid, 'pricing']))
            ->assertRedirect(route('public.booking.show', $type->public_booking_uuid));

        $home = $this->get(route('customer.workspaces.agency.site.preview', [$agency->uid, 'home']))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/\$49|Compare plans|See pricing/i', $home);
    }

    // ------------------------------------------------------------ editor: block and warn

    public function test_call_only_without_a_working_calendar_is_flagged_and_blocks_publishing(): void
    {
        [$agency, $owner] = $this->agency();
        $this->save($agency, $owner, ['x.goal' => 'book_call'] + $this->legal());
        $this->authenticateAs($owner);

        $response = $this->postJson(route('customer.workspaces.agency.site.publish', ['workspaceUid' => $agency->uid]));
        $response->assertStatus(422);
        $this->assertStringContainsString('booking calendar', json_encode($response->json()));
        $this->assertNotSame('published', AgencySite::query()->where('agency_workspace_id', $agency->id)->value('status'));
    }

    public function test_switching_the_selling_mode_never_overwrites_other_saved_content(): void
    {
        [$agency, $owner, $type] = $this->agency();
        $site = $this->save($agency, $owner, [
            'x.goal' => 'both', 'x.booking_type_uid' => $type->public_booking_uuid,
            'copy.home.hero.title' => 'Our own headline.', 'nav.product' => 'Features', 'links.privacy' => 'https://northwind.example/privacy',
        ]);

        foreach (['book_call', 'subscriptions', 'both'] as $mode) {
            $this->save($agency, $owner, ['x.goal' => $mode]);
            $values = AgencySiteManager::values($site->fresh());
            $this->assertSame($mode, $values['x.goal']);
            $this->assertSame('Our own headline.', $values['copy.home.hero.title']);
            $this->assertSame('Features', $values['nav.product']);
            $this->assertSame('https://northwind.example/privacy', $values['links.privacy']);
            $this->assertSame($type->public_booking_uuid, $values['x.booking_type_uid']);
        }
    }

    // ------------------------------------------------------------ self-service keeps working

    public function test_self_service_mode_shows_real_pricing_and_signup(): void
    {
        [$agency, $owner, $type, $plan] = $this->agency();
        $site = $this->save($agency, $owner, ['x.goal' => 'subscriptions', 'x.booking_type_uid' => $type->public_booking_uuid] + $this->legal());
        $this->publish($agency, $owner);

        $pricing = $this->get(route('agency.site.page', [$site->public_id, 'pricing']))->assertOk()->getContent();
        $this->assertStringContainsString('Studio', $pricing);
        $this->assertStringContainsString('$49', $pricing);
        $this->assertStringContainsString(route('agency.site.signup', [$site->public_id, $plan->uid]), $pricing);

        $home = $this->get(route('agency.site.home', $site->public_id))->getContent();
        $this->assertStringContainsString(route('agency.site.page', [$site->public_id, 'pricing']), $home, 'pricing is in the navigation');
        $this->assertStringContainsString('Get started', $home);

        // A self-service site that also offers calls keeps the original "Book a demo" wording exactly.
        $this->save($agency, $owner, ['x.goal' => 'both']);
        $this->publish($agency, $owner);
        $this->assertStringContainsString('Book a demo', $this->get(route('agency.site.page', [$site->public_id, 'solutions']))->getContent());
    }

    // ------------------------------------------------------------ isolation

    public function test_one_agencys_mode_never_changes_another_agency(): void
    {
        [$a, $ownerA, $typeA] = $this->agency('Agency A');
        [$b, $ownerB, $typeB] = $this->agency('Agency B', '#0a7d3b');
        $siteA = $this->save($a, $ownerA, ['x.goal' => 'book_call', 'x.booking_type_uid' => $typeA->public_booking_uuid, 'x.niche' => 'dog_business'] + $this->legal());
        $siteB = $this->save($b, $ownerB, ['x.goal' => 'subscriptions', 'x.booking_type_uid' => $typeB->public_booking_uuid] + $this->legal());
        $this->publish($a, $ownerA);
        $this->publish($b, $ownerB);

        $this->get(route('agency.site.page', [$siteA->public_id, 'pricing']))->assertRedirect();
        $this->get(route('agency.site.page', [$siteB->public_id, 'pricing']))->assertOk();

        $homeB = $this->get(route('agency.site.home', $siteB->public_id))->getContent();
        $this->assertStringContainsString('Lumen Events', $homeB, 'the other Agency keeps the shared illustration examples');
        $this->assertStringNotContainsString('Maple Ridge', $homeB);
        $this->assertStringNotContainsString('Agency A Digital', $homeB);
    }

    // ------------------------------------------------------------ draft preview stylesheet

    public function test_the_draft_preview_stylesheet_works_before_anything_is_published(): void
    {
        [$agency, $owner] = $this->agency('Agency A', '#0a7d3b');
        [$other, $otherOwner] = $this->agency('Agency B');
        $this->save($agency, $owner, []);
        $this->save($other, $otherOwner, []);
        $this->authenticateAs($owner);

        $home = $this->get(route('customer.workspaces.agency.site.preview', [$agency->uid, 'home']))->assertOk()->getContent();
        $css = route('customer.workspaces.agency.site.preview-css', [$agency->uid]);
        $this->assertStringContainsString($css, $home, 'the preview links its own stylesheet, not the published one');
        $this->assertStringNotContainsString('/css/marketing.css', str_replace('/preview-css/marketing.css', '', $home));

        $response = $this->get($css)->assertOk();
        $this->assertStringContainsString('text/css', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('#0a7d3b', $response->getContent());
        $this->assertStringNotContainsString('#B4514A', $response->getContent());

        // Another Agency's team, and guests, cannot read it.
        $this->authenticateAs($otherOwner);
        $this->get($css)->assertNotFound();
        auth()->logout();
        $this->assertContains($this->get($css)->getStatusCode(), [302, 401, 403], 'guests cannot read the draft stylesheet');
    }

    // ------------------------------------------------------------ dog niche

    public function test_the_dog_niche_uses_dog_examples_and_no_event_or_photo_booth_ones(): void
    {
        [$agency, $owner, $type] = $this->agency();
        $site = $this->save($agency, $owner, ['x.goal' => 'book_call', 'x.niche' => 'dog_business', 'x.booking_type_uid' => $type->public_booking_uuid] + $this->story() + $this->legal());
        $this->publish($agency, $owner);

        $all = '';
        foreach (self::PAGES as $page) {
            $all .= $this->get(route($page === 'home' ? 'agency.site.home' : 'agency.site.page', $page === 'home' ? [$site->public_id] : [$site->public_id, $page]))->assertOk()->getContent();
        }

        $this->assertStringContainsString('More clients. More bookings.', $all);
        $this->assertStringContainsString('built for dog training and boarding businesses', $all);
        $this->assertStringContainsString('dog training near me', $all);
        $this->assertStringContainsString('Puppy training', $all);
        $this->assertDoesNotMatchRegularExpression('/wedding|photo ?booth|booth|Lumen Events|Riverside Gala|Corporate events|Event services|Birthday/i', $all);
        $this->assertDoesNotMatchRegularExpression('/kennel management|vaccination|medical record/i', $all);
    }

    public function test_a_niche_never_overwrites_wording_the_agency_already_edited(): void
    {
        [$agency, $owner, $type] = $this->agency();
        $site = $this->save($agency, $owner, ['x.goal' => 'both', 'copy.home.hero.title' => 'Our own words.', 'x.booking_type_uid' => $type->public_booking_uuid]);
        $this->save($agency, $owner, ['x.niche' => 'dog_business']);
        $this->authenticateAs($owner);

        $home = $this->get(route('customer.workspaces.agency.site.preview', [$agency->uid, 'home']))->assertOk()->getContent();
        $this->assertStringContainsString('Our own words.', $home);
        $this->assertStringContainsString('Maple Ridge Dog Training', $home);
        $this->assertSame('Our own words.', AgencySiteManager::values($site->fresh())['copy.home.hero.title']);
    }
}

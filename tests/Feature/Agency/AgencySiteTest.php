<?php

namespace Tests\Feature\Agency;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\AgencySite\AgencySiteBookingService;
use App\Library\AgencySite\AgencySiteManager;
use App\Library\Branding\AgencyWhiteLabelManager;
use App\Models\AgencySite;
use App\Models\AgencyWhiteLabelSetting;
use App\Models\BookingType;
use App\Models\Customer;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Feature\AgencyBilling\Concerns\CreatesAgencySaasFixtures;
use Tests\TestCase;

/**
 * White Label — Landing Page: the starter site, the editor's saves, draft vs published, hiding / reordering,
 * booking CTAs and plan presentation. Signup, payment and tenancy live in AgencySiteSignupTest.
 */
class AgencySiteTest extends TestCase
{
    use CreatesAgencySaasFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdminId();
        $this->ensureRequiredAppConfigRowsExist();
        $this->bindFakeAgencyStripe();
    }

    /** @return array{0: Workspace, 1: Customer} */
    protected function agency(string $name = 'Northwind Agency'): array
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency, $name . ' Business', $name);

        // A real agency Business has its primary location from onboarding; the calendar needs one to hold booking types.
        app(\App\Library\Business\BusinessLocationManager::class)->upsertPrimaryLocation($business, ['service_mode' => 'storefront', 'country_code' => 'US']);

        return [$workspace, $owner];
    }

    protected function brand(Workspace $agency, Customer $owner, string $name = 'Northwind Digital', string $color = '#1a73e8'): AgencyWhiteLabelSetting
    {
        return app(AgencyWhiteLabelManager::class)->save((int) $owner->user_id, $agency, [
            'display_name' => $name, 'accent_color' => $color, 'support_email' => 'help@northwind.example', 'is_enabled' => '1',
        ]);
    }

    protected function url(string $name, Workspace $agency, array $extra = []): string
    {
        return route('customer.workspaces.agency.site.' . $name, ['workspaceUid' => $agency->uid] + $extra);
    }

    protected function sites(): AgencySiteManager
    {
        return app(AgencySiteManager::class);
    }

    /** Opens the Landing Page tab as the owner, which creates the starter draft. */
    protected function openEditor(Workspace $agency, Customer $owner): AgencySite
    {
        $this->authenticateAs($owner);
        $this->get(route('customer.workspaces.agency.white-label.show', ['workspaceUid' => $agency->uid, 'tab' => 'landing']))->assertOk();

        return AgencySite::query()->where('agency_workspace_id', $agency->id)->sole();
    }

    protected function save(Workspace $agency, array $answers, int $step = 2)
    {
        return $this->postJson($this->url('save', $agency), ['answers' => $answers, 'step' => $step]);
    }

    /** A booking type the owner can be booked on, created through the editor's own service. */
    protected function bookableType(Workspace $agency, Customer $owner, string $name = 'Intro call'): BookingType
    {
        return app(AgencySiteBookingService::class)->create((int) $owner->user_id, $agency, [
            'name' => $name, 'duration_minutes' => 30, 'default_hours' => true,
        ]);
    }

    // ------------------------------------------------------------------ starter site

    public function test_opening_the_landing_tab_generates_a_complete_branded_starter_site(): void
    {
        [$agency, $owner] = $this->agency();
        $this->brand($agency, $owner);
        $site = $this->openEditor($agency, $owner);

        $this->assertSame('draft', $site->status);

        foreach (['home', 'product', 'solutions', 'how-it-works', 'pricing'] as $page) {
            $html = $this->get($this->url('preview', $agency, ['page' => $page]))->assertOk()->getContent();
            $this->assertStringContainsString('Northwind Digital', $html, "{$page} carries the agency's name");
            $this->assertStringNotContainsString('MotionGrove', $html, "{$page} must not carry the platform's name");
            $this->assertStringNotContainsString('Jazmin', $html);
            $this->assertStringContainsStringIgnoringCase('#1a73e8', $html . $this->get(route('customer.workspaces.agency.site.preview', [$agency->uid, 'home']))->getContent(), "the agency's colour is applied");
            $this->assertStringNotContainsStringIgnoringCase('#B4514A', $html, "{$page} must not keep the original brand colour");
        }

        // Customers has no approved story, so the page does not exist yet.
        $this->get($this->url('preview', $agency, ['page' => 'customers']))->assertNotFound();
    }

    public function test_the_starter_site_makes_no_promises_prices_or_results_on_the_agencys_behalf(): void
    {
        [$agency, $owner] = $this->agency();
        $this->brand($agency, $owner);
        $this->openEditor($agency, $owner);

        $all = '';
        foreach (['home', 'product', 'solutions', 'how-it-works', 'pricing'] as $page) {
            $all .= $this->get($this->url('preview', $agency, ['page' => $page]))->getContent();
        }

        foreach (['$97', '$297', '$497', '28 days', 'real people', 'Real people', 'guided setup', 'Guided setup', 'no additional implementation fees', 'Most popular', 'Customer stories are coming soon'] as $claim) {
            $this->assertStringNotContainsString($claim, $all, "starter copy must not contain: {$claim}");
        }
    }

    public function test_the_preview_is_only_for_the_agencys_own_team(): void
    {
        [$agency, $owner] = $this->agency('Agency A');
        [$other, $otherOwner] = $this->agency('Agency B');
        $this->openEditor($agency, $owner);

        $this->authenticateAs($otherOwner);
        $this->get($this->url('preview', $agency, ['page' => 'home']))->assertNotFound();
        $this->postJson($this->url('save', $agency), ['answers' => ['copy.home.hero.title' => 'Hijack']])->assertNotFound();
        $this->postJson($this->url('publish', $agency))->assertNotFound();

        auth()->logout();
        $this->get($this->url('preview', $agency, ['page' => 'home']))->assertNotFound();
    }

    // -------------------------------------------------------------- editing

    public function test_answers_are_validated_and_html_or_unknown_settings_are_refused(): void
    {
        [$agency, $owner] = $this->agency();
        $this->openEditor($agency, $owner);

        $this->save($agency, ['copy.home.hero.title' => '<script>alert(1)</script>'])->assertStatus(422);
        $this->save($agency, ['copy.home.hero.title' => str_repeat('x', 200)])->assertStatus(422);
        $this->save($agency, ['config.app.key' => 'x'])->assertStatus(422);
        $this->save($agency, ['links.privacy' => 'http://insecure.example/privacy'])->assertStatus(422);
        $this->save($agency, ['links.privacy' => 'javascript:alert(1)'])->assertStatus(422);
        $this->save($agency, ['x.cta.hero' => 'somewhere-else'])->assertStatus(422);
        $this->save($agency, ['x.home_hidden' => ['not-a-section']])->assertStatus(422);

        $this->assertArrayNotHasKey('copy.home.hero.title', AgencySiteManager::values(AgencySite::query()->sole()));
    }

    public function test_a_saved_answer_changes_the_preview_and_reset_returns_the_default(): void
    {
        [$agency, $owner] = $this->agency();
        $this->openEditor($agency, $owner);

        $this->save($agency, ['copy.home.hero.title' => 'Book more clients.'])->assertOk()->assertJson(['ok' => true]);
        $this->assertStringContainsString('Book more clients.', $this->get($this->url('preview', $agency, ['page' => 'home']))->getContent());

        $this->postJson($this->url('reset-group', $agency), ['group' => 'hero'])->assertOk();
        $html = $this->get($this->url('preview', $agency, ['page' => 'home']))->getContent();
        $this->assertStringNotContainsString('Book more clients.', $html);
        $this->assertStringContainsString('More bookings.', $html);
    }

    public function test_sections_can_be_hidden_and_reordered_and_the_two_sections_that_make_promises_start_hidden(): void
    {
        [$agency, $owner] = $this->agency();
        $this->openEditor($agency, $owner);
        $home = fn () => $this->get($this->url('preview', $agency, ['page' => 'home']))->getContent();

        $html = $home();
        $this->assertStringContainsString('The problem', $html);
        $this->assertStringNotContainsString('Help when you need it.', $html, 'support section starts hidden');
        $this->assertStringNotContainsString('What to expect when you sign up.', $html, 'commitment section starts hidden');

        $this->save($agency, ['x.home_hidden' => ['problem']])->assertOk();
        $this->assertStringNotContainsString('The problem', $home());

        $this->save($agency, ['x.home_hidden' => [], 'x.home_shown' => ['people']])->assertOk();
        $this->assertStringContainsString('Help when you need it.', $home());

        $order = ['hero', 'faq', 'problem', 'trust', 'system', 'capabilities', 'people', 'how', 'videos', 'comparison', 'pricing', 'commitment', 'final_cta'];
        $this->save($agency, ['x.home_order' => $order])->assertOk();
        $html = $home();
        $this->assertLessThan(strpos($html, 'The problem'), strpos($html, 'Questions, answered.'), 'FAQ now renders before the problems');
    }

    public function test_product_features_can_be_renamed_hidden_reordered_and_the_featured_one_shows_first(): void
    {
        [$agency, $owner] = $this->agency();
        $this->openEditor($agency, $owner);
        $page = fn () => $this->get($this->url('preview', $agency, ['page' => 'product']))->getContent();

        $this->save($agency, ['product_features.crm.title' => 'Every customer, one record.', 'x.product_hidden' => ['auto'], 'x.featured_feature' => 'ai'], 3)->assertOk();
        $html = $page();

        $this->assertStringContainsString('Every customer, one record.', $html);
        $this->assertStringNotContainsString('id="auto"', $html);
        $this->assertLessThan(strpos($html, 'id="website"'), strpos($html, 'id="ai"'), 'featured capability is first');
        $this->assertStringNotContainsString('id="m3"', $html);
    }

    public function test_cta_destinations_always_lead_to_a_working_page_and_the_nav_can_be_renamed(): void
    {
        [$agency, $owner] = $this->agency();
        $this->openEditor($agency, $owner);

        $this->save($agency, ['x.cta.hero' => 'booking', 'nav.pricing' => 'Plans'])->assertOk();
        $html = $this->get($this->url('preview', $agency, ['page' => 'home']))->getContent();

        // No booking type exists, so a booking CTA falls back to pricing instead of a dead link.
        $this->assertStringContainsString('>Plans<', $html);
        $this->assertStringNotContainsString('href=""', $html);
        $this->assertStringNotContainsString('href="#"', $html);
    }

    public function test_mobile_navigation_is_present_and_lists_every_visible_page(): void
    {
        [$agency, $owner] = $this->agency();
        $this->openEditor($agency, $owner);
        $html = $this->get($this->url('preview', $agency, ['page' => 'home']))->getContent();

        $this->assertStringContainsString('<details class="mg-menu show-m"', $html);
        $this->assertStringContainsString('aria-label="Open menu"', $html);
        $menu = substr($html, strpos($html, '<details class="mg-menu'));
        foreach (['Product', 'Solutions', 'How it works', 'Pricing'] as $label) {
            $this->assertStringContainsString('>' . $label . '</a>', substr($menu, 0, 2500));
        }
        $this->assertStringNotContainsString('>Customers</a>', substr($menu, 0, 2500), 'no approved stories => no Customers link');
    }

    // ------------------------------------------------------- draft vs published

    public function test_draft_changes_never_reach_the_published_site_until_published(): void
    {
        [$agency, $owner] = $this->agency();
        $this->brand($agency, $owner);
        $site = $this->openEditor($agency, $owner);
        $this->save($agency, ['copy.home.hero.title' => 'First headline.', 'links.privacy' => 'https://northwind.example/privacy', 'links.terms' => 'https://northwind.example/terms', 'x.goal' => 'subscriptions'])->assertOk();

        // Subscriptions without a sellable plan cannot be published.
        $this->postJson($this->url('publish', $agency))->assertStatus(422)->assertJsonPath('ok', false);
        $this->get(route('agency.site.home', $site->public_id))->assertNotFound();

        $this->save($agency, ['x.goal' => 'both', 'x.pricing_mode' => 'both']);
        $uuid = $this->bookableType($agency, $owner)->public_booking_uuid;
        $this->save($agency, ['x.booking_type_uid' => $uuid, 'x.goal' => 'book_call', 'x.pricing_mode' => 'book_call'])->assertOk();
        $this->postJson($this->url('publish', $agency))->assertOk()->assertJsonPath('isLive', true);

        $live = fn () => $this->get(route('agency.site.home', $site->public_id))->assertOk()->getContent();
        $this->assertStringContainsString('First headline.', $live());

        $this->save($agency, ['copy.home.hero.title' => 'Second headline.'])->assertOk();
        $this->assertStringContainsString('First headline.', $live());
        $this->assertStringNotContainsString('Second headline.', $live());
        $this->assertTrue($this->sites()->hasUnpublishedChanges($site->refresh()));

        $this->postJson($this->url('publish', $agency))->assertOk();
        $this->assertStringContainsString('Second headline.', $live());
        $this->assertSame(2, $site->revisions()->count());

        $this->postJson($this->url('unpublish', $agency))->assertOk();
        $this->get(route('agency.site.home', $site->public_id))->assertNotFound();
    }

    public function test_an_ineligible_or_unentitled_agency_does_not_keep_serving_a_live_site(): void
    {
        [$agency, $owner] = $this->agency();
        $this->brand($agency, $owner);
        $site = $this->openEditor($agency, $owner);
        $uuid = $this->bookableType($agency, $owner)->public_booking_uuid;
        $this->save($agency, ['x.booking_type_uid' => $uuid, 'x.goal' => 'book_call', 'x.pricing_mode' => 'book_call']);
        $this->postJson($this->url('publish', $agency))->assertOk();
        $this->get(route('agency.site.home', $site->public_id))->assertOk();

        Workspace::query()->whereKey($agency->id)->update(['is_active' => false]);
        $this->get(route('agency.site.home', $site->public_id))->assertNotFound();
    }

    public function test_unknown_ids_pages_and_unpublished_sites_are_the_same_plain_404(): void
    {
        $this->get(route('agency.site.home', '00000000-0000-4000-8000-000000000000'))->assertNotFound();
        [$agency, $owner] = $this->agency();
        $site = $this->openEditor($agency, $owner);
        $this->get(route('agency.site.page', [$site->public_id, 'pricing']))->assertNotFound();
        $this->get('/w/' . $site->public_id . '/not-a-page')->assertNotFound();
    }

    public function test_the_marketing_stylesheet_is_served_in_the_agencys_colour_only_for_a_live_site(): void
    {
        [$agency, $owner] = $this->agency();
        $this->brand($agency, $owner, 'Northwind Digital', '#0a7d3b');
        $site = $this->openEditor($agency, $owner);
        $this->get(route('agency.site.css', $site->public_id))->assertNotFound();

        $uuid = $this->bookableType($agency, $owner)->public_booking_uuid;
        $this->save($agency, ['x.booking_type_uid' => $uuid, 'x.goal' => 'book_call', 'x.pricing_mode' => 'book_call']);
        $this->postJson($this->url('publish', $agency))->assertOk();

        $css = $this->get(route('agency.site.css', $site->public_id))->assertOk()->assertHeader('Content-Type', 'text/css; charset=UTF-8')->getContent();
        $this->assertStringContainsString('#0a7d3b', $css);
        $this->assertStringNotContainsString('#B4514A', $css);
    }

    // ---------------------------------------------------------- testimonials

    public function test_only_approved_real_testimonials_are_shown_and_none_means_no_customers_page(): void
    {
        [$agency, $owner] = $this->agency();
        $this->openEditor($agency, $owner);

        $this->save($agency, ['x.testimonials' => [['customer_name' => 'Dana', 'business_name' => 'Dana Photo', 'quote' => 'Great software.', 'approved' => false]]], 6)->assertOk();
        $this->get($this->url('preview', $agency, ['page' => 'customers']))->assertNotFound();

        $this->save($agency, ['x.testimonials' => [['customer_name' => 'Dana', 'business_name' => 'Dana Photo', 'quote' => 'Great software.', 'approved' => true]]], 6)->assertOk();
        $html = $this->get($this->url('preview', $agency, ['page' => 'customers']))->assertOk()->getContent();
        $this->assertStringContainsString('Great software.', $html);
        $this->assertStringContainsString('Dana', $html);
        $this->assertStringNotContainsString('Jazmin', $html);
        $this->assertStringNotContainsString('MotionGrove', $html);

        $this->save($agency, ['x.testimonials' => [['customer_name' => 'Dana', 'video' => 'https://evil.example/watch?v=abcdefghijk', 'approved' => true]]], 6)->assertStatus(422);
    }

    // ----------------------------------------------------------------- booking

    public function test_booking_types_can_be_created_inline_selected_and_drive_every_booking_button(): void
    {
        [$agency, $owner] = $this->agency();
        $this->brand($agency, $owner);
        $this->openEditor($agency, $owner);

        $response = $this->postJson($this->url('booking-type', $agency), [
            'name' => 'Discovery call', 'duration_minutes' => 20, 'meeting_location_type' => 'phone', 'default_hours' => 1,
        ])->assertOk()->assertJsonPath('booking.readiness.ready', true);

        $type = BookingType::query()->where('name', 'Discovery call')->sole();
        $this->assertSame((string) $type->public_booking_uuid, AgencySiteManager::values(AgencySite::query()->sole())['x.booking_type_uid']);
        $response->assertJsonPath('booking.url', route('public.booking.show', $type->public_booking_uuid));

        $html = $this->get($this->url('preview', $agency, ['page' => 'home']))->getContent();
        $this->assertStringContainsString('href="' . route('public.booking.show', $type->public_booking_uuid) . '"', $html);
        $this->assertStringContainsString('Book a demo', $html);

        // The appointment page is the platform's own: it really resolves.
        $this->get(route('public.booking.show', $type->public_booking_uuid))->assertOk();
    }

    public function test_an_inactive_or_foreign_booking_type_is_never_advertised(): void
    {
        [$agency, $owner] = $this->agency('Agency A');
        [$other, $otherOwner] = $this->agency('Agency B');
        $mine = $this->bookableType($agency, $owner, 'Mine');
        $theirs = $this->bookableType($other, $otherOwner, 'Theirs');
        $this->openEditor($agency, $owner);

        // Another Agency's booking type cannot be selected.
        $this->save($agency, ['x.booking_type_uid' => $theirs->public_booking_uuid], 8)->assertOk();
        $html = $this->get($this->url('preview', $agency, ['page' => 'home']))->getContent();
        $this->assertStringNotContainsString((string) $theirs->public_booking_uuid, $html);
        $this->assertStringNotContainsString('Book a demo', $html);

        $this->save($agency, ['x.booking_type_uid' => $mine->public_booking_uuid], 8)->assertOk();
        $this->assertStringContainsString((string) $mine->public_booking_uuid, $this->get($this->url('preview', $agency, ['page' => 'home']))->getContent());

        // Deactivating it removes the button and the editor says why.
        app(\App\Library\Calendar\BookingTypeManager::class)->setActive($mine, false);
        $state = $this->postJson($this->url('step', $agency), ['step' => 8])->assertOk()->json();
        $this->assertFalse($state['booking']['readiness']['ready']);
        $this->assertStringNotContainsString((string) $mine->public_booking_uuid, $this->get($this->url('preview', $agency, ['page' => 'home']))->getContent());

        $failing = collect($state['checklist'])->firstWhere('key', 'booking');
        $this->assertSame('fail', $failing['status']);
    }

    public function test_only_the_owner_can_create_booking_types_from_the_editor(): void
    {
        [$agency, $owner] = $this->agency();
        $this->openEditor($agency, $owner);
        $member = $this->createCustomer();
        $this->member($agency, $member->user, \App\Enums\Workspace\WorkspaceMembershipRole::Admin);
        $this->authenticateAs($member);

        $this->postJson($this->url('booking-type', $agency), ['name' => 'Sneaky', 'duration_minutes' => 30])->assertStatus(403);
        $this->assertSame(0, BookingType::query()->where('name', 'Sneaky')->count());
    }

    // ------------------------------------------------------------------ plans

    public function test_published_plans_show_the_plans_own_price_and_presentation_cannot_change_it(): void
    {
        [$agency, $owner] = $this->agency();
        $this->brand($agency, $owner);
        $this->connectAgencyStripe($agency, (int) $owner->user_id);
        $plan = $this->publishedPlan($agency, (int) $owner->user_id, WorkspacePlanTier::Growth, '49.00', null, 'Studio');
        $draft = $this->plans()->create((int) $owner->user_id, $agency, ['name' => 'Hidden Draft', 'tier' => 'core', 'price' => '9.00', 'currency_id' => $this->fixtureCurrencyId(), 'currency_code' => 'USD', 'billing_cycle' => 'monthly']);
        $this->openEditor($agency, $owner);

        $this->save($agency, ['x.plans' => [['uid' => $plan->uid, 'visible' => true, 'featured' => true, 'badge' => 'Best value', 'cta' => 'Join now', 'tagline' => 'For studios', 'features' => ['One', 'Two'], 'price' => '0.01', 'tier' => 'agency']]], 7)->assertOk();
        $html = $this->get($this->url('preview', $agency, ['page' => 'pricing']))->assertOk()->getContent();

        $this->assertStringContainsString('Studio', $html);
        $this->assertStringContainsString('$49', $html);
        $this->assertStringNotContainsString('$0.01', $html);
        $this->assertStringContainsString('Best value', $html);
        $this->assertStringContainsString('Join now', $html);
        $this->assertStringContainsString('For studios', $html);
        $this->assertStringNotContainsString('Hidden Draft', $html);
        $this->assertStringContainsString('/ month', $html);
        $this->assertStringContainsString('href="' . route('agency.site.signup', [AgencySite::query()->sole()->public_id, $plan->uid]) . '"', $html);

        // Marketing wording never grants anything: the plan's tier is the plan's, untouched.
        $this->assertSame('growth', $plan->refresh()->tier->value);
        $this->assertSame('49.00', $plan->price);
    }

    public function test_book_a_call_mode_hides_prices_and_points_plans_at_the_calendar(): void
    {
        [$agency, $owner] = $this->agency();
        $this->connectAgencyStripe($agency, (int) $owner->user_id);
        $this->publishedPlan($agency, (int) $owner->user_id, WorkspacePlanTier::Core, '39.00', null, 'Starter');
        $type = $this->bookableType($agency, $owner);
        $this->openEditor($agency, $owner);
        $this->save($agency, ['x.pricing_mode' => 'book_call', 'x.goal' => 'book_call', 'x.booking_type_uid' => $type->public_booking_uuid], 7)->assertOk();

        $html = $this->get($this->url('preview', $agency, ['page' => 'pricing']))->assertOk()->getContent();
        $this->assertStringNotContainsString('$39', $html);
        $this->assertStringContainsString('Starter', $html);
        $this->assertStringContainsString(route('public.booking.show', $type->public_booking_uuid), $html);
        $this->assertStringNotContainsString('/signup/', $html);
    }

    public function test_the_comparison_table_reflects_what_each_plans_tier_really_includes(): void
    {
        [$agency, $owner] = $this->agency();
        $this->connectAgencyStripe($agency, (int) $owner->user_id);
        $this->publishedPlan($agency, (int) $owner->user_id, WorkspacePlanTier::Core, '39.00', null, 'Starter');
        $this->publishedPlan($agency, (int) $owner->user_id, WorkspacePlanTier::Growth, '99.00', null, 'Pro');
        $this->openEditor($agency, $owner);
        $html = $this->get($this->url('preview', $agency, ['page' => 'pricing']))->assertOk()->getContent();

        // Starter (Core) lacks SEO tools, Pro (Growth) has them — the same packaging the platform enforces.
        preg_match('#<tr><th scope="row"[^>]*>SEO tools &amp; recommendations.*?</tr>#s', $html, $row);
        $this->assertNotEmpty($row, 'the comparison row exists');
        $this->assertMatchesRegularExpression('/class="no"[^>]*>—.*?class="tick"/s', $row[0]);
        $this->assertStringNotContainsString('White-label branding', $html);
        $this->assertStringNotContainsString('Multi-account management', $html);
    }

    // ------------------------------------------- unsellable plans (Codex P2)

    private function stripeCannotCharge(): void
    {
        \App\Models\AgencyStripeConnection::query()->update(['charges_enabled' => false]);
    }

    private function pricing(Workspace $agency): string
    {
        return $this->get($this->url('preview', $agency, ['page' => 'pricing']))->assertOk()->getContent();
    }

    public function test_a_plan_that_cannot_be_bought_offers_a_working_booking_page_instead_of_a_dead_button(): void
    {
        [$agency, $owner] = $this->agency();
        $this->connectAgencyStripe($agency, (int) $owner->user_id);
        $this->publishedPlan($agency, (int) $owner->user_id, WorkspacePlanTier::Growth, '49.00', null, 'Studio');
        $type = $this->bookableType($agency, $owner);
        $this->openEditor($agency, $owner);
        $this->save($agency, ['x.booking_type_uid' => $type->public_booking_uuid], 7)->assertOk();

        $this->assertStringContainsString('/signup/', $this->pricing($agency), 'sellable: online checkout');

        $this->stripeCannotCharge();
        $html = $this->pricing($agency);
        $booking = route('public.booking.show', $type->public_booking_uuid);

        $this->assertStringNotContainsString('/signup/', $html, 'no checkout link for an unsellable plan');
        $this->assertStringContainsString('Studio', $html);
        $this->assertMatchesRegularExpression('#<a href="' . preg_quote($booking, '#') . '"[^>]*>Book a call</a>#', $html, 'the plan button is the real booking page');
        $this->assertStringNotContainsString('<a href="' . route('customer.workspaces.agency.site.preview', [$agency->uid, 'home']) . '" class="btn', $html, 'never a button to the homepage');
    }

    public function test_a_plan_with_neither_checkout_nor_booking_is_not_shown_as_an_offer(): void
    {
        [$agency, $owner] = $this->agency();
        $this->connectAgencyStripe($agency, (int) $owner->user_id);
        $this->publishedPlan($agency, (int) $owner->user_id, WorkspacePlanTier::Growth, '49.00', null, 'Studio');
        $type = $this->bookableType($agency, $owner);
        $this->openEditor($agency, $owner);
        $this->save($agency, ['x.booking_type_uid' => $type->public_booking_uuid], 7)->assertOk();

        // Stripe disconnected AND the booking type is switched off: nothing a visitor could use remains.
        $this->stripeCannotCharge();
        app(\App\Library\Calendar\BookingTypeManager::class)->setActive($type, false);

        $pricing = $this->pricing($agency);
        $this->assertStringNotContainsString('Studio', $pricing, 'the offer is hidden, not shown with a dead button');
        $this->assertStringNotContainsString('$49', $pricing);
        $this->assertStringContainsString('data-role="plans-unavailable"', $pricing);
        $this->assertStringContainsString('Plans are not available right now', $pricing);
        $this->assertStringNotContainsString('Compare plans', $pricing);
        $this->assertStringNotContainsString('Book a call', $pricing);

        $home = $this->get($this->url('preview', $agency, ['page' => 'home']))->getContent();
        $this->assertStringNotContainsString('Studio', $home);
        $this->assertStringNotContainsString('Choose the plan that fits', $home, 'the home pricing section hides with it');
        $this->assertStringNotContainsString('Book a call', $home);
        $this->assertStringNotContainsString('Book a demo', $home);
    }

    public function test_a_subscriptions_only_site_hides_an_unsellable_plan_even_if_a_calendar_exists(): void
    {
        [$agency, $owner] = $this->agency();
        $this->connectAgencyStripe($agency, (int) $owner->user_id);
        $this->publishedPlan($agency, (int) $owner->user_id, WorkspacePlanTier::Core, '19.00', null, 'Basic');
        $type = $this->bookableType($agency, $owner);
        $this->openEditor($agency, $owner);
        // Subscriptions only: the Agency did not offer calls, so the calendar is not a fallback.
        $this->save($agency, ['x.goal' => 'subscriptions', 'x.pricing_mode' => 'subscriptions', 'x.booking_type_uid' => $type->public_booking_uuid], 7)->assertOk();

        $this->assertStringContainsString('Basic', $this->pricing($agency));

        $this->stripeCannotCharge();
        $html = $this->pricing($agency);
        $this->assertStringNotContainsString('Basic', $html);
        $this->assertStringNotContainsString(route('public.booking.show', $type->public_booking_uuid), $html);
    }

    public function test_an_unpublished_plan_and_a_working_checkout_without_any_calendar_still_behave(): void
    {
        [$agency, $owner] = $this->agency();
        $this->connectAgencyStripe($agency, (int) $owner->user_id);
        $plan = $this->publishedPlan($agency, (int) $owner->user_id, WorkspacePlanTier::Growth, '49.00', null, 'Studio');
        $this->openEditor($agency, $owner);

        // No booking type exists, but checkout works: the plan is sold normally, with no booking wording anywhere.
        $html = $this->pricing($agency);
        $this->assertStringContainsString('/signup/', $html);
        $this->assertStringNotContainsString('Book a call', $html);

        $this->plans()->unpublish((int) $owner->user_id, $plan->refresh());
        $this->assertStringNotContainsString('Studio', $this->pricing($agency));
    }

    // -------------------------------------- tier-feature packaging is never stale (Codex P2)

    public function test_a_change_to_a_tiers_packaging_shows_on_the_next_request_without_a_process_restart(): void
    {
        [$agency, $owner] = $this->agency();
        $this->connectAgencyStripe($agency, (int) $owner->user_id);
        $this->publishedPlan($agency, (int) $owner->user_id, WorkspacePlanTier::Growth, '99.00', null, 'Pro');
        $this->openEditor($agency, $owner);

        $row = function (): string {
            preg_match('#<tr><th scope="row"[^>]*>SEO tools &amp; recommendations.*?</tr>#s', $this->pricing($this->agencyForRow), $m);

            return $m[0] ?? '';
        };
        $this->agencyForRow = $agency;

        $this->assertStringContainsString('class="tick"', $row(), 'Growth includes SEO tools today');
        $catalogId = \App\Models\WorkspacePlanCatalog::query()->where('tier', 'growth')->value('id');
        $removed = \Illuminate\Support\Facades\DB::table('workspace_plan_features')->where('workspace_plan_catalog_id', $catalogId)->where('feature_key', 'seo_module')->delete();
        $this->assertSame(1, $removed);

        // The platform owner changed the package. This very PHP process served the previous request, yet the next
        // request already reflects the change: nothing about the packaging survives past its request.
        $this->assertStringNotContainsString('class="tick"', $row());

        \Illuminate\Support\Facades\DB::table('workspace_plan_features')->insert(['workspace_plan_catalog_id' => $catalogId, 'feature_key' => 'seo_module', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertStringContainsString('class="tick"', $row());
    }

    private Workspace $agencyForRow;

    // ------------------------------------------------------- one conversion mode

    private function conversion(Workspace $agency, AgencySite $site): array
    {
        return collect($this->sites()->checklist($agency, $site->fresh()))->firstWhere('key', 'conversion');
    }

    public function test_the_goal_is_the_only_conversion_mode_and_the_legacy_pricing_mode_is_folded_into_it(): void
    {
        [$agency, $owner] = $this->agency();
        $this->brand($agency, $owner);
        $site = $this->openEditor($agency, $owner);

        // The retired alias is accepted, stored as the goal, and never stored itself.
        $this->save($agency, ['x.pricing_mode' => 'book_call'])->assertOk();
        $values = AgencySiteManager::values($site->fresh());
        $this->assertSame('book_call', $values['x.goal']);
        $this->assertArrayNotHasKey('x.pricing_mode', $values);
        $this->assertArrayNotHasKey('x.pricing_mode', $site->fresh()->config['values']);

        // Agreeing values are fine; contradicting ones are refused and change nothing.
        $this->save($agency, ['x.goal' => 'subscriptions', 'x.pricing_mode' => 'subscriptions'])->assertOk();
        $this->save($agency, ['x.goal' => 'both', 'x.pricing_mode' => 'book_call'])->assertStatus(422);
        $this->assertSame('subscriptions', AgencySiteManager::values($site->fresh())['x.goal']);
        $this->save($agency, ['x.pricing_mode' => 'nonsense'])->assertStatus(422);

        // A draft stored before the change, with the two disagreeing, resolves to what visitors were shown.
        $site->forceFill(['config' => ['values' => ['x.goal' => 'both', 'x.pricing_mode' => 'book_call']]])->save();
        $context = app(\App\Library\AgencySite\SiteContextFactory::class)->make($site->fresh(), AgencySiteManager::values($site->fresh()));
        $this->assertSame('book_call', $context->goal);
        $this->assertSame($context->goal, $context->pricingMode);
    }

    public function test_the_checklist_catches_a_goal_with_no_working_way_to_convert(): void
    {
        [$agency, $owner] = $this->agency();
        $this->brand($agency, $owner);
        $site = $this->openEditor($agency, $owner);
        $links = ['links.privacy' => 'https://northwind.example/privacy', 'links.terms' => 'https://northwind.example/terms'];

        // Unavailable: nothing sellable, nothing bookable — for every goal.
        foreach (['subscriptions', 'book_call', 'both'] as $goal) {
            $this->save($agency, ['x.goal' => $goal] + $links)->assertOk();
            $this->assertSame('fail', $this->conversion($agency, $site)['status'], $goal);
            $this->postJson($this->url('publish', $agency))->assertStatus(422);
        }

        // Booking only: a bookable calendar is enough, and plans are not asked for.
        $uuid = $this->bookableType($agency, $owner)->public_booking_uuid;
        $this->save($agency, ['x.goal' => 'book_call', 'x.booking_type_uid' => $uuid])->assertOk();
        $this->assertSame('pass', $this->conversion($agency, $site)['status']);
        $this->assertNull(collect($this->sites()->checklist($agency, $site->fresh()))->firstWhere('key', 'plans'));
        $this->postJson($this->url('publish', $agency))->assertOk();

        // Subscriptions only with nothing sellable fails even though a booking type exists.
        $this->save($agency, ['x.goal' => 'subscriptions'])->assertOk();
        $this->assertSame('fail', $this->conversion($agency, $site)['status']);

        // …and passes once a plan can be bought online.
        $this->connectAgencyStripe($agency, (int) $owner->user_id);
        $this->publishedPlan($agency, (int) $owner->user_id, WorkspacePlanTier::Growth, '49.00', null, 'Studio');
        $this->assertSame('pass', $this->conversion($agency, $site)['status']);

        // Both: working subscriptions and booking.
        $this->save($agency, ['x.goal' => 'both'])->assertOk();
        $this->assertSame('pass', $this->conversion($agency, $site)['status']);
    }

    // ------------------------------------------------------- calendar availability is never overwritten

    private function ownerRules(Workspace $agency, Customer $owner): \Illuminate\Support\Collection
    {
        $location = \App\Models\Business::query()->where('workspace_id', $agency->id)->firstOrFail()->locations()->firstOrFail();

        return \App\Models\StaffAvailabilityRule::query()->where('business_location_id', $location->id)->where('staff_user_id', $owner->user_id)->orderBy('id')->get(['day_of_week', 'start_time', 'end_time']);
    }

    public function test_creating_a_booking_type_seeds_default_hours_only_when_the_owner_has_none(): void
    {
        [$agency, $owner] = $this->agency();
        $this->brand($agency, $owner);

        $this->bookableType($agency, $owner, 'First');
        $seeded = $this->ownerRules($agency, $owner);
        $this->assertCount(5, $seeded);

        // A second booking type does not add a second copy.
        $this->bookableType($agency, $owner, 'Second');
        $this->assertCount(5, $this->ownerRules($agency, $owner));
    }

    public function test_existing_availability_survives_booking_type_creation_exactly(): void
    {
        [$agency, $owner] = $this->agency();
        $this->brand($agency, $owner);
        $business = \App\Models\Business::query()->where('workspace_id', $agency->id)->firstOrFail();
        $location = $business->locations()->firstOrFail();
        $availability = app(\App\Library\Calendar\StaffAvailabilityService::class);

        // The owner already runs a split shift on Tuesday and a Saturday morning for their existing services.
        $uid = (int) $owner->user_id;
        $availability->createRule($agency, $business, $location, $uid, 2, '08:00:00', '12:00:00', $uid);
        $availability->createRule($agency, $business, $location, $uid, 2, '14:00:00', '18:00:00', $uid);
        $availability->createRule($agency, $business, $location, $uid, 6, '10:00:00', '12:00:00', $uid);
        $before = $this->ownerRules($agency, $owner)->toArray();

        $this->bookableType($agency, $owner, 'Intro call');
        $this->bookableType($agency, $owner, 'Strategy call');

        $this->assertSame($before, $this->ownerRules($agency, $owner)->toArray(), 'the owner\'s schedule is untouched');
    }

    // ------------------------------------------------------- publishing validates the committed draft

    public function test_publishing_is_refused_when_another_session_has_since_broken_the_draft(): void
    {
        [$agency, $owner] = $this->agency();
        $this->brand($agency, $owner);
        $this->openEditor($agency, $owner);
        $uuid = $this->bookableType($agency, $owner)->public_booking_uuid;
        $this->save($agency, ['x.goal' => 'both', 'x.booking_type_uid' => $uuid, 'links.privacy' => 'https://northwind.example/privacy', 'links.terms' => 'https://northwind.example/terms']);
        $this->connectAgencyStripe($agency, (int) $owner->user_id);
        $this->publishedPlan($agency, (int) $owner->user_id, WorkspacePlanTier::Growth, '49.00', null, 'Studio');
        $this->assertSame('pass', collect($this->sites()->checklist($agency, AgencySite::query()->sole()))->firstWhere('key', 'conversion')['status']);

        // Session B removes the legal links after session A's editor last looked.
        $this->save($agency, ['links.privacy' => null])->assertOk();

        $this->postJson($this->url('publish', $agency))->assertStatus(422)->assertJsonPath('ok', false);
        $this->assertFalse(AgencySite::query()->sole()->isLive());
        $this->assertSame(0, \App\Models\AgencySiteRevision::query()->count());

        // Invalid conversion settings are caught the same way.
        $this->save($agency, ['links.privacy' => 'https://northwind.example/privacy', 'x.goal' => 'book_call', 'x.booking_type_uid' => null])->assertOk();
        $this->postJson($this->url('publish', $agency))->assertStatus(422);
        $this->assertSame(0, \App\Models\AgencySiteRevision::query()->count());
    }

    public function test_what_is_published_is_exactly_the_draft_that_passed_validation_even_if_a_write_lands_during_publication(): void
    {
        [$agency, $owner] = $this->agency();
        $this->brand($agency, $owner);
        $this->openEditor($agency, $owner);
        $uuid = $this->bookableType($agency, $owner)->public_booking_uuid;
        $this->save($agency, ['x.goal' => 'book_call', 'x.booking_type_uid' => $uuid, 'copy.home.hero.title' => 'Validated headline.'])->assertOk();

        // A write that slips in at the worst moment: after validation, before the revision is stored.
        $armed = true;
        \App\Models\AgencySiteRevision::creating(function () use (&$armed): void {
            if ($armed) {
                $armed = false;
                $site = AgencySite::query()->sole();
                $config = $site->config;
                $config['values']['x.booking_type_uid'] = null;
                \Illuminate\Support\Facades\DB::table('agency_sites')->where('id', $site->id)->update(['config' => json_encode($config)]);
            }
        });

        $this->sites()->publish((int) $owner->user_id, $agency);
        $armed = false;

        $revision = \App\Models\AgencySiteRevision::query()->sole();
        $this->assertSame($uuid, $revision->config['values']['x.booking_type_uid'], 'the published revision is the validated draft');
        $this->assertSame('Validated headline.', $revision->config['values']['copy.home.hero.title']);
    }

    public function test_publishing_with_no_site_is_blocked_not_crashed(): void
    {
        [$agency, $owner] = $this->agency();
        $this->authenticateAs($owner);
        $this->postJson($this->url('publish', $agency))->assertStatus(422);
    }

    // ------------------------------------------------------- inline booking type: authorized and atomic

    private function bookingTypeCount(): int
    {
        return BookingType::query()->count();
    }

    public function test_inline_booking_type_creation_needs_white_label_and_changes_nothing_without_it(): void
    {
        [$agency, $owner] = $this->agency();
        $this->brand($agency, $owner);
        $this->openEditor($agency, $owner);
        $before = [$this->bookingTypeCount(), \App\Models\StaffAvailabilityRule::query()->count()];

        app(\App\Library\Entitlement\EntitlementManager::class)->changePlanStatus($agency->fresh(), \App\Enums\Entitlement\WorkspacePlanAssignmentStatus::Suspended, $this->platformAdminId(), 'Test suspension.');

        try {
            $this->sites()->createAndSelectBookingType((int) $owner->user_id, $agency->fresh(), ['name' => 'Blocked', 'duration_minutes' => 30, 'default_hours' => true]);
            $this->fail('creation must be refused');
        } catch (\App\Exceptions\Branding\AgencyWhiteLabelException|\Illuminate\Validation\ValidationException) {
            $this->assertTrue(true);
        }

        $this->assertSame($before, [$this->bookingTypeCount(), \App\Models\StaffAvailabilityRule::query()->count()]);
        $this->assertArrayNotHasKey('x.booking_type_uid', AgencySiteManager::values(AgencySite::query()->sole()));
    }

    public function test_a_failed_selection_rolls_back_the_booking_type_staff_and_seeded_hours(): void
    {
        [$agency, $owner] = $this->agency();
        $this->brand($agency, $owner);
        $this->openEditor($agency, $owner);
        $kept = $this->bookableType($agency, $owner, 'Existing call');
        $rulesBefore = \App\Models\StaffAvailabilityRule::query()->orderBy('id')->get(['day_of_week', 'start_time', 'end_time'])->toArray();
        $typesBefore = $this->bookingTypeCount();
        $staffBefore = \Illuminate\Support\Facades\DB::table((new BookingType())->staff()->getTable())->count();

        $armed = true;
        AgencySite::saving(function () use (&$armed): void {
            if ($armed) {
                throw new \RuntimeException('site save failed');
            }
        });

        try {
            $this->sites()->createAndSelectBookingType((int) $owner->user_id, $agency, ['name' => 'New call', 'duration_minutes' => 45, 'default_hours' => true]);
            $this->fail('the failure must surface');
        } catch (\RuntimeException) {
            $this->assertTrue(true);
        } finally {
            $armed = false;
        }

        $this->assertSame($typesBefore, $this->bookingTypeCount());
        $this->assertSame($staffBefore, \Illuminate\Support\Facades\DB::table((new BookingType())->staff()->getTable())->count());
        $this->assertSame($rulesBefore, \App\Models\StaffAvailabilityRule::query()->orderBy('id')->get(['day_of_week', 'start_time', 'end_time'])->toArray());
        $this->assertTrue(BookingType::query()->whereKey($kept->id)->exists(), 'existing booking types are untouched');
    }

    public function test_submitting_the_inline_form_twice_creates_one_booking_type_and_keeps_existing_ones(): void
    {
        [$agency, $owner] = $this->agency();
        $this->brand($agency, $owner);
        $this->openEditor($agency, $owner);
        $existing = $this->bookableType($agency, $owner, 'Existing call');
        $rules = \App\Models\StaffAvailabilityRule::query()->count();
        $payload = ['name' => 'Discovery call', 'duration_minutes' => 20, 'meeting_location_type' => 'phone', 'default_hours' => 1];

        $first = $this->postJson($this->url('booking-type', $agency), $payload)->assertOk();
        $second = $this->postJson($this->url('booking-type', $agency), $payload)->assertOk();

        $this->assertSame(1, BookingType::query()->where('name', 'Discovery call')->count());
        $this->assertSame($first->json('booking.url'), $second->json('booking.url'));
        $this->assertSame(2, $this->bookingTypeCount());
        $this->assertTrue(BookingType::query()->whereKey($existing->id)->exists());
        $this->assertSame($rules, \App\Models\StaffAvailabilityRule::query()->count(), 'hours already existed, so none were added');
    }

    // ------------------------------------------------------- the marketing stylesheet reaches the page

    /** The href of the page's marketing stylesheet link. */
    private function stylesheetHref(string $html): string
    {
        $this->assertSame(1, preg_match('#<link rel="stylesheet" href="([^"]*marketing\.css[^"]*)"#', $html, $m), 'the page links the marketing stylesheet');

        return html_entity_decode($m[1]);
    }

    public function test_a_draft_preview_links_a_stylesheet_that_actually_loads_before_the_site_is_published(): void
    {
        [$agency, $owner] = $this->agency();
        $this->brand($agency, $owner, 'Northwind Digital', '#1a73e8');
        $site = $this->openEditor($agency, $owner);
        $this->assertFalse($site->isLive());

        $html = $this->get($this->url('preview', $agency, ['page' => 'home']))->assertOk()->getContent();

        // The marketing layout, not a bare document: its stylesheet, fonts, script and body structure are all present.
        $href = $this->stylesheetHref($html);
        $this->assertStringContainsString('/agency/site/preview-css/marketing.css', $href);
        $this->assertStringContainsString('fonts.googleapis.com', $html);
        $this->assertMatchesRegularExpression('#<script src="[^"]*marketing/js/marketing\.js[^"]*" defer>#', $html);

        // The link resolves for the signed-in owner (this is what used to 404 and leave the page unstyled).
        $css = $this->get($href)->assertOk();
        $this->assertStringContainsString('text/css', (string) $css->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $css->headers->get('Cache-Control'));
        $body = $css->getContent();
        $this->assertGreaterThan(10000, strlen($body), 'the full marketing stylesheet, not a stub');
        $this->assertStringContainsString('#1a73e8', $body, "the Agency's colour is applied");
        $this->assertStringNotContainsStringIgnoringCase('#b4514a', $body, 'the platform colour is gone');

        // The unpublished site's public stylesheet stays private.
        $this->get(route('agency.site.css', $site->public_id))->assertNotFound();
    }

    public function test_the_preview_stylesheet_follows_the_draft_and_is_for_the_agency_team_only(): void
    {
        [$agency, $owner] = $this->agency();
        $this->brand($agency, $owner, 'Northwind Digital', '#1a73e8');
        $this->openEditor($agency, $owner);
        $href = $this->stylesheetHref($this->get($this->url('preview', $agency, ['page' => 'pricing']))->getContent());

        // A changed brand colour shows up in the preview stylesheet at once (draft, never cached).
        $this->brand($agency, $owner, 'Northwind Digital', '#0b7a3b');
        $newHref = $this->stylesheetHref($this->get($this->url('preview', $agency, ['page' => 'home']))->getContent());
        $this->assertStringContainsString('#0b7a3b', $this->get($newHref)->assertOk()->getContent());

        // Outsiders get nothing: not logged in, or a different agency's owner.
        auth()->logout();
        $this->get($href)->assertNotFound();
        [$other, $otherOwner] = $this->agency('Other Agency');
        $this->authenticateAs($otherOwner);
        $this->get($href)->assertStatus(404);
    }

    public function test_a_published_site_links_the_public_stylesheet_and_every_page_has_one(): void
    {
        [$agency, $owner] = $this->agency();
        $this->brand($agency, $owner, 'Northwind Digital', '#1a73e8');
        $site = $this->openEditor($agency, $owner);
        $uuid = $this->bookableType($agency, $owner)->public_booking_uuid;
        $this->save($agency, ['x.goal' => 'book_call', 'x.booking_type_uid' => $uuid, 'links.privacy' => 'https://northwind.example/privacy', 'links.terms' => 'https://northwind.example/terms'])->assertOk();
        $this->postJson($this->url('publish', $agency))->assertOk();
        auth()->logout();

        foreach (['home', 'product', 'solutions', 'how-it-works', 'pricing'] as $page) {
            $html = $this->get($page === 'home' ? route('agency.site.home', $site->public_id) : route('agency.site.page', [$site->public_id, $page]))->assertOk()->getContent();
            $href = $this->stylesheetHref($html);

            $this->assertStringContainsString('/w/'.$site->public_id.'/css/marketing.css', $href, $page);
            $css = $this->get($href)->assertOk();
            $this->assertStringContainsString('text/css', (string) $css->headers->get('Content-Type'));
            $this->assertStringContainsString('#1a73e8', $css->getContent());
        }

        // The preview URL is not what the public site uses.
        $this->assertStringNotContainsString('preview-css', $this->get(route('agency.site.home', $site->public_id))->getContent());
    }
}

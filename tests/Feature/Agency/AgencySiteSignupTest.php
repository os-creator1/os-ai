<?php

namespace Tests\Feature\Agency;

use App\Enums\AgencyBilling\AgencyClientSubscriptionStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\EntitlementManager;
use App\Models\AgencyClientSubscription;
use App\Models\AgencyClientWorkspaceRelationship;
use App\Models\AgencySaasPlan;
use App\Models\AgencySite;
use App\Models\AgencySiteSignup;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AgencyBilling\Concerns\CreatesAgencySaasFixtures;
use Tests\TestCase;

/**
 * White Label — visitor → managed customer: attribution, provisioning, Stripe Checkout on the Agency's connected
 * account, verified activation, retries and tenant isolation.
 */
class AgencySiteSignupTest extends TestCase
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

    /**
     * A published Agency site selling one Growth plan (and booking), with Stripe ready.
     *
     * @return array{agency: Workspace, owner: Customer, plan: AgencySaasPlan, site: AgencySite}
     */
    private function liveAgency(string $name = 'Northwind Agency', string $price = '49.00'): array
    {
        [$owner, $business, $agency] = $this->tenant(WorkspacePlanTier::Agency, $name . ' Business', $name);
        app(\App\Library\Business\BusinessLocationManager::class)->upsertPrimaryLocation($business, ['service_mode' => 'storefront', 'country_code' => 'US']);
        app(\App\Library\Branding\AgencyWhiteLabelManager::class)->save((int) $owner->user_id, $agency, ['display_name' => $name . ' Digital', 'accent_color' => '#1a73e8', 'is_enabled' => '1']);
        $this->connectAgencyStripe($agency, (int) $owner->user_id);
        $plan = $this->publishedPlan($agency, (int) $owner->user_id, WorkspacePlanTier::Growth, $price, null, 'Studio');
        $type = app(\App\Library\AgencySite\AgencySiteBookingService::class)->create((int) $owner->user_id, $agency, ['name' => 'Intro call', 'duration_minutes' => 30, 'default_hours' => true]);

        $sites = app(\App\Library\AgencySite\AgencySiteManager::class);
        $sites->ensure((int) $owner->user_id, $agency);
        $sites->save((int) $owner->user_id, $agency, [
            'x.booking_type_uid' => $type->public_booking_uuid,
            'links.privacy' => 'https://northwind.example/privacy',
            'links.terms' => 'https://northwind.example/terms',
        ]);
        $site = $sites->publish((int) $owner->user_id, $agency);

        return ['agency' => $agency, 'owner' => $owner, 'plan' => $plan, 'site' => $site];
    }

    private function signupUrl(array $live, ?string $planUid = null): string
    {
        return route('agency.site.signup', [$live['site']->public_id, $planUid ?? $live['plan']->uid]);
    }

    /** @return array<string, mixed> */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Dana', 'last_name' => 'Reyes', 'email' => 'dana@harbor.example', 'business_name' => 'Harbor Lane Studio',
            'password' => 'correct-horse-9', 'password_confirmation' => 'correct-horse-9', 'accept' => '1',
        ], $overrides);
    }

    private function tierOf(Workspace $workspace): ?WorkspacePlanTier
    {
        return app(EntitlementManager::class)->getWorkspaceEntitlementSummary($workspace->fresh())->tier;
    }

    private function clientOf(string $email): Workspace
    {
        $user = User::query()->where('email', $email)->firstOrFail();

        return Workspace::query()->where('owner_user_id', $user->id)->firstOrFail();
    }

    // -------------------------------------------------------------- the page

    public function test_the_signup_page_shows_the_plan_and_only_exists_for_a_live_site_and_a_sellable_plan_of_that_agency(): void
    {
        $live = $this->liveAgency();
        $other = $this->liveAgency('Other Agency', '19.00');

        $html = $this->get($this->signupUrl($live))->assertOk()->getContent();
        $this->assertStringContainsString('Studio', $html);
        $this->assertStringContainsString('$49', $html);
        $this->assertStringContainsString('Create your account', $html);
        $this->assertStringContainsString('https://northwind.example/terms', $html);
        $this->assertStringNotContainsString('MotionGrove', $html);

        // Another Agency's plan cannot be bought through this site, and vice versa.
        $this->get($this->signupUrl($live, $other['plan']->uid))->assertNotFound();
        $this->get($this->signupUrl($other, $live['plan']->uid))->assertNotFound();

        $this->authenticateAs($live['owner']);
        $this->postJson(route('customer.workspaces.agency.site.unpublish', $live['agency']->uid))->assertOk();
        auth()->logout();
        $this->get($this->signupUrl($live))->assertNotFound();
    }

    // ------------------------------------------------------------ happy path

    public function test_a_visitor_becomes_a_managed_customer_and_gets_nothing_until_stripe_confirms_payment(): void
    {
        $live = $this->liveAgency();

        $response = $this->post($this->signupUrl($live), $this->form());
        $response->assertRedirect();
        $this->assertStringContainsString('checkout', (string) $response->headers->get('Location'));

        $user = User::query()->where('email', 'dana@harbor.example')->sole();
        $client = $this->clientOf('dana@harbor.example');

        // Correctly attributed and linked: a managed Business subaccount of THIS Agency, not a top-level Agency.
        $relationship = AgencyClientWorkspaceRelationship::query()->where('client_workspace_id', $client->id)->sole();
        $this->assertSame((int) $live['agency']->id, (int) $relationship->agency_workspace_id);
        $this->assertNotSame(WorkspacePlanTier::Agency, $this->tierOf($client));
        $this->assertSame('Harbor Lane Studio', $client->name);
        $this->assertSame((int) $user->id, (int) $client->owner_user_id);

        $signup = AgencySiteSignup::query()->sole();
        $this->assertSame((int) $live['agency']->id, (int) $signup->agency_workspace_id);
        $this->assertSame((int) $client->id, (int) $signup->client_workspace_id);
        $this->assertNotNull($signup->terms_accepted_at);

        // The commercial record is on the AGENCY's connected account and the chosen plan, but not paid yet.
        $subscription = AgencyClientSubscription::query()->sole();
        $this->assertSame((int) $live['plan']->id, (int) $subscription->agency_saas_plan_id);
        $this->assertSame((int) $live['agency']->id, (int) $subscription->agency_workspace_id);
        $this->assertContains($subscription->status, [AgencyClientSubscriptionStatus::Pending, AgencyClientSubscriptionStatus::Offered]);
        $this->assertFalse($subscription->status->grantsAccess());
        $this->assertNull($this->tierOf($client), 'no paid entitlement before payment');

        // Stripe confirms: only then is the plan's tier assigned, through the existing finalizer.
        $this->agencyStripe->completeCheckout((string) $subscription->provider_checkout_session_id);
        $this->actingAs($user)->get(route('customer.workspaces.agency-plan.return', $client->uid))->assertRedirect();

        $this->assertSame(AgencyClientSubscriptionStatus::Active, $subscription->refresh()->status);
        $this->assertSame(WorkspacePlanTier::Growth, $this->tierOf($client));
        $this->assertSame(1, AgencyClientWorkspaceRelationship::query()->where('client_workspace_id', $client->id)->count());
    }

    public function test_the_new_customer_is_branded_with_the_agency_and_cannot_reach_the_agencys_own_workspace(): void
    {
        $live = $this->liveAgency();
        $this->post($this->signupUrl($live), $this->form())->assertRedirect();
        $user = User::query()->where('email', 'dana@harbor.example')->sole();
        $client = $this->clientOf('dana@harbor.example');
        $this->agencyStripe->completeCheckout((string) AgencyClientSubscription::query()->sole()->provider_checkout_session_id);
        $this->actingAs($user)->get(route('customer.workspaces.agency-plan.return', $client->uid));

        $brand = app(\App\Library\Branding\ClientWorkspaceBrandResolver::class)->forClientWorkspaceId((int) $client->id);
        $this->assertSame('Northwind Agency Digital', $brand?->displayName);

        // No access to the parent Agency's own pages, CRM or white-label settings.
        $this->get(route('customer.workspaces.agency.white-label.show', $live['agency']->uid))->assertNotFound();
        $this->get(route('customer.workspaces.agency.site.preview', [$live['agency']->uid, 'home']))->assertNotFound();
        $this->postJson(route('customer.workspaces.agency.site.save', $live['agency']->uid), ['answers' => ['copy.home.hero.title' => 'x']])->assertNotFound();
    }

    // -------------------------------------------------------------- retries

    public function test_retries_resume_the_same_account_and_never_create_a_second_one(): void
    {
        $live = $this->liveAgency();

        $this->post($this->signupUrl($live), $this->form())->assertRedirect();
        auth()->logout();
        $this->post($this->signupUrl($live), $this->form())->assertRedirect();
        auth()->logout();
        $this->post($this->signupUrl($live), $this->form())->assertRedirect();

        $this->assertSame(1, User::query()->where('email', 'dana@harbor.example')->count());
        $this->assertSame(1, AgencySiteSignup::query()->count());
        $this->assertSame(1, AgencyClientSubscription::query()->count());
        $this->assertSame(1, AgencyClientWorkspaceRelationship::query()->where('agency_workspace_id', $live['agency']->id)->count());
        $this->assertSame(1, Workspace::query()->where('name', 'Harbor Lane Studio')->count());
        $this->assertNull($this->tierOf($this->clientOf('dana@harbor.example')));
    }

    public function test_an_existing_account_or_the_wrong_password_can_never_be_attached_to_an_agency(): void
    {
        $live = $this->liveAgency();
        $existing = $this->createCustomer();

        $this->post($this->signupUrl($live), $this->form(['email' => $existing->user->email]))->assertSessionHasErrors('email');
        $this->assertSame(0, AgencySiteSignup::query()->count());
        $this->assertSame(0, AgencyClientWorkspaceRelationship::query()->where('agency_workspace_id', $live['agency']->id)->count());

        // The person who started the signup can resume it; someone else who knows only the email cannot.
        $this->post($this->signupUrl($live), $this->form())->assertRedirect();
        auth()->logout();
        $this->post($this->signupUrl($live), $this->form(['password' => 'a-different-pass1', 'password_confirmation' => 'a-different-pass1']))->assertSessionHasErrors('email');
        $this->assertSame(1, AgencySiteSignup::query()->count());
    }

    public function test_invalid_forms_create_nothing(): void
    {
        $live = $this->liveAgency();

        foreach ([
            $this->form(['accept' => null]),
            $this->form(['email' => 'not-an-email']),
            $this->form(['password' => 'short', 'password_confirmation' => 'short']),
            $this->form(['password_confirmation' => 'different']),
            $this->form(['business_name' => '']),
            $this->form(['first_name' => '<b>x</b>']),
        ] as $bad) {
            $this->post($this->signupUrl($live), $bad)->assertSessionHasErrors();
        }

        $this->assertSame(0, User::query()->where('email', 'dana@harbor.example')->count());
        $this->assertSame(0, AgencySiteSignup::query()->count());
    }

    // ------------------------------------------------- incomplete / failed pay

    public function test_incomplete_failed_or_abandoned_payment_never_activates_paid_entitlements(): void
    {
        $live = $this->liveAgency();
        $this->post($this->signupUrl($live), $this->form())->assertRedirect();
        $user = User::query()->where('email', 'dana@harbor.example')->sole();
        $client = $this->clientOf('dana@harbor.example');
        $subscription = AgencyClientSubscription::query()->sole();

        // Abandoned: the browser comes back without paying.
        $this->actingAs($user)->get(route('customer.workspaces.agency-plan.return', $client->uid))->assertRedirect();
        $this->assertNull($this->tierOf($client));

        // Stripe reports the payment as incomplete / unpaid: still nothing.
        foreach ([AgencyClientSubscriptionStatus::Incomplete, AgencyClientSubscriptionStatus::Unpaid, AgencyClientSubscriptionStatus::IncompleteExpired] as $status) {
            $session = (string) $subscription->refresh()->provider_checkout_session_id;
            $providerSubscription = $this->agencyStripe->completeCheckout($session, $status);
            $this->actingAs($user)->get(route('customer.workspaces.agency-plan.return', $client->uid));
            $this->assertNull($this->tierOf($client), "status {$status->value} grants nothing");
            $this->assertFalse($subscription->refresh()->status->grantsAccess());
        }
    }

    // ----------------------------------------------------- webhooks / lifecycle

    public function test_webhook_replays_and_out_of_order_events_neither_duplicate_nor_regrant_and_cancellation_removes_the_plan(): void
    {
        $live = $this->liveAgency();
        $this->post($this->signupUrl($live), $this->form())->assertRedirect();
        $client = $this->clientOf('dana@harbor.example');
        $subscription = AgencyClientSubscription::query()->sole();
        $providerSubscription = $this->agencyStripe->completeCheckout((string) $subscription->provider_checkout_session_id);
        auth()->logout(); // Stripe's servers carry no session

        $deliver = function (string $type, string $eventId, int $createdAt) use ($subscription, $providerSubscription) {
            [$body, $headers] = $this->agencyWebhookBody($type, (string) $subscription->connected_account_id, $providerSubscription, null, $eventId, $createdAt, (string) $subscription->uid);

            return $this->postAgencyWebhook($body, $headers)->assertOk();
        };

        // The same event delivered three times: one activation.
        $now = now()->getTimestamp();
        foreach (range(1, 3) as $_) {
            $deliver('customer.subscription.updated', 'evt_active_1', $now);
        }
        $this->assertSame(AgencyClientSubscriptionStatus::Active, $subscription->refresh()->status);
        $this->assertSame(WorkspacePlanTier::Growth, $this->tierOf($client));
        $this->assertSame(1, \App\Models\WorkspacePlanAssignment::query()->where('workspace_id', $client->id)->count());
        $this->assertSame(1, AgencyClientWorkspaceRelationship::query()->where('client_workspace_id', $client->id)->count());

        // Cancellation at Stripe is reflected, and a late replay of the old "active" event cannot bring it back.
        $this->agencyStripe->setSubscriptionStatus($providerSubscription, AgencyClientSubscriptionStatus::Canceled);
        $deliver('customer.subscription.deleted', 'evt_canceled_1', $now + 60);
        $this->assertSame(AgencyClientSubscriptionStatus::Canceled, $subscription->refresh()->status);

        $deliver('customer.subscription.updated', 'evt_active_1', $now);
        $deliver('customer.subscription.updated', 'evt_active_old', $now - 600);
        $this->assertSame(AgencyClientSubscriptionStatus::Canceled, $subscription->refresh()->status);
        $this->assertSame(1, \App\Models\WorkspacePlanAssignment::query()->where('workspace_id', $client->id)->count());
    }

    // ------------------------------------------------------------- isolation

    public function test_two_agencies_and_their_customers_stay_isolated(): void
    {
        $a = $this->liveAgency('Agency A');
        $b = $this->liveAgency('Agency B', '19.00');

        $this->post($this->signupUrl($a), $this->form(['email' => 'a-customer@example.test', 'business_name' => 'A Customer Biz']))->assertRedirect();
        auth()->logout();
        $this->post($this->signupUrl($b), $this->form(['email' => 'b-customer@example.test', 'business_name' => 'B Customer Biz']))->assertRedirect();

        $clientA = $this->clientOf('a-customer@example.test');
        $clientB = $this->clientOf('b-customer@example.test');

        $this->assertSame((int) $a['agency']->id, (int) AgencyClientWorkspaceRelationship::query()->where('client_workspace_id', $clientA->id)->value('agency_workspace_id'));
        $this->assertSame((int) $b['agency']->id, (int) AgencyClientWorkspaceRelationship::query()->where('client_workspace_id', $clientB->id)->value('agency_workspace_id'));
        $this->assertSame((int) $a['plan']->id, (int) AgencyClientSubscription::query()->where('client_workspace_id', $clientA->id)->value('agency_saas_plan_id'));
        $this->assertSame((int) $b['plan']->id, (int) AgencyClientSubscription::query()->where('client_workspace_id', $clientB->id)->value('agency_saas_plan_id'));
        $this->assertNotSame(
            AgencyClientSubscription::query()->where('client_workspace_id', $clientA->id)->value('connected_account_id'),
            AgencyClientSubscription::query()->where('client_workspace_id', $clientB->id)->value('connected_account_id'),
            'each customer is billed on its own Agency\'s Stripe account',
        );

        // Customer A cannot open Customer B's workspace or either Agency's.
        $this->actingAs(User::query()->where('email', 'a-customer@example.test')->sole());
        $this->get(route('customer.workspaces.agency-plan.show', $clientB->uid))->assertNotFound();
        $this->get(route('customer.workspaces.agency.white-label.show', $b['agency']->uid))->assertNotFound();
        $this->get(route('customer.workspaces.agency.site.preview', [$b['agency']->uid, 'home']))->assertNotFound();
    }

    public function test_the_owner_sees_acquired_customers_with_their_subscription_in_the_existing_clients_list(): void
    {
        $live = $this->liveAgency();
        $this->post($this->signupUrl($live), $this->form())->assertRedirect();
        auth()->logout();

        $this->authenticateAs($live['owner']);
        $html = $this->get(route('customer.workspaces.clients.index', $live['agency']->uid))->assertOk()->getContent();
        $this->assertStringContainsString('Harbor Lane Studio', $html);
    }

    public function test_disabling_a_plan_or_losing_stripe_removes_it_from_sale_without_touching_existing_customers(): void
    {
        $live = $this->liveAgency();
        $this->post($this->signupUrl($live), $this->form())->assertRedirect();
        $client = $this->clientOf('dana@harbor.example');
        $subscription = AgencyClientSubscription::query()->sole();
        $this->agencyStripe->completeCheckout((string) $subscription->provider_checkout_session_id);
        $this->actingAs(User::query()->where('email', 'dana@harbor.example')->sole())->get(route('customer.workspaces.agency-plan.return', $client->uid));
        $this->assertSame(WorkspacePlanTier::Growth, $this->tierOf($client));
        auth()->logout();

        // The Agency withdraws the plan: the public page stops selling it and signup closes; the paying customer keeps theirs.
        $this->plans()->unpublish((int) $live['owner']->user_id, $live['plan']->refresh());
        $this->get($this->signupUrl($live))->assertNotFound();
        $this->assertStringNotContainsString('Studio', $this->get(route('agency.site.page', [$live['site']->public_id, 'pricing']))->getContent());
        $this->assertSame(WorkspacePlanTier::Growth, $this->tierOf($client));
        $this->assertSame(AgencyClientSubscriptionStatus::Active, $subscription->refresh()->status);

        // And a republish is refused while the funnel has nothing to sell.
        $this->authenticateAs($live['owner']);
        $this->postJson(route('customer.workspaces.agency.site.publish', $live['agency']->uid))->assertStatus(422);
    }

    // ------------------------------------------- verification mail timing (Codex P2)

    public function test_verification_mail_is_sent_after_the_agency_relationship_exists_and_in_the_agencys_brand(): void
    {
        config(['account.verify_account' => true]);
        $live = $this->liveAgency();
        \Illuminate\Support\Facades\Notification::fake();

        $this->post($this->signupUrl($live), $this->form())->assertRedirect();

        $user = User::query()->where('email', 'dana@harbor.example')->sole();
        \Illuminate\Support\Facades\Notification::assertSentToTimes($user, \App\Notifications\Auth\BrandedVerifyEmail::class, 1);

        // At send time the committed relationship exists, so the message resolves the Agency brand (not the platform's).
        \Illuminate\Support\Facades\Notification::assertSentTo($user, \App\Notifications\Auth\BrandedVerifyEmail::class, function ($notification) use ($user, $live) {
            $mail = $notification->toMail($user);

            $this->assertSame('Northwind Agency Digital', $mail->from[1]);
            $this->assertStringContainsString('Northwind Agency Digital', (string) $mail->render());
            $this->assertSame(1, AgencyClientWorkspaceRelationship::query()->where('agency_workspace_id', $live['agency']->id)->count());

            return true;
        });
    }

    public function test_the_brand_is_resolvable_at_the_moment_the_verification_mail_is_built_and_not_before(): void
    {
        config(['account.verify_account' => true]);
        $live = $this->liveAgency();

        // Capture the brand the mail resolver sees at the instant the notification is dispatched.
        $seen = null;
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Notifications\Events\NotificationSending::class, function ($event) use (&$seen) {
            $seen = app(\App\Library\Mail\MailBrandResolver::class)->forUser($event->notifiable)->kind;
        });

        $this->post($this->signupUrl($live), $this->form())->assertRedirect();

        $this->assertSame('agency', $seen, 'the Agency relationship already exists when the mail goes out');
    }

    public function test_no_verification_mail_is_sent_for_a_provisioning_that_rolls_back(): void
    {
        config(['account.verify_account' => true]);
        $live = $this->liveAgency();
        \Illuminate\Support\Facades\Notification::fake();

        $this->mock(\App\Library\Workspace\AgencyClientProvisioningManager::class)
            ->shouldReceive('provisionClientWorkspace')->once()->andThrow(new \RuntimeException('provisioning failed'));

        try {
            app(\App\Library\AgencySite\AgencySiteSignupService::class)->start(
                $live['site'], $live['plan'], ['first_name' => 'Dana', 'email' => 'dana@harbor.example', 'password' => 'correct-horse-9', 'business_name' => 'Harbor Lane Studio'],
                null, fn ($w) => 'https://example.test/return', 'https://example.test/cancel',
            );
            $this->fail('provisioning should have failed');
        } catch (\RuntimeException $e) {
            $this->assertSame('provisioning failed', $e->getMessage());
        }

        \Illuminate\Support\Facades\Notification::assertNothingSent();
        $this->assertSame(0, User::query()->where('email', 'dana@harbor.example')->count(), 'the account rolled back with it');
        $this->assertSame(0, AgencySiteSignup::query()->count());
    }

    public function test_a_retry_does_not_send_the_verification_mail_again(): void
    {
        config(['account.verify_account' => true]);
        $live = $this->liveAgency();
        \Illuminate\Support\Facades\Notification::fake();

        $this->post($this->signupUrl($live), $this->form())->assertRedirect();
        auth()->logout();
        $this->post($this->signupUrl($live), $this->form())->assertRedirect();

        \Illuminate\Support\Facades\Notification::assertSentToTimes(User::query()->where('email', 'dana@harbor.example')->sole(), \App\Notifications\Auth\BrandedVerifyEmail::class, 1);
    }

    public function test_a_mail_failure_does_not_undo_a_committed_signup(): void
    {
        config(['account.verify_account' => true]);
        $live = $this->liveAgency();
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Notifications\Events\NotificationSending::class, fn () => throw new \RuntimeException('mail down'));

        $this->post($this->signupUrl($live), $this->form())->assertRedirect();

        $this->assertSame(1, AgencySiteSignup::query()->count());
        $this->assertNotNull(AgencyClientSubscription::query()->first());
    }

    // ------------------------------------------------ one pass, end to end

    public function test_signup_to_stripe_payment_to_activation_to_branded_login_in_one_pass(): void
    {
        $live = $this->liveAgency();

        // 1. Visitor picks the plan on the Agency's site and signs up; Stripe Checkout opens on the Agency's account.
        $this->post($this->signupUrl($live), $this->form())->assertRedirect();
        $client = $this->clientOf('dana@harbor.example');
        $subscription = AgencyClientSubscription::query()->sole();
        $this->assertNull($this->tierOf($client));

        // 2. Stripe (fake gateway) reports a paid subscription; the return re-reads it and activates the plan.
        $this->agencyStripe->completeCheckout((string) $subscription->provider_checkout_session_id);
        $this->actingAs(User::query()->where('email', 'dana@harbor.example')->sole())->get(route('customer.workspaces.agency-plan.return', $client->uid));
        $this->assertSame(AgencyClientSubscriptionStatus::Active, $subscription->refresh()->status);
        $this->assertSame(WorkspacePlanTier::Growth, $this->tierOf($client));

        // 3. Later, signed out: the Agency's own sign-in page is branded and the customer can really sign in with it.
        auth()->logout();
        $login = $this->get(route('agency.site.login', $live['site']->public_id))->assertOk()->getContent();
        $this->assertStringContainsString('Northwind Agency Digital', $login);

        $this->post(route('login'), ['email' => 'dana@harbor.example', 'password' => 'correct-horse-9'])->assertRedirect();
        $this->assertAuthenticatedAs(User::query()->where('email', 'dana@harbor.example')->sole());

        // 4. Inside the app the customer sees the Agency's name and colours, and only their own account.
        app(\App\Library\Support\RequestScopedCache::class)->flush();
        // A brand-new account may be sent to its own onboarding first; follow it to the first page it really shows.
        $home = $this->followingRedirects()->get(route('user.home'))->assertOk()->getContent();
        $this->assertStringContainsString('Northwind Agency Digital', $home);
        $this->assertStringContainsString('agency-theme-overrides', $home);
        $this->get(route('customer.workspaces.agency.white-label.show', $live['agency']->uid))->assertNotFound();
    }

    // ------------------------------------------------- one payable checkout per customer

    public function test_double_submit_and_returning_to_signup_reuse_the_one_open_checkout_session(): void
    {
        $live = $this->liveAgency();

        $first = $this->post($this->signupUrl($live), $this->form());
        $first->assertRedirect();
        auth()->logout();
        $second = $this->post($this->signupUrl($live), $this->form());
        auth()->logout();
        $third = $this->post($this->signupUrl($live), $this->form());

        $this->assertSame($first->headers->get('Location'), $second->headers->get('Location'));
        $this->assertSame($first->headers->get('Location'), $third->headers->get('Location'));
        $this->assertCount(1, $this->agencyStripe->payableSessionIds(), 'exactly one payable Checkout session');
        $this->assertSame(1, AgencyClientSubscription::query()->count());
        $this->assertSame([], $this->agencyStripe->callsOf('expireCheckoutSession'), 'nothing was expired: nothing was replaced');
    }

    public function test_a_simultaneous_retry_arriving_mid_creation_still_leaves_one_payable_session(): void
    {
        $live = $this->liveAgency();

        $service = app(\App\Library\AgencySite\AgencySiteSignupService::class);
        $inner = null;
        // A second request lands while the first is talking to Stripe.
        $this->agencyStripe->interleaveOnce('createSubscriptionCheckout', function () use ($service, $live, &$inner): void {
            $inner = $service->start($live['site'], $live['plan'], $this->form(), null, fn ($c) => 'https://x.test/ok/'.$c->uid, 'https://x.test/cancel');
        });

        $outer = $service->start($live['site'], $live['plan'], $this->form(), null, fn ($c) => 'https://x.test/ok/'.$c->uid, 'https://x.test/cancel');

        $this->assertNotNull($inner);
        $this->assertSame($inner['checkout']->sessionId, $outer['checkout']->sessionId);
        $this->assertCount(1, $this->agencyStripe->payableSessionIds());
    }

    public function test_an_expired_or_abandoned_session_is_replaced_and_the_old_one_stays_unpayable(): void
    {
        $live = $this->liveAgency();
        $this->post($this->signupUrl($live), $this->form())->assertRedirect();
        $old = (string) AgencyClientSubscription::query()->sole()->provider_checkout_session_id;
        $this->agencyStripe->sessions[$old]['status'] = 'expired';
        auth()->logout();

        $this->post($this->signupUrl($live), $this->form())->assertRedirect();

        $new = (string) AgencyClientSubscription::query()->sole()->provider_checkout_session_id;
        $this->assertNotSame($old, $new);
        $this->assertSame([$new], $this->agencyStripe->payableSessionIds());
    }

    public function test_choosing_a_different_plan_expires_the_first_session_before_opening_the_second(): void
    {
        $live = $this->liveAgency();
        $other = $this->publishedPlan($live['agency'], (int) $live['owner']->user_id, WorkspacePlanTier::Core, '99.00', null, 'Core Studio');

        $this->post($this->signupUrl($live), $this->form())->assertRedirect();
        $first = (string) AgencyClientSubscription::query()->sole()->provider_checkout_session_id;
        auth()->logout();

        $this->post($this->signupUrl($live, $other->uid), $this->form())->assertRedirect();

        $subscription = AgencyClientSubscription::query()->sole();
        $this->assertSame((int) $other->id, (int) $subscription->agency_saas_plan_id);
        $this->assertSame('expired', $this->agencyStripe->sessions[$first]['status']);
        $this->assertSame([(string) $subscription->provider_checkout_session_id], $this->agencyStripe->payableSessionIds());
        $this->assertSame(1, AgencyClientSubscription::query()->count());
        $this->assertSame(1, AgencySiteSignup::query()->count());

        // Paying the NEW session activates the NEW plan only.
        $user = User::query()->where('email', 'dana@harbor.example')->sole();
        $client = $this->clientOf('dana@harbor.example');
        $this->agencyStripe->completeCheckout((string) $subscription->provider_checkout_session_id);
        $this->actingAs($user)->get(route('customer.workspaces.agency-plan.return', $client->uid))->assertRedirect();
        $this->assertSame(WorkspacePlanTier::Core, $this->tierOf($client));
    }

    // ------------------------------------------- a replacement never opens a second payable session unless the first is provably dead

    /** Starts a signup on the first plan, then returns [live, otherPlan, firstSessionId] ready for a plan switch. */
    private function switchSetup(): array
    {
        $live = $this->liveAgency();
        $other = $this->publishedPlan($live['agency'], (int) $live['owner']->user_id, WorkspacePlanTier::Core, '99.00', null, 'Core Studio');
        $this->post($this->signupUrl($live), $this->form())->assertRedirect();
        auth()->logout();

        return [$live, $other, (string) AgencyClientSubscription::query()->sole()->provider_checkout_session_id];
    }

    public function test_when_the_old_session_cannot_be_expired_and_is_still_open_no_second_session_is_created(): void
    {
        [$live, $other, $first] = $this->switchSetup();
        $created = count($this->agencyStripe->callsOf('createSubscriptionCheckout'));

        $this->agencyStripe->hooks['expireCheckoutSession'] = function (): void {
            throw \App\Exceptions\AgencyBilling\AgencyBillingException::because(\App\Exceptions\AgencyBilling\AgencyBillingException::PROVIDER_FAILED);
        };

        $this->post($this->signupUrl($live, $other->uid), $this->form())->assertSessionHasErrors();

        $this->assertSame([$first], $this->agencyStripe->payableSessionIds(), 'only the original session is payable');
        $this->assertCount($created, $this->agencyStripe->callsOf('createSubscriptionCheckout'), 'no new Checkout was even requested');
        $this->assertSame($first, (string) AgencyClientSubscription::query()->sole()->provider_checkout_session_id, 'the attempt is kept');

        // Once Stripe cooperates again the retry replaces it cleanly.
        unset($this->agencyStripe->hooks['expireCheckoutSession']);
        auth()->logout();
        $this->post($this->signupUrl($live, $other->uid), $this->form())->assertRedirect();
        $this->assertSame([(string) AgencyClientSubscription::query()->sole()->provider_checkout_session_id], $this->agencyStripe->payableSessionIds());
        $this->assertSame('expired', $this->agencyStripe->sessions[$first]['status']);
    }

    public function test_when_the_old_sessions_state_cannot_be_confirmed_nothing_new_is_opened(): void
    {
        [$live, $other, $first] = $this->switchSetup();
        $created = count($this->agencyStripe->callsOf('createSubscriptionCheckout'));
        $reads = 0;

        // The first read (deciding to replace) works; the expire fails; the confirming re-read fails too.
        $this->agencyStripe->hooks['retrieveCheckoutSession'] = function () use (&$reads): void {
            if (++$reads >= 2) {
                throw \App\Exceptions\AgencyBilling\AgencyBillingException::because(\App\Exceptions\AgencyBilling\AgencyBillingException::PROVIDER_FAILED);
            }
        };
        $this->agencyStripe->hooks['expireCheckoutSession'] = function (): void {
            throw \App\Exceptions\AgencyBilling\AgencyBillingException::because(\App\Exceptions\AgencyBilling\AgencyBillingException::PROVIDER_FAILED);
        };

        $this->post($this->signupUrl($live, $other->uid), $this->form())->assertSessionHasErrors();

        $this->assertSame([$first], $this->agencyStripe->payableSessionIds());
        $this->assertCount($created, $this->agencyStripe->callsOf('createSubscriptionCheckout'));
    }

    public function test_an_expire_that_lost_a_race_to_someone_else_expiring_it_is_fine(): void
    {
        [$live, $other, $first] = $this->switchSetup();

        // Another request expires it between our read and our expire call.
        $this->agencyStripe->interleaveOnce('expireCheckoutSession', function (array $args, $gateway) use ($first): void {
            $gateway->sessions[$first]['status'] = 'expired';
        });
        $this->agencyStripe->failWith = null;

        $this->post($this->signupUrl($live, $other->uid), $this->form())->assertRedirect();

        $this->assertCount(1, $this->agencyStripe->payableSessionIds());
        $this->assertNotContains($first, $this->agencyStripe->payableSessionIds());
    }

    public function test_a_completed_old_session_is_never_replaced(): void
    {
        [$live, $other, $first] = $this->switchSetup();
        $this->agencyStripe->completeCheckout($first);

        $this->post($this->signupUrl($live, $other->uid), $this->form())->assertSessionHasErrors();

        $this->assertSame([], $this->agencyStripe->payableSessionIds());
        $this->assertSame(1, count($this->agencyStripe->callsOf('createSubscriptionCheckout')));
    }
}

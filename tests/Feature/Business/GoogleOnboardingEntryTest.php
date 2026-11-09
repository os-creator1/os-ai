<?php

namespace Tests\Feature\Business;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Library\Business\OnboardingManager;
use App\Library\Entitlement\EntitlementManager;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileOAuthConfig;
use App\Models\Business;
use App\Models\BusinessGoogleConnection;
use App\Models\BusinessGoogleLocation;
use App\Models\Customer;
use App\Models\CustomerOnboarding;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\GoogleBusinessProfile\Concerns\CreatesGoogleBusinessProfileFixtures;
use Tests\TestCase;

/**
 * The Google quick start's ENTRY POINT for a customer a paid V1 signup has
 * already given a real Draft Business and Workspace.
 */
class GoogleOnboardingEntryTest extends TestCase
{
    use CreatesGoogleBusinessProfileFixtures;
    use RefreshDatabase;

    private const MANAGE = ['view_google_business_profile', 'manage_google_business_profile'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['business.onboarding.enabled' => true]);
        $this->bindFakeGoogleClient();
    }

    public function test_a_freshly_provisioned_signup_customer_sees_google_above_their_prefilled_manual_form(): void
    {
        [$customer, $business] = $this->signupCustomer();

        $html = $this->get(route('customer.onboarding.show'))->assertOk()->getContent();

        $google = strpos($html, 'Connect Google Business Profile');
        $form = strpos($html, 'data-role="business-form"');

        $this->assertNotFalse($google, 'The Google option must be offered.');
        $this->assertNotFalse($form, 'The manual form stays.');
        $this->assertLessThan($form, $google, 'The Google option sits ABOVE the manual form.');

        // The existing manual answers are still there, untouched.
        $this->assertStringContainsString('value="Signup Booths"', $html);
        $this->assertStringContainsString('<option value="photo_booth_service" selected', preg_replace('/\s+/', ' ', $html) ?: '');
        $this->assertNotNull($customer);
        $this->assertSame(BusinessStatus::Draft, $business->refresh()->status);
    }

    public function test_the_option_is_clear_on_a_phone_layout(): void
    {
        $this->signupCustomer();

        $this->get(route('customer.onboarding.show'))
            ->assertOk()
            ->assertSee('name="viewport" content="width=device-width,initial-scale=1.0"', false)
            // Full-width stacked buttons on small screens, side by side from md up, large and primary.
            ->assertSee('d-grid d-md-flex', false)
            ->assertSee('btn-lg', false)
            ->assertSee('data-role="connect-google"', false)
            ->assertSee('Set up manually')
            // No locale questions: the Business already has them.
            ->assertDontSee('data-business-locale data-suggest-defaults', false);
    }

    public function test_connecting_reuses_the_provisioned_draft_business_and_workspace(): void
    {
        [$customer, $business, $workspace] = $this->signupCustomer();

        $response = $this->post(route('customer.onboarding.google.connect'));
        $response->assertRedirect();
        $this->assertStringStartsWith('https://accounts.google.test/authorize', (string) $response->headers->get('Location'));

        $this->assertSame(1, Business::query()->count(), 'No duplicate Business.');
        $this->assertSame(1, Workspace::query()->where('owner_user_id', $customer->user_id)->count(), 'No duplicate Workspace.');
        $this->assertSame((int) $business->id, (int) $this->onboardingOf($customer)->business_id);

        $connection = BusinessGoogleConnection::query()->firstOrFail();
        $this->assertSame((int) $business->id, (int) $connection->business_id);
        $this->assertSame(GoogleConnectionState::Pending, $connection->state);

        $business->refresh();
        $this->assertSame('Signup Booths', $business->name, 'Nothing is overwritten by connecting.');
        $this->assertSame(BusinessStatus::Draft, $business->status);
    }

    public function test_manual_answers_survive_a_cancelled_or_failed_authorization(): void
    {
        [$customer, $business] = $this->signupCustomer(['phone' => '+370 600 11111']);
        $state = $this->beginConnect();

        $this->get(route(GoogleBusinessProfileOAuthConfig::CALLBACK_ROUTE) . '?error=access_denied&state=' . urlencode($state))
            ->assertRedirect(route('customer.onboarding.show'))
            ->assertSessionHasErrors('onboarding');

        $business->refresh();
        $this->assertSame('Signup Booths', $business->name);
        $this->assertSame('+370 600 11111', $business->phone);
        $this->assertSame(BusinessStatus::Draft, $business->status);
        $this->assertFalse(BusinessGoogleConnection::query()->firstOrFail()->hasStoredAuthorization());

        // The manual form is intact, and Google can be tried again.
        $this->get(route('customer.onboarding.show'))
            ->assertOk()
            ->assertSee('value="Signup Booths"', false)
            ->assertSee('value="+370 600 11111"', false)
            ->assertSee('Connect Google Business Profile');
        $this->assertNotNull($customer);
    }

    public function test_confirming_updates_only_the_approved_information_and_links_the_profile(): void
    {
        [$customer, $business] = $this->signupCustomer([
            'description' => 'Our own story.',
            'email' => 'public@signup.test',
            'phone' => '+370 600 11111',
        ]);
        $this->fakeGoogleWithLocation(['title' => 'Google Title', 'phoneNumbers' => ['primaryPhone' => '+1 555 0100']]);
        $this->connectFully();

        $token = $this->firstOfferToken($this->get(route('customer.onboarding.google.show')));
        $this->post(route('customer.onboarding.google.select'), ['candidate_token' => $token])->assertRedirect();
        $card = $this->get(route('customer.onboarding.google.review'))->assertOk();

        // What is already saved is what the card offers; Google's different values are only offered.
        $card->assertSee('value="Signup Booths"', false)
            ->assertSee('value="+370 600 11111"', false)
            ->assertSee('Our own story.')
            ->assertSee('Google has “Google Title”', false);

        // The customer approves the card as shown, and changes only the city.
        $this->post(route('customer.onboarding.google.confirm'), [
            'snapshot' => (string) session('onboarding.google.snapshot'),
            'name' => 'Signup Booths',
            'industry' => 'photo_booth_service',
            'phone' => '+370 600 11111',
            'description' => 'Our own story.',
            'country_code' => 'US',
            'timezone' => 'America/New_York',
            'currency_code' => 'USD',
            'service_mode' => 'service_area',
            'city' => 'Orange',
            'region' => 'CA',
            'main_service' => 'Photo Booth Rental',
            'website_choice' => 'later',
        ])->assertSessionHasNoErrors();

        $business->refresh();
        $this->assertSame('Signup Booths', $business->name);
        $this->assertSame('+370 600 11111', $business->phone);
        $this->assertSame('Our own story.', $business->description);
        $this->assertSame('public@signup.test', $business->email, 'A field the card never touches is left alone.');
        $this->assertSame(1, Business::query()->count());

        $binding = BusinessGoogleLocation::query()->firstOrFail();
        $this->assertSame((int) $business->id, (int) $binding->business_id);
        $this->assertSame((int) $business->locations()->where('is_primary', true)->value('id'), (int) $binding->business_location_id);
        $this->assertNotNull($customer);
    }

    public function test_without_the_entitlement_the_option_is_hidden_and_connecting_is_refused(): void
    {
        $this->signupCustomer([], WorkspacePlanTier::Core);

        $this->get(route('customer.onboarding.show'))
            ->assertOk()
            ->assertDontSee('Connect Google Business Profile')
            ->assertSee('data-role="business-form"', false);

        $this->post(route('customer.onboarding.google.connect'))->assertRedirect(route('customer.onboarding.show'))->assertSessionHasErrors('onboarding');
        $this->assertSame(0, BusinessGoogleConnection::query()->count());
        $this->assertSame(0, $this->fakeGoogle->callCount('authorizationUrl'));
    }

    public function test_without_the_permission_the_option_is_hidden_and_connecting_is_forbidden(): void
    {
        [, , $workspace] = $this->signupCustomer([], WorkspacePlanTier::Growth, ['view_google_business_profile']);
        // Not the Workspace owner, and no explicit permission: nothing authorizes Google management.
        $workspace->forceFill(['owner_user_id' => $this->platformAdminId()])->save();

        $this->get(route('customer.onboarding.show'))
            ->assertOk()
            ->assertDontSee('Connect Google Business Profile')
            ->assertSee('data-role="business-form"', false);

        // This application renders a denied authorization as 401 outside local (existing behaviour).
        $this->post(route('customer.onboarding.google.connect'))->assertUnauthorized();
        $this->assertSame(0, BusinessGoogleConnection::query()->count());
    }

    public function test_manual_setup_remains_available_and_unchanged(): void
    {
        [$customer, $business] = $this->signupCustomer();

        // "Set up manually" hides the Google panel and keeps the prefilled form.
        $this->get(route('customer.onboarding.show', ['step' => 'business', 'manual' => 1]))
            ->assertOk()
            ->assertDontSee('Connect Google Business Profile')
            ->assertSee('value="Signup Booths"', false);

        $this->post(route('customer.onboarding.business.store'), [
            'name' => 'Renamed By Hand',
            'industry' => 'photo_booth_service',
            'country_code' => 'US',
            'timezone' => 'America/New_York',
            'currency_code' => 'USD',
        ])->assertRedirect(route('customer.onboarding.show', ['step' => 'location']));

        $this->assertSame('Renamed By Hand', $business->refresh()->name);
        $this->assertSame(0, BusinessGoogleConnection::query()->count());
        $this->assertSame([], $this->fakeGoogle->calls);
        $this->assertNotNull($customer);
    }

    public function test_diagnostic_does_not_create_onboarding_for_a_customer_who_has_not_started(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $customer = $this->createCustomer();

        $this->assertSame(0, CustomerOnboarding::query()->where('customer_id', $customer->user_id)->count());

        $this->artisan('gbp:diagnose-onboarding', ['customer' => (string) $customer->user_id])
            ->expectsOutputToContain('onboarding_not_started')
            ->assertExitCode(0);

        $this->assertSame(0, CustomerOnboarding::query()->where('customer_id', $customer->user_id)->count());
    }

    public function test_the_diagnostic_names_the_cause_and_never_prints_a_secret(): void
    {
        [$customer, , $workspace] = $this->signupCustomer([], WorkspacePlanTier::Growth, ['view_google_business_profile']);
        $workspace->forceFill(['owner_user_id' => $this->platformAdminId()])->save();

        config(['services.google_business_profile.client_secret' => 'super-secret-value-123']);

        $this->artisan('gbp:diagnose-onboarding', ['customer' => (string) $customer->user_id])
            ->expectsOutputToContain('permission_manage_google_business_profile_missing')
            ->doesntExpectOutputToContain('super-secret-value-123')
            ->doesntExpectOutputToContain('test-client-id')
            ->assertExitCode(0);

        config(['services.google_business_profile.client_id' => null]);

        $this->artisan('gbp:diagnose-onboarding', ['customer' => (string) $customer->user_id])
            ->expectsOutputToContain('oauth_missing_client_id')
            ->assertExitCode(0);
    }

    public function test_a_new_signup_with_the_real_default_permissions_is_authorized_as_workspace_owner(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->reserveSuperAdminId();

        // The ACTUAL provisioning default every signup path writes (Customer::customerPermissions()); nothing is granted by hand.
        $defaults = json_decode(Customer::customerPermissions(), true);
        $this->assertNotContains('manage_google_business_profile', $defaults, 'The global default stays false.');

        $customer = $this->createCustomer();
        $customer->permissions = Customer::customerPermissions();
        $customer->save();

        $created = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Signup Booths']));
        app(EntitlementManager::class)->assignFirstPlan(Workspace::query()->findOrFail($created->workspace_id), WorkspacePlanTier::Growth, $this->platformAdminId(), 'Fixture.', true, 0);
        app(OnboardingManager::class)->startForProvisionedBusiness($customer, $created);
        $this->authenticateAsCustomer($customer, $defaults);

        $this->get(route('customer.onboarding.show'))->assertOk()->assertSee('Connect Google Business Profile');

        // The whole connection works for the owner, including the OAuth callback.
        $this->fakeGoogleWithLocation();
        $this->connectFully();
        $this->assertSame(GoogleConnectionState::Active, BusinessGoogleConnection::query()->firstOrFail()->state);
        $this->assertSame(BusinessStatus::Draft, $created->refresh()->status);
        $this->get(route('customer.onboarding.google.show'))->assertOk()->assertSee('name="candidate_token"', false);

        // Ownership grants NOTHING outside onboarding: the Active-Business GBP module still needs the permission.
        $this->assertFalse(\Illuminate\Support\Facades\Gate::forUser($customer->user)->allows('manage_google_business_profile'));
    }

    public function test_a_non_owner_without_the_permission_cannot_complete_a_callback(): void
    {
        [$customer, , $workspace] = $this->signupCustomer();
        $state = $this->beginConnect();

        // Ownership is revoked after the attempt began, and the actor holds no explicit permission.
        $workspace->forceFill(['owner_user_id' => $this->platformAdminId()])->save();
        $this->authenticateAsCustomer($customer, ['view_google_business_profile']);

        $this->get(route(GoogleBusinessProfileOAuthConfig::CALLBACK_ROUTE) . '?code=c&state=' . urlencode($state))->assertNotFound();
        $this->assertSame(0, $this->fakeGoogle->callCount('exchangeAuthorizationCode'));
    }

    // -----------------------------------------------------------------
    // Helpers

    // -----------------------------------------------------------------

    /**
     * What a paid V1 signup leaves behind: the customer owns a Workspace on a plan, with one real Draft Business
     * already attached to their onboarding.
     *
     * @return array{0: Customer, 1: Business, 2: Workspace}
     */
    private function signupCustomer(array $business = [], WorkspacePlanTier $tier = WorkspacePlanTier::Growth, array $permissions = self::MANAGE): array
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->reserveSuperAdminId();

        $customer = $this->createCustomer();
        $customer->permissions = json_encode($permissions);
        $customer->save();

        $created = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(array_merge(['name' => 'Signup Booths'], $business)));
        $workspace = Workspace::query()->findOrFail($created->workspace_id);
        app(EntitlementManager::class)->assignFirstPlan($workspace, $tier, $this->platformAdminId(), 'Fixture.', true, 0);

        app(OnboardingManager::class)->startForProvisionedBusiness($customer, $created);

        $this->authenticateAsCustomer($customer, $permissions);

        return [$customer, $created->refresh(), $workspace];
    }

    private function beginConnect(): string
    {
        $response = $this->post(route('customer.onboarding.google.connect'));
        $response->assertRedirect();
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        return (string) $query['state'];
    }

    private function connectFully(): void
    {
        $state = $this->beginConnect();
        $this->get(route(GoogleBusinessProfileOAuthConfig::CALLBACK_ROUTE) . '?code=c&state=' . urlencode($state))
            ->assertRedirect(route('customer.onboarding.google.show'));
    }

    private function firstOfferToken(TestResponse $response): string
    {
        $this->assertSame(1, preg_match('/name="candidate_token" value="([^"]+)"/', $response->getContent(), $match));

        return html_entity_decode($match[1]);
    }

    private function onboardingOf(Customer $customer): CustomerOnboarding
    {
        return app(OnboardingManager::class)->start($customer->refresh());
    }
}

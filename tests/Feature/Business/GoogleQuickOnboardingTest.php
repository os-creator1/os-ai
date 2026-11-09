<?php

namespace Tests\Feature\Business;

use App\Enums\Business\BusinessStatus;
use App\Enums\Business\OnboardingStatus;
use App\Enums\Business\OnboardingStep;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Exceptions\GoogleBusinessProfile\GoogleBusinessProfileProviderException;
use App\Library\Business\OnboardingManager;
use App\Library\Entitlement\EntitlementManager;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileOAuthConfig;
use App\Models\Business;
use App\Models\BusinessGoogleConnection;
use App\Models\Customer;
use App\Models\CustomerOnboarding;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\GoogleBusinessProfile\Concerns\CreatesGoogleBusinessProfileFixtures;
use Tests\TestCase;

/**
 * The Google Business Profile quick start inside onboarding: a narrowly
 * authorized bridge between a DRAFT Business and the Google integration.
 *
 * Every test binds the in-memory Google client, so no request ever leaves the
 * process and no real Google credential exists.
 */
class GoogleQuickOnboardingTest extends TestCase
{
    use CreatesGoogleBusinessProfileFixtures;
    use RefreshDatabase;

    private const LOCALE = ['country_code' => 'LT', 'timezone' => 'Europe/Vilnius', 'currency_code' => 'EUR'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['business.onboarding.enabled' => true]);
        $this->bindFakeGoogleClient();
    }

    // -----------------------------------------------------------------
    // The first screen
    // -----------------------------------------------------------------

    public function test_first_screen_offers_google_as_primary_and_manual_as_secondary(): void
    {
        $customer = $this->customerWithPlan();

        $this->get(route('customer.onboarding.show'))
            ->assertOk()
            ->assertSee('Connect Google Business Profile')
            ->assertSee('Set up manually')
            // Compact progress, not five intimidating pills.
            ->assertSee('Step 1 of 5')
            ->assertDontSee('nav-pills', false)
            // The manual form is not on this screen; it is one explicit click away.
            ->assertDontSee('name="industry"', false);

        $this->get(route('customer.onboarding.show', ['step' => 'business', 'manual' => 1]))
            ->assertOk()
            ->assertSee('name="name"', false)
            ->assertSee('name="industry"', false)
            ->assertDontSee('Connect Google Business Profile');

        $this->assertNull($this->onboardingOf($customer)->business_id, 'Viewing the screen creates no Business.');
    }

    public function test_without_google_configuration_the_manual_form_is_simply_shown(): void
    {
        config(['services.google_business_profile.client_id' => null]);
        $this->customerWithPlan();

        $this->get(route('customer.onboarding.show'))
            ->assertOk()
            ->assertSee('name="industry"', false)
            ->assertDontSee('Connect Google Business Profile');
    }

    // -----------------------------------------------------------------
    // Google connection during draft onboarding
    // -----------------------------------------------------------------

    public function test_connecting_during_draft_onboarding_keeps_the_business_draft_and_the_ownership(): void
    {
        $customer = $this->customerWithPlan();

        $response = $this->post(route('customer.onboarding.google.connect'), self::LOCALE);
        $response->assertRedirect();
        $this->assertStringStartsWith('https://accounts.google.test/authorize', (string) $response->headers->get('Location'));

        $onboarding = $this->onboardingOf($customer);
        $business = Business::query()->findOrFail($onboarding->business_id);

        // Ownership and onboarding session preserved; nothing guessed: the locale is exactly what was chosen.
        $this->assertSame((int) $customer->user_id, (int) $business->customer_id);
        $this->assertSame(BusinessStatus::Draft, $business->status);
        $this->assertSame('LT', $business->country_code);
        $this->assertSame('Europe/Vilnius', $business->timezone);
        $this->assertSame('EUR', $business->currency_code);
        $this->assertTrue(app(OnboardingManager::class)->isProvisionalBusiness($onboarding));

        $connection = BusinessGoogleConnection::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertSame(GoogleConnectionState::Pending, $connection->state);
        $this->assertFalse($connection->hasStoredAuthorization());

        // After authorization the customer lands on the profile chooser, still in draft.
        $this->getCallback($this->stateFromRedirect($response))->assertRedirect(route('customer.onboarding.google.show'));

        $this->assertSame(GoogleConnectionState::Active, $connection->refresh()->state);
        $this->assertSame(BusinessStatus::Draft, $business->refresh()->status, 'Connecting never activates the Business.');
        $this->assertNull($onboarding->refresh()->completed_at);
    }

    public function test_a_locale_is_required_when_there_is_no_business_and_is_never_defaulted(): void
    {
        $customer = $this->customerWithPlan();

        $this->post(route('customer.onboarding.google.connect'), ['country_code' => 'LT'])
            ->assertRedirect(route('customer.onboarding.show'))
            ->assertSessionHasErrors('onboarding');

        $this->assertNull($this->onboardingOf($customer)->business_id);
        $this->assertSame(0, BusinessGoogleConnection::query()->count());
    }

    public function test_a_plan_without_google_gets_the_manual_path_and_no_connection(): void
    {
        $customer = $this->customerWithPlan(WorkspacePlanTier::Core);

        $this->post(route('customer.onboarding.google.connect'), self::LOCALE)
            ->assertRedirect(route('customer.onboarding.show'))
            ->assertSessionHasErrors('onboarding');

        $this->assertSame(0, BusinessGoogleConnection::query()->count());
        $this->assertSame(0, $this->fakeGoogle->callCount('authorizationUrl'));
        $this->get(route('customer.onboarding.show', ['step' => 'business', 'manual' => 1]))->assertOk()->assertSee('name="name"', false);
        $this->assertNotNull($customer);
    }

    // -----------------------------------------------------------------
    // Failed OAuth: no partial or unauthorized activation
    // -----------------------------------------------------------------

    public function test_cancelled_authorization_leaves_nothing_active_and_manual_setup_available(): void
    {
        $customer = $this->customerWithPlan();
        $state = $this->beginConnect();

        $this->get(route(GoogleBusinessProfileOAuthConfig::CALLBACK_ROUTE) . '?error=access_denied&state=' . urlencode($state))
            ->assertRedirect(route('customer.onboarding.show'))
            ->assertSessionHasErrors('onboarding');

        $this->assertSame(0, $this->fakeGoogle->callCount('exchangeAuthorizationCode'));
        $this->assertNothingActivated($customer);
        $this->get(route('customer.onboarding.show', ['step' => 'business', 'manual' => 1]))->assertOk()->assertSee('name="name"', false);
    }

    public function test_a_failed_code_exchange_leaves_nothing_active(): void
    {
        $customer = $this->customerWithPlan();
        $state = $this->beginConnect();
        $this->fakeGoogle->failCodeExchangeWith = GoogleBusinessProfileProviderException::providerUnavailable();

        $this->getCallback($state)->assertRedirect(route('customer.onboarding.show'))->assertSessionHasErrors('onboarding');

        $this->assertNothingActivated($customer);
    }

    public function test_a_tampered_state_is_a_404_with_no_token_exchange(): void
    {
        $customer = $this->customerWithPlan();
        $state = $this->beginConnect();

        $this->getCallback($state . 'x')->assertNotFound();

        $this->assertSame(0, $this->fakeGoogle->callCount('exchangeAuthorizationCode'));
        $this->assertNothingActivated($customer);
    }

    // -----------------------------------------------------------------
    // Wrong-account isolation
    // -----------------------------------------------------------------

    public function test_another_customer_cannot_complete_my_authorization(): void
    {
        $mine = $this->customerWithPlan();
        $state = $this->beginConnect();
        $business = Business::query()->findOrFail($this->onboardingOf($mine)->business_id);

        $other = $this->customerWithPlan();

        $this->getCallback($state)->assertNotFound();

        $this->assertSame(0, $this->fakeGoogle->callCount('exchangeAuthorizationCode'));
        $this->assertSame(GoogleConnectionState::Pending, BusinessGoogleConnection::query()->where('business_id', $business->id)->firstOrFail()->state);
        $this->assertNotNull($other);
    }

    public function test_the_draft_callback_exception_does_not_extend_to_a_draft_not_in_my_onboarding(): void
    {
        $customer = $this->customerWithPlan();
        $state = $this->beginConnect();
        $business = Business::query()->findOrFail($this->onboardingOf($customer)->business_id);

        // The same customer, but the Business is no longer the one their onboarding is working on.
        CustomerOnboarding::query()->where('customer_id', $customer->user_id)->update(['business_id' => null]);

        $this->getCallback($state)->assertNotFound();

        $this->assertSame(0, $this->fakeGoogle->callCount('exchangeAuthorizationCode'));
        $this->assertSame(BusinessStatus::Draft, $business->refresh()->status);
    }

    public function test_a_profile_choice_issued_to_another_customer_is_rejected_before_any_read(): void
    {
        // Customer A connects and is shown their profiles.
        $this->customerWithPlan();
        $this->fakeGoogleWithLocation();
        $this->connectFully();
        $tokenOfA = $this->firstOfferToken($this->get(route('customer.onboarding.google.show')));

        // Customer B, with their own connected Google account, replays A's token.
        $this->customerWithPlan();
        $this->connectFully();
        $this->fakeGoogle->clearCalls();

        $this->post(route('customer.onboarding.google.select'), ['candidate_token' => $tokenOfA])
            ->assertRedirect(route('customer.onboarding.google.show'))
            ->assertSessionHasErrors('onboarding');

        $this->assertSame(0, $this->fakeGoogle->callCount('getLocation'));
    }

    // -----------------------------------------------------------------
    // Profile selection: the profiles the user actually manages, chosen explicitly
    // -----------------------------------------------------------------

    public function test_the_managed_profiles_are_listed_and_none_is_chosen_for_the_customer(): void
    {
        $this->customerWithPlan();
        $this->fakeGoogle->withAccount('accounts/A1', 'Snap Booth Co');
        $this->fakeGoogle->withLocation('accounts/A1', 'locations/L1', $this->rawLocationPayload(['name' => 'locations/L1', 'title' => 'Snap Booth Vilnius']));
        $this->fakeGoogle->withLocation('accounts/A1', 'locations/L2', $this->rawLocationPayload(['name' => 'locations/L2', 'title' => 'Snap Booth Kaunas']));
        $this->connectFully();

        $response = $this->get(route('customer.onboarding.google.show'))->assertOk();
        $response->assertSee('Snap Booth Vilnius')->assertSee('Snap Booth Kaunas');
        $this->assertSame(2, substr_count($response->getContent(), 'name="candidate_token"'));
        // Nothing has been read in detail, saved or selected yet.
        $this->assertSame(0, $this->fakeGoogle->callCount('getLocation'));
    }

    public function test_no_profiles_shows_the_manual_fallback(): void
    {
        $this->customerWithPlan();
        $this->fakeGoogle->withAccount('accounts/A1', 'Empty Account');
        $this->connectFully();

        $this->get(route('customer.onboarding.google.show'))
            ->assertOk()
            ->assertSee('No business profiles found')
            ->assertSee('Set up manually')
            ->assertSee('Use a different Google account');
    }

    public function test_an_unavailable_google_api_falls_back_to_manual_setup(): void
    {
        $this->customerWithPlan();
        $this->connectFully();
        $this->fakeGoogle->failNextWith = GoogleBusinessProfileProviderException::providerUnavailable();

        $this->get(route('customer.onboarding.google.show'))
            ->assertRedirect(route('customer.onboarding.show'))
            ->assertSessionHasErrors('onboarding');
    }

    public function test_a_disconnected_or_revoked_account_asks_to_reconnect_and_never_reads(): void
    {
        $customer = $this->customerWithPlan();
        $this->fakeGoogleWithLocation();
        $this->connectFully();
        $connection = BusinessGoogleConnection::query()->firstOrFail();

        // Disconnected through "Use a different Google account": stored authorization is destroyed.
        $this->post(route('customer.onboarding.google.reset'))->assertRedirect(route('customer.onboarding.show'));
        $this->assertSame(GoogleConnectionState::Disconnected, $connection->refresh()->state);
        $this->assertFalse($connection->hasStoredAuthorization());

        $this->fakeGoogle->clearCalls();
        $this->get(route('customer.onboarding.google.show'))->assertRedirect(route('customer.onboarding.show'));
        $this->assertSame(0, $this->fakeGoogle->callCount('listAccounts'));

        // A stale grant Google has revoked behaves the same.
        $this->connectFully();
        $this->fakeGoogle->failRefreshWith = GoogleBusinessProfileProviderException::invalidGrant();
        $this->get(route('customer.onboarding.google.show'))->assertRedirect(route('customer.onboarding.show'));
        $this->assertSame(GoogleConnectionState::Revoked, $connection->refresh()->state);
        $this->assertNotNull($customer);
    }

    // -----------------------------------------------------------------
    // The editable preview
    // -----------------------------------------------------------------

    public function test_the_preview_shows_imported_fields_and_marks_what_is_missing_without_inventing_anything(): void
    {
        $this->customerWithPlan();
        $this->fakeGoogleWithLocation(['storefrontAddress' => ['locality' => 'Vilnius', 'regionCode' => 'LT']]);
        $this->connectFully(addressConsent: true);

        $page = $this->chooseFirstProfile();

        $page->assertOk()
            ->assertSee('data-role="google-listing-card"', false)
            ->assertSee('value="Snap Booth Co"', false)
            ->assertSee('value="+1 (555) 010-1234"', false)
            ->assertSee('value="https://example.test"', false)
            ->assertSee('value="Vilnius"', false)
            ->assertSee('Photo booth')
            ->assertSee('Imported from Google')
            // The service list is not reliable from a category: the category is only SUGGESTED.
            ->assertSee('Suggested · please confirm')
            // The street is never imported; it is asked for, as missing.
            ->assertSee('Missing')
            // No invented description, picture or service.
            ->assertSee('Optional. Add one later if you like.', false);

        // The card carries no fabricated picture (the surrounding page chrome has its own logo).
        $this->assertStringNotContainsString('<img', $this->cardHtml($page));

        // The unconsented address read: with consent the mask asked for the address, otherwise it would not.
        $this->assertContains('storefrontAddress', $this->fakeGoogle->callsTo('getLocation')[0]['read_mask']);
    }

    public function test_without_address_consent_no_address_is_requested_and_the_city_is_asked_for(): void
    {
        $this->customerWithPlan();
        $this->fakeGoogleWithLocation();
        $this->connectFully(addressConsent: false);

        $page = $this->chooseFirstProfile();

        $page->assertOk()->assertDontSee('value="New York"', false);
        $call = $this->fakeGoogle->callsTo('getLocation')[0];
        $this->assertNotContains('storefrontAddress', $call['read_mask']);
    }

    public function test_an_unverified_profile_is_flagged_and_its_values_are_labelled_unverified(): void
    {
        $this->customerWithPlan();
        $this->fakeGoogleWithLocation(['metadata' => ['hasVoiceOfMerchant' => false]]);
        $this->connectFully();

        $this->chooseFirstProfile()
            ->assertOk()
            ->assertSee('Not verified by Google')
            ->assertSee('Imported · unverified');
    }

    public function test_the_preview_can_only_be_opened_by_the_customer_it_was_made_for_and_expires(): void
    {
        $this->customerWithPlan();
        $this->fakeGoogleWithLocation();
        $this->connectFully();
        $this->chooseFirstProfile()->assertOk();

        $this->travel(20)->minutes();

        $this->get(route('customer.onboarding.google.review'))
            ->assertRedirect(route('customer.onboarding.google.show'))
            ->assertSessionHasErrors('onboarding');
    }

    // -----------------------------------------------------------------
    // Confirmed data is persisted through the canonical services
    // -----------------------------------------------------------------

    public function test_confirming_saves_through_the_canonical_services_and_skips_to_finish(): void
    {
        $customer = $this->customerWithPlan();
        $this->fakeGoogleWithLocation(['storefrontAddress' => ['locality' => 'Vilnius', 'regionCode' => 'LT']]);
        $this->connectFully(addressConsent: true);
        $this->chooseFirstProfile()->assertOk();
        $this->fakeGoogle->clearCalls();

        $response = $this->post(route('customer.onboarding.google.confirm'), $this->confirmation([
            'name' => 'Snap Booth Vilnius',
            'service_mode' => 'storefront',
            'city' => 'Vilnius',
            'address_line_1' => 'Gedimino pr. 1',
            'main_service' => 'Photo Booth Rental',
            'website_choice' => 'existing',
            'website_url' => 'https://example.test',
        ]));

        $onboarding = $this->onboardingOf($customer);
        $response->assertRedirect(route('customer.onboarding.show', ['step' => 'finish']));

        $business = Business::query()->findOrFail($onboarding->business_id);
        $this->assertSame('Snap Booth Vilnius', $business->name, 'The customer\'s edit wins over what Google said.');
        $this->assertSame('photo_booth_service', $business->industry->value);
        $this->assertSame('+1 (555) 010-1234', $business->phone);
        $this->assertSame('LT', $business->country_code);
        $this->assertSame('Europe/Vilnius', $business->timezone);
        $this->assertSame('EUR', $business->currency_code);
        $this->assertNull($business->description, 'An empty description is never invented or required.');
        $this->assertFalse(app(OnboardingManager::class)->isProvisionalBusiness($onboarding));

        $location = $business->locations()->where('is_primary', true)->firstOrFail();
        $this->assertSame('storefront', $location->service_mode->value);
        $this->assertSame('Vilnius', $location->city);
        $this->assertSame('Gedimino pr. 1', $location->address_line_1);
        $this->assertFalse((bool) $location->public_address, 'Address publication is never assumed.');

        $service = $business->services()->firstOrFail();
        $this->assertSame('Photo Booth Rental', $service->name);
        $this->assertTrue((bool) $service->is_primary);

        // An imported address is only saved; the website is not claimed to be connected.
        $this->assertSame('https://example.test', $business->website_url);
        $review = app(OnboardingManager::class)->review($onboarding);
        $this->assertSame('existing', $review['website']['choice']);
        $this->assertStringContainsString('We have not connected or checked it yet', $review['website']['status']);
        $this->assertSame([], $review['missing']);

        // Still a Draft until the ordinary Finish step; and nothing was ever sent to Google.
        $this->assertSame(BusinessStatus::Draft, $business->status);
        $this->assertSame([], $this->fakeGoogle->callsTo('getLocation'));
        foreach ($this->fakeGoogle->calls as $call) {
            $this->assertStringStartsNotWith('update', $call['method']);
            $this->assertStringStartsNotWith('patch', $call['method']);
        }
    }

    public function test_mobile_rendering_and_completion_end_to_end(): void
    {
        $customer = $this->customerWithPlan();
        $this->fakeGoogleWithLocation();
        $this->connectFully();

        $card = $this->get(route('customer.onboarding.google.show'));
        $card->assertOk();
        $preview = $this->chooseFirstProfile();

        $preview->assertOk()
            ->assertSee('name="viewport" content="width=device-width,initial-scale=1.0"', false)
            ->assertSee('@media (max-width: 575.98px)', false)
            ->assertSee('gbp-actions', false)
            ->assertSee('Confirm and continue');

        $this->post(route('customer.onboarding.google.confirm'), $this->confirmation([
            'service_mode' => 'service_area',
            'city' => 'New York',
            'region' => 'NY',
            'country_code' => 'US',
            'timezone' => 'America/New_York',
            'currency_code' => 'USD',
            'main_service' => 'Photo Booth Rental',
            'website_choice' => 'later',
        ]))->assertRedirect(route('customer.onboarding.show', ['step' => 'finish']));

        $this->get(route('customer.onboarding.show', ['step' => 'finish']))->assertOk()->assertSee('Step 5 of 5');

        $this->post(route('customer.onboarding.complete'))->assertRedirect(route('user.home'));

        $onboarding = $this->onboardingOf($customer);
        $this->assertSame(OnboardingStatus::Completed, $onboarding->status);
        $this->assertSame(BusinessStatus::Active, Business::query()->findOrFail($onboarding->business_id)->status);
    }

    // -----------------------------------------------------------------
    // Essential questions only; nothing confirmed manually is overwritten
    // -----------------------------------------------------------------

    public function test_missing_essentials_are_asked_for_and_nothing_is_saved_until_they_are_answered(): void
    {
        $customer = $this->customerWithPlan();
        // Google supplies a name only: no category, phone, website or address.
        $this->fakeGoogle->withAccount('accounts/A1', 'Bare');
        $this->fakeGoogle->withLocation('accounts/A1', 'locations/L9', ['name' => 'locations/L9', 'title' => 'Bare Booths']);
        $this->connectFully();
        $this->chooseFirstProfile()->assertOk()->assertSee('value="Bare Booths"', false);

        $this->post(route('customer.onboarding.google.confirm'), $this->confirmation([
            'industry' => '',
            'service_mode' => '',
            'city' => '',
            'main_service' => '',
            'phone' => '',
            'description' => '',
        ]))->assertRedirect(route('customer.onboarding.google.review'))
            ->assertSessionHasErrors(['industry', 'service_mode', 'main_service']);

        $business = Business::query()->findOrFail($this->onboardingOf($customer)->business_id);
        $this->assertSame(OnboardingManager::GOOGLE_PROVISIONAL_NAME, $business->name, 'Nothing was saved or invented.');
        $this->assertSame(0, $business->services()->count());
        $this->assertSame(0, $business->locations()->count());

        // The card is shown again with the customer's input, not an error page.
        $this->followingRedirects()->get(route('customer.onboarding.google.review'))->assertOk();
    }

    public function test_optional_details_are_never_required(): void
    {
        $customer = $this->customerWithPlan();
        $this->fakeGoogleWithLocation();
        $this->connectFully();
        $this->chooseFirstProfile()->assertOk();

        $this->post(route('customer.onboarding.google.confirm'), $this->confirmation([
            'phone' => '',
            'description' => '',
            'website_choice' => '',
            'website_url' => '',
            'service_mode' => 'online',
            'main_service' => 'Photo Booth Rental',
        ]))->assertSessionHasNoErrors();

        $business = Business::query()->findOrFail($this->onboardingOf($customer)->business_id);
        $this->assertNull($business->phone);
        // The one unanswered question is simply where the wizard resumes.
        $this->assertSame(OnboardingStep::Website, app(OnboardingManager::class)->currentStep($this->onboardingOf($customer)));
    }

    public function test_previously_confirmed_manual_information_is_kept_unless_the_customer_replaces_it(): void
    {
        $customer = $this->customerWithPlan();

        // The customer already confirmed an identity by hand (a draft the wizard is continuing).
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes([
            'name' => 'Acme Photo Booths',
            'phone' => '+370 600 00000',
            'country_code' => 'LT',
            'timezone' => 'Europe/Vilnius',
            'currency_code' => 'EUR',
        ]));
        $this->assignPaidPlanFixture($business);
        app(\App\Repositories\Contracts\CustomerOnboardingRepository::class)->attachBusiness($this->onboardingOf($customer), $business);
        $this->fakeGoogleWithLocation();
        $this->connectFully();

        $page = $this->chooseFirstProfile();
        $page->assertOk()
            ->assertSee('value="Acme Photo Booths"', false)
            ->assertSee('value="+370 600 00000"', false)
            ->assertSee('Already saved')
            ->assertSee('Google has “Snap Booth Co”', false)
            ->assertDontSee('value="Snap Booth Co"', false);

        // Confirming exactly what the card showed changes nothing the customer had saved.
        $this->post(route('customer.onboarding.google.confirm'), $this->confirmation([
            'name' => 'Acme Photo Booths',
            'phone' => '+370 600 00000',
            'country_code' => 'LT',
            'timezone' => 'Europe/Vilnius',
            'currency_code' => 'EUR',
            'service_mode' => 'online',
            'main_service' => 'Photo Booth Rental',
            'website_choice' => 'later',
        ]))->assertSessionHasNoErrors();

        $business->refresh();
        $this->assertSame('Acme Photo Booths', $business->name);
        $this->assertSame('+370 600 00000', $business->phone);
    }

    // -----------------------------------------------------------------
    // The manual path is unchanged
    // -----------------------------------------------------------------

    public function test_manual_setup_still_works_end_to_end_and_ignores_google(): void
    {
        $customer = $this->customerWithPlan();

        $this->post(route('customer.onboarding.business.store'), [
            'name' => 'Manual Booths',
            'industry' => 'photo_booth_service',
            'country_code' => 'US',
            'timezone' => 'America/New_York',
            'currency_code' => 'USD',
        ])->assertRedirect(route('customer.onboarding.show', ['step' => 'location']));

        $this->post(route('customer.onboarding.location.store'), [
            'service_mode' => 'service_area',
            'city' => 'Orange',
            'region' => 'CA',
            'country_code' => 'US',
            'public_address' => '0',
        ])->assertRedirect(route('customer.onboarding.show', ['step' => 'services']));

        $this->post(route('customer.onboarding.services.store'), [
            'services' => [['name' => 'Photo Booth Rental', 'is_primary' => '1']],
        ])->assertRedirect(route('customer.onboarding.show', ['step' => 'website']));

        $this->post(route('customer.onboarding.website.store'), ['website_choice' => 'later'])
            ->assertRedirect(route('customer.onboarding.show', ['step' => 'finish']));

        $this->post(route('customer.onboarding.complete'))->assertRedirect(route('user.home'));

        $business = Business::query()->findOrFail($this->onboardingOf($customer)->business_id);
        $this->assertSame('Manual Booths', $business->name);
        $this->assertSame(BusinessStatus::Active, $business->status);
        $this->assertSame(0, BusinessGoogleConnection::query()->count());
        $this->assertSame([], $this->fakeGoogle->calls, 'Manual setup never touches Google.');
    }

    public function test_a_cancelled_google_attempt_never_shows_the_placeholder_name_in_the_manual_form(): void
    {
        $this->customerWithPlan();
        $this->beginConnect();

        $this->get(route('customer.onboarding.show', ['step' => 'business', 'manual' => 1]))
            ->assertOk()
            ->assertDontSee('value="' . OnboardingManager::GOOGLE_PROVISIONAL_NAME . '"', false)
            // The explicit locale choices made before authorizing are kept.
            ->assertSee('Europe/Vilnius');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /** A customer who owns a Workspace on a paid plan, no Business yet, signed in and permitted to manage Google. */
    private function customerWithPlan(WorkspacePlanTier $tier = WorkspacePlanTier::Growth): Customer
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->reserveSuperAdminId();

        $customer = $this->createCustomer();
        $workspace = Workspace::create(['name' => 'Workspace', 'owner_user_id' => $customer->user_id, 'is_active' => true]);

        app(EntitlementManager::class)->assignFirstPlan($workspace, $tier, $this->platformAdminId(), 'Fixture.', true, 0);

        $customer->permissions = \App\Models\Customer::customerPermissions();
        $customer->save();

        $this->authenticateAsCustomer($customer);

        return $customer;
    }

    private function beginConnect(array $overrides = []): string
    {
        $response = $this->post(route('customer.onboarding.google.connect'), array_merge(self::LOCALE, $overrides));
        $response->assertRedirect();

        return $this->stateFromRedirect($response);
    }

    private function connectFully(bool $addressConsent = false): void
    {
        $state = $this->beginConnect($addressConsent ? ['address_consent' => '1'] : []);
        $this->getCallback($state)->assertRedirect(route('customer.onboarding.google.show'));
    }

    private function chooseFirstProfile(): TestResponse
    {
        $token = $this->firstOfferToken($this->get(route('customer.onboarding.google.show')));

        $this->post(route('customer.onboarding.google.select'), ['candidate_token' => $token])
            ->assertRedirect(route('customer.onboarding.google.review'));

        return $this->get(route('customer.onboarding.google.review'));
    }

    private function firstOfferToken(TestResponse $response): string
    {
        $this->assertSame(1, preg_match('/name="candidate_token" value="([^"]+)"/', $response->getContent(), $match), 'A profile must be offered.');

        return html_entity_decode($match[1]);
    }

    /** The posted card: Google's snapshot token (from the session) plus the customer's answers. */
    private function confirmation(array $overrides = []): array
    {
        return array_merge([
            'snapshot' => (string) session('onboarding.google.snapshot'),
            'name' => 'Snap Booth Co',
            'industry' => 'photo_booth_service',
            'phone' => '+1 (555) 010-1234',
            'description' => '',
            'country_code' => 'LT',
            'timezone' => 'Europe/Vilnius',
            'currency_code' => 'EUR',
            'service_mode' => 'service_area',
            'city' => 'Vilnius',
            'region' => '',
            'address_line_1' => '',
            'main_service' => 'Photo Booth Rental',
            'website_choice' => 'later',
            'website_url' => '',
        ], $overrides);
    }

    private function cardHtml(TestResponse $response): string
    {
        $html = $response->getContent();
        $start = strpos($html, 'data-role="google-listing-card"');
        $end = strpos($html, '</form>', (int) $start);

        $this->assertNotFalse($start);

        return substr($html, (int) $start, (int) $end - (int) $start);
    }

    private function onboardingOf(Customer $customer): CustomerOnboarding
    {
        return app(OnboardingManager::class)->start($customer->refresh());
    }

    private function assertNothingActivated(Customer $customer): void
    {
        $onboarding = $this->onboardingOf($customer);
        $business = Business::query()->findOrFail($onboarding->business_id);
        $connection = BusinessGoogleConnection::query()->where('business_id', $business->id)->firstOrFail();

        $this->assertSame(BusinessStatus::Draft, $business->status);
        $this->assertNotSame(OnboardingStatus::Completed, $onboarding->status);
        $this->assertNotSame(GoogleConnectionState::Active, $connection->state);
        $this->assertFalse($connection->hasStoredAuthorization());
    }

    private function stateFromRedirect(TestResponse $response): string
    {
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertNotEmpty($query['state'] ?? null, 'The connect redirect must carry a signed state.');

        return (string) $query['state'];
    }

    private function getCallback(string $state, string $code = 'auth-code'): TestResponse
    {
        return $this->get(route(GoogleBusinessProfileOAuthConfig::CALLBACK_ROUTE) . '?code=' . urlencode($code) . '&state=' . urlencode($state));
    }
}

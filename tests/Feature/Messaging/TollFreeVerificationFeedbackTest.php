<?php

namespace Tests\Feature\Messaging;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Messaging\MessagingEntityType;
use App\Enums\Messaging\MessagingRegistrationStatus;
use App\Enums\Messaging\PhoneNumberType;
use App\Library\Messaging\BusinessMessagingRegistrationService;
use App\Library\Messaging\DTO\MessagingRegistrationSubmission;
use App\Library\Messaging\DTO\RegistrationStatusQuery;
use App\Library\Messaging\Exceptions\MessagingRegistrationPlatformConfigurationException;
use App\Library\Messaging\Exceptions\MessagingRegistrationPreflightException;
use App\Library\Messaging\Registration\ProviderReasonSanitizer;
use App\Library\Messaging\TelnyxProvisioningAdapter;
use App\Models\BusinessMessagingRegistration;
use App\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Toll-free verification feedback: the provider's real state and reason are
 * kept and shown, never collapsed to a generic "pending". Telnyx is always
 * faked; nothing here can reach the real API.
 */
class TollFreeVerificationFeedbackTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesCustomerContextFixtures;
    use CreatesMessagingFixtures;

    private const URL = 'api.telnyx.com/v2/messaging_tollfree/verification/requests/tfv_fb';

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        config([
            'messaging.managed_messaging_enabled' => true,
            'messaging.managed_messaging_provisioning_enabled' => true,
            'services.telnyx.api_key' => 'fixture_api_key_not_a_real_credential',
            'messaging.toll_free_isv_reseller' => null,
            'messaging.toll_free_isv_reseller_required' => false,
        ]);
    }

    /** Replaces (not appends to) the fake: the first matching stub always wins. */
    private function fakeStatus(array|string $body, int $status = 200): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake([self::URL => Http::response($body, $status)]);
    }

    private function query(): RegistrationStatusQuery
    {
        return new RegistrationStatusQuery(PhoneNumberType::TollFree, null, null, 'tfv_fb');
    }

    private function refreshAdapter(array|string $body, int $status = 200)
    {
        $this->fakeStatus($body, $status);

        return app(TelnyxProvisioningAdapter::class)->refreshRegistrationStatus($this->query());
    }

    private function registration(array $overrides = [], $business = null): BusinessMessagingRegistration
    {
        $business ??= $this->createBusinessWithWorkspace($this->createCustomer(), $this->businessAttributes());

        return BusinessMessagingRegistration::create(array_merge([
            'business_id' => $business->id, 'number_type' => 'toll_free', 'status' => 'pending', 'provider_registration_id' => 'tfv_fb',
            'legal_business_name' => 'Harbor Lane Studios LLC', 'entity_type' => 'ein', 'ein' => '12-3456789',
            'address_line_1' => '1 Harbor Lane', 'city' => 'Portland', 'region' => 'OR', 'postal_code' => '97201', 'country_code' => 'US',
            'website_url' => 'https://harborlane.example', 'contact_email' => 'owner@harborlane.example', 'contact_phone' => '+15035550100',
            'use_case' => 'customer_care', 'opt_in_method' => 'Opt in form.', 'campaign_description' => 'Appointment reminders.',
            'sample_message_1' => 'Hi. Reply STOP to opt out.', 'sample_message_2' => 'Hello. Reply STOP to opt out.',
            'privacy_policy_url' => 'https://harborlane.example/p', 'terms_url' => 'https://harborlane.example/t',
            'contact_first_name' => 'Pat', 'contact_last_name' => 'Owner', 'message_volume' => '1,000',
        ], $overrides));
    }

    private function refresh(BusinessMessagingRegistration $registration, array|string $body, int $status = 200): BusinessMessagingRegistration
    {
        $this->fakeStatus($body, $status);

        return app(BusinessMessagingRegistrationService::class)->refreshStatus($registration);
    }

    private function submission(array $overrides = []): MessagingRegistrationSubmission
    {
        $business = $this->createBusinessWithWorkspace($this->createCustomer(), $this->businessAttributes());

        return new MessagingRegistrationSubmission(...array_merge([
            'businessId' => $business->id, 'numberType' => PhoneNumberType::TollFree, 'legalBusinessName' => 'Harbor Lane Studios LLC',
            'entityType' => MessagingEntityType::Ein, 'ein' => '12-3456789', 'addressLine1' => '1 Harbor Lane', 'addressLine2' => null,
            'city' => 'Portland', 'region' => 'OR', 'postalCode' => '97201', 'countryCode' => 'US',
            'websiteUrl' => 'https://harborlane.example', 'contactEmail' => 'owner@harborlane.example', 'contactPhone' => '+15035550100',
            'useCase' => 'customer_care', 'optInMethod' => 'Customers check a box.', 'sampleMessage1' => 'Hi. Reply STOP to unsubscribe.',
            'sampleMessage2' => 'Hello.', 'privacyPolicyUrl' => 'https://harborlane.example/privacy', 'termsUrl' => 'https://harborlane.example/terms',
            'contactFirstName' => 'Pat', 'contactLastName' => 'Owner', 'messageVolume' => '1,000',
            'optInImageUrl' => 'https://example.com/opt-in.png', 'brandVertical' => 'ENTERTAINMENT', 'phoneNumber' => '+18005550100',
            'existingBrandId' => null, 'campaignDescription' => 'Harbor Lane sends reminders to customers who booked.',
        ], $overrides));
    }

    // ----- Phase 1: provider status fidelity (adapter) -----

    public function test_waiting_for_telnyx_with_a_change_request_keeps_status_and_reason(): void
    {
        $r = $this->refreshAdapter(['data' => ['verificationStatus' => 'Waiting For Telnyx', 'reason' => 'change use case to Mixed.']]);

        $this->assertSame(MessagingRegistrationStatus::Pending, $r->status);
        $this->assertSame('waiting_for_telnyx', $r->providerStatus);
        $this->assertSame('change use case to Mixed.', $r->providerReason);
    }

    public function test_waiting_for_telnyx_with_a_missing_field_reason_keeps_it(): void
    {
        $r = $this->refreshAdapter(['verificationStatus' => 'Waiting For Telnyx', 'reason' => 'businessRegistrationCountry is missing.']);

        $this->assertSame('waiting_for_telnyx', $r->providerStatus);
        $this->assertSame('businessRegistrationCountry is missing.', $r->providerReason);
    }

    public function test_waiting_for_customer_with_a_nested_reason_keeps_it(): void
    {
        $r = $this->refreshAdapter(['data' => ['verificationStatus' => 'Waiting For Customer', 'declineReason' => 'Add an isvReseller name.']]);

        $this->assertSame('waiting_for_customer', $r->providerStatus);
        $this->assertSame('Add an isvReseller name.', $r->providerReason);
    }

    public function test_waiting_for_vendor_without_a_reason_has_no_reason(): void
    {
        $r = $this->refreshAdapter(['data' => ['verificationStatus' => 'Waiting For Vendor']]);

        $this->assertSame(MessagingRegistrationStatus::Pending, $r->status);
        $this->assertSame('waiting_for_vendor', $r->providerStatus);
        $this->assertNull($r->providerReason);
    }

    public function test_verified_carries_no_reason_even_if_one_is_present(): void
    {
        $r = $this->refreshAdapter(['data' => ['verificationStatus' => 'Verified', 'reason' => 'stale text']]);

        $this->assertSame(MessagingRegistrationStatus::Approved, $r->status);
        $this->assertSame('verified', $r->providerStatus);
        $this->assertNull($r->providerReason);
    }

    public function test_rejected_with_and_without_a_reason(): void
    {
        $with = $this->refreshAdapter(['data' => ['verificationStatus' => 'Rejected', 'reason' => 'Opt-in evidence unreachable.']]);
        $this->assertSame(MessagingRegistrationStatus::Rejected, $with->status);
        $this->assertSame('Opt-in evidence unreachable.', $with->rejectionReason);

        $without = $this->refreshAdapter(['data' => ['verificationStatus' => 'Rejected']]);
        $this->assertSame(MessagingRegistrationStatus::Rejected, $without->status);
        $this->assertNull($without->rejectionReason);
    }

    public function test_a_reason_is_sanitized_and_never_carries_credentials_or_markup(): void
    {
        $r = $this->refreshAdapter(['verificationStatus' => 'Waiting For Telnyx', 'reason' => "<b>Fix</b> it. Authorization: Bearer abcdefghijklmnop123456\n" . str_repeat('x', 900)]);

        $this->assertStringNotContainsString('<b>', $r->providerReason);
        $this->assertStringNotContainsString('abcdefghijklmnop123456', $r->providerReason);
        $this->assertLessThanOrEqual(500, mb_strlen($r->providerReason));
    }

    public function test_timeout_429_5xx_and_malformed_answers_are_inconclusive(): void
    {
        foreach ([429, 500, 503] as $code) {
            $this->assertFalse($this->refreshAdapter(['errors' => [['detail' => 'secret provider text']]], $code)->conclusive, (string) $code);
        }
        $this->assertFalse($this->refreshAdapter(['data' => ['reason' => 'no status here']])->conclusive);

        Http::fake([self::URL => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);
        $this->assertFalse(app(TelnyxProvisioningAdapter::class)->refreshRegistrationStatus($this->query())->conclusive);
    }

    // ----- Phase 6: persistence through the shared service -----

    public function test_same_pending_status_with_a_new_reason_is_persisted_and_an_unchanged_one_is_idempotent(): void
    {
        $registration = $this->registration();

        $registration = $this->refresh($registration, ['data' => ['verificationStatus' => 'Waiting For Telnyx', 'reason' => 'change use case to Mixed.']]);
        $this->assertSame('pending', $registration->status->value);
        $this->assertSame('waiting_for_telnyx', $registration->provider_status);
        $this->assertSame('change use case to Mixed.', $registration->provider_status_reason);
        $this->assertNotNull($registration->provider_status_checked_at);

        $registration = $this->refresh($registration, ['data' => ['verificationStatus' => 'Waiting For Telnyx', 'reason' => 'businessRegistrationCountry is missing.']]);
        $this->assertSame('businessRegistrationCountry is missing.', $registration->provider_status_reason);

        $again = $this->refresh($registration, ['data' => ['verificationStatus' => 'Waiting For Telnyx', 'reason' => 'businessRegistrationCountry is missing.']]);
        $this->assertSame('pending', $again->status->value);
        $this->assertSame('businessRegistrationCountry is missing.', $again->provider_status_reason);
        $this->assertSame(1, BusinessMessagingRegistration::query()->count());
    }

    public function test_inconclusive_answers_preserve_stored_feedback_and_checked_time(): void
    {
        $registration = $this->refresh($this->registration(), ['data' => ['verificationStatus' => 'Waiting For Telnyx', 'reason' => 'change use case to Mixed.']]);
        $checked = (string) $registration->provider_status_checked_at;

        foreach ([429, 502, 503] as $code) {
            $kept = $this->refresh($registration, [], $code);
            $this->assertSame('pending', $kept->status->value, (string) $code);
            $this->assertSame('change use case to Mixed.', $kept->provider_status_reason);
            $this->assertSame($checked, (string) $kept->provider_status_checked_at);
        }
        $malformed = $this->refresh($registration, ['data' => ['reason' => 'x']]);
        $this->assertSame('change use case to Mixed.', $malformed->provider_status_reason);
    }

    public function test_a_confirmed_newer_state_clears_stale_feedback_and_verified_never_regresses(): void
    {
        $registration = $this->refresh($this->registration(), ['data' => ['verificationStatus' => 'Waiting For Telnyx', 'reason' => 'change use case to Mixed.']]);

        $cleared = $this->refresh($registration, ['data' => ['verificationStatus' => 'Waiting For Vendor']]);
        $this->assertSame('waiting_for_vendor', $cleared->provider_status);
        $this->assertNull($cleared->provider_status_reason);

        $verified = $this->refresh($cleared, ['data' => ['verificationStatus' => 'Verified']]);
        $this->assertSame('approved', $verified->status->value);
        $this->assertNull($verified->provider_status_reason);

        $still = $this->refresh($verified, ['data' => ['verificationStatus' => 'Waiting For Telnyx', 'reason' => 'late noise']]);
        $this->assertSame('approved', $still->status->value);
        $this->assertNull($still->provider_status_reason);
    }

    public function test_repeated_rejected_status_updates_the_customer_visible_reason(): void
    {
        $registration = $this->refresh($this->registration(), [
            'data' => ['verificationStatus' => 'Rejected', 'reason' => 'Opt-in evidence missing.'],
        ]);
        $this->assertSame('Opt-in evidence missing.', $registration->rejection_reason);

        $registration = $this->refresh($registration, [
            'data' => ['verificationStatus' => 'Rejected', 'reason' => 'Correct the business website URL.'],
        ]);
        $this->assertSame('rejected', $registration->status->value);
        $this->assertSame('Correct the business website URL.', $registration->rejection_reason);
        $this->assertSame('Correct the business website URL.', $registration->provider_status_reason);
    }

    public function test_editing_a_rejected_request_clears_old_provider_feedback_before_replacement(): void
    {
        $registration = $this->refresh($this->registration(), [
            'data' => ['verificationStatus' => 'Rejected', 'reason' => 'Please fix your opt-in evidence.'],
        ]);
        $this->assertSame('rejected', $registration->status->value);
        $this->assertNotNull($registration->provider_status_checked_at);

        $edited = app(BusinessMessagingRegistrationService::class)->captureDetails(
            $registration->business,
            ['campaign_description' => 'Updated service messaging description.'],
        );

        $this->assertSame('not_started', $edited->status->value);
        $this->assertNull($edited->provider_registration_id);
        $this->assertNull($edited->rejection_reason);
        $this->assertNull($edited->provider_status);
        $this->assertNull($edited->provider_status_reason);
        $this->assertNull($edited->provider_status_checked_at);
    }

    public function test_the_background_sweep_uses_the_same_persistence(): void
    {
        $registration = $this->registration();
        Http::fake([self::URL => Http::response(['data' => ['verificationStatus' => 'Waiting For Customer', 'reason' => 'Provide an isvReseller name.']])]);

        app(BusinessMessagingRegistrationService::class)->refreshAllPending();

        $registration->refresh();
        $this->assertSame('waiting_for_customer', $registration->provider_status);
        $this->assertSame('Provide an isvReseller name.', $registration->provider_status_reason);
    }

    public function test_a_refresh_never_resubmits_creates_a_duplicate_or_calls_anything_but_the_status_endpoint(): void
    {
        $registration = $this->registration();
        $this->refresh($registration, ['data' => ['verificationStatus' => 'Waiting For Telnyx', 'reason' => 'change use case to Mixed.']]);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_ends_with($request->url(), '/messaging_tollfree/verification/requests/tfv_fb'));
        $registration->refresh();
        $this->assertSame('customer_care', $registration->use_case);
        $this->assertSame('tfv_fb', $registration->provider_registration_id);
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('business_usage_ledger_entries')->count());
    }

    // ----- Phase 3: ISV / reseller -----

    public function test_isv_reseller_is_submitted_from_platform_config_never_the_clients_name(): void
    {
        config(['messaging.toll_free_isv_reseller_required' => true, 'messaging.toll_free_isv_reseller' => 'Configured Platform Co']);
        Http::fake(['api.telnyx.com/v2/messaging_tollfree/verification/requests' => Http::response(['data' => ['id' => 'tfv_isv']])]);

        app(TelnyxProvisioningAdapter::class)->submitRegistration($this->submission());

        Http::assertSent(fn ($request) => $request->data()['isvReseller'] === 'Configured Platform Co' && $request->data()['businessName'] === 'Harbor Lane Studios LLC');
    }

    public function test_a_required_but_missing_reseller_name_is_an_actionable_platform_error_before_any_request(): void
    {
        Http::fake();
        config(['messaging.toll_free_isv_reseller_required' => true, 'messaging.toll_free_isv_reseller' => '  ']);

        try {
            app(TelnyxProvisioningAdapter::class)->submitRegistration($this->submission());
            $this->fail('Expected a platform configuration exception.');
        } catch (MessagingRegistrationPlatformConfigurationException $e) {
            $this->assertSame(['MESSAGING_TOLL_FREE_ISV_RESELLER'], $e->settings);
        }
        Http::assertNothingSent();
    }

    public function test_a_direct_account_omits_the_reseller_field(): void
    {
        Http::fake(['api.telnyx.com/v2/messaging_tollfree/verification/requests' => Http::response(['data' => ['id' => 'tfv_direct']])]);

        app(TelnyxProvisioningAdapter::class)->submitRegistration($this->submission());

        Http::assertSent(fn ($request) => ! array_key_exists('isvReseller', $request->data()));
    }

    // ----- Phase 4: business registration country -----

    public function test_registration_country_is_sent_for_ein_and_sole_proprietor_alike(): void
    {
        Http::fake(['api.telnyx.com/v2/messaging_tollfree/verification/requests' => Http::response(['data' => ['id' => 'tfv_c']])]);
        $adapter = app(TelnyxProvisioningAdapter::class);

        $adapter->submitRegistration($this->submission());
        $adapter->submitRegistration($this->submission(['entityType' => MessagingEntityType::SoleProprietor, 'ein' => null]));

        $countries = [];
        Http::assertSent(function ($request) use (&$countries) {
            $countries[] = $request->data()['businessRegistrationCountry'] ?? null;

            return true;
        });
        $this->assertSame(['US', 'US'], $countries);
    }

    public function test_missing_or_unsupported_country_fails_preflight_before_any_request(): void
    {
        Http::fake();
        foreach (['', 'CA', 'GB'] as $country) {
            try {
                app(TelnyxProvisioningAdapter::class)->submitRegistration($this->submission(['countryCode' => $country]));
                $this->fail("Country [$country] must be refused.");
            } catch (MessagingRegistrationPreflightException $e) {
                $this->assertSame(['business_registration_country'], $e->fields);
            }
        }
        Http::assertNothingSent();
    }

    // ----- Phase 2 / 7.15: the real customer page -----

    private function page(array $registration): string
    {
        Http::fake();
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->attachNumber($this->attachIdentity($business), '+1888555' . random_int(1000, 9999))->update(['number_type' => 'toll_free']);
        $this->registration($registration, $business);
        $this->authenticateAs($customer);

        $html = str_replace('&#039;', "'", $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]))->assertOk()->getContent());
        Http::assertNothingSent();

        return $html;
    }

    public function test_page_shows_a_requested_change_as_needs_attention_with_the_reason_and_no_edit_button(): void
    {
        $html = $this->page(['provider_status' => 'waiting_for_telnyx', 'provider_status_reason' => 'change use case to Mixed.']);

        $this->assertStringContainsString('Telnyx has requested a change to your SMS verification.', $html);
        $this->assertStringContainsString('Change use case to Mixed.', $html);
        $this->assertStringContainsString('Correction requested', $html);
        $this->assertStringContainsString('View submitted details', $html);
        $this->assertStringNotContainsString('Review verification details', $html);
        $this->assertStringContainsString('Contact support', $html);
        $this->assertStringContainsString('Verification submitted — correction requested', $html);
        $this->assertStringContainsString('data-role="resolve-verification-issue"', $html);
        $this->assertStringNotContainsString('typically reviewed within a few business days', $html);
        $this->assertStringNotContainsString('id="registration-form"', $html);
    }

    public function test_page_shows_missing_information_with_a_humanized_field_name(): void
    {
        $html = $this->page(['provider_status' => 'waiting_for_telnyx', 'provider_status_reason' => 'businessRegistrationCountry is missing.']);

        $this->assertStringContainsString('Your toll-free verification needs additional business information.', $html);
        $this->assertStringContainsString('Business registration country is missing.', $html);
    }

    public function test_page_shows_waiting_for_vendor_and_for_telnyx_without_a_reason_as_no_problem(): void
    {
        $vendor = $this->page(['provider_status' => 'waiting_for_vendor']);
        $this->assertStringContainsString('Verification submitted — under carrier review', $vendor);
        $this->assertStringContainsString('No action is currently required', $vendor);
        $this->assertStringNotContainsString('Needs attention', $vendor);

        $telnyx = $this->page(['provider_status' => 'waiting_for_telnyx']);
        $this->assertStringContainsString('Verification submitted — awaiting Telnyx review', $telnyx);
        $this->assertStringContainsString('Telnyx is reviewing it', $telnyx);
        $this->assertStringNotContainsString('Needs attention', $telnyx);
    }

    public function test_a_submitted_registration_never_shows_initial_submission_instructions(): void
    {
        foreach ([
            ['provider_status' => 'waiting_for_telnyx'],
            ['provider_status' => 'waiting_for_vendor'],
            ['provider_status' => 'waiting_for_telnyx', 'provider_status_reason' => 'change use case to Mixed.'],
            ['provider_status' => 'waiting_for_customer'],
            [],
        ] as $state) {
            $html = $this->page($state);

            $this->assertStringContainsString('has been submitted to Telnyx and is being tracked', $html);
            $this->assertStringNotContainsString('nothing is sent until you submit', $html);
            $this->assertStringNotContainsString('One more step before this number', $html);
            $this->assertStringNotContainsString('id="registration-form"', $html);
            $this->assertStringNotContainsString('Rejected', $html);
            $this->assertStringContainsString('data-role="registration-tracker"', $html);
        }
    }

    public function test_a_correction_request_has_a_functional_support_action_with_only_safe_context(): void
    {
        $html = $this->page(['provider_status' => 'waiting_for_telnyx', 'provider_status_reason' => 'change use case to Mixed.', 'provider_registration_id' => 'tfv_secret_id']);

        $this->assertMatchesRegularExpression('#data-role="resolve-verification-issue"#', $html);
        preg_match('#href="(mailto:[^"]+)"[^>]*data-role="resolve-verification-issue"|data-role="resolve-verification-issue"[^>]*href="(mailto:[^"]+)"#', $html, $m);
        $mailto = html_entity_decode($m[1] ?: $m[2]);
        $this->assertStringStartsWith('mailto:support@getmotiongrove.com?subject=', $mailto);
        $body = rawurldecode(substr($mailto, strpos($mailto, '&body=') + 6));
        $this->assertStringContainsString('Harbor Lane Studios LLC', $body);
        $this->assertStringContainsString('Number: +1888555', $body);
        $this->assertStringContainsString('Change use case to Mixed.', $body);
        $this->assertStringContainsString('Do not create a new request', $body);
        $this->assertStringNotContainsString('tfv_secret_id', $html);
        $this->assertStringNotContainsString('fixture_api_key_not_a_real_credential', $html);
        // The tracker marks the review stage as needing attention, not as plain "in review".
        $this->assertStringContainsString('Correction requested', $html);
    }

    public function test_no_resolve_action_without_a_correction_and_no_dead_end_link(): void
    {
        foreach ([['provider_status' => 'waiting_for_telnyx'], ['provider_status' => 'waiting_for_vendor']] as $state) {
            $html = $this->page($state);
            $this->assertStringNotContainsString('resolve-verification-issue', $html);
            $this->assertStringNotContainsString('Review verification details', $html);
            $this->assertStringContainsString('View submitted details', $html);
            $this->assertStringContainsString('toll-free-submitted-details', $html);
        }
    }

    public function test_rejected_is_distinct_from_a_pending_correction_and_verified_has_no_warning(): void
    {
        $rejected = $this->page(['status' => 'rejected', 'rejection_reason' => 'Opt-in evidence unreachable.', 'provider_status' => 'rejected']);
        $this->assertStringContainsString('Telnyx rejected the submitted verification', $rejected);
        $this->assertStringNotContainsString('correction requested', $rejected);
        $this->assertStringNotContainsString('data-role="resolve-verification-issue"', $rejected);

        $row = $this->registration(['status' => 'approved', 'provider_status' => 'verified', 'provider_status_reason' => 'stale']);
        $feedback = \App\Library\Messaging\Registration\TollFreeVerificationFeedback::for($row);
        $this->assertSame('verified', $feedback['key']);
        $this->assertNull($feedback['reason']);
        $this->assertFalse($feedback['correction']);

        $pending = \App\Library\Messaging\Registration\TollFreeVerificationFeedback::for($this->registration(['provider_status' => 'waiting_for_telnyx']));
        $this->assertSame('Verification submitted — awaiting Telnyx review', $pending['headline']);
        $this->assertSame('not_submitted', \App\Library\Messaging\Registration\TollFreeVerificationFeedback::for($this->registration(['status' => 'not_started']))['key']);
    }

    public function test_refreshing_from_the_page_changes_only_provider_feedback_and_never_submits_again(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $number = $this->attachNumber($this->attachIdentity($business), '+1888555' . random_int(1000, 9999));
        $number->update(['number_type' => 'toll_free']);
        $registration = $this->registration(['provider_status' => 'waiting_for_telnyx'], $business);
        $this->authenticateAs($customer);
        $before = $registration->fresh()->only(['status', 'use_case', 'campaign_description', 'consent_confirmations', 'provider_registration_id', 'submitted_at', 'legal_business_name']);
        $numberBefore = $number->fresh()->only(['phone_number', 'status', 'is_primary', 'number_type', 'business_messaging_identity_id']);

        $this->fakeStatus(['data' => ['verificationStatus' => 'Waiting For Telnyx', 'reason' => 'change use case to Mixed.']]);
        $show = route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]);

        $this->post(route('customer.workspaces.businesses.text-messaging.registration.refresh', [$workspace->uid, $business->uid]))->assertRedirect();
        $html = str_replace('&#039;', "'", $this->get($show)->assertOk()->getContent());

        // Telnyx's change is shown accurately...
        $this->assertStringContainsString('Change use case to Mixed.', $html);
        $this->assertStringContainsString('Verification submitted — correction requested', $html);
        // ...and then a later confirmed state replaces it.
        $this->fakeStatus(['data' => ['verificationStatus' => 'Waiting For Vendor']]);
        BusinessMessagingRegistration::query()->update(['provider_status_checked_at' => now()->subMinutes(5)]);
        $this->post(route('customer.workspaces.businesses.text-messaging.registration.refresh', [$workspace->uid, $business->uid]))->assertRedirect();
        $html = str_replace('&#039;', "'", $this->get($show)->assertOk()->getContent());
        $this->assertStringNotContainsString('Change use case to Mixed.', $html);
        $this->assertStringContainsString('under carrier review', $html);

        // Only GET status calls: no submission, PATCH or POST to Telnyx, and nothing else altered.
        Http::assertSent(fn ($request) => $request->method() === 'GET');
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
        $this->assertSame($before, $registration->fresh()->only(array_keys($before)));
        $this->assertSame($numberBefore, $number->fresh()->only(array_keys($numberBefore)));
        $this->assertSame(1, BusinessMessagingRegistration::query()->count());
    }

    public function test_page_shows_verified_without_stale_warnings_and_rejected_with_the_real_reason_or_the_fallback(): void
    {
        $verified = $this->page(['status' => 'approved', 'provider_status' => 'verified', 'provider_status_reason' => 'change use case to Mixed.']);
        $this->assertStringNotContainsString('Change use case to Mixed.', $verified);

        $rejected = $this->page(['status' => 'rejected', 'rejection_reason' => 'Opt-in evidence unreachable.', 'provider_status' => 'rejected']);
        $this->assertStringContainsString('Opt-in evidence unreachable.', $rejected);
        $this->assertStringContainsString('Verification rejected', $rejected);
        $this->assertStringContainsString('Needs attention', $rejected);

        $bare = $this->page(['status' => 'rejected', 'provider_status' => 'rejected']);
        $this->assertStringContainsString('did not supply a detailed reason', $bare);
    }

    public function test_the_customer_refresh_action_persists_the_reason_and_reports_a_conclusive_check(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->registration([], $business);
        $this->authenticateAs($customer);
        Http::fake([self::URL => Http::response(['data' => ['verificationStatus' => 'Waiting For Telnyx', 'reason' => 'change use case to Mixed.']])]);

        $this->post(route('customer.workspaces.businesses.text-messaging.registration.refresh', [$workspace->uid, $business->uid]))
            ->assertRedirect()->assertSessionHas('status', 'success');

        $this->assertSame('change use case to Mixed.', BusinessMessagingRegistration::query()->where('business_id', $business->id)->value('provider_status_reason'));
    }

    public function test_sanitizer_humanizes_field_names_and_capitalises(): void
    {
        $this->assertSame('Business registration country is missing.', ProviderReasonSanitizer::humanize('businessRegistrationCountry is missing.'));
        $this->assertSame('Change use case to Mixed.', ProviderReasonSanitizer::humanize('change use case to Mixed.'));
        $this->assertNull(ProviderReasonSanitizer::clean("  \n "));
    }
}

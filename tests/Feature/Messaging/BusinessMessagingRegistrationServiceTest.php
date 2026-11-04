<?php

namespace Tests\Feature\Messaging;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Messaging\MessagingRegistrationStatus;
use App\Enums\Messaging\PhoneNumberType;
use App\Library\Messaging\BusinessMessagingRegistrationService;
use App\Library\Messaging\Contracts\MessagingProvisioningAdapter;
use App\Library\Messaging\Exceptions\MessagingProviderNotConfiguredException;
use App\Library\Messaging\Exceptions\MessagingRegistrationImmutableException;
use App\Library\Messaging\FakeProvisioningAdapter;
use App\Library\Messaging\TelnyxProvisioningAdapter;
use App\Library\Usage\UsageWalletManager;
use App\Models\BusinessMessagingRegistration;
use App\Models\Currency;
use App\Models\User;
use App\Repositories\Contracts\UsageMeterRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

/**
 * Text messaging setup/number/compliance hub — STATE 2's capture/submit/
 * refresh lifecycle. Capturing details never contacts the provider;
 * submitting never re-asks for data the customer already gave; refreshing
 * only ever moves the status to what the provider actually reports.
 */
class BusinessMessagingRegistrationServiceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMessagingFixtures;

    private function bindFakeProvisioningAdapter(): FakeProvisioningAdapter
    {
        $fake = new FakeProvisioningAdapter();
        $this->app->instance(MessagingProvisioningAdapter::class, $fake);
        config([
            'messaging.managed_messaging_enabled' => true,
            'messaging.managed_messaging_provisioning_enabled' => true,
            'services.telnyx.api_key' => 'fixture_key',
        ]);

        return $fake;
    }

    /** Drives both 10DLC stages (toll-free is a single stage): brand, verification refresh, authorized campaign. */
    private function submitFully(\App\Models\BusinessMessagingRegistration $registration): \App\Models\BusinessMessagingRegistration
    {
        $registration = $this->service()->submit($registration);

        if ($registration->number_type !== \App\Enums\Messaging\PhoneNumberType::Local || $registration->status !== MessagingRegistrationStatus::NotStarted) {
            return $registration;
        }

        $registration = $this->service()->refreshBrand($registration);

        return $this->service()->submit($registration, true);
    }

    private function service(): BusinessMessagingRegistrationService
    {
        return app(BusinessMessagingRegistrationService::class);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'number_type' => 'local',
            'legal_business_name' => 'Harbor Lane Studios LLC',
            'entity_type' => 'ein',
            'ein' => '12-3456789',
            'address_line_1' => '1 Harbor Lane',
            'city' => 'Portland',
            'region' => 'OR',
            'postal_code' => '97201',
            'country_code' => 'US',
            'website_url' => 'https://harborlane.example',
            'contact_email' => 'owner@harborlane.example',
            'contact_phone' => '+15035550100',
            'use_case' => 'appointment_reminders',
            'opt_in_method' => 'Customers check a box on our booking form.',
            'sample_message_1' => 'Harbor Lane: your appointment is confirmed for Tuesday. Reply STOP to unsubscribe.',
            'sample_message_2' => 'Harbor Lane: reminder, your appointment is tomorrow at 2pm.',
            'privacy_policy_url' => 'https://harborlane.example/privacy',
            'terms_url' => 'https://harborlane.example/terms', 'support_contact' => 'help@fixture.example', 'support_contact_confirmed' => '1', 'support_contact_confirmed_for' => 'help@fixture.example', 'support_contact_confirmed_at' => '2026-01-01 00:00:00',
            'contact_first_name' => 'Pat', 'contact_last_name' => 'Owner', 'message_volume' => '1,000', 'opt_in_image_url' => 'https://example.com/opt-in.png', 'brand_vertical' => 'ENTERTAINMENT',
        ], $overrides);
    }

    // -----------------------------------------------------------------
    // Capture — idempotent per Business, no provider call.
    // -----------------------------------------------------------------

    public function test_capturing_details_creates_one_row_per_business(): void
    {
        $business = $this->makeBusiness();

        $registration = $this->service()->captureDetails($business, $this->payload());

        $this->assertSame($business->id, $registration->business_id);
        $this->assertSame('Harbor Lane Studios LLC', $registration->legal_business_name);
        $this->assertSame(MessagingRegistrationStatus::NotStarted, $registration->status);
        $this->assertSame(1, BusinessMessagingRegistration::where('business_id', $business->id)->count());
    }

    public function test_capturing_details_again_updates_the_same_row_never_creates_a_second(): void
    {
        $business = $this->makeBusiness();
        $this->service()->captureDetails($business, $this->payload());

        $this->service()->captureDetails($business, $this->payload(['legal_business_name' => 'Harbor Lane Studios, Corrected LLC']));

        $this->assertSame(1, BusinessMessagingRegistration::where('business_id', $business->id)->count());
        $this->assertSame('Harbor Lane Studios, Corrected LLC', BusinessMessagingRegistration::where('business_id', $business->id)->first()->legal_business_name);
    }

    public function test_editing_a_rejected_registration_clears_the_rejection(): void
    {
        $business = $this->makeBusiness();
        $registration = $this->service()->captureDetails($business, $this->payload());
        $registration->update(['status' => MessagingRegistrationStatus::Rejected->value, 'rejection_reason' => 'Missing a valid website.']);

        $updated = $this->service()->captureDetails($business, $this->payload(['website_url' => 'https://harborlane.example/home']));

        $this->assertSame(MessagingRegistrationStatus::NotStarted, $updated->status);
        $this->assertNull($updated->rejection_reason);
    }

    public function test_capturing_details_never_contacts_the_provider(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $business = $this->makeBusiness();

        $this->service()->captureDetails($business, $this->payload());

        $this->assertSame([], $fake->submittedRegistrations);
    }

    // -----------------------------------------------------------------
    // Submit — sends exactly the captured data, realistically Pending.
    // -----------------------------------------------------------------

    public function test_submitting_sends_the_captured_fields_and_records_the_providers_pending_answer(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $business = $this->makeBusiness();
        $registration = $this->service()->captureDetails($business, $this->payload());

        $submitted = $this->submitFully($registration);

        // One request per stage: the business brand first, then the authorized campaign.
        $this->assertCount(2, $fake->submittedRegistrations);
        $this->assertSame(['brand', 'campaign'], array_map(fn ($r) => $r->phase, $fake->submittedRegistrations));
        $this->assertSame('Harbor Lane Studios LLC', $fake->submittedRegistrations[0]->legalBusinessName);
        $this->assertSame(MessagingRegistrationStatus::Pending, $submitted->status);
        $this->assertNotNull($submitted->provider_brand_id);
        $this->assertNotNull($submitted->provider_campaign_id);
        $this->assertNotNull($submitted->submitted_at);
        // Review correction — a local (10DLC) submission has no number yet
        // in this scenario (makeBusiness() attaches none); the submission
        // must never invent one.
        $this->assertNull($fake->submittedRegistrations[0]->phoneNumber);
    }

    /**
     * Review correction — toll-free's own submission requires the
     * already-owned number being verified; submit() must resolve it from
     * the Business's own primary number, never leave it null when one
     * genuinely exists.
     */
    public function test_retrying_an_already_submitted_registration_is_refused_without_a_second_provider_call(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $registration = $this->service()->captureDetails($this->makeBusiness(), $this->payload());

        $submitted = $this->submitFully($registration);

        try {
            $this->service()->submit($registration);
            $this->fail('Expected MessagingRegistrationAlreadySubmittedException.');
        } catch (\App\Library\Messaging\Exceptions\MessagingRegistrationAlreadySubmittedException) {
            // expected
        }

        $this->assertCount(2, $fake->submittedRegistrations);
        $this->assertSame($submitted->provider_brand_id, $registration->fresh()->provider_brand_id);
    }

    public function test_a_pending_registration_cannot_be_edited_back_into_a_resubmittable_state(): void
    {
        $this->bindFakeProvisioningAdapter();
        $business = $this->makeBusiness();
        $submitted = $this->submitFully($this->service()->captureDetails($business, $this->payload()));

        $this->expectException(MessagingRegistrationImmutableException::class);

        try {
            $this->service()->captureDetails($business, $this->payload(['legal_business_name' => 'Other LLC']));
        } finally {
            $this->assertSame(MessagingRegistrationStatus::Pending, $submitted->fresh()->status);
        }
    }

    public function test_editing_after_a_rejection_clears_the_stale_campaign_but_never_the_saved_brand(): void
    {
        $business = $this->makeBusiness();
        $registration = $this->service()->captureDetails($business, $this->payload());
        $registration->update(['status' => 'rejected', 'provider_brand_id' => 'brand_old', 'provider_campaign_id' => 'camp_old']);

        $edited = $this->service()->captureDetails($business, $this->payload());

        $this->assertSame('brand_old', $edited->provider_brand_id);
        $this->assertNull($edited->provider_campaign_id);
    }

    public function test_submitting_a_rejected_registration_without_edits_starts_a_fresh_submission(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $registration = $this->service()->captureDetails($this->makeBusiness(), $this->payload());
        $registration->update(['status' => 'rejected', 'provider_brand_id' => 'brand_old', 'provider_campaign_id' => 'camp_old']);

        // The saved brand is reconciled with the carrier (verified) and reused; only the campaign is redone.
        $submitted = $this->service()->submit($registration, true);

        $this->assertCount(1, $fake->submittedRegistrations);
        $this->assertSame('campaign', $fake->submittedRegistrations[0]->phase);
        $this->assertSame('brand_old', $fake->submittedRegistrations[0]->existingBrandId);
        $this->assertNull($fake->submittedRegistrations[0]->existingCampaignId);
        $this->assertSame('brand_old', $submitted->provider_brand_id);
        $this->assertSame(MessagingRegistrationStatus::Pending, $submitted->status);
    }

    public function test_a_saved_campaign_cannot_be_edited_into_a_different_submission(): void
    {
        $business = $this->makeBusiness();
        $registration = $this->service()->captureDetails($business, $this->payload());
        $registration->update(['provider_brand_id' => 'brand_1', 'provider_campaign_id' => 'camp_1']);

        try {
            $this->service()->captureDetails($business, $this->payload(['sample_message_1' => 'Harbor Lane: a brand new sample. Reply STOP to unsubscribe.']));
            $this->fail('Expected refusal.');
        } catch (MessagingRegistrationImmutableException) {
            // expected
        }

        $fresh = $registration->fresh();
        $this->assertSame('camp_1', $fresh->provider_campaign_id);
        $this->assertNotSame('Harbor Lane: a brand new sample. Reply STOP to unsubscribe.', $fresh->sample_message_1);
    }

    public function test_a_second_worker_cannot_submit_while_the_shared_lock_is_held(): void
    {
        if (\Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('The shared submit lock is a MySQL session lock.');
        }

        $fake = $this->bindFakeProvisioningAdapter();
        $registration = $this->service()->captureDetails($this->makeBusiness(), $this->payload());

        $config = config('database.connections.' . config('database.default'));
        $other = new \PDO(sprintf('mysql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']), $config['username'], $config['password']);
        $held = $other->query("SELECT GET_LOCK('msgreg-submit:" . $registration->id . "', 0)")->fetchColumn();
        $this->assertSame(1, (int) $held);

        try {
            $this->service()->submit($registration);
            $this->fail('Expected refusal while the lock is held.');
        } catch (\App\Library\Messaging\Exceptions\MessagingRegistrationAlreadySubmittedException) {
            // expected
        } finally {
            $other->query("SELECT RELEASE_LOCK('msgreg-submit:" . $registration->id . "')");
        }

        $this->assertCount(0, $fake->submittedRegistrations);
        $this->assertSame(MessagingRegistrationStatus::NotStarted, $registration->fresh()->status);
    }

    public function test_submitting_resolves_and_sends_the_already_owned_phone_number_when_one_exists(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $business = $this->makeBusiness();
        $this->attachNumber($this->attachIdentity($business), '+18005550199');
        $registration = $this->service()->captureDetails($business, $this->payload(['number_type' => 'toll_free']));

        $this->service()->submit($registration);

        $this->assertCount(1, $fake->submittedRegistrations);
        $this->assertSame('+18005550199', $fake->submittedRegistrations[0]->phoneNumber);
    }

    /**
     * PR #295 Correction Round 1, item 7 — a toll-free submission has no
     * brand/campaign concept at all; it must never reuse those two 10DLC
     * fields to smuggle through one opaque id (the prior round's bug).
     */
    public function test_submitting_a_toll_free_registration_produces_a_registration_id_never_a_brand_or_campaign(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $business = $this->makeBusiness();
        $registration = $this->service()->captureDetails($business, $this->payload(['number_type' => 'toll_free']));

        $submitted = $this->submitFully($registration);

        $this->assertNull($submitted->provider_brand_id);
        $this->assertNull($submitted->provider_campaign_id);
        $this->assertNotNull($submitted->provider_registration_id);
    }

    public function test_submitting_a_local_registration_produces_a_brand_and_campaign_never_a_bare_registration_id(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $business = $this->makeBusiness();
        $registration = $this->service()->captureDetails($business, $this->payload(['number_type' => 'local']));

        $submitted = $this->submitFully($registration);

        $this->assertNotNull($submitted->provider_brand_id);
        $this->assertNotNull($submitted->provider_campaign_id);
        $this->assertNull($submitted->provider_registration_id);
    }

    public function test_submitting_when_not_configured_throws_and_does_not_change_status(): void
    {
        config(['messaging.managed_messaging_enabled' => false]);
        $business = $this->makeBusiness();
        $registration = $this->service()->captureDetails($business, $this->payload());

        try {
            $this->service()->submit($registration);
            $this->fail('Expected MessagingProviderNotConfiguredException.');
        } catch (MessagingProviderNotConfiguredException) {
            // expected
        }

        $this->assertSame(MessagingRegistrationStatus::NotStarted, $registration->fresh()->status);
    }

    // -----------------------------------------------------------------
    // Refresh — only ever moves to what the provider actually reports.
    // -----------------------------------------------------------------

    public function test_refresh_applies_an_approved_answer_and_stamps_approved_at(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $business = $this->makeBusiness();
        $registration = $this->submitFully($this->service()->captureDetails($business, $this->payload()));
        $fake->scriptRegistrationStatus($registration, MessagingRegistrationStatus::Approved);

        $refreshed = $this->service()->refreshStatus($registration);

        $this->assertSame(MessagingRegistrationStatus::Approved, $refreshed->status);
        $this->assertNotNull($refreshed->approved_at);
        $this->assertTrue($refreshed->isApproved());
    }

    public function test_refresh_applies_a_rejected_answer_and_stamps_rejected_at(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $business = $this->makeBusiness();
        $registration = $this->submitFully($this->service()->captureDetails($business, $this->payload()));
        $fake->scriptRegistrationStatus($registration, MessagingRegistrationStatus::Rejected);

        $refreshed = $this->service()->refreshStatus($registration);

        $this->assertSame(MessagingRegistrationStatus::Rejected, $refreshed->status);
        $this->assertNotNull($refreshed->rejected_at);
        $this->assertNull($refreshed->rejection_reason, 'No reason was scripted — must never be guessed.');
    }

    /**
     * Review correction — refreshRegistrationStatus() now returns the
     * whole RegistrationStatusResult; a Rejected answer that carries a
     * carrier-supplied reason must have that reason stored, never
     * silently dropped.
     */
    public function test_refresh_stores_the_carrier_supplied_rejection_reason_when_present(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $business = $this->makeBusiness();
        $registration = $this->submitFully($this->service()->captureDetails($business, $this->payload()));
        $fake->scriptRegistrationStatus($registration, MessagingRegistrationStatus::Rejected, 'Sample message missing required opt-out language.');

        $refreshed = $this->service()->refreshStatus($registration);

        $this->assertSame(MessagingRegistrationStatus::Rejected, $refreshed->status);
        $this->assertSame('Sample message missing required opt-out language.', $refreshed->rejection_reason);
    }

    public function test_refresh_without_a_prior_submission_is_a_no_op(): void
    {
        $business = $this->makeBusiness();
        $registration = $this->service()->captureDetails($business, $this->payload());

        $refreshed = $this->service()->refreshStatus($registration);

        $this->assertSame(MessagingRegistrationStatus::NotStarted, $refreshed->status);
    }

    public function test_refresh_when_not_configured_never_throws_and_leaves_status_unchanged(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $business = $this->makeBusiness();
        $registration = $this->submitFully($this->service()->captureDetails($business, $this->payload()));
        config(['messaging.managed_messaging_enabled' => false]);

        $refreshed = $this->service()->refreshStatus($registration);

        $this->assertSame(MessagingRegistrationStatus::Pending, $refreshed->status);
    }

    public function test_refresh_routes_a_toll_free_registration_through_its_own_registration_id(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $business = $this->makeBusiness();
        $registration = $this->submitFully($this->service()->captureDetails($business, $this->payload(['number_type' => 'toll_free'])));
        $this->assertNotNull($registration->provider_registration_id);
        $fake->scriptRegistrationStatus($registration, MessagingRegistrationStatus::Approved);

        $refreshed = $this->service()->refreshStatus($registration);

        $this->assertSame(MessagingRegistrationStatus::Approved, $refreshed->status);
    }

    // -----------------------------------------------------------------
    // PR #295 Correction Round 1, item 6 — the one canonical mechanism
    // that actually advances a submitted registration.
    // -----------------------------------------------------------------

    public function test_refresh_all_pending_advances_every_pending_registration_in_one_sweep(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $businessA = $this->makeBusiness();
        $businessB = $this->makeBusiness();
        $registrationA = $this->submitFully($this->service()->captureDetails($businessA, $this->payload()));
        $registrationB = $this->submitFully($this->service()->captureDetails($businessB, $this->payload(['number_type' => 'toll_free'])));
        $fake->scriptRegistrationStatus($registrationA, MessagingRegistrationStatus::Approved);
        $fake->scriptRegistrationStatus($registrationB, MessagingRegistrationStatus::Rejected);

        $this->service()->refreshAllPending();

        $this->assertSame(MessagingRegistrationStatus::Approved, $registrationA->fresh()->status);
        $this->assertSame(MessagingRegistrationStatus::Rejected, $registrationB->fresh()->status);
    }

    public function test_refresh_all_pending_never_touches_a_not_started_registration(): void
    {
        $this->bindFakeProvisioningAdapter();
        $business = $this->makeBusiness();
        $notStarted = $this->service()->captureDetails($business, $this->payload());

        $this->service()->refreshAllPending();

        $this->assertSame(MessagingRegistrationStatus::NotStarted, $notStarted->fresh()->status);
    }

    // -----------------------------------------------------------------
    // PR #295 Correction Round 1, item 8 — once Approved, immutable
    // through the normal customer edit route.
    // -----------------------------------------------------------------

    public function test_capturing_details_on_an_approved_registration_is_refused(): void
    {
        $business = $this->makeBusiness();
        $registration = $this->service()->captureDetails($business, $this->payload());
        $registration->update(['status' => MessagingRegistrationStatus::Approved->value, 'approved_at' => now()]);

        $this->expectException(MessagingRegistrationImmutableException::class);

        $this->service()->captureDetails($business, $this->payload(['legal_business_name' => 'A Different Name LLC']));
    }

    public function test_an_approved_registrations_data_is_unchanged_after_a_refused_capture_attempt(): void
    {
        $business = $this->makeBusiness();
        $registration = $this->service()->captureDetails($business, $this->payload());
        $registration->update(['status' => MessagingRegistrationStatus::Approved->value, 'approved_at' => now()]);

        try {
            $this->service()->captureDetails($business, $this->payload(['legal_business_name' => 'A Different Name LLC']));
        } catch (MessagingRegistrationImmutableException) {
            // expected
        }

        $this->assertSame('Harbor Lane Studios LLC', $registration->fresh()->legal_business_name);
    }

    // =================================================================
    // Review correction — chargeDisclosureFor() must never claim business
    // verification is free: TelnyxProvisioningAdapter genuinely reserves
    // wallet funds for both regimes. The unconfigured state (no owner has
    // ever approved a rate) is truthfully disclosed as such, and a real
    // configured rate is shown exactly as configured — never invented.
    // =================================================================

    private function createActorUserId(): int
    {
        return User::create([
            'first_name' => 'Test',
            'last_name' => 'Actor',
            'email' => 'actor' . uniqid() . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ])->id;
    }

    /**
     * Mirrors TelnyxProvisioningAdapterTest's own fixture sequence exactly
     * (a real UsageMeter row, then setActiveRate(), then
     * activateMetering()) — no retail price is invented here either.
     */
    private function configureRateFor(string $featureKey, string $retailRateMicro, string $unitLabel): void
    {
        if (Currency::query()->count() === 0) {
            Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        }

        $actorId = $this->createActorUserId();
        $currencyId = Currency::query()->first()->id;

        app(UsageMeterRepository::class)->create([
            'meter_key' => $featureKey,
            'feature_key' => PlatformFeature::MessagingTransport->value,
            'business_id' => null,
            'currency_id' => $currencyId,
            'description' => 'Review correction fixture meter (test-only).',
            'updated_by_user_id' => $actorId,
        ]);

        $wallet = app(UsageWalletManager::class);
        $wallet->setActiveRate($featureKey, $retailRateMicro, '0', $unitLabel, $currencyId, $actorId, 'Test rate activation.');
        $wallet->activateMetering($featureKey, $actorId, 'Test metering activation.');
    }

    public function test_charge_disclosure_for_local_states_no_fee_configured_when_no_rate_exists(): void
    {
        $disclosure = $this->service()->chargeDisclosureFor(PhoneNumberType::Local);

        $this->assertSame('No fee is currently configured for business verification in this environment.', $disclosure);
    }

    public function test_charge_disclosure_for_toll_free_states_verification_is_free_but_number_and_usage_are_charged(): void
    {
        $disclosure = $this->service()->chargeDisclosureFor(PhoneNumberType::TollFree);

        $this->assertSame('Toll-free verification is $0 through Telnyx. Number rental and SMS usage are still charged.', $disclosure);
    }

    public function test_charge_disclosure_for_local_shows_the_real_configured_rate_never_an_invented_one(): void
    {
        $this->configureRateFor(TelnyxProvisioningAdapter::FEATURE_TEN_DLC_REGISTRATION, '2500000', 'registration');

        $disclosure = $this->service()->chargeDisclosureFor(PhoneNumberType::Local);

        $this->assertStringContainsString('USD 2.50', $disclosure);
        $this->assertStringContainsString('per registration', $disclosure);
        $this->assertStringNotContainsString('No fee is currently configured', $disclosure);
    }

    public function test_charge_disclosure_keeps_local_and_toll_free_rates_independent(): void
    {
        $this->configureRateFor(TelnyxProvisioningAdapter::FEATURE_TEN_DLC_REGISTRATION, '2500000', 'registration');

        $tollFreeDisclosure = $this->service()->chargeDisclosureFor(PhoneNumberType::TollFree);

        $this->assertStringNotContainsString('2.50', $tollFreeDisclosure);
        $this->assertStringContainsString('$0', $tollFreeDisclosure);
    }
}

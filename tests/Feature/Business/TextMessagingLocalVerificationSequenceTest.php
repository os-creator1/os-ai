<?php

namespace Tests\Feature\Business;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Messaging\CampaignAssignmentOutcome;
use App\Enums\Messaging\MessagingRegistrationStatus;
use App\Enums\Messaging\PhoneNumberType;
use App\Library\Messaging\BusinessMessagingRegistrationService;
use App\Library\Messaging\Contracts\MessagingProvisioningAdapter;
use App\Library\Messaging\DTO\AvailableNumberCandidate;
use App\Library\Messaging\FakeProvisioningAdapter;
use App\Library\Messaging\ManagedMessageDispatcher;
use App\Models\BusinessMessagingRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Review correction — the truthful, guided sequence Telnyx's current
 * documentation genuinely supports for a LOCAL (10DLC) number: business
 * verification (brand+campaign registration) can be, and this platform now
 * requires it be, completed BEFORE any number is purchased. This is the
 * sibling TextMessagingCustomerAcceptanceTest's own docblock names: that
 * file proves the UNCHANGED toll-free (number-first) sequence — Telnyx's
 * own toll-free verification submission requires the number to already be
 * owned and assigned to a messaging profile, so toll-free could never be
 * verified before purchase regardless of this platform's own choices.
 *
 * No real Telnyx call anywhere — FakeProvisioningAdapter throughout,
 * disposable database, no production gate.
 */
class TextMessagingLocalVerificationSequenceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;
    use CreatesMessagingFixtures;

    private function bindFakeProvisioningAdapter(): FakeProvisioningAdapter
    {
        $fake = new FakeProvisioningAdapter();
        $this->app->instance(MessagingProvisioningAdapter::class, $fake);
        config([
            'messaging.managed_messaging_enabled' => true,
            'messaging.managed_messaging_provisioning_enabled' => true,
            'services.telnyx.api_key' => 'fixture_key_not_a_real_credential',
        ]);

        return $fake;
    }

    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'legal_business_name' => 'Harbor Lane Studios LLC',
            'entity_type' => 'ein',
            'ein' => '12-3456789',
            'address_line_1' => '1 Harbor Lane',
            'city' => 'Portland',
            'region' => 'OR',
            'postal_code' => '97201',
            'website_url' => 'https://harborlane.example',
            'contact_email' => 'owner@harborlane.example',
            'contact_phone' => '+15035550100',
            'use_case' => 'appointment_reminders',
            'opt_in_method' => 'Customers check a box on our booking form.',
            'sample_message_1' => 'Harbor Lane: your appointment is confirmed. Reply STOP to unsubscribe.',
            'sample_message_2' => 'Harbor Lane: reminder, your appointment is tomorrow.',
            'privacy_policy_url' => 'https://harborlane.example/privacy',
            'terms_url' => 'https://harborlane.example/terms',
        ], $overrides);
    }

    private function extractCandidateToken(string $html): string
    {
        preg_match('/name="candidate_token" value="([^"]+)"/', $html, $matches);

        return $matches[1] ?? '';
    }

    // =================================================================
    // Business verification completed before any number exists.
    // =================================================================

    public function test_local_business_verification_can_be_completed_before_any_number_exists(): void
    {
        $fpa = $this->bindFakeProvisioningAdapter();
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $this->post(route('customer.workspaces.businesses.text-messaging.registration.start-local-verification', [$workspace->uid, $business->uid]))
            ->assertSessionHas('status', 'success');

        $registration = BusinessMessagingRegistration::where('business_id', $business->id)->firstOrFail();
        $this->assertSame(PhoneNumberType::Local, $registration->number_type);
        $this->assertSame(MessagingRegistrationStatus::NotStarted, $registration->status);
        $this->assertDatabaseCount('business_messaging_numbers', 0);
        $this->assertDatabaseCount('business_messaging_identities', 0);

        $this->post(route('customer.workspaces.businesses.text-messaging.registration.update', [$workspace->uid, $business->uid]), $this->registrationPayload());
        $this->post(route('customer.workspaces.businesses.text-messaging.registration.submit', [$workspace->uid, $business->uid]))
            ->assertSessionHas('status', 'success');

        $registration->refresh();
        $this->assertSame(MessagingRegistrationStatus::Pending, $registration->status);
        $this->assertNotNull($registration->provider_brand_id, '10DLC submission must produce a brand id.');
        $this->assertNotNull($registration->provider_campaign_id, '10DLC submission must produce a campaign id.');
        // Never a phone number in the 10DLC submission — it never had one.
        $this->assertSame([], array_filter($fpa->submittedRegistrations, fn ($s) => $s->phoneNumber !== null), 'A local submission before any number exists must never carry a phone number.');

        // Still no number — this is the whole point of the sequence.
        $this->assertDatabaseCount('business_messaging_numbers', 0);
    }

    public function test_the_registration_screen_shows_no_number_yet_honestly_while_verifying(): void
    {
        $this->bindFakeProvisioningAdapter();
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $this->post(route('customer.workspaces.businesses.text-messaging.registration.start-local-verification', [$workspace->uid, $business->uid]));

        $response = $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]));
        $response->assertOk();
        $response->assertSee('Not yet chosen');
        // Review correction — never claims verification is free; states
        // the truth from the actual configured rate (none, in every test
        // environment today).
        $response->assertSee('No fee is currently configured for business verification in this environment.');
    }

    // =================================================================
    // Approved with no number yet -> "choose your number" state.
    // =================================================================

    public function test_an_approved_local_verification_with_no_number_reaches_the_choose_your_number_state(): void
    {
        $fpa = $this->bindFakeProvisioningAdapter();
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $this->post(route('customer.workspaces.businesses.text-messaging.registration.start-local-verification', [$workspace->uid, $business->uid]));
        $this->post(route('customer.workspaces.businesses.text-messaging.registration.update', [$workspace->uid, $business->uid]), $this->registrationPayload());
        $this->post(route('customer.workspaces.businesses.text-messaging.registration.submit', [$workspace->uid, $business->uid]));

        $registration = BusinessMessagingRegistration::where('business_id', $business->id)->firstOrFail();
        $fpa->scriptRegistrationStatus($registration, MessagingRegistrationStatus::Approved);
        app(BusinessMessagingRegistrationService::class)->refreshAllPending();

        $response = $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]));
        $response->assertOk();
        $response->assertSee('Business verified', false);
        $response->assertSee('data-role="verified-banner"', false);
        $response->assertSee('Choose your local number');
    }

    // =================================================================
    // The guard: a local number can never be searched/ordered without an
    // Approved verification already on file.
    // =================================================================

    public function test_a_local_number_cannot_be_searched_or_ordered_without_prior_approved_verification(): void
    {
        $fpa = $this->bindFakeProvisioningAdapter();
        $fpa->queueSearchResult(new AvailableNumberCandidate('+14155552000', PhoneNumberType::Local, 'candidate-ref-guard-1'));
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $this->post(route('customer.workspaces.businesses.text-messaging.number.search', [$workspace->uid, $business->uid]), ['number_type' => 'local'])
            ->assertSessionHas('status', 'error');

        $this->assertSame([], $fpa->searches, 'The provider must never be searched for a local number before verification is Approved.');
        $this->assertDatabaseCount('business_messaging_numbers', 0);
    }

    public function test_a_local_number_order_is_refused_at_the_mutation_boundary_even_with_a_genuine_candidate_token(): void
    {
        // A Business somehow reaches the order route with a genuinely
        // valid candidate token for a local number (e.g. a stale page from
        // before this correction) but has no Approved verification on
        // file — the order route re-checks this independently, never
        // trusting the token alone.
        $fpa = $this->bindFakeProvisioningAdapter();
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $candidate = new AvailableNumberCandidate('+14155552001', PhoneNumberType::Local, 'candidate-ref-guard-2');
        $token = \App\Library\Messaging\CandidateToken::encode($business, $candidate);

        $this->post(route('customer.workspaces.businesses.text-messaging.number.order', [$workspace->uid, $business->uid]), [
            'candidate_token' => $token,
        ])->assertSessionHas('status', 'error');

        $this->assertSame([], $fpa->provisionedOrders);
        $this->assertDatabaseCount('business_messaging_numbers', 0);
    }

    // =================================================================
    // The complete verify-first journey: verify -> approve -> choose a
    // local number -> campaign assignment REQUESTED (not confirmed) ->
    // NOT Ready, sending refused -> the carrier's own completion polled
    // and resolves Confirmed -> Ready -> a send succeeds.
    //
    // Review correction — this is the exact test the reviewer flagged:
    // it previously showed Ready and a successful send immediately after
    // ordering, treating an asynchronous "Requested" task response as if
    // it were confirmed completion. Telnyx's own assignment endpoint
    // returns only a background task id; this now proves the customer is
    // held out of Ready and every real send attempt is refused until
    // that task is actually polled and reports completed.
    // =================================================================

    public function test_completing_the_verify_first_sequence_orders_a_local_number_and_requires_confirmed_campaign_assignment_before_ready(): void
    {
        $fpa = $this->bindFakeProvisioningAdapter();
        $fma = $this->bindFakeAdapter();
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $this->post(route('customer.workspaces.businesses.text-messaging.registration.start-local-verification', [$workspace->uid, $business->uid]));
        $this->post(route('customer.workspaces.businesses.text-messaging.registration.update', [$workspace->uid, $business->uid]), $this->registrationPayload());
        $this->post(route('customer.workspaces.businesses.text-messaging.registration.submit', [$workspace->uid, $business->uid]));

        $registration = BusinessMessagingRegistration::where('business_id', $business->id)->firstOrFail();
        $fpa->scriptRegistrationStatus($registration, MessagingRegistrationStatus::Approved);
        app(BusinessMessagingRegistrationService::class)->refreshAllPending();

        $fpa->queueSearchResult(new AvailableNumberCandidate('+14155552002', PhoneNumberType::Local, 'candidate-ref-verified-1'));
        $searchResponse = $this->post(route('customer.workspaces.businesses.text-messaging.number.search', [$workspace->uid, $business->uid]), ['number_type' => 'local'])
            ->assertOk();
        $candidateToken = $this->extractCandidateToken($searchResponse->getContent());

        $orderResponse = $this->post(route('customer.workspaces.businesses.text-messaging.number.order', [$workspace->uid, $business->uid]), [
            'candidate_token' => $candidateToken,
        ]);
        $orderResponse->assertSessionHas('status', 'success');
        $orderResponse->assertSessionHas('message', fn ($message) => str_contains($message, 'asked the carrier to enable this number'));

        // The campaign-assignment request itself, using the freshly
        // created Messaging Profile and the already-approved campaign.
        $this->assertCount(1, $fpa->campaignAssignments);
        $this->assertSame($registration->fresh()->provider_campaign_id, $fpa->campaignAssignments[0]['providerCampaignId']);

        $number = \App\Models\BusinessMessagingNumber::where('phone_number', '+14155552002')->firstOrFail();
        $this->assertSame('requested', $number->campaign_assignment_status, 'A background task id is not confirmed completion.');
        $this->assertFalse($number->isCampaignAssignmentConfirmedOrNotRequired());

        // Not Ready, and the screen must never claim it is, while the
        // carrier enablement task is still only Requested.
        $notReadyYet = $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]));
        $notReadyYet->assertOk();
        $notReadyYet->assertDontSee('Ready');
        $notReadyYet->assertSee('+14155552002');

        // The same fail-closed check is enforced again at the actual
        // outbound-send boundary — never only on the status screen.
        $this->expectException(\App\Library\Messaging\Exceptions\MessagingCampaignAssignmentNotConfirmedException::class);
        try {
            app(ManagedMessageDispatcher::class)->dispatch($business->fresh(), '+14155559999', 'Your appointment is confirmed.', 'op_local_verify_first_1');
        } finally {
            $this->assertSame(0, $fma->sentCount(), 'A send must never reach the provider before confirmation.');
        }
    }

    public function test_a_confirmed_campaign_assignment_poll_makes_the_customer_ready_and_unblocks_sending(): void
    {
        $fpa = $this->bindFakeProvisioningAdapter();
        $fma = $this->bindFakeAdapter();
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $this->post(route('customer.workspaces.businesses.text-messaging.registration.start-local-verification', [$workspace->uid, $business->uid]));
        $this->post(route('customer.workspaces.businesses.text-messaging.registration.update', [$workspace->uid, $business->uid]), $this->registrationPayload());
        $this->post(route('customer.workspaces.businesses.text-messaging.registration.submit', [$workspace->uid, $business->uid]));

        $registration = BusinessMessagingRegistration::where('business_id', $business->id)->firstOrFail();
        $fpa->scriptRegistrationStatus($registration, MessagingRegistrationStatus::Approved);
        app(BusinessMessagingRegistrationService::class)->refreshAllPending();

        $fpa->queueSearchResult(new AvailableNumberCandidate('+14155552009', PhoneNumberType::Local, 'candidate-ref-verified-3'));
        $searchResponse = $this->post(route('customer.workspaces.businesses.text-messaging.number.search', [$workspace->uid, $business->uid]), ['number_type' => 'local'])->assertOk();
        $candidateToken = $this->extractCandidateToken($searchResponse->getContent());

        $this->post(route('customer.workspaces.businesses.text-messaging.number.order', [$workspace->uid, $business->uid]), [
            'candidate_token' => $candidateToken,
        ])->assertSessionHas('status', 'success');

        $number = \App\Models\BusinessMessagingNumber::where('phone_number', '+14155552009')->firstOrFail();

        // The carrier's own real completion mechanism, now polled — this
        // is the ONLY path that can ever move the number to Ready.
        $fpa->scriptCampaignAssignmentPollOutcome('+14155552009', CampaignAssignmentOutcome::Confirmed);
        app(\App\Library\Messaging\BusinessMessagingProvisioningService::class)->refreshPendingCampaignAssignment($number->fresh());

        $number->refresh();
        $this->assertSame('confirmed', $number->campaign_assignment_status);
        $this->assertTrue($number->isCampaignAssignmentConfirmedOrNotRequired());

        $ready = $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]));
        $ready->assertOk();
        $ready->assertSee('Ready');
        $ready->assertSee('+14155552009');

        $result = app(ManagedMessageDispatcher::class)->dispatch($business->fresh(), '+14155559999', 'Your appointment is confirmed.', 'op_local_verify_first_2');
        $this->assertTrue($result->accepted);
        $this->assertSame(1, $fma->sentCount());
    }

    public function test_a_failed_campaign_assignment_still_completes_the_number_purchase_and_is_recorded(): void
    {
        $fpa = $this->bindFakeProvisioningAdapter();
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $this->post(route('customer.workspaces.businesses.text-messaging.registration.start-local-verification', [$workspace->uid, $business->uid]));
        $this->post(route('customer.workspaces.businesses.text-messaging.registration.update', [$workspace->uid, $business->uid]), $this->registrationPayload());
        $this->post(route('customer.workspaces.businesses.text-messaging.registration.submit', [$workspace->uid, $business->uid]));

        $registration = BusinessMessagingRegistration::where('business_id', $business->id)->firstOrFail();
        $fpa->scriptRegistrationStatus($registration, MessagingRegistrationStatus::Approved);
        app(BusinessMessagingRegistrationService::class)->refreshAllPending();

        $fpa->queueSearchResult(new AvailableNumberCandidate('+14155552003', PhoneNumberType::Local, 'candidate-ref-verified-2'));
        $searchResponse = $this->post(route('customer.workspaces.businesses.text-messaging.number.search', [$workspace->uid, $business->uid]), ['number_type' => 'local'])->assertOk();
        $candidateToken = $this->extractCandidateToken($searchResponse->getContent());

        // Script the assignment to fail AFTER the number is already
        // purchased — the fake's messaging profile id is deterministic
        // ("fake_profile_%06d"); this is the very first order in this
        // test, so it is fake_profile_000001.
        $fpa->scriptCampaignAssignmentOutcome('fake_profile_000001', CampaignAssignmentOutcome::Failed);

        $orderResponse = $this->post(route('customer.workspaces.businesses.text-messaging.number.order', [$workspace->uid, $business->uid]), [
            'candidate_token' => $candidateToken,
        ]);

        // The purchase itself is never undone or reported as a failure —
        // the number is genuinely, already bought.
        $orderResponse->assertSessionHas('status', 'success');
        $orderResponse->assertSessionHas('message', fn ($message) => str_contains($message, 'could not confirm your number was linked'));
        $this->assertDatabaseHas('business_messaging_numbers', ['phone_number' => '+14155552003', 'status' => 'active']);

        $number = \App\Models\BusinessMessagingNumber::where('phone_number', '+14155552003')->firstOrFail();
        $this->assertSame('failed', $number->campaign_assignment_status);
        $this->assertNotNull($number->campaign_assignment_failed_at);
        $this->assertFalse($number->isCampaignAssignmentConfirmedOrNotRequired());

        $this->assertDatabaseHas('business_messaging_provisioning_incidents', [
            'business_id' => $business->id,
            'stage' => 'campaign_assignment_request_failed',
        ]);

        // A failed assignment must never leave sending reachable either.
        $this->expectException(\App\Library\Messaging\Exceptions\MessagingCampaignAssignmentNotConfirmedException::class);
        app(ManagedMessageDispatcher::class)->dispatch($business->fresh(), '+14155559999', 'Your appointment is confirmed.', 'op_local_verify_failed_1');
    }

    // =================================================================
    // Rejection reason capture — a real reason when supplied, an explicit
    // "no reason supplied" statement otherwise. Proven for local (10DLC);
    // TextMessagingCustomerAcceptanceTest proves the "no reason" case for
    // toll-free's own rejection path.
    // =================================================================

    public function test_a_specific_carrier_rejection_reason_is_shown_when_supplied(): void
    {
        $fpa = $this->bindFakeProvisioningAdapter();
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $this->post(route('customer.workspaces.businesses.text-messaging.registration.start-local-verification', [$workspace->uid, $business->uid]));
        $this->post(route('customer.workspaces.businesses.text-messaging.registration.update', [$workspace->uid, $business->uid]), $this->registrationPayload());
        $this->post(route('customer.workspaces.businesses.text-messaging.registration.submit', [$workspace->uid, $business->uid]));

        $registration = BusinessMessagingRegistration::where('business_id', $business->id)->firstOrFail();
        $fpa->scriptRegistrationStatus($registration, MessagingRegistrationStatus::Rejected, 'MNO_REJECTED: sample message did not include required opt-out language.');
        app(BusinessMessagingRegistrationService::class)->refreshAllPending();

        $registration->refresh();
        $this->assertSame('MNO_REJECTED: sample message did not include required opt-out language.', $registration->rejection_reason);

        $response = $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]));
        $response->assertSee('MNO_REJECTED: sample message did not include required opt-out language.');
        $response->assertDontSee('The carrier did not supply a detailed reason');
    }

    // =================================================================
    // Cross-business refusal of the verify-first entry point.
    // =================================================================

    public function test_business_a_cannot_start_local_verification_for_business_b(): void
    {
        $this->bindFakeProvisioningAdapter();
        [$customerA, , $workspaceA] = $this->tenant(WorkspacePlanTier::Growth, 'Business A', 'Workspace A');
        [, $businessB, $workspaceB] = $this->tenant(WorkspacePlanTier::Growth, 'Business B', 'Workspace B');
        $this->authenticateAs($customerA);

        $this->post(route('customer.workspaces.businesses.text-messaging.registration.start-local-verification', [$workspaceB->uid, $businessB->uid]))
            ->assertNotFound();

        $this->assertDatabaseCount('business_messaging_registrations', 0);
    }
}

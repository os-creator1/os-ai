<?php

namespace Tests\Feature\Business;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Messaging\MessagingRegistrationStatus;
use App\Enums\Messaging\PhoneNumberType;
use App\Library\Messaging\BusinessMessagingRegistrationService;
use App\Library\Messaging\Contracts\MessagingProvisioningAdapter;
use App\Library\Messaging\DTO\AvailableNumberCandidate;
use App\Library\Messaging\DTO\ProvisionedNumberResult;
use App\Library\Messaging\Exceptions\MessagingInsufficientFundsException;
use App\Library\Messaging\FakeProvisioningAdapter;
use App\Library\Messaging\ManagedMessageDispatcher;
use App\Models\Business;
use App\Models\BusinessMessagingRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * The single, complete fake-only customer acceptance flow through
 * Settings -> Text messaging: search a real-shaped number, fund and order
 * it, submit the correct 10DLC/toll-free registration, refresh through
 * approval, and confirm the resulting Ready state's path into messaging
 * (an actual send through ManagedMessageDispatcher).
 *
 * No test anywhere else in this suite chains every one of these steps
 * together over real HTTP routes — TextMessagingSetupStateTest proves
 * each state renders correctly and TextMessagingRegistrationAuthorizationTest
 * proves the authorization matrix, but neither walks search -> order ->
 * registration -> Approved -> a real send in one journey. This file is
 * that journey, plus the four required refusal scenarios: insufficient
 * funds, a rejected registration's retry path, a duplicate purchase
 * attempt, and cross-business refusal of the order action specifically
 * (the one step the existing cross-business test did not cover).
 *
 * Review correction — exercises the toll-free (number-first) sequence
 * throughout: Telnyx's own toll-free verification submission requires the
 * number to already be owned, so toll-free's order-then-verify sequence
 * is unchanged by that correction. TextMessagingLocalVerificationSequenceTest
 * is this file's own sibling for the NEW local (10DLC) verify-first
 * sequence that correction introduced.
 *
 * FakeProvisioningAdapter (search/order/registration) and
 * FakeMessagingAdapter (the resulting send) throughout — no real Telnyx
 * call, credential, or production gate anywhere in this file.
 */
class TextMessagingCustomerAcceptanceTest extends TestCase
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

    private function extractCandidateToken(string $html): string
    {
        preg_match('/name="candidate_token" value="([^"]+)"/', $html, $matches);

        return $matches[1] ?? '';
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

    /**
     * Search -> order, returning the phone number ordered. Shared by every
     * test below so each one starts from the same, already-proven step.
     */
    private function searchAndOrder(Business $business, $workspace): void
    {
        $searchResponse = $this->post(route('customer.workspaces.businesses.text-messaging.number.search', [$workspace->uid, $business->uid]), [
            'number_type' => 'toll_free',
        ])->assertOk();
        $candidateToken = $this->extractCandidateToken($searchResponse->getContent());

        $this->post(route('customer.workspaces.businesses.text-messaging.number.order', [$workspace->uid, $business->uid]), [
            'candidate_token' => $candidateToken,
        ])->assertSessionHas('status', 'success');
    }

    // =================================================================
    // The complete journey: search -> funded order -> registration ->
    // approval -> Ready -> a real send.
    // =================================================================

    public function test_the_complete_search_to_ready_journey_and_the_resulting_number_can_send(): void
    {
        $fpa = $this->bindFakeProvisioningAdapter();
        $fma = $this->bindFakeAdapter();
        $fpa->queueSearchResult(new AvailableNumberCandidate('+14155551900', PhoneNumberType::TollFree, 'candidate-ref-acceptance-1'));

        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        // 1. Search and a funded (fake, cost-free) order.
        $this->searchAndOrder($business, $workspace);
        $this->assertDatabaseHas('business_messaging_numbers', ['phone_number' => '+14155551900', 'status' => 'active']);

        $show = $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]));
        $show->assertOk();
        $show->assertSee('+14155551900');
        $show->assertSee('Not started'); // no registration submitted yet

        // 2. Enter and submit the correct registration for this number's
        // regime (toll-free -> Telnyx toll-free verification, via
        // BusinessMessagingRegistrationService routing on number_type —
        // never guessed by this test). Toll-free's own submission requires
        // the already-owned number (review correction), which this
        // Business now has from step 1.
        $this->post(route('customer.workspaces.businesses.text-messaging.registration.update', [$workspace->uid, $business->uid]), $this->registrationPayload());
        $this->post(route('customer.workspaces.businesses.text-messaging.registration.submit', [$workspace->uid, $business->uid]))
            ->assertSessionHas('status', 'success');

        $registration = BusinessMessagingRegistration::where('business_id', $business->id)->firstOrFail();
        $this->assertSame(MessagingRegistrationStatus::Pending, $registration->status);
        $this->assertNotNull($registration->provider_registration_id, 'Toll-free submission must produce a bare registration id.');
        $this->assertNull($registration->provider_brand_id, 'Toll-free has no brand/campaign concept.');

        $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]))
            ->assertSee('Pending review');

        // 3. Refresh through approval — the real scheduled mechanism
        // (RefreshPendingMessagingRegistrations -> refreshAllPending()),
        // never a page render.
        $fpa->scriptRegistrationStatus($registration, MessagingRegistrationStatus::Approved);
        app(BusinessMessagingRegistrationService::class)->refreshAllPending();

        $registration->refresh();
        $this->assertSame(MessagingRegistrationStatus::Approved, $registration->status);
        $this->assertNotNull($registration->approved_at);

        // 4. Confirm the Ready state and its path into messaging.
        $ready = $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]));
        $ready->assertOk();
        $ready->assertSee('Ready');
        $ready->assertSeeInOrder(['Text messages', 'Available']);

        $result = app(ManagedMessageDispatcher::class)->dispatch($business->fresh(), '+14155559999', 'Your appointment is confirmed.', 'op_acceptance_journey_1');

        $this->assertTrue($result->accepted, 'A Ready number must be able to send.');
        $this->assertSame(1, $fma->sentCount());
    }

    // =================================================================
    // Insufficient funds — refused before any provider commitment, with a
    // distinct, customer-actionable message (never confused with "not
    // configured").
    // =================================================================

    public function test_ordering_is_refused_with_a_distinct_message_when_the_wallet_has_insufficient_funds(): void
    {
        $fpa = new class extends FakeProvisioningAdapter {
            public function provisionNumber(Business $business, AvailableNumberCandidate $candidate): ProvisionedNumberResult
            {
                throw new MessagingInsufficientFundsException('insufficient_balance');
            }
        };
        $this->app->instance(MessagingProvisioningAdapter::class, $fpa);
        config([
            'messaging.managed_messaging_enabled' => true,
            'messaging.managed_messaging_provisioning_enabled' => true,
            'services.telnyx.api_key' => 'fixture_key_not_a_real_credential',
        ]);
        $fpa->queueSearchResult(new AvailableNumberCandidate('+14155551901', PhoneNumberType::TollFree, 'candidate-ref-acceptance-2'));

        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $searchResponse = $this->post(route('customer.workspaces.businesses.text-messaging.number.search', [$workspace->uid, $business->uid]), ['number_type' => 'toll_free']);
        $candidateToken = $this->extractCandidateToken($searchResponse->getContent());

        $this->post(route('customer.workspaces.businesses.text-messaging.number.order', [$workspace->uid, $business->uid]), [
            'candidate_token' => $candidateToken,
        ])
            ->assertSessionHas('status', 'error')
            ->assertSessionHas('message', 'Your Business doesn\'t have enough funds to add this number. Add funds to your Business balance and try again.');

        // Never a purchase: nothing persisted, no identity reserved either.
        $this->assertDatabaseCount('business_messaging_numbers', 0);
        $this->assertDatabaseCount('business_messaging_identities', 0);
    }

    // =================================================================
    // A rejected registration: shown truthfully, corrected, and
    // resubmitted all the way to Ready over HTTP.
    // =================================================================

    public function test_a_rejected_registration_can_be_corrected_and_resubmitted_to_approval(): void
    {
        $fpa = $this->bindFakeProvisioningAdapter();
        $fpa->queueSearchResult(new AvailableNumberCandidate('+14155551902', PhoneNumberType::TollFree, 'candidate-ref-acceptance-3'));

        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $this->searchAndOrder($business, $workspace);
        $this->post(route('customer.workspaces.businesses.text-messaging.registration.update', [$workspace->uid, $business->uid]), $this->registrationPayload());
        $this->post(route('customer.workspaces.businesses.text-messaging.registration.submit', [$workspace->uid, $business->uid]));

        $registration = BusinessMessagingRegistration::where('business_id', $business->id)->firstOrFail();
        $fpa->scriptRegistrationStatus($registration, MessagingRegistrationStatus::Rejected);
        app(BusinessMessagingRegistrationService::class)->refreshAllPending();

        $registration->refresh();
        $this->assertSame(MessagingRegistrationStatus::Rejected, $registration->status);
        $this->assertNotNull($registration->rejected_at);

        $rejectedPage = $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]));
        $rejectedPage->assertSee('Needs attention');
        // No reason was scripted on this rejection — the view must say so
        // explicitly, never paraphrase a reason that was never supplied.
        $rejectedPage->assertSee('The carrier did not supply a detailed reason for this rejection');

        // Correcting clears the rejection and returns to not_started.
        $this->post(route('customer.workspaces.businesses.text-messaging.registration.update', [$workspace->uid, $business->uid]), $this->registrationPayload([
            'legal_business_name' => 'Harbor Lane Studios LLC (Corrected)',
        ]))->assertSessionHas('status', 'success');

        $registration->refresh();
        $this->assertSame(MessagingRegistrationStatus::NotStarted, $registration->status);
        $this->assertNull($registration->rejection_reason);

        // Resubmit and approve — the same registration row, never a
        // duplicate.
        $this->post(route('customer.workspaces.businesses.text-messaging.registration.submit', [$workspace->uid, $business->uid]))
            ->assertSessionHas('status', 'success');

        $registration->refresh();
        $fpa->scriptRegistrationStatus($registration, MessagingRegistrationStatus::Approved);
        app(BusinessMessagingRegistrationService::class)->refreshAllPending();

        $this->assertDatabaseCount('business_messaging_registrations', 1);
        $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]))
            ->assertSee('Ready');
    }

    // =================================================================
    // Duplicate purchase — a second order attempt once a number is
    // already active is refused, never a second identity/number.
    // =================================================================

    public function test_a_second_order_attempt_after_already_having_a_number_is_refused(): void
    {
        $fpa = $this->bindFakeProvisioningAdapter();
        $fpa->queueSearchResult(new AvailableNumberCandidate('+14155551903', PhoneNumberType::TollFree, 'candidate-ref-acceptance-4'));

        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $this->searchAndOrder($business, $workspace);
        $this->assertDatabaseCount('business_messaging_numbers', 1);

        // A second search/order attempt against the SAME, now-active
        // Business is refused before ever reaching the provider again —
        // guardNoExistingNumber() fires first.
        $this->post(route('customer.workspaces.businesses.text-messaging.number.search', [$workspace->uid, $business->uid]), ['number_type' => 'toll_free'])
            ->assertNotFound();
        $this->post(route('customer.workspaces.businesses.text-messaging.number.order', [$workspace->uid, $business->uid]), [
            'candidate_token' => 'stale-or-forged-token-irrelevant-here',
        ])->assertNotFound();

        $this->assertDatabaseCount('business_messaging_numbers', 1);
        $this->assertDatabaseCount('business_messaging_identities', 1);
        $this->assertSame(1, count($fpa->provisionedOrders), 'The second attempt must never reach the provider.');
    }

    // =================================================================
    // Cross-business refusal — Business A cannot order a number for
    // Business B. (show/search cross-business refusal is already covered
    // by TextMessagingSetupStateTest; this is the one step it did not
    // cover.)
    // =================================================================

    public function test_business_a_cannot_order_a_number_for_business_b(): void
    {
        $fpa = $this->bindFakeProvisioningAdapter();
        $fpa->queueSearchResult(new AvailableNumberCandidate('+14155551904', PhoneNumberType::TollFree, 'candidate-ref-acceptance-5'));

        [$customerA, , $workspaceA] = $this->tenant(WorkspacePlanTier::Growth, 'Business A', 'Workspace A');
        [$customerB, $businessB, $workspaceB] = $this->tenant(WorkspacePlanTier::Growth, 'Business B', 'Workspace B');

        // Business B genuinely searches for and receives a valid token on
        // its OWN route first.
        $this->authenticateAs($customerB);
        $searchResponse = $this->post(route('customer.workspaces.businesses.text-messaging.number.search', [$workspaceB->uid, $businessB->uid]), ['number_type' => 'toll_free']);
        $candidateToken = $this->extractCandidateToken($searchResponse->getContent());

        // Business A attempts to spend that token against Business B's own
        // route.
        $this->authenticateAs($customerA);
        $this->post(route('customer.workspaces.businesses.text-messaging.number.order', [$workspaceB->uid, $businessB->uid]), [
            'candidate_token' => $candidateToken,
        ])->assertNotFound();

        $this->assertDatabaseCount('business_messaging_numbers', 0);
        $this->assertSame([], $fpa->provisionedOrders, 'Business A must never be able to provision a number for Business B.');
    }
}

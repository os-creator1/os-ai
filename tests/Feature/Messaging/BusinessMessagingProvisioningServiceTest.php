<?php

namespace Tests\Feature\Messaging;

use App\Enums\Messaging\BusinessMessagingIdentityStatus;
use App\Enums\Messaging\MessagingProvider;
use App\Enums\Messaging\PhoneNumberType;
use App\Library\Messaging\BusinessMessagingIdentityResolver;
use App\Library\Messaging\BusinessMessagingProvisioningService;
use App\Library\Messaging\Contracts\MessagingProvisioningAdapter;
use App\Library\Messaging\DTO\AvailableNumberCandidate;
use App\Library\Messaging\DTO\NumberSearchCriteria;
use App\Library\Messaging\Exceptions\MessagingIdentityConflictException;
use App\Library\Messaging\Exceptions\MessagingProviderNotConfiguredException;
use App\Library\Messaging\FakeProvisioningAdapter;
use App\Models\BusinessMessagingNumber;
use App\Models\BusinessMessagingProvisioningIncident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

/**
 * Text messaging setup/number/compliance hub — STATE 1's provisioning
 * orchestration: search returns whatever the adapter genuinely returned,
 * order only ever persists a number after a real provider round trip, and
 * a Business already carrying a number can never provision a second one.
 */
class BusinessMessagingProvisioningServiceTest extends TestCase
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

    private function service(): BusinessMessagingProvisioningService
    {
        return app(BusinessMessagingProvisioningService::class);
    }

    // -----------------------------------------------------------------
    // Availability / preview.
    // -----------------------------------------------------------------

    public function test_provisioning_is_unavailable_when_the_platform_switch_is_off(): void
    {
        config(['messaging.managed_messaging_enabled' => false]);

        $this->assertFalse($this->service()->isAvailable());
    }

    public function test_provisioning_is_unavailable_when_no_api_key_is_configured(): void
    {
        config(['messaging.managed_messaging_enabled' => true, 'services.telnyx.api_key' => '']);

        $this->assertFalse($this->service()->isAvailable());
    }

    public function test_provisioning_is_available_once_both_gates_are_set(): void
    {
        $this->bindFakeProvisioningAdapter();

        $this->assertTrue($this->service()->isAvailable());
    }

    public function test_searching_when_not_configured_returns_an_empty_list_never_a_guess(): void
    {
        config(['messaging.managed_messaging_enabled' => false]);

        $results = $this->service()->searchNumbers(new NumberSearchCriteria('US', PhoneNumberType::Local));

        $this->assertSame([], $results);
    }

    // -----------------------------------------------------------------
    // Search — a pure pass-through to the adapter.
    // -----------------------------------------------------------------

    public function test_search_returns_exactly_what_the_adapter_returns(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $candidate = new AvailableNumberCandidate('+14155550100', PhoneNumberType::Local, 'candidate-ref-1');
        $fake->queueSearchResult($candidate);

        $criteria = new NumberSearchCriteria('US', PhoneNumberType::Local, '415');
        $results = $this->service()->searchNumbers($criteria);

        $this->assertSame([$candidate], $results);
        $this->assertSame([$criteria], $fake->searches, 'The exact criteria reached the adapter.');
    }

    // -----------------------------------------------------------------
    // Order — never a fake success; only a real round trip persists.
    // -----------------------------------------------------------------

    public function test_provisioning_a_number_creates_an_identity_and_an_active_primary_number(): void
    {
        $this->bindFakeProvisioningAdapter();
        $business = $this->makeBusiness();
        $candidate = new AvailableNumberCandidate('+14155550101', PhoneNumberType::Local, 'candidate-ref-2');

        $number = $this->service()->provisionNumber($business, $candidate);

        $this->assertInstanceOf(BusinessMessagingNumber::class, $number);
        $this->assertSame('+14155550101', $number->phone_number);
        $this->assertTrue($number->is_primary);
        $this->assertTrue($number->isActive());
        $this->assertSame(PhoneNumberType::Local, $number->number_type);

        $identity = app(BusinessMessagingIdentityResolver::class)->resolveForBusiness($business);
        $this->assertNotNull($identity, 'A new managed identity must exist for this Business.');
        $this->assertNotNull($identity->messaging_profile_id);
    }

    public function test_provisioning_a_toll_free_number_records_its_number_type(): void
    {
        $this->bindFakeProvisioningAdapter();
        $business = $this->makeBusiness();
        $candidate = new AvailableNumberCandidate('+18005550100', PhoneNumberType::TollFree, 'candidate-ref-3');

        $number = $this->service()->provisionNumber($business, $candidate);

        $this->assertSame(PhoneNumberType::TollFree, $number->number_type);
    }

    public function test_a_business_that_already_has_an_active_identity_cannot_provision_a_second_number(): void
    {
        $this->bindFakeProvisioningAdapter();
        [$business] = $this->managedBusiness();

        $this->expectException(MessagingIdentityConflictException::class);

        $this->service()->provisionNumber($business, new AvailableNumberCandidate('+14155550102', PhoneNumberType::Local, 'candidate-ref-4'));
    }

    public function test_provisioning_when_not_configured_throws_and_writes_nothing(): void
    {
        config(['messaging.managed_messaging_enabled' => false]);
        $business = $this->makeBusiness();

        try {
            $this->service()->provisionNumber($business, new AvailableNumberCandidate('+14155550103', PhoneNumberType::Local, 'candidate-ref-5'));
            $this->fail('Expected MessagingProviderNotConfiguredException.');
        } catch (MessagingProviderNotConfiguredException) {
            // expected
        }

        $this->assertNull(app(BusinessMessagingIdentityResolver::class)->resolveForBusiness($business));
        $this->assertDatabaseMissing('business_messaging_numbers', ['phone_number' => '+14155550103']);
    }

    public function test_provisioning_writes_the_identity_and_its_number_together(): void
    {
        $this->bindFakeProvisioningAdapter();
        $business = $this->makeBusiness();

        $number = $this->service()->provisionNumber($business, new AvailableNumberCandidate('+14155550104', PhoneNumberType::Local, 'candidate-ref-6'));

        $this->assertDatabaseHas('business_messaging_identities', ['business_id' => $business->id]);
        $this->assertDatabaseHas('business_messaging_numbers', ['id' => $number->id, 'business_messaging_identity_id' => $number->business_messaging_identity_id]);
    }

    // -----------------------------------------------------------------
    // PR #295 Correction Round 1, item 3 — the identity slot is reserved
    // BEFORE any provider call, so a concurrent/stale second attempt can
    // never reach the adapter and can never cause a second paid
    // commitment.
    // -----------------------------------------------------------------

    public function test_a_pending_reservation_from_a_concurrent_attempt_blocks_a_second_attempt_before_any_provider_call(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $business = $this->makeBusiness();

        // Simulates a first, still-in-flight request having already won
        // the reservation (the exact row provisionNumber() itself would
        // create before calling the adapter).
        app(BusinessMessagingIdentityResolver::class)->create(
            $business,
            'reserved:concurrent-attempt',
            null,
            MessagingProvider::Telnyx,
            BusinessMessagingIdentityStatus::Pending,
        );

        $this->expectException(MessagingIdentityConflictException::class);

        try {
            $this->service()->provisionNumber($business, new AvailableNumberCandidate('+14155550110', PhoneNumberType::Local, 'candidate-ref-7'));
        } finally {
            $this->assertSame([], $fake->provisionedOrders, 'The second, conflicting attempt must never reach the provider.');
        }
    }

    public function test_a_provider_failure_frees_the_reservation_for_another_attempt(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $business = $this->makeBusiness();

        $this->app->bind(MessagingProvisioningAdapter::class, fn () => new class extends FakeProvisioningAdapter {
            public function provisionNumber(\App\Models\Business $business, AvailableNumberCandidate $candidate): \App\Library\Messaging\DTO\ProvisionedNumberResult
            {
                throw new \RuntimeException('Simulated provider failure.');
            }
        });

        try {
            $this->service()->provisionNumber($business, new AvailableNumberCandidate('+14155550111', PhoneNumberType::Local, 'candidate-ref-8'));
            $this->fail('Expected the simulated provider failure to propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated provider failure.', $e->getMessage());
        }

        // The failed reservation must not leave the Business permanently
        // stuck — a fresh attempt is possible.
        $this->assertNull(app(BusinessMessagingIdentityResolver::class)->resolveForBusiness($business));
        $this->assertDatabaseCount('business_messaging_identities', 0);

        $this->bindFakeProvisioningAdapter();
        $number = $this->service()->provisionNumber($business, new AvailableNumberCandidate('+14155550112', PhoneNumberType::Local, 'candidate-ref-9'));
        $this->assertInstanceOf(BusinessMessagingNumber::class, $number);
    }

    public function test_a_local_finalization_failure_after_provider_success_is_recorded_never_lost(): void
    {
        $this->bindFakeProvisioningAdapter();
        $business = $this->makeBusiness();

        // A phone number already claimed by another active mapping makes
        // attachNumber() throw AFTER the (fake) provider has already
        // "succeeded" — exactly the partial-failure window item 3 requires
        // reconciliation state for.
        [$otherBusiness] = $this->managedBusiness('+14155550113');

        $number = new AvailableNumberCandidate('+14155550113', PhoneNumberType::Local, 'candidate-ref-10');

        try {
            $this->service()->provisionNumber($business, $number);
            $this->fail('Expected a MessagingIdentityConflictException from the claimed number.');
        } catch (MessagingIdentityConflictException) {
            // expected
        }

        // The reservation identity itself survives with the real provider
        // profile id — it is not lost.
        $identity = \App\Models\BusinessMessagingIdentity::query()->where('business_id', $business->id)->first();
        $this->assertNotNull($identity);
        $this->assertStringStartsNotWith('reserved:', $identity->messaging_profile_id);

        // And an incident row exists recording exactly what the provider
        // returned, so the phone number itself is never invisible either.
        $this->assertDatabaseHas('business_messaging_provisioning_incidents', [
            'business_id' => $business->id,
            'stage' => 'number_attach_failed_after_provider_success',
            'phone_number' => '+14155550113',
        ]);
        $this->assertSame(1, BusinessMessagingProvisioningIncident::where('business_id', $business->id)->count());
    }

    // =================================================================
    // Review correction — assignToApprovedCampaign()'s durable, pollable
    // campaign-assignment state. Requested is never treated as Confirmed
    // anywhere; a failed or unconfirmable request is recorded both on the
    // number row and as a provisioning incident.
    // =================================================================

    public function test_assign_to_approved_campaign_persists_requested_status_and_task_id(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $business = $this->makeBusiness();
        $number = $this->service()->provisionNumber($business, new AvailableNumberCandidate('+14155550300', PhoneNumberType::Local, 'candidate-ref-assign-1'));

        $result = $this->service()->assignToApprovedCampaign($business, $number, 'fake_campaign_000001');

        $this->assertSame(\App\Enums\Messaging\CampaignAssignmentOutcome::Requested, $result->outcome);
        $number->refresh();
        $this->assertSame('requested', $number->campaign_assignment_status);
        $this->assertNotNull($number->campaign_assignment_task_id);
        $this->assertNull($number->campaign_assignment_confirmed_at);
        $this->assertNull($number->campaign_assignment_failed_at);
        // A merely-Requested outcome is never itself an incident.
        $this->assertDatabaseCount('business_messaging_provisioning_incidents', 0);
    }

    public function test_assign_to_approved_campaign_persists_failed_status_and_records_an_incident(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $business = $this->makeBusiness();
        $number = $this->service()->provisionNumber($business, new AvailableNumberCandidate('+14155550301', PhoneNumberType::Local, 'candidate-ref-assign-2'));
        $fake->scriptCampaignAssignmentOutcome('fake_profile_000001', \App\Enums\Messaging\CampaignAssignmentOutcome::Failed);

        $result = $this->service()->assignToApprovedCampaign($business, $number, 'fake_campaign_000002');

        $this->assertSame(\App\Enums\Messaging\CampaignAssignmentOutcome::Failed, $result->outcome);
        $number->refresh();
        $this->assertSame('failed', $number->campaign_assignment_status);
        $this->assertNotNull($number->campaign_assignment_failed_at);
        $this->assertSame('fake_failed', $number->campaign_assignment_failure_reason);
        $this->assertDatabaseHas('business_messaging_provisioning_incidents', [
            'business_id' => $business->id,
            'stage' => 'campaign_assignment_request_failed',
        ]);
    }

    public function test_assign_to_approved_campaign_fails_and_records_an_incident_when_the_messaging_profile_id_is_missing(): void
    {
        $this->bindFakeProvisioningAdapter();
        $business = $this->makeBusiness();
        $number = $this->service()->provisionNumber($business, new AvailableNumberCandidate('+14155550309', PhoneNumberType::Local, 'candidate-ref-assign-11'));
        // messaging_profile_id is a real NOT-NULL, UNIQUE column, and a
        // number's identity is protected by its own restrictOnDelete()
        // foreign key — there is no way to genuinely orphan a number via
        // SQL. Faking the in-memory relation is the only way to exercise
        // this method's own defensive "identity is missing" branch without
        // corrupting the database.
        $number->setRelation('identity', null);

        $result = $this->service()->assignToApprovedCampaign($business, $number, 'fake_campaign_000003');

        $this->assertSame(\App\Enums\Messaging\CampaignAssignmentOutcome::Failed, $result->outcome);
        $this->assertSame('missing_messaging_profile_id', $result->detail);
        $number->refresh();
        $this->assertSame('failed', $number->campaign_assignment_status);
        $this->assertDatabaseHas('business_messaging_provisioning_incidents', [
            'business_id' => $business->id,
            'stage' => 'campaign_assignment_request_failed',
            'error_message' => 'missing_messaging_profile_id',
        ]);
    }

    public function test_assign_to_approved_campaign_fails_and_records_an_incident_when_the_campaign_id_is_missing(): void
    {
        $this->bindFakeProvisioningAdapter();
        $business = $this->makeBusiness();
        $number = $this->service()->provisionNumber($business, new AvailableNumberCandidate('+14155550302', PhoneNumberType::Local, 'candidate-ref-assign-4'));

        $result = $this->service()->assignToApprovedCampaign($business, $number, null);

        $this->assertSame(\App\Enums\Messaging\CampaignAssignmentOutcome::Failed, $result->outcome);
        $this->assertSame('missing_campaign_id', $result->detail);
        $number->refresh();
        $this->assertSame('failed', $number->campaign_assignment_status);
        $this->assertDatabaseHas('business_messaging_provisioning_incidents', [
            'business_id' => $business->id,
            'stage' => 'campaign_assignment_request_failed',
            'error_message' => 'missing_campaign_id',
        ]);
    }

    // =================================================================
    // Review correction — refreshPendingCampaignAssignment(): the real
    // completion mechanism, polled until it resolves.
    // =================================================================

    public function test_refresh_pending_campaign_assignment_confirms_when_the_poll_reports_completed(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $business = $this->makeBusiness();
        $number = $this->service()->provisionNumber($business, new AvailableNumberCandidate('+14155550303', PhoneNumberType::Local, 'candidate-ref-assign-5'));
        $this->service()->assignToApprovedCampaign($business, $number, 'fake_campaign_000005');
        $fake->scriptCampaignAssignmentPollOutcome('+14155550303', \App\Enums\Messaging\CampaignAssignmentOutcome::Confirmed);

        $this->service()->refreshPendingCampaignAssignment($number->fresh());

        $number->refresh();
        $this->assertSame('confirmed', $number->campaign_assignment_status);
        $this->assertNotNull($number->campaign_assignment_confirmed_at);
        $this->assertTrue($number->isCampaignAssignmentConfirmedOrNotRequired());
    }

    public function test_refresh_pending_campaign_assignment_fails_when_the_poll_reports_failed(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $business = $this->makeBusiness();
        $number = $this->service()->provisionNumber($business, new AvailableNumberCandidate('+14155550304', PhoneNumberType::Local, 'candidate-ref-assign-6'));
        $this->service()->assignToApprovedCampaign($business, $number, 'fake_campaign_000006');
        $fake->scriptCampaignAssignmentPollOutcome('+14155550304', \App\Enums\Messaging\CampaignAssignmentOutcome::Failed);

        $this->service()->refreshPendingCampaignAssignment($number->fresh());

        $number->refresh();
        $this->assertSame('failed', $number->campaign_assignment_status);
        $this->assertNotNull($number->campaign_assignment_failed_at);
        $this->assertFalse($number->isCampaignAssignmentConfirmedOrNotRequired());
        $this->assertDatabaseHas('business_messaging_provisioning_incidents', [
            'business_id' => $business->id,
            'stage' => 'campaign_assignment_request_failed',
        ]);
    }

    /**
     * Retry/reconciliation — still processing on this poll never advances
     * or regresses anything; a LATER poll (the next scheduled sweep) can
     * still resolve it.
     */
    public function test_refresh_pending_campaign_assignment_is_a_no_op_while_still_processing_and_can_later_resolve(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $business = $this->makeBusiness();
        $number = $this->service()->provisionNumber($business, new AvailableNumberCandidate('+14155550305', PhoneNumberType::Local, 'candidate-ref-assign-7'));
        $this->service()->assignToApprovedCampaign($business, $number, 'fake_campaign_000007');
        // Default scripted poll outcome is Requested ("still processing").

        $this->service()->refreshPendingCampaignAssignment($number->fresh());

        $number->refresh();
        $this->assertSame('requested', $number->campaign_assignment_status, 'Still processing must never regress or advance the status.');
        $this->assertNull($number->campaign_assignment_confirmed_at);
        $this->assertNull($number->campaign_assignment_failed_at);

        // The next sweep tick, now that the carrier has actually finished.
        $fake->scriptCampaignAssignmentPollOutcome('+14155550305', \App\Enums\Messaging\CampaignAssignmentOutcome::Confirmed);
        $this->service()->refreshPendingCampaignAssignment($number->fresh());

        $this->assertSame('confirmed', $number->fresh()->campaign_assignment_status);
    }

    public function test_refresh_pending_campaign_assignment_does_nothing_for_a_number_with_no_assignment_in_progress(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $business = $this->makeBusiness();
        $number = $this->service()->provisionNumber($business, new AvailableNumberCandidate('+14155550306', PhoneNumberType::TollFree, 'candidate-ref-assign-8'));

        $this->service()->refreshPendingCampaignAssignment($number->fresh());

        $this->assertSame([], $fake->campaignAssignmentStatusChecks, 'A toll-free number (or any number never put through assignment) must never be polled.');
    }

    public function test_refresh_all_pending_campaign_assignments_sweeps_every_requested_number(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $businessA = $this->makeBusiness();
        $numberA = $this->service()->provisionNumber($businessA, new AvailableNumberCandidate('+14155550307', PhoneNumberType::Local, 'candidate-ref-assign-9'));
        $this->service()->assignToApprovedCampaign($businessA, $numberA, 'fake_campaign_000009');

        $businessB = $this->makeBusiness();
        $numberB = $this->service()->provisionNumber($businessB, new AvailableNumberCandidate('+14155550308', PhoneNumberType::Local, 'candidate-ref-assign-10'));
        $this->service()->assignToApprovedCampaign($businessB, $numberB, 'fake_campaign_000010');

        $fake->scriptCampaignAssignmentPollOutcome('+14155550307', \App\Enums\Messaging\CampaignAssignmentOutcome::Confirmed);
        $fake->scriptCampaignAssignmentPollOutcome('+14155550308', \App\Enums\Messaging\CampaignAssignmentOutcome::Failed);

        $this->service()->refreshAllPendingCampaignAssignments();

        $this->assertSame('confirmed', $numberA->fresh()->campaign_assignment_status);
        $this->assertSame('failed', $numberB->fresh()->campaign_assignment_status);
    }
}

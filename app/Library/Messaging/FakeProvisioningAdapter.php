<?php

namespace App\Library\Messaging;

use App\Enums\Messaging\CampaignAssignmentOutcome;
use App\Enums\Messaging\CarrierReleaseOutcome;
use App\Enums\Messaging\MessagingRegistrationStatus;
use App\Enums\Messaging\PhoneNumberType;
use App\Library\Messaging\Contracts\MessagingProvisioningAdapter;
use App\Library\Messaging\DTO\AvailableNumberCandidate;
use App\Library\Messaging\DTO\CampaignAssignmentResult;
use App\Library\Messaging\DTO\CarrierReleaseResult;
use App\Library\Messaging\DTO\MessagingRegistrationSubmission;
use App\Library\Messaging\DTO\NumberReleaseQuery;
use App\Library\Messaging\DTO\NumberSearchCriteria;
use App\Library\Messaging\DTO\ProvisionedNumberResult;
use App\Library\Messaging\DTO\RegistrationStatusQuery;
use App\Library\Messaging\DTO\RegistrationStatusResult;
use App\Library\Messaging\DTO\RegistrationSubmissionResult;
use App\Models\Business;
use App\Models\BusinessMessagingRegistration;

/**
 * Text messaging setup/number/compliance hub — the deterministic,
 * in-memory adapter the test suite exercises, mirroring
 * FakeMessagingAdapter's established pattern exactly. Performs no I/O and
 * holds no credential, and therefore never gates on a funding reservation
 * either (PR #295 Correction Round 1, item 4) — it never spends real
 * money, so preserving this fake's happy path is exactly what "keep the
 * real cost-incurring path disabled while preserving the UI/fake test
 * flow" means; only TelnyxProvisioningAdapter checks funding.
 *
 * Every result is explicitly scripted by the test (or a documented,
 * clearly-labelled default) — there is no "always succeed" shortcut a
 * test can reach without setting one up, so a test that never calls
 * searchNumbers() can never observe a provisioned number either.
 *
 * Bound only inside a test's own setUp() via
 * $this->app->instance(MessagingProvisioningAdapter::class, new FakeProvisioningAdapter()).
 */
class FakeProvisioningAdapter implements MessagingProvisioningAdapter
{
    /** @var list<NumberSearchCriteria> */
    public array $searches = [];

    /** @var list<AvailableNumberCandidate> the next searchNumbers() result */
    public array $nextSearchResults = [];

    /** @var list<array{business: Business, candidate: AvailableNumberCandidate}> */
    public array $provisionedOrders = [];

    /** @var list<MessagingRegistrationSubmission> */
    public array $submittedRegistrations = [];

    /** Scripted refreshRegistrationStatus() answer, keyed by RegistrationStatusQuery::key(). */
    public array $registrationStatuses = [];

    /** Scripted refreshRegistrationStatus() rejection reason, same key. */
    public array $registrationRejectionReasons = [];

    /** @var list<array{messagingProfileId: string, providerCampaignId: string}> every assignMessagingProfileToCampaign() call, in order. */
    public array $campaignAssignments = [];

    /** Scripted assignMessagingProfileToCampaign() outcome, keyed by messagingProfileId; defaults to Requested. */
    public array $campaignAssignmentOutcomes = [];

    /** @var list<NumberReleaseQuery> every releaseNumber() call, in order. */
    public array $releaseAttempts = [];

    /** Scripted releaseNumber() outcome, keyed by providerPhoneNumberId; defaults to Confirmed. */
    public array $releaseOutcomes = [];

    private int $numberCounter = 0;

    private int $profileCounter = 0;

    private int $brandCounter = 0;

    private int $registrationIdCounter = 0;

    public function searchNumbers(NumberSearchCriteria $criteria): array
    {
        $this->searches[] = $criteria;

        return $this->nextSearchResults;
    }

    public function provisionNumber(Business $business, AvailableNumberCandidate $candidate): ProvisionedNumberResult
    {
        $this->provisionedOrders[] = ['business' => $business, 'candidate' => $candidate];

        $this->numberCounter++;
        $this->profileCounter++;

        return new ProvisionedNumberResult(
            messagingProfileId: sprintf('fake_profile_%06d', $this->profileCounter),
            providerPhoneNumberId: sprintf('fake_number_%06d', $this->numberCounter),
            phoneNumber: $candidate->phoneNumber,
        );
    }

    public function submitRegistration(MessagingRegistrationSubmission $submission): RegistrationSubmissionResult
    {
        $this->submittedRegistrations[] = $submission;

        // PR #295 Correction Round 1, item 7 — mirrors the real adapter's
        // honest per-regime modeling: a toll-free submission never
        // produces a brand/campaign pair, and a 10DLC submission never
        // produces a bare registration id.
        if ($submission->numberType === PhoneNumberType::TollFree) {
            $this->registrationIdCounter++;

            return new RegistrationSubmissionResult(
                providerBrandId: null,
                providerCampaignId: null,
                providerRegistrationId: sprintf('fake_tfv_%06d', $this->registrationIdCounter),
                status: MessagingRegistrationStatus::Pending,
            );
        }

        $this->brandCounter++;

        return new RegistrationSubmissionResult(
            providerBrandId: sprintf('fake_brand_%06d', $this->brandCounter),
            providerCampaignId: sprintf('fake_campaign_%06d', $this->brandCounter),
            providerRegistrationId: null,
            status: MessagingRegistrationStatus::Pending,
        );
    }

    public function refreshRegistrationStatus(RegistrationStatusQuery $query): RegistrationStatusResult
    {
        $status = $this->registrationStatuses[$query->key()] ?? MessagingRegistrationStatus::Pending;

        return new RegistrationStatusResult(
            $status,
            $status === MessagingRegistrationStatus::Rejected ? ($this->registrationRejectionReasons[$query->key()] ?? null) : null,
        );
    }

    public function assignMessagingProfileToCampaign(string $messagingProfileId, string $providerCampaignId): CampaignAssignmentResult
    {
        $this->campaignAssignments[] = ['messagingProfileId' => $messagingProfileId, 'providerCampaignId' => $providerCampaignId];

        $outcome = $this->campaignAssignmentOutcomes[$messagingProfileId] ?? CampaignAssignmentOutcome::Requested;

        return new CampaignAssignmentResult(
            $outcome,
            $outcome === CampaignAssignmentOutcome::Requested ? 'fake_task_000001' : 'fake_failed',
        );
    }

    /**
     * Test helper — scripts this exact Messaging Profile's next
     * assignMessagingProfileToCampaign() outcome.
     */
    public function scriptCampaignAssignmentOutcome(string $messagingProfileId, CampaignAssignmentOutcome $outcome): void
    {
        $this->campaignAssignmentOutcomes[$messagingProfileId] = $outcome;
    }

    public function releaseNumber(NumberReleaseQuery $query): CarrierReleaseResult
    {
        $this->releaseAttempts[] = $query;

        $outcome = $this->releaseOutcomes[$query->providerPhoneNumberId] ?? CarrierReleaseOutcome::Confirmed;

        return new CarrierReleaseResult(
            $outcome,
            $outcome === CarrierReleaseOutcome::Confirmed ? 'fake_confirmed' : 'fake_not_confirmed',
        );
    }

    /**
     * Test helper — scripts this exact provider reference's next
     * releaseNumber() outcome. Never a global "always fail" switch: keyed
     * per reference so a test exercising two numbers at once can script
     * each independently.
     */
    public function scriptReleaseOutcome(string $providerPhoneNumberId, CarrierReleaseOutcome $outcome): void
    {
        $this->releaseOutcomes[$providerPhoneNumberId] = $outcome;
    }

    /**
     * Test helper — script the next call's result explicitly, so a test
     * never relies on an implicit "it just works" default.
     */
    public function queueSearchResult(AvailableNumberCandidate $candidate): void
    {
        $this->nextSearchResults[] = $candidate;
    }

    /**
     * Scripts refreshRegistrationStatus()'s answer for this exact
     * registration's current provider references, whichever regime it is.
     * $reason is only ever returned when $status is Rejected — scripting
     * one alongside any other status is a no-op, matching
     * TelnyxProvisioningAdapter's own real behavior (a reason only ever
     * accompanies a rejection).
     */
    public function scriptRegistrationStatus(BusinessMessagingRegistration $registration, MessagingRegistrationStatus $status, ?string $reason = null): void
    {
        $key = RegistrationStatusQuery::fromModel($registration)->key();
        $this->registrationStatuses[$key] = $status;

        if ($reason !== null) {
            $this->registrationRejectionReasons[$key] = $reason;
        }
    }
}

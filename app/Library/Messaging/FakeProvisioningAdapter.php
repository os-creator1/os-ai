<?php

namespace App\Library\Messaging;

use App\Enums\Messaging\MessagingRegistrationStatus;
use App\Library\Messaging\Contracts\MessagingProvisioningAdapter;
use App\Library\Messaging\DTO\AvailableNumberCandidate;
use App\Library\Messaging\DTO\MessagingRegistrationSubmission;
use App\Library\Messaging\DTO\NumberSearchCriteria;
use App\Library\Messaging\DTO\ProvisionedNumberResult;
use App\Library\Messaging\DTO\RegistrationSubmissionResult;
use App\Models\Business;

/**
 * Text messaging setup/number/compliance hub — the deterministic,
 * in-memory adapter the test suite exercises, mirroring
 * FakeMessagingAdapter's established pattern exactly. Performs no I/O and
 * holds no credential.
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

    /** Scripted refreshRegistrationStatus() answer, keyed by "brand:campaign". */
    public array $registrationStatuses = [];

    private int $numberCounter = 0;

    private int $profileCounter = 0;

    private int $brandCounter = 0;

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
        $this->brandCounter++;

        return new RegistrationSubmissionResult(
            providerBrandId: sprintf('fake_brand_%06d', $this->brandCounter),
            providerCampaignId: sprintf('fake_campaign_%06d', $this->brandCounter),
            status: MessagingRegistrationStatus::Pending,
        );
    }

    public function refreshRegistrationStatus(string $providerBrandId, string $providerCampaignId): MessagingRegistrationStatus
    {
        $key = $providerBrandId . ':' . $providerCampaignId;

        return $this->registrationStatuses[$key] ?? MessagingRegistrationStatus::Pending;
    }

    /**
     * Test helper — script the next call's result explicitly, so a test
     * never relies on an implicit "it just works" default.
     */
    public function queueSearchResult(AvailableNumberCandidate $candidate): void
    {
        $this->nextSearchResults[] = $candidate;
    }

    public function scriptRegistrationStatus(string $providerBrandId, string $providerCampaignId, MessagingRegistrationStatus $status): void
    {
        $this->registrationStatuses[$providerBrandId . ':' . $providerCampaignId] = $status;
    }
}

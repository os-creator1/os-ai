<?php

namespace App\Library\Messaging\Contracts;

use App\Enums\Messaging\MessagingRegistrationStatus;
use App\Library\Messaging\DTO\AvailableNumberCandidate;
use App\Library\Messaging\DTO\MessagingRegistrationSubmission;
use App\Library\Messaging\DTO\NumberSearchCriteria;
use App\Library\Messaging\DTO\ProvisionedNumberResult;
use App\Library\Messaging\DTO\RegistrationSubmissionResult;
use App\Models\Business;

/**
 * Text messaging setup/number/compliance hub — the provider-neutral
 * number-search/order and carrier-registration boundary
 * MessagingProviderAdapter's own docblock names as "Slice 4's own
 * interface extension". Kept as a SEPARATE interface, never folded into
 * MessagingProviderAdapter: sending a message and provisioning/
 * registering a number are different capabilities with different
 * lifecycles, and a future adapter could plausibly implement one without
 * the other.
 *
 * Exactly four methods, each provider-neutral: no Telnyx-specific
 * parameter (rate center, TCR vetting tier, ...) crosses this boundary —
 * only what STATE 1/2 of the customer-facing hub actually need.
 *
 * "Never fake a successful purchase" (the product requirement) is a
 * structural guarantee of this interface's shape, not a runtime check:
 * provisionNumber() is the ONLY method that can produce a
 * ProvisionedNumberResult, and a caller can reach it only by first
 * obtaining a real AvailableNumberCandidate from searchNumbers() — there
 * is no shortcut that manufactures a result without a real (or
 * explicitly-scripted-in-a-test) round trip through an implementation of
 * this interface.
 */
interface MessagingProvisioningAdapter
{
    /**
     * @return list<AvailableNumberCandidate>
     */
    public function searchNumbers(NumberSearchCriteria $criteria): array;

    /**
     * Places the real order and provisions this Business's own dedicated
     * Messaging Profile if it does not already have one. Only ever called
     * with a candidate this same adapter just returned from
     * searchNumbers() in the same request.
     */
    public function provisionNumber(Business $business, AvailableNumberCandidate $candidate): ProvisionedNumberResult;

    /**
     * Submits the Business's already-captured, already-validated
     * compliance data (10DLC brand+campaign, or toll-free verification,
     * per $submission->numberType) and returns the provider's initial
     * acknowledgement — realistically always Pending; see
     * RegistrationSubmissionResult's own docblock.
     */
    public function submitRegistration(MessagingRegistrationSubmission $submission): RegistrationSubmissionResult;

    /**
     * Polls the provider for this registration's current state. Never
     * guesses Approved without an explicit provider answer saying so.
     */
    public function refreshRegistrationStatus(string $providerBrandId, string $providerCampaignId): MessagingRegistrationStatus;
}

<?php

namespace App\Library\Messaging\Contracts;

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
 * Six methods, each provider-neutral: no Telnyx-specific parameter (rate
 * center, TCR vetting tier, ...) crosses this boundary — only what
 * STATE 1/2 of the customer-facing hub and the Phone Numbers + A2P lane's
 * own carrier-release boundary actually need.
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
     *
     * PR #295 Correction Round 1, item 7 — takes the whole, type-aware
     * $query rather than a bare (brand, campaign) pair: a toll-free
     * verification has no brand/campaign concept at all, and this is the
     * seam that lets an implementation route 10DLC and toll-free to their
     * own real status endpoints instead of one shared (and, for toll-free,
     * fictitious) "/campaign/{id}" call.
     *
     * Review correction — returns the whole RegistrationStatusResult, not
     * a bare status: a Rejected answer must carry whatever specific,
     * carrier-supplied reason the provider's own response included, so
     * the customer sees a real reason when one exists rather than always
     * the generic fallback copy.
     */
    public function refreshRegistrationStatus(RegistrationStatusQuery $query): RegistrationStatusResult;

    /**
     * Phone Numbers + A2P lane — the final step of the 10DLC sequence this
     * platform now supports completing before a number exists: once a
     * Business's brand+campaign is Approved, and a local number has since
     * been purchased under this call's own, freshly created Messaging
     * Profile, that profile must be linked to the approved campaign before
     * the number is genuinely enabled for 10DLC-compliant traffic. Never
     * called for a toll-free number — toll-free verification is always
     * submitted against an already-owned, already-assigned number, so this
     * step does not apply to it.
     *
     * Requested (not Confirmed): Telnyx's own assignment endpoint responds
     * with a background task id, never an immediate confirmation that the
     * link is live — see CampaignAssignmentOutcome's own docblock. A
     * caller must never treat Requested as proof the number can already
     * send 10DLC traffic.
     */
    public function assignMessagingProfileToCampaign(string $messagingProfileId, string $providerCampaignId): CampaignAssignmentResult;

    /**
     * Phone Numbers + A2P lane — the carrier-release boundary. Attempts to
     * remove the number from the provider account and returns whether the
     * carrier itself confirmed that. Never throws to signal an ordinary
     * "not confirmed" outcome (a non-2xx response) — only a genuine
     * transport-level failure a caller cannot otherwise observe may
     * propagate as an exception; NumberLifecycleManager::
     * confirmCarrierRelease() treats either the same way (NotConfirmed,
     * never released).
     *
     * Only ever called with a number this platform's own records show as
     * Suspended, already decided for release, and free of any active
     * port-out request — this method itself has no opinion on local
     * eligibility and performs no local write; it is purely the provider
     * round trip.
     */
    public function releaseNumber(NumberReleaseQuery $query): CarrierReleaseResult;
}

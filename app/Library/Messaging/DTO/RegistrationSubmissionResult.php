<?php

namespace App\Library\Messaging\DTO;

use App\Enums\Messaging\MessagingRegistrationStatus;

/**
 * Text messaging setup/number/compliance hub — the provider's
 * acknowledgement of a registration submission. Approval is never
 * immediate in reality (10DLC campaign review is typically 3-7 business
 * days; a Sole Proprietor brand needs a manual OTP-PIN loop first), so a
 * fresh submission's status is realistically always Pending — Approved/
 * Rejected are reached later through refreshRegistrationStatus().
 *
 * PR #295 Correction Round 1, item 7 — modeled honestly per registration
 * regime instead of always populating both opaque fields: a 10DLC
 * (local-number) submission carries providerBrandId/providerCampaignId and
 * a null providerRegistrationId; a toll-free verification submission
 * carries only providerRegistrationId, with both 10DLC fields null. No
 * caller may reuse one regime's identifier as if it belonged to the other.
 */
final readonly class RegistrationSubmissionResult
{
    public function __construct(
        public ?string $providerBrandId,
        public ?string $providerCampaignId,
        public ?string $providerRegistrationId,
        public MessagingRegistrationStatus $status,
    ) {
    }
}

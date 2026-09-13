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
 */
final readonly class RegistrationSubmissionResult
{
    public function __construct(
        public string $providerBrandId,
        public string $providerCampaignId,
        public MessagingRegistrationStatus $status,
    ) {
    }
}

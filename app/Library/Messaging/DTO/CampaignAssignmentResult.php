<?php

namespace App\Library\Messaging\DTO;

use App\Enums\Messaging\CampaignAssignmentOutcome;

/**
 * Phone Numbers + A2P lane — MessagingProvisioningAdapter::
 * assignMessagingProfileToCampaign()'s result. $detail is a short,
 * non-sensitive label for the audit trail — never a raw provider
 * response body or a credential.
 */
final readonly class CampaignAssignmentResult
{
    public function __construct(
        public CampaignAssignmentOutcome $outcome,
        public ?string $detail = null,
    ) {
    }
}

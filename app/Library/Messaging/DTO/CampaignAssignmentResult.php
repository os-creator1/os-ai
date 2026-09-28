<?php

namespace App\Library\Messaging\DTO;

use App\Enums\Messaging\CampaignAssignmentOutcome;

/**
 * Phone Numbers + A2P lane — the result of both
 * MessagingProvisioningAdapter::assignMessagingProfileToCampaign() (the
 * initial request) and ::checkCampaignAssignmentStatus() (the poll).
 *
 * $taskId is Telnyx's own background task identifier — present on a
 * successful initial request (never on a poll's own result, which
 * already has it from the caller) so BusinessMessagingProvisioningService
 * can persist it for later polling. $detail is a short, non-sensitive
 * label for the audit trail — never a raw provider response body or a
 * credential.
 */
final readonly class CampaignAssignmentResult
{
    public function __construct(
        public CampaignAssignmentOutcome $outcome,
        public ?string $detail = null,
        public ?string $taskId = null,
    ) {
    }
}

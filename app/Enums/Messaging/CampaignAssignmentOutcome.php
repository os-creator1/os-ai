<?php

namespace App\Enums\Messaging;

/**
 * Phone Numbers + A2P lane — MessagingProvisioningAdapter::
 * assignMessagingProfileToCampaign()'s result. Telnyx's own "Assign
 * Messaging Profile To Campaign" endpoint (POST
 * /10dlc/phoneNumberAssignmentByProfile) responds with a background
 * taskId, not an immediate, synchronous confirmation that the assignment
 * completed — so Requested here means "the carrier accepted the request
 * for processing," never "the number is now confirmed live on the
 * campaign." This platform does not yet poll that task to completion
 * (see the adapter's own docblock); Requested is deliberately the
 * strongest honest claim available today.
 */
enum CampaignAssignmentOutcome: string
{
    case Requested = 'requested';
    case Failed = 'failed';
}

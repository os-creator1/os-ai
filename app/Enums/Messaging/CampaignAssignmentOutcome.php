<?php

namespace App\Enums\Messaging;

/**
 * Phone Numbers + A2P lane — the persisted, pollable state of a local
 * number's own Messaging-Profile-to-campaign assignment, and the result
 * both MessagingProvisioningAdapter::assignMessagingProfileToCampaign()
 * (the initial request) and ::checkCampaignAssignmentStatus() (the poll)
 * return.
 *
 * Review correction — Telnyx's own "Assign Messaging Profile To Campaign"
 * endpoint (POST /10dlc/phoneNumberAssignmentByProfile) responds with a
 * background taskId, never an immediate, synchronous confirmation that
 * the assignment completed. Requested means only "the carrier accepted
 * the request for processing" — it must never be treated as, compared
 * to, or displayed as Confirmed anywhere in this codebase. Confirmed is
 * reachable ONLY via checkCampaignAssignmentStatus() actually polling
 * Telnyx's own confirmed "Get Phone Number Status" endpoint (GET
 * /10dlc/phoneNumberAssignmentByProfile/{taskId}/phoneNumbers) and
 * finding this exact number's own record reporting "completed". A
 * request that is still processing on a poll is reported as Requested
 * again (poll again later) rather than inventing a fourth "Pending"
 * state — the persisted column only ever needs to distinguish "not yet
 * known", Confirmed, and Failed.
 */
enum CampaignAssignmentOutcome: string
{
    case Requested = 'requested';
    case Confirmed = 'confirmed';
    case Failed = 'failed';
}

<?php

namespace App\Library\Messaging\Exceptions;

use RuntimeException;

/**
 * Phone Numbers + A2P lane — review correction: thrown by
 * ManagedMessageDispatcher::dispatch() when the Business's resolved
 * primary number is local (10DLC) and its own campaign-assignment
 * confirmation
 * (BusinessMessagingNumber::isCampaignAssignmentConfirmedOrNotRequired())
 * has not yet been confirmed by the carrier — whether the request was
 * merely Requested, or Failed outright. Telnyx's own assignment endpoint
 * returns a background task id, never an immediate confirmation, so a
 * number must never be able to send 10DLC traffic on the strength of a
 * request alone. Thrown with zero provider calls, zero operation rows,
 * and zero usage measurement — exactly like MessagingIdentityConflictException's
 * own "fails closed before any side effect" discipline.
 */
class MessagingCampaignAssignmentNotConfirmedException extends RuntimeException
{
}

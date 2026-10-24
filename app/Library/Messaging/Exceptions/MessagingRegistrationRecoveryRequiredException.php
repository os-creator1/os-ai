<?php

namespace App\Library\Messaging\Exceptions;

use RuntimeException;

/**
 * Thrown when the outcome of an earlier (or the current) Telnyx 10DLC
 * brand/campaign call cannot be confirmed — a timeout, a 5xx, or a 2xx
 * with no identifier. Automatic retry is blocked until an operator
 * resolves the recorded provisioning incident, so a brand or campaign
 * that may already exist at the provider is never created a second time.
 */
class MessagingRegistrationRecoveryRequiredException extends RuntimeException
{
}

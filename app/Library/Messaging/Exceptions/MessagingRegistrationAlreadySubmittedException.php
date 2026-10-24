<?php

namespace App\Library\Messaging\Exceptions;

use RuntimeException;

/**
 * Thrown when a registration submission is refused because the
 * registration is already Pending/Approved or another submission for it is
 * in flight — a retry must never submit or charge a second time.
 */
class MessagingRegistrationAlreadySubmittedException extends RuntimeException
{
}

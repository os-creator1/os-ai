<?php

namespace App\Library\Messaging\Exceptions;

use RuntimeException;

/**
 * The next registration stage cannot be started yet: the business is still
 * being verified by the carrier, or the customer has not authorized the
 * campaign and its fees. Raised before any wallet reservation or provider
 * call; the message is safe to show the customer.
 */
class MessagingRegistrationNotReadyException extends RuntimeException
{
    public const REASON_VERIFICATION_PENDING = 'verification_pending';

    public const REASON_NEEDS_AUTHORIZATION = 'needs_authorization';

    public function __construct(string $message, public readonly string $reason = self::REASON_VERIFICATION_PENDING)
    {
        parent::__construct($message);
    }
}

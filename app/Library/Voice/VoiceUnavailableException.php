<?php

namespace App\Library\Voice;

use RuntimeException;

/** Calling cannot proceed; `reason` is a stable code, the message is safe to show a customer. */
final class VoiceUnavailableException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $httpStatus = 422)
    {
        parent::__construct($message);
    }
}

<?php

namespace App\Library\Voice;

/** A customer call was refused before any Telnyx request. `reason` is a stable code for the UI; never holds a number. */
final class CustomerCallRefusedException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $httpStatus = 422)
    {
        parent::__construct($message);
    }
}

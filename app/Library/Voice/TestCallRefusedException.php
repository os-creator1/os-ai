<?php

namespace App\Library\Voice;

/** A test call was refused BEFORE any outbound request was made. `reason` is a stable code; the message never holds a number. */
final class TestCallRefusedException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}

<?php

namespace App\Library\Voice;

/** Browser calling cannot be offered right now. `reason` is a stable code; the message never holds a credential. */
final class BrowserCallsUnavailableException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}

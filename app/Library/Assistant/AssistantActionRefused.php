<?php

namespace App\Library\Assistant;

use RuntimeException;

/** The action cannot (or can no longer) be done safely. `getMessage()` is customer-safe; `$code` is for the audit trail. */
final class AssistantActionRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, string $customerMessage)
    {
        parent::__construct($customerMessage);
    }
}

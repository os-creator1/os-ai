<?php

namespace App\Library\Messaging;

use RuntimeException;

/** A message whose cost cannot be settled without guessing: it becomes a visible incident instead. */
final class ReconciliationIncident extends RuntimeException
{
    public function __construct(public readonly string $incidentCode, string $message)
    {
        parent::__construct($message);
    }
}

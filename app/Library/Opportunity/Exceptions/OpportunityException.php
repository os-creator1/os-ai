<?php

declare(strict_types=1);

namespace App\Library\Opportunity\Exceptions;

use RuntimeException;

/**
 * Shared base for every Opportunity Engine exception (RFC-002), so future
 * callers (the Milestone 2B/2C run protocol) can catch one common ancestor
 * without swallowing unrelated application errors.
 */
abstract class OpportunityException extends RuntimeException
{
}

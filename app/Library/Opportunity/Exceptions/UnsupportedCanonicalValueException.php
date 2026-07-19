<?php

declare(strict_types=1);

namespace App\Library\Opportunity\Exceptions;

/**
 * Thrown by CanonicalJson when a value cannot be canonicalized: a non-finite
 * float (NAN/INF/-INF), a resource, a closure, an arbitrary PHP object, or
 * any other type outside RFC-002 §26's supported set.
 */
class UnsupportedCanonicalValueException extends OpportunityException
{
}

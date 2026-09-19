<?php

declare(strict_types=1);

namespace App\Library\Support\Exceptions;

use RuntimeException;

/**
 * Thrown by App\Library\Support\CanonicalJson when a value cannot be
 * canonicalized: a non-finite float (NAN/INF/-INF), a resource, a closure, an
 * arbitrary PHP object, or any other type outside the supported set.
 *
 * Domain-neutral counterpart of the Opportunity domain's exception of the same
 * name, which the Opportunity adapter translates this into so Opportunity
 * callers still see an OpportunityException.
 */
class UnsupportedCanonicalValueException extends RuntimeException
{
}

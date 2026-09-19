<?php

declare(strict_types=1);

namespace App\Library\Support\Exceptions;

use RuntimeException;

/**
 * Thrown by App\Library\Support\CanonicalJson when the intl extension's
 * Normalizer class is not available in the current runtime. NFC string
 * normalization is required; this fails loudly rather than silently skipping
 * it or falling back to an unnormalized/approximate algorithm.
 *
 * Domain-neutral counterpart of the Opportunity domain's exception of the same
 * name, which the Opportunity adapter translates this into.
 */
class CanonicalJsonUnavailableException extends RuntimeException
{
}

<?php

declare(strict_types=1);

namespace App\Library\Opportunity\Exceptions;

/**
 * Thrown by CanonicalJson when the intl extension's Normalizer class is not
 * available in the current runtime. RFC-002 §26 requires NFC string
 * normalization; this fails loudly rather than silently skipping it or
 * falling back to an unnormalized/approximate algorithm.
 */
class CanonicalJsonUnavailableException extends OpportunityException
{
}

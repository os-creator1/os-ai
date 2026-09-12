<?php

namespace App\Exceptions\Entitlement;

use RuntimeException;

/**
 * Customer Experience Slice 1A (contract §7.3a rule 9) — a Business always
 * keeps at least one active location.
 *
 * Carries only numeric identifiers — never a name, address or contact detail.
 */
class LastActiveLocationCannotBeArchivedException extends RuntimeException
{
    public function __construct(public readonly int $businessId)
    {
        parent::__construct("Business [{$businessId}] must keep at least one active location.");
    }
}

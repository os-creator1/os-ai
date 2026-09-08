<?php

namespace App\Exceptions\Entitlement;

use RuntimeException;

/**
 * Customer Experience Slice 1A (contract §7.3/§7.3a).
 * Thrown when archiving would leave a Business with zero active physical locations (§7.3a rule 9).
 *
 * Carries only numeric identifiers — never Customer, User or Business
 * names, company, email, phone, or address.
 */
class LastActiveLocationCannotBeArchivedException extends RuntimeException
{
    public function __construct(public readonly int $businessId)
    {
        parent::__construct("Business [{\$businessId}] cannot archive its last active physical location.");
    }
}

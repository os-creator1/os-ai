<?php

namespace App\Exceptions\Entitlement;

use RuntimeException;

/**
 * Customer Experience Slice 1A (contract §7.3/§7.3a).
 * Thrown when archiving would remove the primary physical location without first reassigning primary status to another active location (§7.3a rule 9).
 *
 * Carries only numeric identifiers — never Customer, User or Business
 * names, company, email, phone, or address.
 */
class PrimaryLocationCannotBeArchivedException extends RuntimeException
{
    public function __construct(public readonly int $businessId, public readonly int $locationId)
    {
        parent::__construct("Location [{\$locationId}] is the primary location of Business [{\$businessId}] and cannot be archived while primary.");
    }
}

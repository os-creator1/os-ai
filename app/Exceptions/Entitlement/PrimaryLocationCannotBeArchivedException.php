<?php

namespace App\Exceptions\Entitlement;

use RuntimeException;

/**
 * Customer Experience Slice 1A (contract §7.3a rule 9) — the primary location
 * can only be archived together with naming another ACTIVE location of the
 * same Business as the new primary, in one transaction.
 *
 * Carries only numeric identifiers — never a name, address or contact detail.
 */
class PrimaryLocationCannotBeArchivedException extends RuntimeException
{
    public function __construct(public readonly int $locationId)
    {
        parent::__construct("Location [{$locationId}] is the primary location; choose another active location as primary to archive it.");
    }
}

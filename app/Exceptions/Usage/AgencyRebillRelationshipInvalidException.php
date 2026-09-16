<?php

namespace App\Exceptions\Usage;

use RuntimeException;

/**
 * Implementation Contract 09 §5.3 — thrown by EffectivePayerResolver when a
 * Business's payer is AgencyRebill but its recorded managing-Agency
 * relationship does not currently prove who pays: the id is missing, the row
 * is gone, it is no longer Active, it does not target this Business's own
 * Workspace, or it names the Client Workspace as its own Agency.
 *
 * Fail closed, never a fallback: the caller must refuse the paid effect
 * rather than charge anyone else — above all never the client itself.
 *
 * Carries only the numeric Business identifier.
 */
class AgencyRebillRelationshipInvalidException extends RuntimeException
{
    public function __construct(public readonly int $businessId)
    {
        parent::__construct("Business [{$businessId}]'s AgencyRebill payer does not resolve through an Active managing-Agency relationship.");
    }
}

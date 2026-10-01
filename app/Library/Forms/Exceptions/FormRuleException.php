<?php

namespace App\Library\Forms\Exceptions;

use DomainException;

/**
 * A Forms change the rules refuse (a field list out of bounds, a duplicate key,
 * two phone fields, an Opportunity configured without a phone field, a form or
 * Location that does not belong to the Business it was addressed through, ...).
 * Mirrors `CatalogRuleException` / `CrmRuleException` exactly.
 *
 * The message is written for the customer: controllers show it as-is.
 */
class FormRuleException extends DomainException
{
}

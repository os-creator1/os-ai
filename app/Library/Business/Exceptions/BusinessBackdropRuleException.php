<?php

namespace App\Library\Business\Exceptions;

use DomainException;

/**
 * A Backdrop change the rules refuse (a name out of bounds, a backdrop
 * that doesn't belong to the Business it was addressed through, ...).
 * Mirrors `App\Library\Catalog\Exceptions\CatalogRuleException` exactly.
 *
 * The message is written for the customer: controllers show it as-is.
 */
class BusinessBackdropRuleException extends DomainException
{
}

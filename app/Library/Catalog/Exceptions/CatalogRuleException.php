<?php

namespace App\Library\Catalog\Exceptions;

use DomainException;

/**
 * A Packages & Products catalog change the rules refuse (an invalid type,
 * a name/description out of bounds, a price without a currency or vice
 * versa, a reorder that doesn't name every active item exactly once, a
 * catalog item that doesn't belong to the Business it was addressed
 * through, ...). Mirrors `App\Library\Crm\Exceptions\CrmRuleException`
 * exactly.
 *
 * The message is written for the customer: controllers show it as-is.
 */
class CatalogRuleException extends DomainException
{
}

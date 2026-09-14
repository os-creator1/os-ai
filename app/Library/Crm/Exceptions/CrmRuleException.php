<?php

namespace App\Library\Crm\Exceptions;

use DomainException;

/**
 * A CRM change the rules refuse (archiving New inquiry, moving a closed deal,
 * a stage from another pipeline, ...).
 *
 * The message is written for the customer: controllers show it as-is.
 */
class CrmRuleException extends DomainException
{
}

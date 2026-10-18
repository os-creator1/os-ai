<?php

namespace App\Library\CustomFields;

use DomainException;

/**
 * A Custom Field change the rules refuse. The message is written for the
 * customer: controllers show it as-is.
 */
class CustomFieldRuleException extends DomainException
{
}

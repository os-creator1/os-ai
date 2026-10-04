<?php

namespace App\Library\Crm\Exceptions;

/**
 * A stage move that was asked for from a stage the deal is no longer in — another
 * tab, another person or an automation moved it first. The board uses `$currentStageId`
 * to put the card where the server says it is, without reloading anything.
 */
class CrmStageConflictException extends CrmRuleException
{
    public function __construct(public readonly int $currentStageId)
    {
        parent::__construct('This opportunity was moved somewhere else in the meantime.');
    }
}

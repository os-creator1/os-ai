<?php

declare(strict_types=1);

namespace App\Library\Opportunity\Exceptions;

/**
 * Implementation Contract 19 §5.4(4) — a failed MUTATING or PAID-EFFECT
 * action may not be retried under its original approval; it must be
 * approved again.
 *
 * Extends OpportunityExecutionRetryNotAvailableException so the existing
 * customer-facing mapping for "retry is not available" keeps working
 * unchanged, while the distinct type makes "refused because it needs
 * re-approval" separately assertable from "refused because there is nothing
 * to retry".
 */
class OpportunityRetryRequiresReapprovalException extends OpportunityExecutionRetryNotAvailableException
{
    public function __construct(private readonly string $actionKey = '')
    {
        parent::__construct();
    }

    public function actionKey(): string
    {
        return $this->actionKey;
    }
}

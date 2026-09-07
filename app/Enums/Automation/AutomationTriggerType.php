<?php

namespace App\Enums\Automation;

/**
 * B4 Business Automations — the closed, code-backed set of v1 triggers
 * (contract §6). Trigger identity is always one of these enum values,
 * never a class name, callable, or user-supplied string resolved at
 * runtime.
 */
enum AutomationTriggerType: string
{
    /**
     * A Business-scoped Contact date custom field (any date field — never
     * only "birthday") reaches its offset-adjusted occurrence in the
     * Business timezone. Evaluated by the five-minute scheduler sweep.
     */
    case ContactDateReached = 'contact_date_reached';

    /**
     * A Contact with an explicit business_id is successfully committed via
     * one of the two in-scope CRM repository creation seams. Enters
     * through the after-commit hook, never the sweep.
     */
    case ContactCreated = 'contact_created';

    public function label(): string
    {
        return match ($this) {
            self::ContactDateReached => 'Contact date reached',
            self::ContactCreated => 'Contact created',
        };
    }
}

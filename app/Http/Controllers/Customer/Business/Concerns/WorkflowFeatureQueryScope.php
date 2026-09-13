<?php

namespace App\Http\Controllers\Customer\Business\Concerns;

/**
 * Automations V2 §18 — the explicit boundary between SHARED platform SQL and
 * V2-E FEATURE-OWNED SQL on one request.
 *
 * WHY A BOUNDARY AND NOT A LIST OF TABLES. §18 now budgets V2-E's own queries
 * separately from the shared request overhead (context, Account/Business
 * authorization, the entitlement snapshot, app_config, and the customer shell).
 * Classifying SQL by matching its text against table names would silently
 * misfile new feature SQL the moment a workflow endpoint touched a table that
 * shared code also reads, and a budget you can pass by picking the right regex
 * is not a budget. So ownership is decided by WHEN a statement runs, not by
 * what it says.
 *
 * THE RULE, AS PRODUCTION CODE MARKS IT:
 *
 *   before  ResolvesAutomationWorkflows::resolveEntitledBusiness() completes —
 *           middleware, the Gate, and the canonical tenancy and entitlement
 *           chain — everything is SHARED;
 *   between that point and the moment the controller action returns, every
 *           statement is V2-E FEATURE-OWNED;
 *   after   the action returns — rendering the customer layout, its theme,
 *           languages and notifications — everything is SHARED again.
 *
 * IT FAILS SAFE, IN ONE DIRECTION ONLY. Any SQL added to a workflow action is
 * inside the window and therefore charged to the feature, including a shared
 * service a future action happens to call. Nothing inside the window can ever
 * be reclassified as shared, so the feature budget can only be overstated,
 * never hidden.
 *
 * It holds no state beyond one attribute on the current request, costs no
 * query, and changes no behaviour — it is read only by the §18 tests.
 */
final class WorkflowFeatureQueryScope
{
    public const REQUEST_ATTRIBUTE = 'automations.v2e.feature_query_scope';

    /** Tenancy is done; what follows belongs to the workflow feature. */
    public static function begin(): void
    {
        request()->attributes->set(self::REQUEST_ATTRIBUTE, true);
    }

    /** The action has returned; anything after it is shared again. */
    public static function end(): void
    {
        request()->attributes->remove(self::REQUEST_ATTRIBUTE);
    }

    public static function isActive(): bool
    {
        return request()->attributes->get(self::REQUEST_ATTRIBUTE) === true;
    }
}

<?php

namespace App\Enums\Coo;

/**
 * Implementation Contract 19 §5.6 — the AI COO's three authorization
 * contexts. One component, three contexts, never three implementations.
 *
 * Only `Business` may be written by sub-slice 19.A. `Agency` and `Platform`
 * exist here so the schema, the fingerprint document (§5.8) and the writer
 * invariants (§8) are shaped correctly from the start; the code that composes
 * their facts and writes their rows is 19.H's, and 19.A's writer guard refuses
 * them (see App\Models\CooInsight::assertScopeInvariants()).
 *
 * The tenancy shape of each scope is contract §8's table and is enforced, not
 * assumed:
 *
 *   business  workspace_id NOT NULL, business_id NOT NULL
 *   agency    workspace_id NOT NULL (Agency Workspace), business_id NOT NULL
 *             (that Workspace's own sole canonical Business — Workspace:Business
 *             is 1:1 under Contract 13, so Agency scope needs no nullable
 *             Business and no second AI budget authority)
 *   platform  workspace_id NULL, business_id NULL — never a fabricated tenant
 *             (§5.5 R-19)
 */
enum CooScope: string
{
    case Business = 'business';

    case Agency = 'agency';

    case Platform = 'platform';

    /**
     * True when a row of this scope must carry a real Workspace and a real
     * Business. False only for `Platform`, which has no tenant at all.
     */
    public function requiresTenancy(): bool
    {
        return $this !== self::Platform;
    }

    /** The only scope any code merged before Contract 19 sub-slice 19.H may write. */
    public function isWritableInThisSlice(): bool
    {
        return $this === self::Business;
    }
}

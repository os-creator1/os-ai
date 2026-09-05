<?php

namespace App\Library\Business;

use App\Models\Business;

/**
 * Business Data Tenancy Foundation, Pass 1 — the one legacy compatibility
 * resolver every non-Workspace-aware caller (backfill, dual-write, legacy
 * controllers, API, admin) uses to map a legacy owner/customer user id to
 * a single deterministic Business.
 *
 * Deliberately conservative: it never guesses. A future Workspace-aware
 * caller that already knows exactly which Business it is acting on (e.g.
 * a Business-addressable Outreach route) passes that Business explicitly
 * and never calls this resolver at all.
 */
class LegacyBusinessResolver
{
    /**
     * @param int $customerUserId the legacy owning customer/user id
     *   (users.id — the same value historically stored in user_id/
     *   customer_id columns across these legacy tables)
     */
    public function resolveForCustomer(int $customerUserId): ?Business
    {
        $businesses = Business::where('customer_id', $customerUserId)->get();

        if ($businesses->isEmpty()) {
            return null;
        }

        if ($businesses->count() === 1) {
            return $businesses->first();
        }

        $primaries = $businesses->where('is_primary', true);

        if ($primaries->count() === 1) {
            return $primaries->first();
        }

        // Multiple Businesses with zero, or more than one, primary row —
        // an ambiguous or malformed state. Never pick "first", never
        // guess by lowest id, never silently repair the data.
        return null;
    }
}

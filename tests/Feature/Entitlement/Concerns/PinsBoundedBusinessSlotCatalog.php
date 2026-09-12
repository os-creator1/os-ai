<?php

namespace Tests\Feature\Entitlement\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Customer Experience Slice 1A (RFC-004 §33.2) corrected the SEEDED Core and
 * Growth rows to exactly one Business (business_slot_included = 1,
 * business_slot_max = 1): neither offers an additional Business slot, so
 * EntitlementManager refuses to allocate or price one. Tests that prove the
 * generic additional-Business-slot ENGINE — allocation, pricing invariants,
 * audit rows, payment-verified idempotency, plan-change direction rules —
 * pin the M1 bounded values (3 included / 5 max: two additional slots)
 * inside their own test transaction. They prove the arithmetic, not the
 * product rule; tests/Feature/Business/BusinessAccountCapacityTest asserts
 * the product rule against the real corrected rows.
 */
trait PinsBoundedBusinessSlotCatalog
{
    /**
     * @param  array<int, string>  $tiers
     */
    protected function pinBoundedBusinessSlotCatalog(array $tiers = ['core', 'growth']): void
    {
        DB::table('workspace_plan_catalog')->whereIn('tier', $tiers)->update([
            'business_slot_included' => 3,
            'business_slot_max' => 5,
        ]);
    }
}

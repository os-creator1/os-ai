<?php

use App\Enums\Workspace\LocationAccessScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Implementation Contract 02 (Location ACL Foundation) §8, Migration B —
 * query-builder-only (no Eloquent dependency), mirroring the M2 payer
 * backfill's own convention. Sets `location_access_scope = 'all'` for
 * EVERY existing WorkspaceMembership row, active OR inactive — no
 * `is_active` filter.
 *
 * Defaulting to `All`, never `Selected`, is deliberate: before this slice,
 * a Business-scoped staff member already implicitly saw every Location of
 * every Business they were granted. Defaulting the new axis to `All`
 * preserves that existing effective reach unchanged until Contract 08B's
 * consumer wiring narrows anyone deliberately. Defaulting to `Selected`
 * with zero grant rows would silently REVOKE everyone's access the moment
 * enforcement lands — exactly the "never silently widen or narrow"
 * instruction this backfill exists to satisfy.
 *
 * Covering inactive rows is not optional: an inactive row left NULL would
 * make the next migration's NOT NULL constraint fail outright, and
 * reactivating an old inactive membership later (WorkspaceMembershipRepository
 * ::setActive(), confirmed by full signature read to touch only
 * `is_active`, never this column) must land the member back with the
 * SAME deterministic value it already had — which only holds if every row
 * already has one before setActive() can ever run.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('workspace_memberships')
            ->whereNull('location_access_scope')
            ->update(['location_access_scope' => LocationAccessScope::All->value]);
    }

    public function down(): void
    {
        DB::table('workspace_memberships')
            ->update(['location_access_scope' => null]);
    }
};

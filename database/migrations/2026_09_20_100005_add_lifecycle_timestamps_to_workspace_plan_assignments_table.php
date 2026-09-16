<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 03 §5/§8 (Slice 4) — the account lifecycle's three
 * nullable timestamps, on the canonical assignment row rather than a second
 * table or a second authority (Addendum §7).
 *
 *  - `trial_ends_at`    an OUTSTANDING trial's expiry, never a historical
 *                       marker: `NULL` means "not currently trialing", and
 *                       EntitlementManager::recoverAccess() clears it on every
 *                       confirmed conversion so an already-paying Workspace can
 *                       never satisfy the trial-expiry sweep again (§5 point 4).
 *  - `grace_started_at` when the 3-day Grace window opened — from a renewal
 *                       failure OR a trial that ended without conversion; both
 *                       funnel through this one column (§5).
 *  - `locked_at`        when Grace elapsed without payment.
 *
 * Additive and nullable, so every existing row reads exactly as it does today
 * (`NULL`/`NULL`/`NULL` resolves to "whatever `status` alone already implies")
 * and no backfill is required (§8).
 *
 * The two composite indexes serve the scheduled sweeps' own predicates (§7):
 * `(status, trial_ends_at)` for Sweep 1 and `(status, grace_started_at)` for
 * Sweep 2 — the columns each sweep filters on, in the order it filters them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_plan_assignments', function (Blueprint $table): void {
            $table->timestamp('trial_ends_at')->nullable()->after('additional_business_slots');
            $table->timestamp('grace_started_at')->nullable()->after('trial_ends_at');
            $table->timestamp('locked_at')->nullable()->after('grace_started_at');

            $table->index(['status', 'trial_ends_at'], 'wpa_status_trial_ends_at_index');
            $table->index(['status', 'grace_started_at'], 'wpa_status_grace_started_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_plan_assignments', function (Blueprint $table): void {
            $table->dropIndex('wpa_status_trial_ends_at_index');
            $table->dropIndex('wpa_status_grace_started_at_index');
            $table->dropColumn(['trial_ends_at', 'grace_started_at', 'locked_at']);
        });
    }
};

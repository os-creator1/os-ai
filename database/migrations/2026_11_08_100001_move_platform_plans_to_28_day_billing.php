<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform plans bill every EXACTLY 28 days, and nothing is free by default.
 *
 * 1. `provider_product_id` — the Stripe Product the plan's Prices hang off, so
 *    negotiated Prices created by MotionGrove group under the plan instead of
 *    each spawning an orphan product.
 *
 * 2. `legacy_provider_price_id` — where a pre-existing Price id is kept for
 *    the record when its row is moved to the 28-day cycle (below).
 *
 * 3. `pre_28_day_snapshot` — each plan row's commercial state exactly as it
 *    was BEFORE this migration (cycle, Price, signup availability, trial), so
 *    `down()` can put the legacy catalog back instead of guessing.
 *
 * 4. DATA: the three plan rows.
 *
 *    - `trial_enabled` is switched OFF and `trial_days` cleared: a new
 *      customer pays immediately. An owner can still switch a trial on
 *      deliberately; it is just no longer the default.
 *
 *    - A `monthly` row becomes `every_28_days`. A calendar month is 28 to 31
 *      days, so a monthly Stripe Price is NOT a 28-day cadence, and a plan
 *      advertised as "Every 28 days" must never be sold on one. A row that
 *      already pointed at a (monthly) Price therefore LOSES that pointer and
 *      comes off sale until the owner creates the 28-day Price (one click on
 *      Billing & Revenue), failing closed instead of selling the wrong
 *      cadence. `yearly` rows are left exactly as they are.
 *
 *    EXISTING SUBSCRIPTIONS ARE NOT TOUCHED. Every platform subscription holds
 *    its own price, currency, cycle, trial and Stripe Price snapshot; none of
 *    those columns is written here, so existing active subscribers keep their
 *    current commercial terms until something explicitly changes them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_plan_catalog', function (Blueprint $table): void {
            $table->string('provider_product_id', 191)->nullable()->after('provider_price_id');
            $table->string('legacy_provider_price_id', 191)->nullable()->after('provider_product_id');
            $table->text('pre_28_day_snapshot')->nullable();
        });

        $this->convertCatalogData();
    }

    /**
     * The DATA half, callable on its own so the conversion of a pre-existing
     * monthly catalog can be proven (and re-run safely) without re-adding columns.
     */
    public function convertCatalogData(): void
    {
        // Remember each not-yet-converted row's pre-release state (a converted row keeps its snapshot).
        DB::table('workspace_plan_catalog')->where('billing_cycle', '!=', 'every_28_days')->orderBy('id')->get()->each(function ($row): void {
            DB::table('workspace_plan_catalog')->where('id', $row->id)->update([
                'pre_28_day_snapshot' => json_encode([
                    'billing_cycle' => $row->billing_cycle,
                    'provider_price_id' => $row->provider_price_id,
                    'available_for_signup' => (bool) $row->available_for_signup,
                    'trial_enabled' => (bool) $row->trial_enabled,
                    'trial_days' => $row->trial_days,
                ]),
            ]);
        });

        DB::table('workspace_plan_catalog')->update(['trial_enabled' => false, 'trial_days' => null]);

        DB::table('workspace_plan_catalog')
            ->where('billing_cycle', 'monthly')
            ->whereNotNull('provider_price_id')
            ->update([
                'legacy_provider_price_id' => DB::raw('provider_price_id'),
                'provider_price_id' => null,
                'available_for_signup' => false,
            ]);

        DB::table('workspace_plan_catalog')
            ->where('billing_cycle', 'monthly')
            ->update(['billing_cycle' => 'every_28_days']);
    }

    public function down(): void
    {
        // ROLLBACK: the previous release only knows `monthly` and `yearly`, so
        // every plan goes back to exactly what it was before this migration —
        // cycle, Price, signup availability and trial — from the snapshot taken
        // on the way up. A row the snapshot does not cover (created afterwards),
        // or one the previous release could not represent, is taken off sale
        // rather than sold on a cadence it cannot show. Subscriptions were never
        // touched, so they need nothing.
        DB::table('workspace_plan_catalog')->orderBy('id')->get()->each(function ($row): void {
            $snap = $row->pre_28_day_snapshot === null ? null : json_decode((string) $row->pre_28_day_snapshot, true);

            if (is_array($snap)) {
                DB::table('workspace_plan_catalog')->where('id', $row->id)->update([
                    'billing_cycle' => $snap['billing_cycle'] ?? 'monthly',
                    'provider_price_id' => $snap['provider_price_id'] ?? null,
                    'available_for_signup' => (bool) ($snap['available_for_signup'] ?? false),
                    'trial_enabled' => (bool) ($snap['trial_enabled'] ?? false),
                    'trial_days' => $snap['trial_days'] ?? null,
                ]);

                return;
            }

            if (! in_array((string) $row->billing_cycle, ['monthly', 'yearly'], true)) {
                DB::table('workspace_plan_catalog')->where('id', $row->id)->update([
                    'billing_cycle' => 'monthly',
                    'provider_price_id' => null,
                    'available_for_signup' => false,
                ]);
            }
        });

        Schema::table('workspace_plan_catalog', function (Blueprint $table): void {
            $table->dropColumn(['provider_product_id', 'legacy_provider_price_id', 'pre_28_day_snapshot']);
        });
    }
};

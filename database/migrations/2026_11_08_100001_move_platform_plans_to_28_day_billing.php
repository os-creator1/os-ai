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
 * 3. DATA: the three plan rows.
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
        });

        $this->convertCatalogData();
    }

    /**
     * The DATA half, callable on its own so the conversion of a pre-existing
     * monthly catalog can be proven (and re-run safely) without re-adding columns.
     */
    public function convertCatalogData(): void
    {
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
        // ROLLBACK: put every plan that was moved off a monthly Price back on it
        // BEFORE the column that remembers it is dropped. (Plans that had no
        // Price keep the 28-day label, which only matters once a Price exists.)
        // Subscriptions were never touched, so they need nothing.
        DB::table('workspace_plan_catalog')
            ->whereNotNull('legacy_provider_price_id')
            ->update([
                'provider_price_id' => DB::raw('legacy_provider_price_id'),
                'billing_cycle' => 'monthly',
            ]);

        Schema::table('workspace_plan_catalog', function (Blueprint $table): void {
            $table->dropColumn(['provider_product_id', 'legacy_provider_price_id']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 21 §11 — the Platform Owner's lane-A commercial
 * configuration, added to the EXISTING V1 plan catalog rather than to a new
 * payments-only settings table.
 *
 * WHY HERE. `workspace_plan_catalog` is already the sole authority for what a
 * tier costs (`price`, `currency_id`, `billing_cycle`) and already has its own
 * audited change path (EntitlementManager::updateCatalogPricing() +
 * workspace_plan_catalog_pricing_changes). Trial policy and signup
 * availability are the same kind of fact — commercial configuration of a tier
 * — so putting them anywhere else would create a second authority for "what
 * are we selling", which §4 forbids.
 *
 * `available_for_signup` is deliberately SEPARATE from the existing
 * `is_active`. `is_active` governs whether a tier may be assigned at all
 * (EntitlementManager::assertCatalogActive() refuses an inactive tier for
 * assignment AND for plan changes); turning it off would strand existing
 * subscribers who need to change plans. Availability for NEW signup is a
 * narrower, purely commercial switch: stop selling Growth today without
 * breaking anybody already on it.
 *
 * `provider_price_id` records WHICH immutable provider Price identity the
 * current commercial terms correspond to (§10.1). Stripe Prices are immutable
 * in the relevant sense, so a new amount means a new Price; storing the id
 * here is what lets a subscription created yesterday keep pointing at the
 * Price it was actually sold on while new signups use the new one. No secret
 * lives in this column — a Price id is a public object identifier.
 *
 * NOTHING HERE IS A SECRET. The Stripe API key and the lane-A webhook signing
 * secret stay in secure runtime configuration (§5.1) and are never moved into
 * an editable database column to make an admin screen convenient.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_plan_catalog', function (Blueprint $table): void {
            // Trial policy, per tier. `trial_days` is meaningless while
            // `trial_enabled` is false and is validated by the manager, not by
            // DDL, so an operator turning trials off for a week does not lose
            // the duration they had configured.
            $table->boolean('trial_enabled')->default(false)->after('billing_cycle');
            $table->unsignedSmallInteger('trial_days')->nullable()->after('trial_enabled');

            // §7 — whether this tier may be SOLD to a new customer today.
            // Distinct from is_active, which governs assignability at all.
            $table->boolean('available_for_signup')->default(false)->after('is_active');

            // The provider Price identity the current terms map to (§10.1).
            $table->string('provider_price_id', 191)->nullable()->after('available_for_signup');

            $table->index('available_for_signup', 'wpc_available_for_signup_index');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_plan_catalog', function (Blueprint $table): void {
            $table->dropIndex('wpc_available_for_signup_index');
            $table->dropColumn(['trial_enabled', 'trial_days', 'available_for_signup', 'provider_price_id']);
        });
    }
};

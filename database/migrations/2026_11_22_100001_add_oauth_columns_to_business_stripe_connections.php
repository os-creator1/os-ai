<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Business Stripe Connect — "Connect existing Stripe account" (Standard OAuth).
 *
 * WHY EACH CHANGE EXISTS
 *  - `connection_method` ('created' | 'oauth'): disconnecting an OAuth-linked
 *    account must also revoke our OAuth grant at Stripe; a platform-created
 *    account has no grant to revoke. Nothing else may infer this.
 *  - `connected_by_user_id`: the owner who authorised the link (audit).
 *  - `requirements_due`: Stripe's outstanding requirement keys (codes only), so the
 *    status page can tell the owner what is left to finish.
 *  - `active_stripe_account_id` (STORED generated, UNIQUE): at most ONE live
 *    Business connection may hold a given Stripe account, enforced by the
 *    database so two racing OAuth callbacks cannot both win.
 *  - the old global UNIQUE(stripe_account_id) becomes a plain index. It made a
 *    disconnected account permanently unusable, so an owner who disconnected
 *    and later re-linked the SAME account could never do so. History rows are
 *    still never rewritten (§5.7); only LIVE uniqueness is a constraint now.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('business_stripe_connections', function (Blueprint $table) {
            $table->string('connection_method', 16)->default('created')->after('stripe_account_id');
            $table->unsignedBigInteger('connected_by_user_id')->nullable()->after('connection_method');
            $table->json('requirements_due')->nullable();
        });

        Schema::table('business_stripe_connections', function (Blueprint $table) {
            $table->string('active_stripe_account_id', 64)
                ->nullable()
                ->storedAs("CASE WHEN status IN ('pending','onboarding','active','restricted') THEN stripe_account_id ELSE NULL END")
                ->after('active_business_id');
        });

        Schema::table('business_stripe_connections', function (Blueprint $table) {
            $table->unique('active_stripe_account_id', 'bsc_active_account_unique');
            $table->dropUnique('bsc_stripe_account_unique');
            $table->index('stripe_account_id', 'bsc_stripe_account_index');
        });
    }

    public function down(): void
    {
        // Restoring GLOBAL uniqueness is impossible once an account has been
        // disconnected and re-linked: both historical rows must keep their
        // stripe_account_id (payments reference them, §5.7), so this refuses
        // BEFORE changing anything rather than failing half-way or rewriting
        // history. Roll back only while no account appears on two rows.
        $duplicated = DB::table('business_stripe_connections')
            ->select('stripe_account_id')
            ->groupBy('stripe_account_id')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($duplicated > 0) {
            throw new RuntimeException(
                "Cannot roll back: {$duplicated} Stripe account(s) appear on more than one business_stripe_connections row "
                . '(disconnected and re-linked). Restoring the global unique key would require rewriting payment-referenced history.'
            );
        }

        Schema::table('business_stripe_connections', function (Blueprint $table) {
            $table->dropUnique('bsc_active_account_unique');
            $table->dropIndex('bsc_stripe_account_index');
            $table->unique('stripe_account_id', 'bsc_stripe_account_unique');
            $table->dropColumn(['active_stripe_account_id', 'requirements_due', 'connected_by_user_id', 'connection_method']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFC-005 Remediation #6 §6/§21 — the wallet-level counter of unconsumed
 * provider-paid credit. Backfilled to 0 for every existing wallet
 * (§21) — a deliberately conservative default, since historical paid-vs-
 * non-paid provenance cannot be reconstructed exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_usage_wallets', function (Blueprint $table) {
            $table->bigInteger('refundable_paid_available_micro')->default(0)->after('available_balance_micro');
        });
    }

    public function down(): void
    {
        Schema::table('business_usage_wallets', function (Blueprint $table) {
            $table->dropColumn('refundable_paid_available_micro');
        });
    }
};

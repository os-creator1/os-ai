<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFC-005 Remediation #6 §6/§21 — the durable, per-reservation snapshot of
 * the paid-attributable amount removed from refundable_paid_available_micro
 * at reserve() time. Never re-derived — commit()/release() consume or
 * restore exactly this stored value. Backfilled to 0 for every existing
 * (necessarily already-resolved) reservation (§21).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_usage_reservations', function (Blueprint $table) {
            $table->bigInteger('paid_attributable_amount_micro')->default(0)->after('reserved_amount_micro');
        });
    }

    public function down(): void
    {
        Schema::table('business_usage_reservations', function (Blueprint $table) {
            $table->dropColumn('paid_attributable_amount_micro');
        });
    }
};

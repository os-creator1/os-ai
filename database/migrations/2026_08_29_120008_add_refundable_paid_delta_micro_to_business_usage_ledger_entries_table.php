<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFC-005 Remediation #6 §6/§12 — the signed, per-row audit trail of every
 * refundable_paid_available_micro mutation. Populated at every mutation
 * site §6 locks; left NULL for every entry type this correction does not
 * touch (ManualCredit, PromotionalCredit, and all others).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_usage_ledger_entries', function (Blueprint $table) {
            $table->bigInteger('refundable_paid_delta_micro')->nullable()->after('debt_delta_micro');
        });
    }

    public function down(): void
    {
        Schema::table('business_usage_ledger_entries', function (Blueprint $table) {
            $table->dropColumn('refundable_paid_delta_micro');
        });
    }
};

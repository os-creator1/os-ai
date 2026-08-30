<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFC-005 Remediation #6 §18 — widens the existing payment_provider_events
 * table with eleven nullable, durable, administrator-visible attribution
 * columns. Four distinct amount fields (reported/outcome_delta/
 * wallet_delta/policy_excess) — never a single ambiguous pair. No FK on
 * business_id/funding_attempt_id (§25).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_provider_events', function (Blueprint $table) {
            $table->unsignedBigInteger('business_id')->nullable()->after('provider_object_id');
            $table->unsignedBigInteger('funding_attempt_id')->nullable()->after('business_id');
            $table->string('normalized_outcome', 64)->nullable()->after('funding_attempt_id');
            $table->string('normalized_status', 32)->nullable()->after('normalized_outcome');
            $table->bigInteger('normalized_reported_amount_micro')->nullable()->after('normalized_status');
            $table->bigInteger('normalized_outcome_delta_micro')->nullable()->after('normalized_reported_amount_micro');
            $table->bigInteger('normalized_wallet_delta_micro')->nullable()->after('normalized_outcome_delta_micro');
            $table->bigInteger('normalized_policy_excess_micro')->nullable()->after('normalized_wallet_delta_micro');
            $table->string('normalized_currency_code', 3)->nullable()->after('normalized_policy_excess_micro');
            $table->string('normalized_reason', 64)->nullable()->after('normalized_currency_code');
            $table->timestamp('normalized_recorded_at')->nullable()->after('normalized_reason');
        });
    }

    public function down(): void
    {
        Schema::table('payment_provider_events', function (Blueprint $table) {
            $table->dropColumn([
                'business_id',
                'funding_attempt_id',
                'normalized_outcome',
                'normalized_status',
                'normalized_reported_amount_micro',
                'normalized_outcome_delta_micro',
                'normalized_wallet_delta_micro',
                'normalized_policy_excess_micro',
                'normalized_currency_code',
                'normalized_reason',
                'normalized_recorded_at',
            ]);
        });
    }
};

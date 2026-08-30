<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFC-005 Remediation #6 §20 — supports
 * sumRefundedMicroForFundingAttempt(), sumDisputeMicroForFundingAttemptAndDispute(),
 * and hasOutstandingDisputeExposureForFundingAttempt(), all scoped by
 * funding_attempt_id[, provider_reference].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_usage_ledger_entries', function (Blueprint $table) {
            $table->index(['funding_attempt_id', 'entry_type', 'provider_reference'], 'bule_funding_attempt_entry_type_provider_reference_index');
        });
    }

    public function down(): void
    {
        Schema::table('business_usage_ledger_entries', function (Blueprint $table) {
            $table->dropIndex('bule_funding_attempt_entry_type_provider_reference_index');
        });
    }
};

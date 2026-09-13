<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice AI-2 correction — record which allowance refused a budget refusal.
 *
 * `refusal_reason` already says a call was refused for `budget_exhausted`;
 * it cannot say whether the Workspace cap or an Agency Business's own
 * per-Business cap was the limit, and the customer's AI usage page must tell
 * those apart (contract §11.3). AiUsageLedgerManager::reserve() knows the
 * answer at the moment it refuses, so it is written there, once.
 *
 * Additive and backward compatible: nullable, no default, no backfill. Rows
 * written before this column existed stay readable with a null scope, and
 * nothing that reads `refusal_reason` changes. No index: every reader
 * already narrows by (workspace_id, period_key) or (business_id, period_key).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage_ledger', function (Blueprint $table): void {
            $table->string('refusal_scope', 32)->nullable()->after('refusal_reason');
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_ledger', function (Blueprint $table): void {
            $table->dropColumn('refusal_scope');
        });
    }
};

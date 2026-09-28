<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 19 §8, §5.7a F, sub-slice 19.H0 — explicit scope
 * attribution on `ai_usage_ledger`.
 *
 * Platform rows must never be encoded as `workspace_id = 0` or any other
 * magic id (R-19): the ledger gains its own `scope_type`/`scope_id`
 * instead, mirroring the discriminator `ai_usage_periods` has carried since
 * its first migration. `workspace_id` becomes nullable (no FK exists on it
 * today, so no referential consequence) so a Platform-scope entry carries
 * no Workspace at all.
 *
 * Backwards compatible by construction: every existing row backfills to
 * `scope_type = 'workspace'`, `scope_id = workspace_id`, which reproduces
 * today's semantics exactly — `App\Library\Ai\AiUsageLedgerManager`'s
 * settle path reads an entry's OWN `scope_type`/`scope_id`, so old and new
 * rows settle identically. The new `(scope_type, scope_id, period_key)`
 * index serves Platform/Workspace period lookups; the three existing
 * `workspace_id`-touching indexes and the global `idempotency_key` UNIQUE
 * are left exactly as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage_ledger', function (Blueprint $table): void {
            $table->string('scope_type', 16)->default('workspace')->after('business_id');
            $table->unsignedBigInteger('scope_id')->nullable()->after('scope_type');
        });

        DB::table('ai_usage_ledger')->update([
            'scope_type' => 'workspace',
            'scope_id' => DB::raw('workspace_id'),
        ]);

        Schema::table('ai_usage_ledger', function (Blueprint $table): void {
            $table->unsignedBigInteger('scope_id')->nullable(false)->change();
            $table->unsignedBigInteger('workspace_id')->nullable()->change();

            $table->index(['scope_type', 'scope_id', 'period_key'], 'ai_usage_ledger_scope_period_index');
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_ledger', function (Blueprint $table): void {
            $table->dropIndex('ai_usage_ledger_scope_period_index');
        });

        // A Platform-scope row (workspace_id = NULL) cannot be represented
        // by the pre-19.H0 schema, so it is removed rather than given a
        // fabricated Workspace id.
        DB::table('ai_usage_ledger')->whereNull('workspace_id')->delete();

        Schema::table('ai_usage_ledger', function (Blueprint $table): void {
            $table->unsignedBigInteger('workspace_id')->nullable(false)->change();
            $table->dropColumn(['scope_type', 'scope_id']);
        });
    }
};

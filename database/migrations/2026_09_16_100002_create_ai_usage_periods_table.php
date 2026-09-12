<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unified Business Home and COO Decision Engine Contract §10.2 (slice
 * AI-1) — one row per (scope, period), used as the lock-and-counter row
 * for AiGateway's reservation protocol. `scope_type`/`scope_id` avoids a
 * nullable unique key: a Workspace-level row and a Business-level row
 * for the same Business's Workspace are two distinct rows, never one row
 * with a nullable business column.
 *
 * Additive only. No backfill: the ledger starts at zero, so every
 * existing Workspace begins its first period with its full allowance —
 * there is nothing to reconcile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_periods', function (Blueprint $table): void {
            $table->id();
            $table->string('scope_type', 16);
            $table->unsignedBigInteger('scope_id');
            // Deliberately no FK: this row must survive and stay
            // attributable for platform audit regardless of what later
            // happens to the Workspace row (mirrors ai_usage_ledger).
            $table->unsignedBigInteger('workspace_id');
            $table->string('period_key', 64);
            $table->string('policy_key', 16);
            $table->unsignedInteger('policy_version');
            $table->unsignedBigInteger('cap_microusd');
            $table->unsignedBigInteger('reserved_microusd')->default(0);
            $table->unsignedBigInteger('committed_microusd')->default(0);
            $table->unsignedBigInteger('interactive_reserved_microusd')->default(0);
            $table->unsignedBigInteger('interactive_committed_microusd')->default(0);
            $table->timestamps();

            $table->unique(['scope_type', 'scope_id', 'period_key'], 'ai_usage_periods_scope_period_unique');
            $table->index('workspace_id', 'ai_usage_periods_workspace_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_periods');
    }
};

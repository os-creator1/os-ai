<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unified Business Home and COO Decision Engine Contract §10.2 (slice
 * AI-1) — the append-only provider-cost ledger. Every first-party AI
 * call carries a category, and every category draws on the same
 * Workspace budget (D-2); a call with no category is a bug, not a free
 * call, so `category` is NOT NULL.
 *
 * Internal only, never shown to customers (§10.2, §15.4): no message
 * content, no phone number, no request/response body column exists here
 * — only counts, provenance and cost.
 *
 * `business_id` cascades to null on delete (a Business's ledger history
 * is platform audit, not Business data). `workspace_id` deliberately has
 * no FK constraint, so it survives for platform audit regardless of what
 * later happens to the Workspace row (§15.9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_ledger', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('workspace_id');
            $table->foreignId('business_id')->nullable()->constrained('businesses')->nullOnDelete();
            $table->string('category', 32);
            $table->string('lane', 16);
            $table->string('model_route', 16);
            $table->string('provider', 32);
            $table->string('provider_model', 64)->nullable();
            $table->unsignedInteger('price_version');
            $table->string('status', 16);
            $table->string('refusal_reason', 32)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('cached_input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('estimated_cost_microusd')->default(0);
            $table->unsignedBigInteger('actual_cost_microusd')->nullable();
            $table->string('period_key', 64);
            $table->string('idempotency_key', 191)->unique();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('settled_at')->nullable();

            $table->index(['workspace_id', 'period_key'], 'ai_usage_ledger_workspace_period_index');
            $table->index(['business_id', 'period_key'], 'ai_usage_ledger_business_period_index');
            $table->index(['workspace_id', 'period_key', 'category'], 'ai_usage_ledger_workspace_period_category_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_ledger');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Content Autopilot (Contract 25) - the audit trail of what Autopilot decided and why. One row per evaluation outcome.
 *
 * Why a table: "do nothing" and "hold" are successful decisions that must be explainable ("why didn't you write anything
 * this month?"); an open "needs your input" question must survive between ticks; a retried job must find its own decision
 * (idempotency); a rejected topic must not be retried for a cool-down; and the owner page reads Next up / This month /
 * Awaiting approval from here. Opportunities themselves stay COMPUTED (Contract 24), never stored.
 *
 * The structured brief is a JSON column on the decision (one brief per decision), not a table of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_autopilot_decisions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            // create | update | maintain
            $table->string('kind', 16)->default('create');
            // create | update | hold | needs_input | none
            $table->string('decision', 16);
            // scored | briefed | drafting | validating | awaiting_approval | scheduled | published
            // | deferred_budget | held | rejected | needs_input | resolved | none
            $table->string('state', 24);
            $table->string('opportunity_key', 120)->nullable();
            $table->unsignedSmallInteger('score')->nullable();
            $table->json('score_breakdown')->nullable();
            $table->string('reason_code', 48)->nullable();
            $table->json('brief')->nullable();
            $table->string('brief_hash', 64)->nullable();
            $table->string('fact_hash', 64)->nullable();
            // { key: differentiators|common_questions|emphasis, prompt: string } - at most one open per Business.
            $table->json('needs_input')->nullable();
            $table->foreignId('article_id')->nullable()->constrained('website_articles')->nullOnDelete();
            $table->unsignedBigInteger('cost_microusd')->default(0);
            $table->string('period_key', 7);
            $table->dateTime('evaluated_at');
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'state'], 'content_autopilot_decisions_state_index');
            $table->index(['business_id', 'opportunity_key'], 'content_autopilot_decisions_key_index');
            $table->index(['business_id', 'evaluated_at'], 'content_autopilot_decisions_evaluated_index');
            $table->index(['business_id', 'period_key'], 'content_autopilot_decisions_period_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_autopilot_decisions');
    }
};

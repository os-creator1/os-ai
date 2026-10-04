<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEO Keyword Rank Tracking V1 — one provider task for one target and one check
 * type (organic or local). The durable idempotency anchor: idempotency_key
 * (target:check_type:period) is UNIQUE, so a duplicated scheduler tick, a
 * retried job or a double click cannot create a second paid task for the same
 * period. provider_task_id is stored the moment the provider accepts a task and
 * is the only thing polling uses, so a failed poll never resubmits.
 *
 * States: scheduled -> submitting -> submitted -> completed | failed_terminal,
 * or held (submit outcome ambiguous: the reservation stays counted and nothing
 * is auto-resubmitted). reserved_micros / actual_micros are integer micro-USD.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_rank_check_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->foreignId('seo_rank_target_id')->constrained('seo_rank_targets')->restrictOnDelete();
            $table->string('check_type', 8);
            $table->string('trigger', 12);
            $table->string('idempotency_key', 120)->unique();
            $table->string('state', 24)->default('scheduled');
            $table->string('provider', 16);
            $table->string('provider_task_id', 64)->nullable()->unique();
            $table->unsignedSmallInteger('depth');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedSmallInteger('poll_attempts')->default(0);
            $table->unsignedInteger('reserved_micros')->default(0);
            $table->unsignedInteger('actual_micros')->nullable();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error_code', 40)->nullable();
            $table->timestamps();

            $table->index(['state', 'next_attempt_at'], 'seo_rank_run_work_index');
            $table->index(['seo_rank_target_id', 'check_type', 'trigger', 'created_at'], 'seo_rank_run_target_history_index');
            $table->index(['business_id', 'created_at'], 'seo_rank_run_business_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_rank_check_runs');
    }
};

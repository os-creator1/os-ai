<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform Automations (platform-scoped automation) and the Announcements system.
 *
 * WHY NEW TABLES AND NOT business_id = NULL ON automation_workflows: every table of
 * the Business engine is `business_id NOT NULL` with a foreign key, and its
 * enrollments are contact-bound. Loosening that would weaken the tenancy guarantees
 * the Business engine relies on, and would let a Platform workflow be picked up by a
 * Business runtime. Platform scope therefore has its own tables and its own runner;
 * it reuses the canonical PATTERNS (versioned immutable definitions, occurrence-key
 * idempotency, claim/retry steps, run history), not the Business rows.
 *
 *  platform_automations          the editable definition (trigger + conditions + steps)
 *  platform_automation_versions  immutable snapshot per save; a run is pinned to one
 *  platform_automation_runs      one per (automation, occurrence key); explicit target
 *  platform_automation_steps     one per step of a run; the audit/retry unit
 *  platform_announcements        the Announcements lifecycle (draft..expired)
 *  platform_announcement_receipts per-user delivery/dismissal; the idempotency ledger
 *  platform_account_notes        internal notes / tasks / manual-review flags on an account
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_automations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->string('status', 16)->default('draft'); // draft|enabled|disabled|archived
            $table->string('trigger_type', 64);
            $table->json('definition');
            $table->unsignedInteger('version')->default(1);
            $table->string('recipe_key', 64)->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'trigger_type'], 'pa_status_trigger_index');
            $table->foreign('created_by_user_id', 'pa_created_by_foreign')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by_user_id', 'pa_updated_by_foreign')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('platform_automation_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('automation_id');
            $table->unsignedInteger('version_number');
            $table->string('trigger_type', 64);
            $table->json('definition');
            $table->char('definition_hash', 64);
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['automation_id', 'version_number'], 'pav_automation_version_unique');
            $table->foreign('automation_id', 'pav_automation_foreign')->references('id')->on('platform_automations')->cascadeOnDelete();
        });

        Schema::create('platform_automation_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('automation_id');
            $table->unsignedBigInteger('version_id');
            $table->string('trigger_type', 64);
            // Idempotency: one run per logical occurrence of one automation.
            $table->string('occurrence_key', 191);
            // Every run carries an explicit target. These are RESOLVED at fire time
            // and never recomputed from "the current Business".
            $table->string('target_type', 16); // user|workspace|business|subscription|provider|platform
            $table->unsignedBigInteger('target_id')->default(0);
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->unsignedBigInteger('business_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->json('context')->nullable(); // ids and small facts only
            $table->string('state', 24)->default('queued'); // queued|running|waiting|awaiting_approval|succeeded|failed|skipped|cancelled
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('safe_error', 255)->nullable();
            $table->timestamps();

            $table->unique(['automation_id', 'occurrence_key'], 'par_occurrence_unique');
            $table->index(['state', 'scheduled_at'], 'par_state_scheduled_index');
            $table->index(['workspace_id'], 'par_workspace_index');
            $table->index(['business_id'], 'par_business_index');
            $table->foreign('automation_id', 'par_automation_foreign')->references('id')->on('platform_automations')->cascadeOnDelete();
            $table->foreign('version_id', 'par_version_foreign')->references('id')->on('platform_automation_versions')->restrictOnDelete();
        });

        Schema::create('platform_automation_steps', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('run_id');
            $table->unsignedSmallInteger('step_index');
            $table->string('step_key', 32);
            $table->string('action_type', 48);
            $table->string('safety_class', 24);
            $table->json('config');
            $table->string('state', 24)->default('pending'); // pending|waiting|awaiting_approval|succeeded|failed|skipped|rejected
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('run_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->json('result')->nullable();
            $table->string('safe_error', 255)->nullable();
            $table->string('operation_ref', 64)->nullable(); // reference to the canonical operation, e.g. audit id
            $table->unsignedBigInteger('decided_by_user_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'step_index'], 'pas_run_step_unique');
            $table->index(['state', 'run_at'], 'pas_state_run_at_index');
            $table->foreign('run_id', 'pas_run_foreign')->references('id')->on('platform_automation_runs')->cascadeOnDelete();
        });

        Schema::create('platform_announcements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->string('title', 160);
            $table->text('body');
            $table->string('severity', 12)->default('info'); // info|success|warning|critical
            $table->json('channels'); // subset of banner|notification|email
            $table->json('audience'); // {"kind": "everyone|workspace_owners|business_owners|tier|trial|workspaces|businesses", ...}
            $table->string('status', 16)->default('draft'); // draft|scheduled|published|expired|cancelled
            $table->timestamp('publish_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('recipients_total')->default(0);
            $table->unsignedInteger('recipients_done')->default(0);
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('source_run_id')->nullable(); // set when an automation raised it
            $table->timestamps();

            $table->index(['status', 'publish_at'], 'pann_status_publish_index');
            $table->foreign('created_by_user_id', 'pann_created_by_foreign')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('platform_announcement_receipts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('announcement_id');
            $table->unsignedBigInteger('user_id');
            $table->string('channel', 16); // banner|notification|email
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->unique(['announcement_id', 'user_id', 'channel'], 'parc_unique');
            $table->index(['user_id', 'channel'], 'parc_user_channel_index');
            $table->foreign('announcement_id', 'parc_announcement_foreign')->references('id')->on('platform_announcements')->cascadeOnDelete();
            $table->foreign('user_id', 'parc_user_foreign')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('platform_account_notes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->string('target_type', 16);
            $table->unsignedBigInteger('target_id');
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->unsignedBigInteger('business_id')->nullable();
            $table->string('kind', 16); // note|task|review_flag
            $table->text('body');
            $table->string('state', 8)->default('open'); // open|done
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('source_run_id')->nullable();
            $table->timestamps();

            $table->index(['target_type', 'target_id'], 'pan_target_index');
            $table->index(['kind', 'state'], 'pan_kind_state_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_account_notes');
        Schema::dropIfExists('platform_announcement_receipts');
        Schema::dropIfExists('platform_announcements');
        Schema::dropIfExists('platform_automation_steps');
        Schema::dropIfExists('platform_automation_runs');
        Schema::dropIfExists('platform_automation_versions');
        Schema::dropIfExists('platform_automations');
    }
};

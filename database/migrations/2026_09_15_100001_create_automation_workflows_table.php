<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automations V2 slice V2-0 §4.1 — the stable workflow identity.
 *
 * MIGRATION 1 OF 6, AND THE ORDER MATTERS (§4.7).
 *
 * `published_version_id` is created here as a plain nullable column with NO
 * index and NO foreign key, because the table it must reference
 * (`automation_workflow_versions`) does not exist yet, while that table's own
 * `workflow_id` references this one. Migration 2 creates the versions table
 * and then adds this column's index and composite foreign key in its own
 * `Schema::table()` call; migration 2's `down()` removes them again BEFORE
 * dropping the versions table, so this migration's `down()` is always safe.
 *
 * Deliberately absent: `enrollment_policy` and `failure_policy`. Both are
 * behavioural settings, and the trigger they belong with lives on the
 * VERSION — so putting them here would let a Settings edit change how the
 * live published version behaves before anything was published (§4.1 C2).
 * They live on `automation_workflow_versions` and are pinned with it.
 *
 * Rollback drops the table, which is destructive of every workflow definition
 * it holds. That is inherent: this table IS the definition's identity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_workflows', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid');
            $table->unsignedBigInteger('business_id');
            $table->string('name', 120);

            // draft | published | paused | archived — App\Enums\Automation\Workflow\WorkflowStatus
            $table->string('status', 16)->default('draft');

            // The version new enrollments use. Constrained by migration 2.
            $table->unsignedBigInteger('published_version_id')->nullable();

            // Set only by the B4 converter (§15.2); unique so one legacy row
            // can never be converted twice.
            $table->unsignedBigInteger('legacy_automation_id')->nullable();

            // Audit only. Never an authorization input: background work
            // resolves its identity from the Business (§14.3).
            $table->unsignedBigInteger('created_by_user_id')->nullable();

            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique('uid', 'aw_uid_unique');
            $table->unique('legacy_automation_id', 'aw_legacy_automation_unique');

            $table->foreign('business_id', 'aw_business_foreign')
                ->references('id')->on('businesses')->restrictOnDelete();
            $table->foreign('legacy_automation_id', 'aw_legacy_automation_foreign')
                ->references('id')->on('automations')->nullOnDelete();
            $table->foreign('created_by_user_id', 'aw_created_by_user_foreign')
                ->references('id')->on('users')->nullOnDelete();

            $table->index('business_id', 'aw_business_index');
            $table->index(['business_id', 'status'], 'aw_business_status_index');
        });
    }

    public function down(): void
    {
        // Safe without touching published_version_id: migration 2's down()
        // has already dropped the only foreign key on that column.
        Schema::dropIfExists('automation_workflows');
    }
};

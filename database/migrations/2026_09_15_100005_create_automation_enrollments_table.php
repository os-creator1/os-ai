<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automations V2 slice V2-0 §4.5 — one contact's journey through one pinned
 * version.
 *
 * MIGRATION 5 OF 6.
 *
 * THE PIN. `version_id` is recorded at enrollment and never changes, and it
 * RESTRICTS deletion of that version. Because published version rows are
 * immutable, an enrollment that began on version 3 keeps executing version 3's
 * nodes even after version 4 is published underneath it.
 *
 * `version_id` is additionally tied to `workflow_id` by a COMPOSITE foreign key
 * against `automation_workflow_versions (id, workflow_id)`, so an enrollment
 * cannot pin a version belonging to a different workflow. Likewise
 * `current_node_id` is tied to `version_id` against
 * `automation_workflow_nodes (id, version_id)`, so the cursor cannot point into
 * another version's graph. Both are cross-version/cross-workflow references
 * that this slice is required to make impossible, and neither is left to
 * application care.
 *
 * THE CURSOR. `current_node_id` is the node about to execute. It is NULL once
 * the enrollment is terminal. `status = active` means that node is executable
 * now; `waiting` means it is not until `resume_at`. No other non-terminal state
 * exists — the Resume path depends on that invariant (§6.3, §7.1).
 *
 * THE CLAIM. `enrollment_key` is UNIQUE: it is how "this contact has already
 * entered this workflow (for this occurrence)" is enforced by the database
 * rather than by a read-then-write race. `active_contact_guard` adds the
 * stronger, policy-independent rule that one contact can never occupy one
 * workflow twice at the same time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid');
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('workflow_id');
            $table->unsignedBigInteger('version_id');
            $table->unsignedBigInteger('contact_id');

            // active | waiting | completed | failed | exited | cancelled
            $table->string('status', 16)->default('active');

            $table->unsignedBigInteger('current_node_id')->nullable();
            $table->timestamp('resume_at')->nullable();

            $table->string('trigger_type', 32);

            // What made this enrollment: a year for a yearly date, an inbound
            // message id, a manual-request uid. Server-derived, never request
            // input.
            $table->string('trigger_occurrence_key', 191);

            // The durable idempotency claim (§7.5).
            $table->string('enrollment_key', 191);

            // Cross-workflow cascade bound (Lane F §6.1).
            $table->unsignedTinyInteger('causation_depth')->default(0);

            // Defence in depth against a runaway traversal; the tree shape is
            // the real guarantee.
            $table->unsignedSmallInteger('step_count')->default(0);

            $table->timestamp('enrolled_at');
            $table->timestamp('last_advanced_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('exit_reason', 64)->nullable();
            $table->timestamps();

            $table->unique('uid', 'aen_uid_unique');
            $table->unique('enrollment_key', 'aen_enrollment_key_unique');

            $table->foreign('business_id', 'aen_business_foreign')
                ->references('id')->on('businesses')->restrictOnDelete();
            $table->foreign('workflow_id', 'aen_workflow_foreign')
                ->references('id')->on('automation_workflows')->restrictOnDelete();
            $table->foreign('contact_id', 'aen_contact_foreign')
                ->references('id')->on('contacts')->cascadeOnDelete();

            // Composite: the pinned version must belong to this workflow.
            $table->foreign(['version_id', 'workflow_id'], 'aen_version_workflow_foreign')
                ->references(['id', 'workflow_id'])->on('automation_workflow_versions')
                ->restrictOnDelete();

            // Composite: the cursor must belong to the pinned version. NULL
            // current_node_id is not checked, which is the terminal state.
            $table->foreign(['current_node_id', 'version_id'], 'aen_current_node_foreign')
                ->references(['id', 'version_id'])->on('automation_workflow_nodes')
                ->restrictOnDelete();

            // The per-minute due sweep.
            $table->index(['status', 'resume_at'], 'aen_status_resume_index');
            // The lost/interrupted-job recovery sweep (15 minutes, §8.4).
            $table->index(['status', 'last_advanced_at'], 'aen_status_last_advanced_index');
            // Enrollment history, analytics, and the duplicate-enrollment check.
            $table->index(['workflow_id', 'created_at'], 'aen_workflow_created_index');
            $table->index(['business_id', 'created_at'], 'aen_business_created_index');
            $table->index('contact_id', 'aen_contact_index');
        });

        // One contact can never occupy one workflow twice at once, under any
        // enrollment policy. A terminal enrollment yields NULL and so never
        // blocks a legitimate later re-enrollment.
        //
        // VIRTUAL, NOT STORED — and the reason is a real MySQL rule, not a
        // preference. MySQL forbids CASCADE / SET NULL / SET DEFAULT as a
        // referential action on a column that appears in the generation
        // expression of a STORED generated column. This guard is computed from
        // `contact_id`, whose foreign key deliberately cascades so that deleting
        // a Contact removes their enrollment history with them (matching B4's
        // `automation_executions.contact_id`). A STORED guard here therefore
        // fails at migration time with errno 1215.
        //
        // A VIRTUAL generated column carries no such restriction, and InnoDB
        // supports a UNIQUE secondary index on one, so the invariant stays
        // enforced by the database. Every other guard column in this slice, and
        // in the patterns it follows (payment_provider_customers,
        // business_messaging_identities, business_messaging_numbers), is STORED
        // because each of those is computed only from columns whose foreign keys
        // RESTRICT.
        Schema::table('automation_enrollments', function (Blueprint $table): void {
            $table->unsignedBigInteger('active_contact_guard')
                ->nullable()
                ->virtualAs("CASE WHEN status IN ('active','waiting') THEN contact_id ELSE NULL END")
                ->after('status');
        });

        Schema::table('automation_enrollments', function (Blueprint $table): void {
            $table->unique(['workflow_id', 'active_contact_guard'], 'aen_workflow_active_contact_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_enrollments');
    }
};

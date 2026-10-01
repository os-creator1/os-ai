<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website Builder redesign — Niche Builder foundation, migration 3 of 3.
 *
 * Mirrors `2026_09_15_100005_create_automation_enrollments_table.php`:
 * one Business's attempt at answering one questionnaire, through one
 * PINNED version.
 *
 * THE PIN. `questionnaire_version_id` is set once when the session starts
 * and never reassigned. Because a published version is immutable, a
 * session that began on version 3 keeps reading/writing against version
 * 3's question tree even after version 4 is published — enforced by the
 * composite foreign key against `questionnaire_versions (id,
 * questionnaire_definition_id)`, so a response can never pin a version
 * belonging to a different definition.
 *
 * THE CLAIM. `active_definition_guard` forbids the same Business having
 * two `in_progress` responses for the same questionnaire definition at
 * once — the DB-enforced half of "resume, don't duplicate."
 *
 * `answers` is a single JSON document (matching `definition`'s own
 * document-per-row shape) plus `answers_revision`, an optimistic-
 * concurrency counter for autosave — a stale revision loses the write
 * rather than clobbering another tab, exactly like
 * `automation_workflow_versions.definition_revision`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questionnaire_responses', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->foreignId('website_id')->nullable()->constrained('websites')->nullOnDelete();
            $table->unsignedBigInteger('questionnaire_definition_id');
            $table->unsignedBigInteger('questionnaire_version_id');

            // in_progress | completed | abandoned
            $table->string('status', 16)->default('in_progress');

            $table->string('current_step_key', 64)->nullable();
            $table->json('answers')->nullable();
            $table->unsignedInteger('answers_revision')->default(1);

            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('questionnaire_definition_id', 'qr_definition_foreign')
                ->references('id')->on('questionnaire_definitions')->restrictOnDelete();

            // Composite: the pinned version must belong to this definition.
            $table->foreign(['questionnaire_version_id', 'questionnaire_definition_id'], 'qr_version_definition_foreign')
                ->references(['id', 'questionnaire_definition_id'])->on('questionnaire_versions')
                ->restrictOnDelete();

            $table->index(['business_id', 'created_at']);
        });

        // STORED (not VIRTUAL): both business_id and questionnaire_definition_id
        // RESTRICT on delete, so this guard's dependency chain never runs into
        // the CASCADE/SET NULL/SET DEFAULT restriction that forces
        // automation_enrollments.active_contact_guard to be VIRTUAL.
        Schema::table('questionnaire_responses', function (Blueprint $table): void {
            $table->unsignedBigInteger('active_definition_guard')
                ->nullable()
                ->storedAs("CASE WHEN status = 'in_progress' THEN questionnaire_definition_id ELSE NULL END")
                ->after('status');
        });

        Schema::table('questionnaire_responses', function (Blueprint $table): void {
            $table->unique(['business_id', 'active_definition_guard'], 'qr_business_active_definition_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questionnaire_responses');
    }
};

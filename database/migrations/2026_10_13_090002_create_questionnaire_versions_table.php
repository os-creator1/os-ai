<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website Builder redesign — Niche Builder foundation, migration 2 of 3.
 *
 * Mirrors `2026_09_15_100002_create_automation_workflow_versions_table.php`
 * exactly: drafts and immutable published versions, with the two STORED
 * generated guard columns MySQL requires in place of a partial unique
 * index to enforce "at most one draft" / "at most one published version"
 * per definition.
 *
 * `definition` (json) holds the ordered step/question tree: each question
 * carries its key, prompt, help_text, input_type, required flag, options,
 * a conditional_visibility rule, and where its answer routes (target
 * canonical module/field). THE WIZARD NEVER READS ANYTHING ELSE for a
 * published version — this is the "not hardcoded into the Website UI"
 * requirement made concrete: the questionnaire IS this JSON document.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questionnaire_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('questionnaire_definition_id');
            $table->unsignedInteger('version_number');

            // draft | published | superseded
            $table->string('state', 16)->default('draft');

            $table->json('definition');
            $table->char('definition_hash', 64)->nullable();

            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['questionnaire_definition_id', 'version_number'],
                'qv_definition_version_unique'
            );

            // Required by the composite back-reference from
            // questionnaire_responses: MySQL needs the referenced columns
            // indexed in the referencing order. Trivially unique because
            // `id` is the primary key.
            $table->unique(['id', 'questionnaire_definition_id'], 'qv_id_definition_unique');

            $table->foreign('questionnaire_definition_id', 'qv_definition_foreign')
                ->references('id')->on('questionnaire_definitions')->restrictOnDelete();
        });

        // A draft row yields its questionnaire_definition_id in draft_guard
        // and NULL in published_guard, and vice versa; any number of
        // superseded rows coexist since they yield NULL in both. Both
        // guards are STORED (not VIRTUAL): questionnaire_definition_id's
        // own foreign key above RESTRICTs, so — per the documented MySQL
        // rule that forbids a STORED generated column depending on a
        // column with a CASCADE/SET NULL/SET DEFAULT action — STORED is
        // safe here, exactly as it is for automation_workflow_versions.
        Schema::table('questionnaire_versions', function (Blueprint $table): void {
            $table->unsignedBigInteger('draft_guard')
                ->nullable()
                ->storedAs("CASE WHEN state = 'draft' THEN questionnaire_definition_id ELSE NULL END")
                ->after('state');

            $table->unsignedBigInteger('published_guard')
                ->nullable()
                ->storedAs("CASE WHEN state = 'published' THEN questionnaire_definition_id ELSE NULL END")
                ->after('draft_guard');
        });

        Schema::table('questionnaire_versions', function (Blueprint $table): void {
            $table->unique('draft_guard', 'qv_draft_guard_unique');
            $table->unique('published_guard', 'qv_published_guard_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questionnaire_versions');
    }
};

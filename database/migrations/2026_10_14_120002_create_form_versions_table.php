<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forms V1 domain foundation — the immutable version of what a form asks.
 *
 * WHY THIS TABLE EXISTS. "Historical submissions must remain intelligible
 * after a definition is edited." A submission stores the answers keyed by field
 * key; this row stores what each key meant (label, type, options, whether it
 * was required) at the moment the answer was given. Without it, renaming a
 * field or deleting a question would silently rewrite old leads.
 *
 * ONE ROW PER MEANINGFUL EDIT. FormManager writes a new row (version =
 * previous + 1) only when the content hash changes; saving an unchanged form
 * writes nothing. Rows are NEVER updated or deleted (the model refuses both;
 * there is no `updated_at` column), and `form_id` is RESTRICT so a submission's
 * version can never vanish underneath it.
 *
 * `fields` is the bounded, code-validated JSON list (key, label, type,
 * required, options, contact role) — a closed shape, not an arbitrary schema.
 * The CRM behaviour (`create_opportunity`, optional `opportunity_pipeline_id`)
 * is versioned with it because it is part of how a submission was handled.
 * `opportunity_pipeline_id` only references a pipeline (null = the Business's
 * first active pipeline); pipelines are archived, not deleted, so SET NULL is
 * a defensive default, never an expected path.
 *
 * `content_hash` is sha256 of the canonical content, the cheap "did anything
 * change" test, unique per (form, version) by construction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('form_id')->constrained('forms')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->char('content_hash', 64);
            $table->text('intro')->nullable();
            $table->string('submit_label', 40);
            $table->string('success_message', 300);
            $table->json('fields');
            $table->boolean('create_opportunity')->default(false);
            $table->foreignId('opportunity_pipeline_id')->nullable()->constrained('crm_pipelines')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['form_id', 'version'], 'form_versions_form_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_versions');
    }
};

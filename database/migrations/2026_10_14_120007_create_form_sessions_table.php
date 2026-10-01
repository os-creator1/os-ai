<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forms V1 correction round 1 — the in-progress state of a multi-page
 * questionnaire.
 *
 * WHY THIS TABLE EXISTS. A questionnaire spans several requests, and the answers
 * given on earlier pages must survive until the last one. They are kept HERE,
 * server-side, rather than carried by the browser as an answer blob the visitor
 * (or an attacker) could alter between pages. A session holds only answers the
 * server has already validated against the pinned version, keyed by field key.
 *
 * IN PROGRESS IS NOT HISTORY. A session is mutable working state and creates no
 * Contact, Opportunity, event or `form_submissions` row. Only the final submit
 * does, through the same idempotency claim an ordinary form uses. When that
 * claim commits the session is stamped with `form_submission_id` and
 * `finalized_at` (in the same transaction) and accepts no further answers.
 *
 * IDENTITY AND PINNING. `(form_deployment_id, operation_nonce)` is unique — the
 * nonce is the one issued, HMAC-bound, when the questionnaire started, so two
 * separately started questionnaires are two sessions even with identical
 * answers. `form_version_id` pins the ONE immutable version the whole flow was
 * shown; both foreign keys are RESTRICT.
 *
 * BOUNDED. `answers` can hold only keys of the pinned definition (itself bounded
 * in fields and value length); `expires_at` limits how long a session is honoured
 * and makes abandoned ones prunable (the model is MassPrunable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('form_deployment_id')->constrained('form_deployments')->restrictOnDelete();
            $table->foreignId('form_version_id')->constrained('form_versions')->restrictOnDelete();
            $table->char('operation_nonce', 32);
            $table->json('answers');
            $table->json('completed_pages');
            $table->timestamp('expires_at');
            $table->foreignId('form_submission_id')->nullable()->constrained('form_submissions')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->unique(['form_deployment_id', 'operation_nonce'], 'form_sessions_deployment_nonce_unique');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_sessions');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Independent-review correction round — "Edit setup answers" must reopen
 * the same pinned response/version rather than the wizard mistaking a
 * completed response for a fresh in-progress one. Reopening flips
 * `status` back to `in_progress` (so every existing wizard route's
 * `status = in_progress` resume check keeps working unmodified, and the
 * `active_definition_guard` unique constraint keeps enforcing "one active
 * session per Business per questionnaire" without any change to its own
 * generated expression) while `edit_mode` records WHY it is in_progress
 * again, so WebsiteWizardController::generate() can tell an edit-existing
 * finish (reconcile canonical facts only, never regenerate pages) apart
 * from a genuine first-time completion (reconcile + generate + publish
 * the first draft).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questionnaire_responses', function (Blueprint $table): void {
            $table->boolean('edit_mode')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('questionnaire_responses', function (Blueprint $table): void {
            $table->dropColumn('edit_mode');
        });
    }
};

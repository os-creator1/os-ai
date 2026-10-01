<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forms V1 correction round 1 — the immutable page structure of a version.
 *
 * WHY A COLUMN, NOT A SECOND PRODUCT. Blueprint §16: "Questionnaires support
 * multi-page flows." A questionnaire is the SAME definition as a form with two
 * or more ordered pages; an ordinary form is one page. So the page structure
 * lives on `form_versions` beside `fields` (every field carries the key of the
 * one page it belongs to), written once and never edited with the rest of the
 * version, instead of a parallel Questionnaire domain.
 *
 * `pages` is the ordered list of `{key, title}`. It is NULLABLE on purpose:
 * a version written before this migration has no page structure and reads as ONE
 * implicit page holding every field (FormVersion::pages()), so nothing already
 * stored changes meaning. FormManager always writes it for new versions, and the
 * content hash covers it, so reordering pages is a real new version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_versions', function (Blueprint $table): void {
            $table->json('pages')->nullable()->after('success_message');
        });
    }

    public function down(): void
    {
        Schema::table('form_versions', function (Blueprint $table): void {
            $table->dropColumn('pages');
        });
    }
};

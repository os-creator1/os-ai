<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Independent-review correction round 3 — after a Website already has
 * generated pages, editing a presentation-only setup answer (FAQ,
 * custom section, gallery cover/order) through the wizard's "Edit setup
 * answers" flow previously updated only `questionnaire_responses.answers`
 * and `website_assets`, never the Website's own generated
 * `website_pages` rows — so the edit silently had no visible effect. A
 * full deterministic re-synchronization of generated content without a
 * new AI call is not safe to do narrowly for every one of these
 * surfaces (a gallery reorder alone, for instance, would need to rebind
 * every image_text slot the exact same way GuidedGenerationCommitService
 * does at generation time), so this column instead records the fact
 * plainly and requires the owner's own explicit, already-existing
 * "Regenerate"/"Rebuild" action to apply it — the narrowest change that
 * is honest about what did and did not happen.
 *
 * Set by WebsiteWizardController whenever a presentation-affecting
 * mutation (gallery upload/update/move/remove, custom-section upload/
 * remove/improve, or a `faq_items`/`custom_section` answer edit) occurs
 * on a Website that already has generated pages; cleared by
 * GuidedGenerationCommitService the moment a generation or rebuild
 * actually commits a fresh page batch — the one place real Website page
 * content changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->timestamp('presentation_changes_pending_at')->nullable()->after('gallery_page_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->dropColumn('presentation_changes_pending_at');
        });
    }
};

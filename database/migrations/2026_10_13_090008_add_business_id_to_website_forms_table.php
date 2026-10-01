<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Website Builder redesign — Forms must become a real, reusable canonical
 * Business-owned resource (per the brief: "Forms and questionnaires
 * created here must remain reusable elsewhere"), not something that only
 * exists via its parent Website.
 *
 * Minimal-change approach: `website_id` stays required and unchanged, so
 * every existing form-binding code path (MediaBindingService::
 * bindForms(), WebsiteSectionValidator's Website-scoped $validFormUids,
 * WebsiteStarterDraftService::ensurePhotoBoothQuoteForm()) needs zero
 * changes — a `form` section's `form_uid` still resolves exactly as
 * before. `business_id` is added alongside it and backfilled from the
 * owning Website, so a WebsiteForm can now also be looked up/listed by
 * Business directly (Website Studio's Forms tab, and any future
 * Automations reference) without joining through `websites`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Added plain first (no FK yet, still nullable) — MySQL refuses to
        // MODIFY a column that already participates in a foreign key
        // constraint (errno 1832), so the constraint is added last, once
        // the column is already fully backfilled and NOT NULL.
        Schema::table('website_forms', function (Blueprint $table): void {
            $table->unsignedBigInteger('business_id')->nullable()->after('website_id');
        });

        DB::statement(<<<'SQL'
            UPDATE website_forms
            INNER JOIN websites ON websites.id = website_forms.website_id
            SET website_forms.business_id = websites.business_id
        SQL);

        Schema::table('website_forms', function (Blueprint $table): void {
            $table->unsignedBigInteger('business_id')->nullable(false)->change();
        });

        Schema::table('website_forms', function (Blueprint $table): void {
            $table->index('business_id');
            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('website_forms', function (Blueprint $table): void {
            $table->dropForeign(['business_id']);
            $table->dropIndex(['business_id']);
            $table->dropColumn('business_id');
        });
    }
};

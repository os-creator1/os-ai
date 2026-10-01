<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Forms / Questionnaires V1 completion (Blueprint §16, Addendum §5/§13).
 *
 * `website_forms` stays THE form definition (Business-wide through its
 * Website, §14's one-website rule). It gains only the facts a definition
 * needs to turn a public submission into correct Location-bound CRM state:
 *
 *   - `location_id`        the Location a submission through this form is
 *                          bound to — "the form itself carries the Location"
 *                          (§16). NULL = not configured yet, and a form with
 *                          no Location accepts nothing (fail closed).
 *   - `is_active`          activate / deactivate without republishing.
 *   - `create_opportunity` + `crm_pipeline_id`  whether, and into which of
 *                          the Business's pipelines, a submission opens an
 *                          Opportunity (NULL pipeline = the Business's first
 *                          active one, the pre-existing behaviour).
 *
 * `website_form_submissions` gains:
 *
 *   - `location_id`        the operational Location, persisted so the Contact
 *                          and Opportunity inherit one proven fact, never a
 *                          guess. Nullable only for rows that predate this.
 *   - `idempotency_key`    the visitor-page token that makes a browser retry,
 *                          double-click or network retry the SAME logical
 *                          submission. UNIQUE per form (NULLs allowed: a
 *                          post with no token is its own submission).
 *   - `contact_resolution` created | matched | ambiguous | none — how the
 *                          Contact link was reached, for audit.
 *   - `page_uid`, `source_revision_id`  source attribution that survives
 *                          later page/definition edits (a home page has a
 *                          NULL slug, so the slug alone is not attribution).
 *
 * `dedupe_key` (a content hash) is retained but made nullable: it silently
 * dropped a legitimate repeat inquiry whose body happened to match, so it is
 * no longer written. Legacy rows keep their value.
 *
 * Backfill follows Contract 08B §5's single-Active-Location rule exactly as
 * the Contacts/Opportunities backfills do: a legacy form (and its
 * submissions) is attributed only when its Business has exactly ONE active
 * Location; otherwise it stays NULL for the owner to configure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_forms', function (Blueprint $table): void {
            $table->unsignedBigInteger('location_id')->nullable()->after('website_id');
            $table->boolean('is_active')->default(true)->after('submit_label');
            $table->boolean('create_opportunity')->default(true)->after('is_active');
            $table->unsignedBigInteger('crm_pipeline_id')->nullable()->after('create_opportunity');

            $table->foreign('location_id', 'website_forms_location_id_foreign')
                ->references('id')->on('business_locations')->restrictOnDelete();
            $table->foreign('crm_pipeline_id', 'website_forms_crm_pipeline_id_foreign')
                ->references('id')->on('crm_pipelines')->nullOnDelete();
            $table->unique(['website_id', 'type', 'location_id'], 'website_forms_website_type_location_unique');
        });

        Schema::table('website_form_submissions', function (Blueprint $table): void {
            $table->string('dedupe_key', 64)->nullable()->change();
            $table->unsignedBigInteger('location_id')->nullable()->after('website_form_id');
            $table->string('idempotency_key', 64)->nullable()->after('dedupe_key');
            $table->string('contact_resolution', 16)->nullable()->after('crm_opportunity_id');
            $table->uuid('page_uid')->nullable()->after('page_slug');
            $table->unsignedBigInteger('source_revision_id')->nullable()->after('page_uid');

            $table->foreign('location_id', 'website_form_submissions_location_id_foreign')
                ->references('id')->on('business_locations')->restrictOnDelete();
            $table->foreign('source_revision_id', 'website_form_submissions_source_revision_foreign')
                ->references('id')->on('website_revisions')->nullOnDelete();
            $table->unique(['website_form_id', 'idempotency_key'], 'website_form_submissions_idempotency_unique');
            $table->index(['location_id', 'created_at'], 'website_form_submissions_location_created_index');
        });

        $this->backfillSingleActiveLocation();
    }

    public function down(): void
    {
        Schema::table('website_form_submissions', function (Blueprint $table): void {
            $table->dropForeign('website_form_submissions_location_id_foreign');
            $table->dropForeign('website_form_submissions_source_revision_foreign');
            $table->dropUnique('website_form_submissions_idempotency_unique');
            $table->dropIndex('website_form_submissions_location_created_index');
            $table->dropColumn(['location_id', 'idempotency_key', 'contact_resolution', 'page_uid', 'source_revision_id']);
        });

        Schema::table('website_forms', function (Blueprint $table): void {
            $table->dropForeign('website_forms_location_id_foreign');
            $table->dropForeign('website_forms_crm_pipeline_id_foreign');
            $table->dropUnique('website_forms_website_type_location_unique');
            $table->dropColumn(['location_id', 'is_active', 'create_opportunity', 'crm_pipeline_id']);
        });
    }

    /**
     * Set-based copy of Contacts::singleActiveLocationIdFor() — the one
     * definition of "exactly one Active Location" — kept as its own copy for
     * the same reason ContactsLocationBackfillV1 is.
     */
    private function backfillSingleActiveLocation(): void
    {
        $single = DB::table('business_locations')
            ->where('lifecycle_state', 'active')
            ->groupBy('business_id')
            ->havingRaw('COUNT(*) = 1')
            ->selectRaw('business_id, MIN(id) as location_id');

        DB::table('website_forms')
            ->join('websites', 'websites.id', '=', 'website_forms.website_id')
            ->joinSub($single, 'single_location', 'single_location.business_id', '=', 'websites.business_id')
            ->whereNull('website_forms.location_id')
            ->update(['website_forms.location_id' => DB::raw('single_location.location_id')]);

        DB::table('website_form_submissions')
            ->join('website_forms', 'website_forms.id', '=', 'website_form_submissions.website_form_id')
            ->whereNull('website_form_submissions.location_id')
            ->whereNotNull('website_forms.location_id')
            ->update(['website_form_submissions.location_id' => DB::raw('website_forms.location_id')]);
    }
};

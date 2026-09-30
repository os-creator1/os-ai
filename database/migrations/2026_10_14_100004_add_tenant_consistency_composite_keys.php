<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Independent-review correction round — `questionnaire_responses` and
 * `website_forms` each independently reference `business_id` and a
 * Website, but nothing in the database proved the referenced Website
 * actually belongs to that same Business; only application-layer checks
 * did. Mirrors `questionnaire_versions`' own `(id, questionnaire_
 * definition_id)` composite-unique-plus-composite-foreign-key shape
 * (create_questionnaire_versions_table migration): `websites` gains a
 * trivially-unique `(id, business_id)` index (unique because `id` alone
 * already is), and each child's single-column `website_id` foreign key
 * is replaced with a composite `(website_id, business_id) references
 * websites (id, business_id)` — so a row whose `business_id` does not
 * match its own `website_id`'s Website can never be inserted or updated,
 * not only rejected by a service-layer re-check.
 *
 * `cascadeOnDelete()` on both composite keys, matching (or replacing)
 * the single-column FKs' own previous delete behavior:
 * `website_forms.website_id` was already `cascadeOnDelete()` — a real
 * test (WebsiteFormSubmissionConcurrencyTest, which manages its own
 * fixtures outside RefreshDatabase and deletes its Website directly in
 * tearDown()) depends on that. `questionnaire_responses.website_id` was
 * `nullOnDelete()`, but a composite FK cannot honor that against this
 * table's NOT NULL `business_id` column — cascading its own setup-
 * response history away when its Website is deleted is the closest
 * sound equivalent, and nothing in this codebase deletes a Website while
 * expecting its questionnaire responses to survive with a null website_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->unique(['id', 'business_id'], 'websites_id_business_id_unique');
        });

        Schema::table('questionnaire_responses', function (Blueprint $table): void {
            $table->dropForeign('questionnaire_responses_website_id_foreign');
            $table->index(['website_id', 'business_id'], 'qr_website_business_index');
            $table->foreign(['website_id', 'business_id'], 'qr_website_business_foreign')
                ->references(['id', 'business_id'])->on('websites')->cascadeOnDelete();
        });

        Schema::table('website_forms', function (Blueprint $table): void {
            $table->dropForeign('website_forms_website_id_foreign');
            $table->index(['website_id', 'business_id'], 'wf_website_business_index');
            $table->foreign(['website_id', 'business_id'], 'wf_website_business_foreign')
                ->references(['id', 'business_id'])->on('websites')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('website_forms', function (Blueprint $table): void {
            $table->dropForeign('wf_website_business_foreign');
            $table->dropIndex('wf_website_business_index');
            $table->foreign('website_id')->references('id')->on('websites')->cascadeOnDelete();
        });

        Schema::table('questionnaire_responses', function (Blueprint $table): void {
            $table->dropForeign('qr_website_business_foreign');
            $table->dropIndex('qr_website_business_index');
            $table->foreign('website_id')->references('id')->on('websites')->nullOnDelete();
        });

        Schema::table('websites', function (Blueprint $table): void {
            $table->dropUnique('websites_id_business_id_unique');
        });
    }
};

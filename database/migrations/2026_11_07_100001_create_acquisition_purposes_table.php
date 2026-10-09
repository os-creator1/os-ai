<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Acquisition Purpose V1 — the one Business-level row that joins an ad
 * campaign to what the Business is actually trying to win:
 *
 *   campaign -> landing destination -> Form -> CRM pipeline -> outcome -> economics
 *
 * WHY A TABLE. The relationship is Business truth, not provider truth: the
 * same "Student Enrollment" goal can be fed by Google and by Meta, and a
 * tutoring Business has a second, separate "Teacher Recruitment" goal whose
 * results must never be averaged with it. Nothing here duplicates a CRM
 * pipeline, a Form or a Website page — the row only REFERENCES them
 * (nullable foreign keys, set null when the referenced record is deleted so a
 * purpose is never silently destroyed with its pipeline).
 *
 * WHY NO COLUMN PER NICHE NUMBER. A purpose must work for tutoring today and
 * for "wedding bookings" or "employees" tomorrow without a migration, so the
 * Business's answers live in the bounded `economics` JSON (keyed by the
 * question keys the purpose's calculator declares), while the question
 * schema, labels, guidance and website intent are copied from the installed
 * Blueprint component into JSON (`question_schema`, `labels`, `guidance`,
 * `website_intent`) so a later Blueprint edit never rewrites a Business's
 * own setup. `purpose_key` is the stable identity inside a Business. Blueprint
 * provenance is NOT duplicated here: it is the installation record
 * (business_blueprint_component_installations, installed_record_type
 * 'acquisition_purpose'), which already carries blueprint, component and version.
 *
 * `outcome_type` is the generic noun of the Business outcome the purpose
 * ends in (student, hire, customer, booking...). `calculator_key` names the
 * code-owned economics calculator; the Blueprint chooses which one applies,
 * code owns every formula.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acquisition_purposes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->unsignedBigInteger('business_id');
            $table->string('purpose_key', 64);
            $table->string('name', 120);
            $table->string('outcome_type', 32);
            $table->string('calculator_key', 48);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->unsignedBigInteger('crm_pipeline_id')->nullable();
            $table->unsignedBigInteger('form_id')->nullable();
            $table->string('destination_type', 16)->default('none');
            $table->unsignedBigInteger('destination_page_id')->nullable();
            $table->string('destination_url', 2048)->nullable();

            $table->json('labels')->nullable();
            $table->json('question_schema')->nullable();
            $table->json('economics')->nullable();
            $table->json('guidance')->nullable();
            $table->json('website_intent')->nullable();

            $table->timestamps();

            $table->foreign('business_id', 'acqp_business_fk')->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign('crm_pipeline_id', 'acqp_pipeline_fk')->references('id')->on('crm_pipelines')->nullOnDelete();
            $table->foreign('form_id', 'acqp_form_fk')->references('id')->on('forms')->nullOnDelete();
            $table->foreign('destination_page_id', 'acqp_page_fk')->references('id')->on('website_pages')->nullOnDelete();

            $table->unique(['business_id', 'purpose_key'], 'acqp_business_key_unique');
            $table->index(['business_id', 'is_active', 'sort_order'], 'acqp_business_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acquisition_purposes');
    }
};

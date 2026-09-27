<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One real visitor inquiry. This is the record a business can always
 * find, independent of whether a Contact or CrmOpportunity could also be
 * created for it (a Business with no CRM pipeline yet still keeps every
 * submission here).
 *
 * `dedupe_key` + `created_at` is how a duplicate double-submit (a
 * refreshed confirmation page, a doubled tap) is recognised and refused
 * without a second row ever being written — see
 * WebsiteFormSubmissionService. `ip_hash` never stores a raw IP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_form_submissions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('website_form_id')->constrained('website_forms')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('crm_opportunity_id')->nullable()->constrained('crm_opportunities')->nullOnDelete();
            $table->string('page_slug', 80)->nullable();
            $table->json('data');
            $table->string('dedupe_key', 64);
            $table->string('ip_hash', 64)->nullable();
            $table->boolean('is_spam')->default(false);
            $table->string('status', 16)->default('new');
            $table->timestamps();

            $table->index(['website_form_id', 'dedupe_key', 'created_at'], 'website_form_submissions_dedupe_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_form_submissions');
    }
};

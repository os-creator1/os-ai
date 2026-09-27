<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The smallest reusable Forms foundation: one configurable form per
 * Website (v1 ships exactly one preset — the Photo Booth quote request —
 * but `fields` is a generic, code-validated JSON config so a future
 * preset for another vertical reuses this same table and pipeline,
 * never a Photo-Booth-only shape).
 *
 * No `business_id` column, matching every other Website-owned table
 * (`website_pages`, `website_assets`): tenancy is reached by joining
 * through `websites.business_id`, never duplicated here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_forms', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->string('type', 32)->default('quote_request');
            $table->string('name', 120);
            $table->json('fields');
            $table->string('submit_label', 40)->default('Send');
            $table->timestamps();

            $table->index('website_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_forms');
    }
};

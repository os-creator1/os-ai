<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM Opportunities — a Business's sales pipelines.
 *
 * DISTINCT FROM `opportunities`. That table belongs to the AI COO / Business
 * Advisor recommendation engine ("an opportunity we noticed for you"). This is
 * the CRM sales domain ("a deal with a contact"), so every table here carries the
 * `crm_` prefix and nothing references or alters the recommendation schema.
 *
 * A pipeline is always owned by exactly one Business. When it came from a
 * Business Template, `template_key`, `template_version` and
 * `template_pipeline_key` record WHICH snapshot it was copied from. They are
 * provenance only: the rows below are the Business's own copy, and a later
 * change to the template never reaches back into them.
 *
 * Pipelines are archived, never hard-deleted, so won and lost deals keep the
 * pipeline they were closed in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_pipelines', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('template_key', 64)->nullable();
            $table->unsignedInteger('template_version')->nullable();
            $table->string('template_pipeline_key', 64)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            // The selector: this Business's active pipelines, in order.
            $table->index(['business_id', 'archived_at', 'position'], 'crm_pipelines_selector_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_pipelines');
    }
};

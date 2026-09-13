<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM Opportunities — the stages (board columns) of one pipeline.
 *
 * `name` is what the customer reads and may change at any time. `semantic_key`
 * is what the product means by a stage, and never changes with the label:
 * every standard pipeline starts with `new_inquiry`, so a future template
 * automation ("follow up a new inquiry") or form ("create the deal at the start
 * of the pipeline") finds that stage whatever the Business has renamed it to.
 * A stage the customer adds has no semantic key. Unique per pipeline; MySQL
 * allows many NULLs under the unique index.
 *
 * `business_id` is repeated from the pipeline on purpose: every tenancy check
 * on a stage is one indexed equality, and a stage can never be attached to an
 * opportunity of another Business without a mismatch being visible in one row.
 *
 * Stages are archived, never hard-deleted: a won or lost deal keeps the stage it
 * closed in, and its history keeps pointing at a real row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_pipeline_stages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('pipeline_id')->constrained('crm_pipelines')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('semantic_key', 64)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['pipeline_id', 'semantic_key'], 'crm_pipeline_stages_semantic_unique');

            // The board: this pipeline's active columns, in order.
            $table->index(['pipeline_id', 'archived_at', 'position'], 'crm_pipeline_stages_board_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_pipeline_stages');
    }
};

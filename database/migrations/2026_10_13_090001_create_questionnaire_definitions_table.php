<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website Builder redesign — Niche Builder foundation, migration 1 of 3.
 *
 * The stable parent identity a definition's versions hang off, exactly
 * like `automation_workflows` is to `automation_workflow_versions`. A
 * platform-seeded niche questionnaire (e.g. Photobooth's website setup)
 * has `business_id` NULL; a future business-authored questionnaire (out
 * of scope for this pass, but the schema is shaped to allow it without a
 * later migration) would set it. `key` is the durable identity a caller
 * resolves by (e.g. 'photobooth_website_setup'), never the numeric id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questionnaire_definitions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->string('key', 191)->unique();
            // website_niche_setup | business_custom
            $table->string('scope', 24)->default('website_niche_setup');
            $table->string('niche_key', 64)->nullable();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->restrictOnDelete();
            $table->string('name', 160);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['scope', 'niche_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questionnaire_definitions');
    }
};

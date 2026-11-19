<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner-controlled website tracking (Website → Tracking). One row per website per scope:
 * `*` is the site-wide setting every published page inherits; every other `scope_key` is a stable page identity
 * (`home`, the page's slug, or `@blog` for the native blog index and articles). The key is a page ADDRESS identity, not a
 * page row id, so settings survive draft edits, regeneration (which re-creates page rows) and publishing.
 * Tracking values are validated identifiers (never AI-written); `head_snippet` is trusted-owner code.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('website_tracking_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->string('scope_key', 120);
            $table->boolean('is_enabled')->default(true);
            $table->boolean('require_consent')->default(true);
            $table->string('google_tag_id', 32)->nullable();
            $table->string('google_ads_id', 32)->nullable();
            $table->string('google_ads_lead_label', 64)->nullable();
            $table->string('meta_pixel_id', 24)->nullable();
            $table->text('head_snippet')->nullable();
            $table->timestamps();

            $table->unique(['website_id', 'scope_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_tracking_settings');
    }
};

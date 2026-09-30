<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Independent-review correction round — the wizard's template step must
 * offer only templates that fit the Business's resolved niche, never
 * every active template regardless of vertical. The minimal durable
 * mapping this needs: one nullable `niche_key` column on the existing,
 * already-canonical `website_templates` table (matching
 * `QuestionnaireDefinition.niche_key`'s own values) — no new table is
 * justified for a single string tag. NULL means "generic, offered
 * regardless of niche" (there are none of these yet, but the column
 * stays nullable so a future non-niche-specific template does not need a
 * schema change); WebsiteTemplateSeeder backfills all four existing,
 * literally Photobooth-branded templates to 'photo_booth_service'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_templates', function (Blueprint $table): void {
            $table->string('niche_key', 60)->nullable()->after('key');
            $table->index('niche_key');
        });
    }

    public function down(): void
    {
        Schema::table('website_templates', function (Blueprint $table): void {
            $table->dropIndex(['niche_key']);
            $table->dropColumn('niche_key');
        });
    }
};

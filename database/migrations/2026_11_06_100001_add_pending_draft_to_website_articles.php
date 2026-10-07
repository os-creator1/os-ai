<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEO Content Engine V1 (release closure) — a Published article is never changed by Save. Edits to a published
 * article are held here, on the same row, as a small draft of the editable fields. The public renderer never
 * reads it; "Publish update" copies it over the live columns (and records the old slug for a redirect) in one
 * deliberate step. One pending draft per article — this is not a revision history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_articles', function (Blueprint $table): void {
            $table->json('draft_payload')->nullable()->after('referenced_catalog_uids');
            $table->dateTime('draft_saved_at')->nullable()->after('last_reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('website_articles', function (Blueprint $table): void {
            $table->dropColumn(['draft_payload', 'draft_saved_at']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nullable so every existing Website (created before the template
 * system existed) remains valid with no backfill required — a null
 * template_key means "starter-design theme only, no catalog template,"
 * exactly today's pre-existing behavior. Never a foreign key to
 * `website_templates.id`: the catalog is keyed by its own stable `key`
 * string (a template may be retired/deactivated without ever deleting
 * the row a live Website still references), so referential integrity is
 * enforced in application code (WebsiteTemplate::findActiveOrFail()),
 * mirroring the same nullable-string-reference pattern already used by
 * `question_packs.applies_to_vertical_key` -> `business_verticals.key`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->string('template_key', 40)->nullable()->after('theme');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn('template_key');
        });
    }
};

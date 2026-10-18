<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forms visual builder — the lightweight Form Style of a version.
 *
 * WHY A COLUMN ON THE VERSION. How a form LOOKED when a visitor answered it is
 * part of what that submission means (its button, its width, its accent), and a
 * form version is the immutable statement of exactly that. So the style lives on
 * `form_versions` beside `pages` / `fields`, is covered by the content hash, and
 * an older response still renders against the style it was answered under. A
 * separate mutable style table would let an edit rewrite history.
 *
 * `design` is a small closed object (accent, background, button_align, radius,
 * width — see FormDefinitionNormalizer::design()), NEVER free CSS. It is NULLABLE:
 * every version written before this migration, and every version that never set a
 * style, reads as the platform default, so nothing already stored changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_versions', function (Blueprint $table): void {
            $table->json('design')->nullable()->after('pages');
        });
    }

    public function down(): void
    {
        Schema::table('form_versions', function (Blueprint $table): void {
            $table->dropColumn('design');
        });
    }
};

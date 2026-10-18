<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 17B §6b — a stable identity for platform templates
 * that the platform ships itself (the Photo Booth set).
 *
 * WHY. `documents:seed-photo-booth-templates` must be re-runnable: running it
 * twice has to leave ONE row per shipped template, never a duplicate, and must
 * never overwrite a template a Platform Owner has since renamed or edited. A
 * `name` is mutable and not unique, and `uid` is generated per row, so the
 * seed needs its own immutable key. NULL for every template a person created
 * (Business templates, and platform templates made in the admin editor); the
 * UNIQUE index permits any number of NULLs and refuses a second row for a key.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table): void {
            $table->string('seed_key', 64)->nullable()->after('uid');
            $table->unique('seed_key', 'document_templates_seed_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table): void {
            $table->dropUnique('document_templates_seed_key_unique');
            $table->dropColumn('seed_key');
        });
    }
};

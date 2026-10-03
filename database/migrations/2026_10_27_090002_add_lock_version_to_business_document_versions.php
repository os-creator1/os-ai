<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 17B §5 — optimistic concurrency for the visual
 * editor's debounced autosave.
 *
 * Two tabs (or a tab and a stale autosave) must never silently overwrite one
 * another's draft. Every draft mutation carries the caller's
 * `expected_lock_version` and bumps this counter in a conditional update;
 * a mismatch is a 409. It is meaningful for DRAFT versions only and is NOT
 * part of the content hash (the hasher reads named fields, not this column).
 * Existing rows start at 1.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('business_document_versions', function (Blueprint $table): void {
            $table->unsignedInteger('lock_version')->default(1)->after('schema_version');
        });
    }

    public function down(): void
    {
        Schema::table('business_document_versions', function (Blueprint $table): void {
            $table->dropColumn('lock_version');
        });
    }
};

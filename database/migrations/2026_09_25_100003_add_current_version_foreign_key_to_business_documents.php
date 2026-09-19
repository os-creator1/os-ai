<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 17 §5.3.3, Sub-slice A — step 3 of the staged
 * circular-FK DDL: add business_documents.current_version_id -> versions.
 *
 * MUST run after both 2026_09_25_100001 (which created the scalar column
 * without an FK) and 2026_09_25_100002 (which created the referenced table).
 * nullOnDelete, per §5.3.3. The down() path drops this FK before
 * business_document_versions can be dropped (step 4): migrations roll back in
 * reverse order, so this one is undone before 100002 is.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('business_documents', function (Blueprint $table) {
            $table->foreign('current_version_id', 'bd_current_version_foreign')
                ->references('id')->on('business_document_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('business_documents', function (Blueprint $table) {
            $table->dropForeign('bd_current_version_foreign');
        });
    }
};

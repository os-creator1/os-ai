<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A snapshot is the text of a document AS DISPLAYED with one company revision. Some documents do not contain the
 * registered office in their body, so (slug, version, content hash) alone would let a later acceptance reuse a
 * snapshot that belongs to an earlier company revision. The company revision joins the identity. Existing
 * snapshots are untouched; the wider unique key is satisfied by every row that already satisfies the old one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_document_snapshots', function (Blueprint $table): void {
            $table->unique(['slug', 'version', 'content_hash', 'company_profile_id'], 'legal_snapshots_identity_unique');
        });

        Schema::table('legal_document_snapshots', function (Blueprint $table): void {
            $table->dropUnique(['slug', 'version', 'content_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('legal_document_snapshots', function (Blueprint $table): void {
            $table->unique(['slug', 'version', 'content_hash']);
        });

        Schema::table('legal_document_snapshots', function (Blueprint $table): void {
            $table->dropUnique('legal_snapshots_identity_unique');
        });
    }
};

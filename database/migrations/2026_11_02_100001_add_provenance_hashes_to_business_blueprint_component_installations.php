<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Niche Blueprint V2 — what "an update is available" and "the owner changed
 * it" are decided FROM. Two hashes, both written only by the installer:
 *
 *   source_checksum        sha256 of the component descriptor (payload) the
 *                          Business was provisioned from. A newer published
 *                          version whose descriptor hashes differently means
 *                          "update available" — matched by
 *                          (blueprint_id, component_key), never by a mutable
 *                          name.
 *   installed_fingerprint  adapter-defined fingerprint of the Business-owned
 *                          row(s) immediately after install. A later
 *                          fingerprint that differs means the owner customised
 *                          the copy, so no update may replace it silently.
 *
 * Both nullable: rows written before V2 have neither and are reported as
 * "unknown", never as modified or current.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_blueprint_component_installations', function (Blueprint $table): void {
            $table->char('source_checksum', 64)->nullable()->after('installed_record_id');
            $table->char('installed_fingerprint', 64)->nullable()->after('source_checksum');
        });
    }

    public function down(): void
    {
        Schema::table('business_blueprint_component_installations', function (Blueprint $table): void {
            $table->dropColumn(['source_checksum', 'installed_fingerprint']);
        });
    }
};

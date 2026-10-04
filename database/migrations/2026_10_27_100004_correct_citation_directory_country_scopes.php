<?php

use Database\Seeders\SeoCitationDirectorySeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Citations V1 closure — country applicability now filters the offered list, so
 * the shipped country_scope values were re-checked against the verification
 * evidence in Contract 23:
 *  - WeddingWire & The Knot: US -> NULL (the evidence only says "US focus" and
 *    WeddingWire runs other countries' sites; the WeddingPro claim page is not
 *    country-specific, so no restriction is proven).
 *  - Bark: NULL -> US (the verified claim path is Bark's US site, /en/us/...).
 * The data lives in the seeder; this migration re-applies it for environments
 * that already ran 2026_10_27_100003. Idempotent; touches catalog fields only.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new SeoCitationDirectorySeeder())->run();
    }

    public function down(): void
    {
        // Reference data; nothing to undo.
    }
};

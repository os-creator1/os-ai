<?php

use Database\Seeders\SeoCitationDirectorySeeder;
use Database\Seeders\SeoNicheCitationRecommendationSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Citations V1 — ships the verified catalog and the Photo Booth niche
 * recommendations with the schema so a deployed environment has them without
 * anyone running `db:seed`. The data lives in ONE place each (the two
 * seeders); this migration only invokes them. Both are idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new SeoCitationDirectorySeeder())->run();
        (new SeoNicheCitationRecommendationSeeder())->run();
    }

    public function down(): void
    {
        // Reference data goes with its tables.
    }
};

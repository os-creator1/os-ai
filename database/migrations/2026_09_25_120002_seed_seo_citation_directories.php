<?php

use Database\Seeders\SeoCitationDirectorySeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Implementation Contract 18 §8.5, Sub-slice E — ships the verified directory
 * reference rows with the schema, so a deployed environment has them without
 * anyone running `db:seed`. The data lives in ONE place
 * (SeoCitationDirectorySeeder::DIRECTORIES); this migration only invokes it.
 * The seeder is idempotent (keyed on `key`), so re-running never duplicates.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new SeoCitationDirectorySeeder())->run();
    }

    public function down(): void
    {
        // Reference data is removed with its table by the create migration's
        // down(); nothing to undo here.
    }
};

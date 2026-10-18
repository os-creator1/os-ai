<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Implementation Contract 18 §8.5, Sub-slice E — ships the verified directory
 * reference rows with the schema, so a deployed environment has them without
 * anyone running `db:seed`.
 *
 * FROZEN AT THE TWO ORIGINAL ROWS. This migration used to call
 * SeoCitationDirectorySeeder, but that seeder evolves with the catalog
 * (Citations V1 added columns this migration's moment in history does not
 * have yet). A migration must only know the schema of its own time, so the
 * original two rows live here, verbatim and idempotent (keyed on `key`). The
 * current catalog is shipped by 2026_10_27_100003, after the columns exist.
 */
return new class extends Migration
{
    private const DIRECTORIES = [
        ['key' => 'bing_places', 'name' => 'Bing Places for Business', 'claim_url' => 'https://www.bing.com/forbusiness/management', 'country_scope' => null, 'sort_order' => 10],
        ['key' => 'facebook_pages', 'name' => 'Facebook Business Page', 'claim_url' => 'https://www.facebook.com/pages/create', 'country_scope' => null, 'sort_order' => 20],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::DIRECTORIES as $directory) {
            $existing = DB::table('seo_citation_directories')->where('key', $directory['key'])->first();

            if ($existing !== null) {
                DB::table('seo_citation_directories')->where('id', $existing->id)->update($directory + ['updated_at' => $now]);

                continue;
            }

            DB::table('seo_citation_directories')->insert($directory + [
                'uid' => (string) Str::uuid(),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Reference data is removed with its table by the create migration's
        // down(); nothing to undo here.
    }
};

<?php

namespace Tests\Feature\Website\Gallery;

use App\Enums\Website\WebsiteAssetPurpose;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Independent-review correction round 3 (item 8) — the round-2 migration
 * that added `alt_text_is_custom` defaulted EVERY existing row to false,
 * including rows whose alt_text was typed by a human through the
 * pre-wizard `WebsiteController::storeAsset()` form. Left at false, a
 * later wizard metadata edit (WebsiteGalleryManager::
 * setTitleAndCategory()) would silently regenerate and overwrite that
 * historical text. The
 * 2026_10_20_090001_backfill_legacy_alt_text_as_custom migration fixes
 * this for data that already existed before it ran.
 *
 * Runs the migration's own up() directly against rows inserted to
 * simulate "already existed before this migration" — RefreshDatabase has
 * already run every migration (including this one) against an empty
 * table by the time a normal test method starts, so simply creating a
 * row afterward would never exercise the backfill; this test re-invokes
 * the migration file's own up() method a second time, against rows that
 * exist ONLY at that point, to prove its actual logic.
 */
class LegacyAltTextBackfillMigrationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    private const MIGRATION_PATH = __DIR__ . '/../../../../database/migrations/2026_10_20_090001_backfill_legacy_alt_text_as_custom.php';

    public function test_a_legacy_nonblank_alt_text_row_is_backfilled_as_custom(): void
    {
        [, $business] = $this->entitledTenant();
        $website = Website::create(['business_id' => $business->id, 'name' => 'Legacy Site', 'status' => 'draft']);

        // Simulate a row that existed BEFORE alt_text_is_custom's own
        // default ever applied intentionally — a human-authored alt text
        // from the pre-wizard storeAsset() upload path, explicitly
        // forced to false here to represent the round-2 migration's own
        // blanket default.
        $legacyAssetId = DB::table('website_assets')->insertGetId([
            'uid' => (string) \Illuminate\Support\Str::uuid(),
            'website_id' => $website->id,
            'disk' => 'public',
            'path' => 'images/websites/legacy/human.png',
            'mime_type' => 'image/png',
            'size' => 100,
            'alt_text' => 'A hand-typed caption a real person wrote',
            'alt_text_is_custom' => false,
            'purpose' => WebsiteAssetPurpose::Gallery->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $blankAssetId = DB::table('website_assets')->insertGetId([
            'uid' => (string) \Illuminate\Support\Str::uuid(),
            'website_id' => $website->id,
            'disk' => 'public',
            'path' => 'images/websites/legacy/blank.png',
            'mime_type' => 'image/png',
            'size' => 100,
            'alt_text' => null,
            'alt_text_is_custom' => false,
            'purpose' => WebsiteAssetPurpose::Gallery->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (require self::MIGRATION_PATH)->up();

        $this->assertTrue((bool) DB::table('website_assets')->where('id', $legacyAssetId)->value('alt_text_is_custom'), 'A pre-existing nonblank alt text must be protected as custom.');
        $this->assertFalse((bool) DB::table('website_assets')->where('id', $blankAssetId)->value('alt_text_is_custom'), 'A blank alt text has nothing to protect and must be left alone.');
    }

    public function test_an_already_custom_row_is_left_unchanged(): void
    {
        [, $business] = $this->entitledTenant();
        $website = Website::create(['business_id' => $business->id, 'name' => 'Legacy Site', 'status' => 'draft']);

        $assetId = DB::table('website_assets')->insertGetId([
            'uid' => (string) \Illuminate\Support\Str::uuid(),
            'website_id' => $website->id,
            'disk' => 'public',
            'path' => 'images/websites/legacy/already-custom.png',
            'mime_type' => 'image/png',
            'size' => 100,
            'alt_text' => 'Already marked custom',
            'alt_text_is_custom' => true,
            'purpose' => WebsiteAssetPurpose::Gallery->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (require self::MIGRATION_PATH)->up();

        $this->assertTrue((bool) DB::table('website_assets')->where('id', $assetId)->value('alt_text_is_custom'));
    }
}

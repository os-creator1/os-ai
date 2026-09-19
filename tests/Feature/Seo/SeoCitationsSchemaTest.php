<?php

namespace Tests\Feature\Seo;

use App\Library\Seo\SeoLinkSafety;
use App\Models\SeoCitation;
use Database\Seeders\SeoCitationDirectorySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Seo\Concerns\CreatesSeoCitationFixtures;
use Tests\TestCase;

/**
 * Contract 18 §8.5 / §15.E — the citation schema exactly as contracted, the
 * database's own uniqueness and Business-consistency guarantees, and the
 * seeded directory reference data.
 */
class SeoCitationsSchemaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesSeoCitationFixtures;

    // -----------------------------------------------------------------
    // Columns
    // -----------------------------------------------------------------

    public function test_seo_citations_has_exactly_the_contracted_columns(): void
    {
        $this->assertSame([
            'id', 'uid', 'business_id', 'business_location_id', 'seo_citation_directory_id', 'status',
            'listing_url', 'listed_name', 'listed_phone', 'listed_address', 'last_verified_at',
            'verification_source', 'notes', 'updated_by_user_id', 'created_at', 'updated_at',
        ], Schema::getColumnListing('seo_citations'));
    }

    public function test_no_nap_comparison_result_is_ever_stored(): void
    {
        // §8.5 — the comparison is computed at read time and never persisted:
        // there is no comparison column, and no comparison table.
        foreach (Schema::getColumnListing('seo_citations') as $column) {
            $this->assertDoesNotMatchRegularExpression('/consisten|mismatch|nap_|comparison|score/i', $column);
        }

        foreach (Schema::getTableListing() as $table) {
            $this->assertDoesNotMatchRegularExpression('/^seo_.*(nap|comparison)/i', $table);
        }
    }

    public function test_column_types_lengths_and_nullability(): void
    {
        $columns = collect(DB::select('SHOW COLUMNS FROM seo_citations'))->keyBy('Field');

        $this->assertSame('varchar(2048)', $columns['listing_url']->Type);
        $this->assertSame('varchar(500)', $columns['notes']->Type);
        $this->assertSame('date', $columns['last_verified_at']->Type, 'last_verified_at is a user-asserted DATE, not a timestamp.');
        $this->assertSame('varchar(24)', $columns['status']->Type);
        $this->assertSame('not_started', $columns['status']->Default);
        $this->assertSame('user_asserted', $columns['verification_source']->Default);
        $this->assertSame('NO', $columns['business_location_id']->Null, 'business_location_id is NOT NULL.');
        $this->assertSame('NO', $columns['business_id']->Null);
        $this->assertSame('YES', $columns['listed_address']->Null);
        $this->assertSame('YES', $columns['listing_url']->Null);
    }

    public function test_directory_table_has_exactly_the_contracted_columns(): void
    {
        $this->assertSame([
            'id', 'uid', 'key', 'name', 'claim_url', 'country_scope', 'is_active', 'sort_order', 'created_at', 'updated_at',
        ], Schema::getColumnListing('seo_citation_directories'));

        // Global reference data: it belongs to no Business.
        $this->assertFalse(Schema::hasColumn('seo_citation_directories', 'business_id'));
    }

    // -----------------------------------------------------------------
    // Uniqueness and consistency, enforced by the database
    // -----------------------------------------------------------------

    public function test_the_database_refuses_a_second_citation_for_the_same_location_and_directory(): void
    {
        [, $business] = $this->growthTenantWithLocation();
        $location = $this->publicStorefront($business);

        $this->makeCitation($business, $location);

        $this->expectException(QueryException::class);

        try {
            $this->makeCitation($business, $location);
        } catch (QueryException $e) {
            $this->assertStringContainsString('seo_citations_location_directory_unique', $e->getMessage());

            throw $e;
        }
    }

    public function test_the_same_directory_is_allowed_at_a_different_location_and_a_different_directory_at_the_same_location(): void
    {
        [, $business] = $this->growthTenantWithLocation();
        $one = $this->publicStorefront($business, 'One');
        $two = $this->publicStorefront($business, 'Two');

        $this->makeCitation($business, $one, $this->directory('bing_places'));
        $this->makeCitation($business, $two, $this->directory('bing_places'));
        $this->makeCitation($business, $one, $this->directory('facebook_pages'));

        $this->assertSame(3, SeoCitation::query()->count());
    }

    public function test_the_composite_foreign_key_refuses_a_location_paired_with_another_business(): void
    {
        [, $business] = $this->growthTenantWithLocation();
        [, $otherBusiness] = $this->growthTenantWithLocation();
        $foreignLocation = $this->publicStorefront($otherBusiness);

        $this->expectException(QueryException::class);

        try {
            $this->makeCitation($business, $foreignLocation);
        } catch (QueryException $e) {
            $this->assertStringContainsString('seo_citations_location_business_foreign', $e->getMessage());

            throw $e;
        }
    }

    public function test_a_location_with_citations_cannot_be_deleted(): void
    {
        // Location lifecycle is archive, never delete (contract §8 preamble).
        [, $business] = $this->growthTenantWithLocation();
        $location = $this->publicStorefront($business);
        $this->makeCitation($business, $location);

        $this->expectException(QueryException::class);

        DB::table('business_locations')->where('id', $location->id)->delete();
    }

    public function test_the_uid_is_a_uuid_and_unique(): void
    {
        [, $business] = $this->growthTenantWithLocation();
        $one = $this->makeCitation($business, $this->publicStorefront($business, 'A'));
        $two = $this->makeCitation($business, $this->publicStorefront($business, 'B'));

        $this->assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $one->uid);
        $this->assertNotSame($one->uid, $two->uid);
    }

    // -----------------------------------------------------------------
    // Seeded directories (Contract §8.5 seeding rule / OD-3)
    // -----------------------------------------------------------------

    public function test_the_seed_migration_provides_the_directories_without_running_db_seed(): void
    {
        $keys = DB::table('seo_citation_directories')->orderBy('sort_order')->pluck('key')->all();

        $this->assertSame(array_column(SeoCitationDirectorySeeder::DIRECTORIES, 'key'), $keys);
    }

    public function test_at_most_ten_directories_and_google_business_profile_is_not_one(): void
    {
        $this->assertLessThanOrEqual(10, count(SeoCitationDirectorySeeder::DIRECTORIES));

        foreach (SeoCitationDirectorySeeder::DIRECTORIES as $directory) {
            $this->assertStringNotContainsStringIgnoringCase('google', $directory['key'] . $directory['name']);
        }
    }

    public function test_every_seeded_claim_url_is_a_safe_https_url_with_no_tracking_or_affiliate_parameters(): void
    {
        foreach (DB::table('seo_citation_directories')->get() as $directory) {
            $this->assertNotNull(SeoLinkSafety::safeHttpsUrl($directory->claim_url), "[{$directory->key}] claim_url must be a safe https URL.");
            $this->assertNull(parse_url($directory->claim_url, PHP_URL_QUERY), "[{$directory->key}] claim_url must carry no query string.");
            $this->assertNull(parse_url($directory->claim_url, PHP_URL_FRAGMENT));
            $this->assertDoesNotMatchRegularExpression('/utm_|ref=|affiliate|aff_|tracking|clickid/i', $directory->claim_url);
            $this->assertSame(1, (int) $directory->is_active);
        }
    }

    public function test_directory_keys_are_unique_and_stable_slugs(): void
    {
        $keys = array_column(SeoCitationDirectorySeeder::DIRECTORIES, 'key');

        $this->assertSame(count($keys), count(array_unique($keys)));

        foreach ($keys as $key) {
            $this->assertMatchesRegularExpression('/\A[a-z][a-z0-9_]{1,63}\z/', $key);
        }

        $this->expectException(QueryException::class);

        DB::table('seo_citation_directories')->insert([
            'uid' => (string) \Illuminate\Support\Str::uuid(), 'key' => $keys[0], 'name' => 'Duplicate',
            'claim_url' => 'https://example.test/', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_seeder_is_idempotent_and_never_changes_a_uid(): void
    {
        $before = DB::table('seo_citation_directories')->orderBy('id')->get(['id', 'uid', 'key'])->toArray();

        (new SeoCitationDirectorySeeder())->run();
        (new SeoCitationDirectorySeeder())->run();

        $this->assertEquals($before, DB::table('seo_citation_directories')->orderBy('id')->get(['id', 'uid', 'key'])->toArray());
    }

    public function test_customers_have_no_write_path_to_the_directory_model(): void
    {
        $directory = $this->directory();

        // Fully guarded: mass assignment from a request can set nothing, and
        // says so loudly rather than silently discarding.
        try {
            $directory->fill(['name' => 'Hijacked', 'claim_url' => 'https://evil.example/']);
            $this->fail('Mass assignment to the reference table must be refused.');
        } catch (\Illuminate\Database\Eloquent\MassAssignmentException) {
            $this->assertNotSame('Hijacked', $directory->name);
            $this->assertNotSame('https://evil.example/', $directory->claim_url);
        }
    }
}

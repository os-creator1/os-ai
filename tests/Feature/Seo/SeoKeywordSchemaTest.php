<?php

namespace Tests\Feature\Seo;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Seo\SeoKeywordLifecycleState;
use App\Models\Keywords;
use App\Models\SeoKeyword;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Seo\Concerns\CreatesSeoFixtures;
use Tests\TestCase;

/**
 * Contract 18 §8.4 — the database itself enforces the keyword invariants: the
 * generated location_key and the unique backstop across lifecycle states, the
 * composite Business/Location FK, restrict-on-delete, defaults, and the
 * model's mass-assignment boundary. None of this depends on application code.
 */
class SeoKeywordSchemaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesSeoFixtures;

    /** A raw insert, bypassing the model and the manager entirely. */
    private function insertKeyword(int $businessId, string $normalized, ?int $locationId = null, array $overrides = []): int
    {
        return DB::table('seo_keywords')->insertGetId(array_merge([
            'uid' => (string) Str::uuid(),
            'business_id' => $businessId,
            'business_location_id' => $locationId,
            'phrase' => $normalized,
            'phrase_normalized' => $normalized,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_location_key_is_a_stored_generated_column_of_the_location_or_zero(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Core);
        $location = $this->createLocation($business);

        $wide = $this->insertKeyword($business->id, 'best bakery');
        $local = $this->insertKeyword($business->id, 'best bakery', $location->id);

        $this->assertSame(0, (int) DB::table('seo_keywords')->where('id', $wide)->value('location_key'));
        $this->assertSame((int) $location->id, (int) DB::table('seo_keywords')->where('id', $local)->value('location_key'));

        $extra = DB::selectOne("select EXTRA as extra from information_schema.columns where table_schema = database() and table_name = 'seo_keywords' and column_name = 'location_key'");
        $this->assertStringContainsStringIgnoringCase('STORED GENERATED', (string) $extra->extra);
    }

    public function test_the_generated_column_can_never_be_written(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Core);

        $this->expectException(QueryException::class);
        $this->insertKeyword($business->id, 'x', null, ['location_key' => 5]);
    }

    public function test_a_business_wide_phrase_cannot_be_inserted_twice(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Core);
        $this->insertKeyword($business->id, 'best bakery');

        // A plain unique index would treat both NULL locations as distinct.
        $this->expectException(UniqueConstraintViolationException::class);
        $this->insertKeyword($business->id, 'best bakery');
    }

    public function test_the_same_phrase_is_allowed_across_locations_and_business_wide(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Core);
        $one = $this->createLocation($business);
        $two = $this->extraLocation($business, 'Second');

        $this->insertKeyword($business->id, 'best bakery');
        $this->insertKeyword($business->id, 'best bakery', $one->id);
        $this->insertKeyword($business->id, 'best bakery', $two->id);

        $this->assertSame(3, DB::table('seo_keywords')->where('business_id', $business->id)->count());
    }

    public function test_the_same_phrase_cannot_repeat_within_one_location(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Core);
        $location = $this->createLocation($business);
        $this->insertKeyword($business->id, 'best bakery', $location->id);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->insertKeyword($business->id, 'best bakery', $location->id);
    }

    public function test_the_same_phrase_is_allowed_for_different_businesses(): void
    {
        [, $a] = $this->entitledTenant(WorkspacePlanTier::Core);
        [, $b] = $this->entitledTenant(WorkspacePlanTier::Core);

        $this->insertKeyword($a->id, 'best bakery');
        $this->insertKeyword($b->id, 'best bakery');

        $this->assertSame(2, DB::table('seo_keywords')->where('phrase_normalized', 'best bakery')->count());
    }

    public function test_an_archived_keyword_still_owns_its_phrase(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Core);
        $this->insertKeyword($business->id, 'best bakery', null, ['lifecycle_state' => 'archived', 'archived_at' => now()]);

        // Uniqueness spans lifecycle states: it is reactivated, never duplicated.
        $this->expectException(UniqueConstraintViolationException::class);
        $this->insertKeyword($business->id, 'best bakery');
    }

    public function test_a_keyword_cannot_pair_a_location_with_a_different_business(): void
    {
        [, $mine] = $this->entitledTenant(WorkspacePlanTier::Core);
        [, $other] = $this->entitledTenant(WorkspacePlanTier::Core);
        $foreignLocation = $this->createLocation($other);

        // The composite FK (location, business) -> business_locations(id, business_id).
        $this->expectException(QueryException::class);
        $this->insertKeyword($mine->id, 'best bakery', $foreignLocation->id);
    }

    public function test_a_referenced_location_cannot_be_deleted(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Core);
        $location = $this->createLocation($business);
        $this->insertKeyword($business->id, 'best bakery', $location->id);

        $this->expectException(QueryException::class);
        DB::table('business_locations')->where('id', $location->id)->delete();
    }

    public function test_a_business_with_keywords_cannot_be_deleted(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Core);
        $this->insertKeyword($business->id, 'best bakery');

        $this->expectException(QueryException::class);
        DB::table('businesses')->where('id', $business->id)->delete();
    }

    public function test_defaults_and_uid_shape(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Core);
        $id = $this->insertKeyword($business->id, 'best bakery');

        $row = DB::table('seo_keywords')->find($id);

        $this->assertSame('active', $row->lifecycle_state);
        $this->assertNull($row->archived_at);
        $this->assertSame('manual', $row->source);
        $this->assertTrue(Str::isUuid($row->uid));
        $this->assertNull($row->created_by_user_id);
    }

    public function test_the_model_assigns_a_uuid_uid_and_casts_the_lifecycle_state(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Core);

        $keyword = new SeoKeyword(['phrase' => 'best bakery', 'phrase_normalized' => 'best bakery']);
        $keyword->forceFill(['business_id' => $business->id])->save();
        $keyword->refresh();

        $this->assertTrue(Str::isUuid($keyword->uid));
        $this->assertSame(SeoKeywordLifecycleState::Active, $keyword->lifecycle_state);
        $this->assertTrue($keyword->isActive());
        $this->assertSame(1, SeoKeyword::query()->active()->count());
    }

    // -----------------------------------------------------------------
    // Mass-assignment boundary.
    // -----------------------------------------------------------------

    public function test_lifecycle_business_and_generated_columns_are_not_mass_assignable(): void
    {
        $model = new SeoKeyword();

        foreach (['lifecycle_state', 'archived_at', 'business_id', 'location_key', 'id'] as $guarded) {
            $this->assertFalse($model->isFillable($guarded), "{$guarded} must not be fillable.");
        }

        foreach (['phrase', 'phrase_normalized', 'business_location_id', 'source', 'created_by_user_id', 'updated_by_user_id'] as $fillable) {
            $this->assertTrue($model->isFillable($fillable), "{$fillable} should be fillable.");
        }
    }

    public function test_mass_assignment_silently_ignores_the_guarded_columns(): void
    {
        $keyword = new SeoKeyword([
            'phrase' => 'best bakery',
            'phrase_normalized' => 'best bakery',
            'lifecycle_state' => 'archived',
            'archived_at' => now(),
            'business_id' => 999,
            'location_key' => 7,
        ]);

        $attributes = $keyword->getAttributes();

        foreach (['lifecycle_state', 'archived_at', 'business_id', 'location_key'] as $guarded) {
            $this->assertArrayNotHasKey($guarded, $attributes);
        }
    }

    public function test_fill_and_update_cannot_change_the_lifecycle(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Core);
        $keyword = new SeoKeyword(['phrase' => 'best bakery', 'phrase_normalized' => 'best bakery']);
        $keyword->forceFill(['business_id' => $business->id])->save();

        $keyword->update(['lifecycle_state' => 'archived', 'archived_at' => now(), 'phrase' => 'best bakery in town']);

        $fresh = $keyword->fresh();
        $this->assertSame(SeoKeywordLifecycleState::Active, $fresh->lifecycle_state, 'The lifecycle is written only by SeoKeywordManager.');
        $this->assertNull($fresh->archived_at);
        $this->assertSame('best bakery in town', $fresh->phrase);
    }

    // -----------------------------------------------------------------
    // Domain separation from the legacy inbound-SMS Keywords product.
    // -----------------------------------------------------------------

    public function test_the_seo_table_and_model_are_distinct_from_the_legacy_keywords_product(): void
    {
        $this->assertNotSame((new Keywords())->getTable(), (new SeoKeyword())->getTable());
        $this->assertSame('seo_keywords', (new SeoKeyword())->getTable());

        $legacy = Schema::getColumnListing('keywords');
        $seo = Schema::getColumnListing('seo_keywords');

        foreach (['keyword_name', 'sender_id', 'reply_text', 'price', 'billing_cycle', 'validity_date'] as $legacyOnly) {
            $this->assertContains($legacyOnly, $legacy);
            $this->assertNotContains($legacyOnly, $seo, "seo_keywords must not carry the legacy column [{$legacyOnly}].");
        }

        $this->assertFalse(is_subclass_of(SeoKeyword::class, Keywords::class));
        $this->assertFalse(is_subclass_of(Keywords::class, SeoKeyword::class));
    }

    public function test_the_migration_left_the_legacy_keywords_table_untouched(): void
    {
        $this->assertSame(
            ['id', 'uid', 'user_id', 'business_id', 'currency_id', 'title', 'keyword_name', 'sender_id', 'reply_text', 'reply_voice', 'reply_mms', 'status', 'price', 'billing_cycle', 'frequency_amount', 'frequency_unit', 'validity_date', 'transaction_id', 'created_at', 'updated_at'],
            Schema::getColumnListing('keywords'),
        );
    }
}

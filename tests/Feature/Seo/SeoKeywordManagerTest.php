<?php

namespace Tests\Feature\Seo;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Seo\SeoKeywordLifecycleState;
use App\Exceptions\Seo\SeoKeywordException;
use App\Library\Seo\SeoKeywordManager;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\SeoKeyword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Seo\Concerns\CreatesSeoFixtures;
use Tests\TestCase;

/**
 * Contract 18 Sub-slice D — SeoKeywordManager: create / update / archive /
 * reactivate, normalization, the active-keyword ceiling, Location
 * attribution, and Location + tenancy authority.
 */
class SeoKeywordManagerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesSeoFixtures;

    private function manager(): SeoKeywordManager
    {
        return app(SeoKeywordManager::class);
    }

    private function actor(\App\Models\Customer $customer): int
    {
        return (int) $customer->user_id;
    }

    private function assertRefused(string $reason, callable $callback): void
    {
        try {
            $callback();
        } catch (SeoKeywordException $e) {
            $this->assertSame($reason, $e->reason);

            return;
        }

        $this->fail("Expected a [{$reason}] refusal, but the call succeeded.");
    }

    /** @return array{0: \App\Models\Customer, 1: Business, 2: \App\Models\Workspace, 3: BusinessLocation} */
    private function tenant(): array
    {
        return $this->growthTenantWithLocation();
    }

    // -----------------------------------------------------------------
    // Create.
    // -----------------------------------------------------------------

    public function test_a_business_wide_keyword_is_created_with_its_typed_and_normalized_phrase(): void
    {
        [$owner, $business] = $this->tenant();

        $keyword = $this->manager()->create($this->actor($owner), $business, '  Best  BAKERY ');

        $this->assertSame('Best  BAKERY', $keyword->phrase, 'The typed phrase is kept, trimmed.');
        $this->assertSame('best bakery', $keyword->phrase_normalized);
        $this->assertNull($keyword->business_location_id);
        $this->assertSame((int) $business->id, (int) $keyword->business_id);
        $this->assertSame(SeoKeywordLifecycleState::Active, $keyword->fresh()->lifecycle_state);
        $this->assertSame('manual', $keyword->fresh()->source);
        $this->assertSame($this->actor($owner), (int) $keyword->created_by_user_id);
        $this->assertSame($this->actor($owner), (int) $keyword->updated_by_user_id);
        $this->assertTrue(Str::isUuid($keyword->uid));
    }

    public function test_a_keyword_can_be_attributed_to_an_active_accessible_location(): void
    {
        [$owner, $business, , $location] = $this->tenant();

        $keyword = $this->manager()->create($this->actor($owner), $business, 'plumber tampa', $location);

        $this->assertSame((int) $location->id, (int) $keyword->business_location_id);
    }

    public function test_the_same_phrase_may_exist_business_wide_and_per_location_but_never_twice_in_one_scope(): void
    {
        [$owner, $business, , $location] = $this->tenant();
        $second = $this->extraLocation($business, 'Second');
        $m = $this->manager();
        $a = $this->actor($owner);

        $m->create($a, $business, 'Best Bakery');
        $m->create($a, $business, 'best bakery', $location);
        $m->create($a, $business, 'BEST BAKERY', $second);

        $this->assertRefused(SeoKeywordException::DUPLICATE, fn () => $m->create($a, $business, '  best   bakery '));
        $this->assertRefused(SeoKeywordException::DUPLICATE, fn () => $m->create($a, $business, 'Best Bakery', $location));

        $this->assertSame(3, SeoKeyword::query()->where('business_id', $business->id)->count());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidPhrases(): array
    {
        return [
            'empty' => [''],
            'whitespace only' => ["   \t  "],
            'too long' => [str_repeat('a', 121)],
            'newline' => ["best\nbakery"],
            'tab' => ["best\tbakery"],
            'control character' => ["best\x07bakery"],
            'zero width space' => ["best\u{200B}bakery"],
            'invalid utf-8' => ["bad \xC3\x28"],
            'expands past the column under NFKC' => [str_repeat('ﬃ', 60)],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPhrases')]
    public function test_an_invalid_phrase_is_refused_and_nothing_is_written(string $phrase): void
    {
        [$owner, $business] = $this->tenant();

        $this->assertRefused(SeoKeywordException::INVALID_PHRASE, fn () => $this->manager()->create($this->actor($owner), $business, $phrase));

        $this->assertSame(0, SeoKeyword::query()->count());
    }

    public function test_a_phrase_of_exactly_the_maximum_length_is_accepted(): void
    {
        [$owner, $business] = $this->tenant();

        $keyword = $this->manager()->create($this->actor($owner), $business, str_repeat('a', 120));

        $this->assertSame(120, mb_strlen($keyword->phrase));
    }

    // -----------------------------------------------------------------
    // Ceiling.
    // -----------------------------------------------------------------

    public function test_the_active_keyword_ceiling_is_enforced_per_business(): void
    {
        config(['seo.keywords.max_active_per_business' => 3]);
        [$owner, $business] = $this->tenant();
        [$otherOwner, $otherBusiness] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $m = $this->manager();

        foreach (['one', 'two', 'three'] as $phrase) {
            $m->create($this->actor($owner), $business, $phrase);
        }

        $this->assertRefused(SeoKeywordException::LIMIT_REACHED, fn () => $m->create($this->actor($owner), $business, 'four'));

        // Another Business is unaffected.
        $m->create($this->actor($otherOwner), $otherBusiness, 'one');
        $this->assertSame(3, SeoKeyword::query()->where('business_id', $business->id)->count());
    }

    public function test_archived_keywords_do_not_count_and_reactivation_respects_the_ceiling(): void
    {
        config(['seo.keywords.max_active_per_business' => 2]);
        [$owner, $business] = $this->tenant();
        $m = $this->manager();
        $a = $this->actor($owner);

        $one = $m->create($a, $business, 'one');
        $m->create($a, $business, 'two');
        $this->assertRefused(SeoKeywordException::LIMIT_REACHED, fn () => $m->create($a, $business, 'three'));

        $m->archive($a, $business, $one);
        $three = $m->create($a, $business, 'three');   // room again

        $this->assertRefused(SeoKeywordException::LIMIT_REACHED, fn () => $m->reactivate($a, $business, $one));

        $m->archive($a, $business, $three);
        $this->assertSame(SeoKeywordLifecycleState::Active, $m->reactivate($a, $business, $one)->fresh()->lifecycle_state);
    }

    public function test_the_ceiling_config_cannot_be_raised_past_the_contract_limit(): void
    {
        config(['seo.keywords.max_active_per_business' => 500]);

        $this->assertSame(50, app(\App\Library\Seo\SeoConfig::class)->keywordsMaxActivePerBusiness());
    }

    public function test_every_write_locks_the_business_row_so_the_ceiling_cannot_be_raced(): void
    {
        [$owner, $business] = $this->tenant();

        $queries = $this->capturedQueries(fn () => $this->manager()->create($this->actor($owner), $business, 'best bakery'));

        $locked = array_filter($queries, fn (string $q) => str_contains($q, 'from `businesses`') && str_contains($q, 'for update'));
        $this->assertNotEmpty($locked, 'The Business row must be locked FOR UPDATE inside the write transaction.');
    }

    // -----------------------------------------------------------------
    // Update.
    // -----------------------------------------------------------------

    public function test_update_changes_the_phrase_and_renormalizes(): void
    {
        [$owner, $business] = $this->tenant();
        $keyword = $this->manager()->create($this->actor($owner), $business, 'best bakery');

        $updated = $this->manager()->update($this->actor($owner), $business, $keyword, 'Best BAKERY in Town');

        $this->assertSame('Best BAKERY in Town', $updated->phrase);
        $this->assertSame('best bakery in town', $updated->phrase_normalized);
    }

    public function test_update_can_move_a_keyword_between_a_location_and_business_wide(): void
    {
        [$owner, $business, , $location] = $this->tenant();
        $keyword = $this->manager()->create($this->actor($owner), $business, 'best bakery');

        $toLocation = $this->manager()->update($this->actor($owner), $business, $keyword, 'best bakery', $location);
        $this->assertSame((int) $location->id, (int) $toLocation->fresh()->business_location_id);

        $back = $this->manager()->update($this->actor($owner), $business, $toLocation, 'best bakery', null);
        $this->assertNull($back->fresh()->business_location_id);
    }

    public function test_an_unchanged_update_is_a_no_op_that_touches_nothing(): void
    {
        [$owner, $business] = $this->tenant();
        $keyword = $this->manager()->create($this->actor($owner), $business, 'best bakery');
        DB::table('seo_keywords')->where('id', $keyword->id)->update(['updated_at' => '2020-01-01 00:00:00', 'updated_by_user_id' => null]);

        $this->manager()->update($this->actor($owner), $business, $keyword, 'best bakery');

        $row = DB::table('seo_keywords')->find($keyword->id);
        $this->assertSame('2020-01-01 00:00:00', $row->updated_at);
        $this->assertNull($row->updated_by_user_id);
    }

    public function test_update_into_an_existing_phrase_is_a_duplicate(): void
    {
        [$owner, $business] = $this->tenant();
        $this->manager()->create($this->actor($owner), $business, 'best bakery');
        $other = $this->manager()->create($this->actor($owner), $business, 'cheap bread');

        $this->assertRefused(SeoKeywordException::DUPLICATE, fn () => $this->manager()->update($this->actor($owner), $business, $other, 'BEST  Bakery'));

        $this->assertSame('cheap bread', $other->fresh()->phrase);
    }

    public function test_an_archived_keyword_cannot_be_edited_until_reactivated(): void
    {
        [$owner, $business] = $this->tenant();
        $keyword = $this->manager()->create($this->actor($owner), $business, 'best bakery');
        $this->manager()->archive($this->actor($owner), $business, $keyword);

        $this->assertRefused(SeoKeywordException::NOT_ACTIVE, fn () => $this->manager()->update($this->actor($owner), $business, $keyword, 'something else'));
    }

    // -----------------------------------------------------------------
    // Archive / reactivate.
    // -----------------------------------------------------------------

    public function test_archive_and_reactivate_round_trip_with_timestamps_and_actor(): void
    {
        [$owner, $business] = $this->tenant();
        $keyword = $this->manager()->create($this->actor($owner), $business, 'best bakery');

        $archived = $this->manager()->archive($this->actor($owner), $business, $keyword)->fresh();
        $this->assertSame(SeoKeywordLifecycleState::Archived, $archived->lifecycle_state);
        $this->assertNotNull($archived->archived_at);

        $active = $this->manager()->reactivate($this->actor($owner), $business, $keyword)->fresh();
        $this->assertSame(SeoKeywordLifecycleState::Active, $active->lifecycle_state);
        $this->assertNull($active->archived_at);
        $this->assertSame('best bakery', $active->phrase, 'The phrase survives the round trip.');
    }

    public function test_archive_and_reactivate_are_idempotent(): void
    {
        [$owner, $business] = $this->tenant();
        $keyword = $this->manager()->create($this->actor($owner), $business, 'best bakery');

        $first = $this->manager()->archive($this->actor($owner), $business, $keyword)->fresh();
        $again = $this->manager()->archive($this->actor($owner), $business, $keyword)->fresh();
        $this->assertEquals($first->archived_at, $again->archived_at, 'Archiving twice must not move archived_at.');

        $this->manager()->reactivate($this->actor($owner), $business, $keyword);
        $this->manager()->reactivate($this->actor($owner), $business, $keyword);
        $this->assertSame(1, SeoKeyword::query()->active()->count());
    }

    public function test_an_archived_keyword_keeps_owning_its_phrase(): void
    {
        [$owner, $business] = $this->tenant();
        $keyword = $this->manager()->create($this->actor($owner), $business, 'best bakery');
        $this->manager()->archive($this->actor($owner), $business, $keyword);

        $this->assertRefused(SeoKeywordException::DUPLICATE, fn () => $this->manager()->create($this->actor($owner), $business, 'Best Bakery'));
    }

    // -----------------------------------------------------------------
    // Tenancy: a keyword is only ever reached THROUGH its own Business.
    // -----------------------------------------------------------------

    public function test_a_stranger_cannot_do_anything(): void
    {
        [$owner, $business, , $location] = $this->tenant();
        $keyword = $this->manager()->create($this->actor($owner), $business, 'best bakery');
        $stranger = $this->actor($this->createCustomer());
        $m = $this->manager();

        $this->assertRefused(SeoKeywordException::ACCESS_DENIED, fn () => $m->create($stranger, $business, 'x'));
        $this->assertRefused(SeoKeywordException::ACCESS_DENIED, fn () => $m->create($stranger, $business, 'x', $location));
        $this->assertRefused(SeoKeywordException::ACCESS_DENIED, fn () => $m->update($stranger, $business, $keyword, 'y'));
        $this->assertRefused(SeoKeywordException::ACCESS_DENIED, fn () => $m->archive($stranger, $business, $keyword));
        $this->assertRefused(SeoKeywordException::ACCESS_DENIED, fn () => $m->reactivate($stranger, $business, $keyword));

        $this->assertSame('best bakery', $keyword->fresh()->phrase);
        $this->assertTrue($keyword->fresh()->isActive());
        $this->assertSame(1, SeoKeyword::query()->count());
    }

    public function test_a_keyword_of_another_business_can_never_be_reached_through_my_business(): void
    {
        [$owner, $business] = $this->tenant();
        [$otherOwner, $otherBusiness] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $foreign = $this->manager()->create($this->actor($otherOwner), $otherBusiness, 'their secret');
        $m = $this->manager();
        $a = $this->actor($owner);

        // Even the legitimate owner of $business, passing the foreign keyword.
        $this->assertRefused(SeoKeywordException::ACCESS_DENIED, fn () => $m->update($a, $business, $foreign, 'mine now'));
        $this->assertRefused(SeoKeywordException::ACCESS_DENIED, fn () => $m->archive($a, $business, $foreign));
        $this->assertRefused(SeoKeywordException::ACCESS_DENIED, fn () => $m->reactivate($a, $business, $foreign));

        $this->assertSame('their secret', $foreign->fresh()->phrase);
        $this->assertTrue($foreign->fresh()->isActive());
        $this->assertNull($m->findAccessible($a, $business, $foreign->uid), 'A foreign uid resolves to nothing.');
    }

    public function test_an_inactive_business_refuses_every_write(): void
    {
        [$owner, $business] = $this->tenant();
        $keyword = $this->manager()->create($this->actor($owner), $business, 'best bakery');
        DB::table('businesses')->where('id', $business->id)->update(['status' => 'inactive']);

        $this->assertRefused(SeoKeywordException::ACCESS_DENIED, fn () => $this->manager()->create($this->actor($owner), $business, 'x'));
        $this->assertRefused(SeoKeywordException::ACCESS_DENIED, fn () => $this->manager()->archive($this->actor($owner), $business, $keyword));
    }

    // -----------------------------------------------------------------
    // Location ACL — the only Location authority is LocationAccessGuard.
    // -----------------------------------------------------------------

    public function test_a_location_of_another_business_cannot_be_attributed(): void
    {
        [$owner, $business] = $this->tenant();
        [, $otherBusiness] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $foreignLocation = $this->createLocation($otherBusiness);

        $this->assertRefused(SeoKeywordException::ACCESS_DENIED, fn () => $this->manager()->create($this->actor($owner), $business, 'x', $foreignLocation));
        $this->assertSame(0, SeoKeyword::query()->count());
    }

    public function test_a_selected_scope_actor_can_only_attribute_to_a_granted_location(): void
    {
        [$owner, $business, $workspace, $granted] = $this->tenant();
        $ungranted = $this->extraLocation($business, 'Ungranted Site');
        $member = $this->selectedScopeMember($workspace, [$granted]);
        $m = $this->manager();
        $a = $this->actor($member);

        $ok = $m->create($a, $business, 'granted phrase', $granted);
        $this->assertSame((int) $granted->id, (int) $ok->business_location_id);

        $this->assertRefused(SeoKeywordException::ACCESS_DENIED, fn () => $m->create($a, $business, 'sneaky', $ungranted));
        $this->assertSame(1, SeoKeyword::query()->count());
    }

    public function test_a_selected_scope_actor_cannot_touch_a_keyword_of_an_ungranted_location(): void
    {
        [$owner, $business, $workspace, $granted] = $this->tenant();
        $ungranted = $this->extraLocation($business, 'Ungranted Site');
        $hidden = $this->manager()->create($this->actor($owner), $business, 'hidden phrase', $ungranted);

        $member = $this->selectedScopeMember($workspace, [$granted]);
        $m = $this->manager();
        $a = $this->actor($member);

        $this->assertRefused(SeoKeywordException::ACCESS_DENIED, fn () => $m->update($a, $business, $hidden, 'renamed'));
        $this->assertRefused(SeoKeywordException::ACCESS_DENIED, fn () => $m->archive($a, $business, $hidden));
        $this->assertRefused(SeoKeywordException::ACCESS_DENIED, fn () => $m->reactivate($a, $business, $hidden));

        // ... including moving a keyword it CAN see onto that Location.
        $mine = $m->create($a, $business, 'mine', $granted);
        $this->assertRefused(SeoKeywordException::ACCESS_DENIED, fn () => $m->update($a, $business, $mine, 'mine', $ungranted));

        $this->assertSame('hidden phrase', $hidden->fresh()->phrase);
        $this->assertTrue($hidden->fresh()->isActive());
        $this->assertNull($m->findAccessible($a, $business, $hidden->uid));
    }

    public function test_a_selected_scope_actor_can_still_manage_business_wide_keywords(): void
    {
        [$owner, $business, $workspace, $granted] = $this->tenant();
        $wide = $this->manager()->create($this->actor($owner), $business, 'business wide');
        $member = $this->selectedScopeMember($workspace, [$granted]);
        $m = $this->manager();
        $a = $this->actor($member);

        // Definitions are Business-wide configuration (Blueprint §5).
        $this->assertNotNull($m->findAccessible($a, $business, $wide->uid));
        $m->update($a, $business, $wide, 'business wide edited');
        $m->archive($a, $business, $wide);
        $m->reactivate($a, $business, $wide);

        $this->assertSame('business wide edited', $wide->fresh()->phrase);
    }

    public function test_an_actor_with_no_location_grant_sees_only_business_wide_keywords(): void
    {
        [$owner, $business, $workspace, $location] = $this->tenant();
        $this->manager()->create($this->actor($owner), $business, 'wide');
        $this->manager()->create($this->actor($owner), $business, 'local', $location);
        $member = $this->selectedScopeMember($workspace, []);

        $visible = $this->manager()->listVisible($this->actor($member), $business);

        $this->assertSame(['wide'], $visible->pluck('phrase')->all());
    }

    public function test_archived_locations_are_read_only_for_their_keywords(): void
    {
        [$owner, $business, , $location] = $this->tenant();
        $other = $this->extraLocation($business, 'Other');
        $keyword = $this->manager()->create($this->actor($owner), $business, 'local phrase', $location);
        DB::table('business_locations')->where('id', $location->id)->update(['lifecycle_state' => 'archived', 'archived_at' => now()]);
        $m = $this->manager();
        $a = $this->actor($owner);

        $this->assertRefused(SeoKeywordException::LOCATION_NOT_ACTIVE, fn () => $m->create($a, $business, 'new', $location->fresh()));
        $this->assertRefused(SeoKeywordException::LOCATION_NOT_ACTIVE, fn () => $m->update($a, $business, $keyword, 'edited'));
        $this->assertRefused(SeoKeywordException::LOCATION_NOT_ACTIVE, fn () => $m->update($a, $business, $keyword, 'local phrase', $other), 'Moving off an archived Location is a write to it.');
        $this->assertRefused(SeoKeywordException::LOCATION_NOT_ACTIVE, fn () => $m->archive($a, $business, $keyword));

        // History stays visible read-only.
        $this->assertNotNull($m->findAccessible($a, $business, $keyword->uid));
        $this->assertSame(['local phrase'], $m->listVisible($a, $business)->pluck('phrase')->all());
        $this->assertTrue($keyword->fresh()->isActive());
    }

    // -----------------------------------------------------------------
    // Reads: filter BEFORE count.
    // -----------------------------------------------------------------

    public function test_counts_are_computed_after_location_filtering(): void
    {
        [$owner, $business, $workspace, $granted] = $this->tenant();
        $hiddenA = $this->extraLocation($business, 'Hidden A');
        $hiddenB = $this->extraLocation($business, 'Hidden B');
        $m = $this->manager();
        $o = $this->actor($owner);

        $m->create($o, $business, 'wide one');
        $m->create($o, $business, 'granted one', $granted);
        $m->create($o, $business, 'hidden one', $hiddenA);
        $m->create($o, $business, 'hidden two', $hiddenB);

        $member = $this->selectedScopeMember($workspace, [$granted]);
        $memberIds = app(\App\Library\Workspace\LocationAccessGuard::class)->accessibleLocationIdsForBusiness($this->actor($member), $business);
        $ownerIds = app(\App\Library\Workspace\LocationAccessGuard::class)->accessibleLocationIdsForBusiness($o, $business);

        $this->assertSame(4, $m->countActiveVisible($business, $ownerIds));
        $this->assertSame(2, $m->countActiveVisible($business, $memberIds), 'Hidden Locations\' keywords must not be countable.');
        $this->assertSame(1, $m->countActiveVisible($business, []), 'With no Location access only Business-wide keywords remain.');
    }

    public function test_listing_never_includes_another_business(): void
    {
        [$owner, $business] = $this->tenant();
        [$otherOwner, $otherBusiness] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->manager()->create($this->actor($owner), $business, 'mine');
        $this->manager()->create($this->actor($otherOwner), $otherBusiness, 'theirs');

        $this->assertSame(['mine'], $this->manager()->listVisible($this->actor($owner), $business)->pluck('phrase')->all());
    }

    public function test_listing_cost_does_not_grow_with_the_number_of_keywords(): void
    {
        [$ownerA, $small] = $this->tenant();
        [$ownerB, $large] = $this->tenant();
        $m = $this->manager();
        $m->create($this->actor($ownerA), $small, 'one');
        foreach (range(1, 25) as $i) {
            $m->create($this->actor($ownerB), $large, "phrase {$i}");
        }

        $m->listVisible($this->actor($ownerA), $small); // warm

        $smallQueries = $this->capturedQueries(fn () => $m->listVisible($this->actor($ownerA), $small));
        $largeQueries = $this->capturedQueries(fn () => $m->listVisible($this->actor($ownerB), $large));

        $this->assertSame(count($smallQueries), count($largeQueries));
    }

    // -----------------------------------------------------------------
    // The manager writes seo_keywords and nothing else.
    // -----------------------------------------------------------------

    public function test_the_manager_writes_no_business_location_website_or_google_row(): void
    {
        [$owner, $business, , $location] = $this->tenant();
        $this->publishWebsite($business, [$this->snapshotPage('a', 'Home', [], [], true)]);
        $this->bindGoogleLocation($business, $location, $this->activeConnection($business), true);
        $before = $this->dbFingerprint($this->seoProtectedTables());
        $m = $this->manager();
        $a = $this->actor($owner);

        $k = $m->create($a, $business, 'best bakery', $location);
        $m->update($a, $business, $k, 'best bakery shop');
        $m->archive($a, $business, $k);
        $m->reactivate($a, $business, $k);

        $this->assertSame($before, $this->dbFingerprint($this->seoProtectedTables()), 'Keyword writes must not touch Business, Location, Website or Google rows.');
    }
}

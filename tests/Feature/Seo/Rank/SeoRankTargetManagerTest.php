<?php

namespace Tests\Feature\Seo\Rank;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Exceptions\Seo\SeoKeywordException;
use App\Library\Seo\Rank\SeoRankException;
use App\Library\Seo\Rank\SeoRankTargetManager;
use App\Library\Seo\SeoKeywordManager;
use App\Library\Support\RequestScopedCache;
use App\Models\Business;
use App\Models\Customer;
use App\Models\SeoKeyword;
use App\Models\SeoRankCheckRun;
use App\Models\SeoRankObservation;
use App\Models\SeoRankTarget;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Seo\Rank\Concerns\CreatesRankFixtures;
use Tests\Feature\Seo\Rank\Concerns\CreatesRankObservations;
use Tests\TestCase;

/**
 * Start / stop / restart tracking through SeoRankTargetManager: allowance by
 * tier, the untouched 50-keyword ceiling, idempotency, history retention and
 * tenancy / Location authority.
 */
class SeoRankTargetManagerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRankFixtures;
    use CreatesRankObservations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rankSetUp();
    }

    private function manager(): SeoRankTargetManager
    {
        return app(SeoRankTargetManager::class);
    }

    private function actor(Customer $c): int
    {
        return (int) $c->user_id;
    }

    private function assertRankRefused(string $reason, callable $callback): SeoRankException
    {
        try {
            $callback();
        } catch (SeoRankException $e) {
            $this->assertSame($reason, $e->reason);

            return $e;
        }

        $this->fail("Expected a [{$reason}] rank refusal, but the call succeeded.");
    }

    private function makeTrial(Workspace $workspace): void
    {
        DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->update(['trial_ends_at' => now()->addDays(10)]);
        app(RequestScopedCache::class)->flush();
    }

    /** @return list<SeoKeyword> */
    private function keywords(Customer $owner, Business $business, int $count, string $prefix = 'kw'): array
    {
        $out = [];

        for ($i = 1; $i <= $count; $i++) {
            $out[] = $this->keyword($owner, $business, "{$prefix} {$i}");
        }

        return $out;
    }

    private function fill(Customer $owner, Business $business, int $count): array
    {
        $targets = [];

        foreach ($this->keywords($owner, $business, $count) as $k) {
            $targets[] = $this->track($owner, $business, $k);
        }

        return $targets;
    }

    // ------------------------------ Allowance ------------------------------

    public function test_trial_rejects_the_sixth_tracked_target(): void
    {
        [$owner, $business, $workspace] = $this->rankTenant(WorkspacePlanTier::Growth);
        $this->makeTrial($workspace);
        $this->fill($owner, $business, 5);
        $extra = $this->keyword($owner, $business, 'sixth');

        $e = $this->assertRankRefused(SeoRankException::LIMIT_REACHED, fn () => $this->track($owner, $business, $extra));

        $this->assertSame(5, $e->used);
        $this->assertSame(5, $e->limit);
        $this->assertSame(5, SeoRankTarget::query()->count());
    }

    public function test_core_rejects_the_sixth_tracked_target(): void
    {
        [$owner, $business] = $this->rankTenant(WorkspacePlanTier::Core);
        $this->fill($owner, $business, 5);
        $extra = $this->keyword($owner, $business, 'sixth');

        $e = $this->assertRankRefused(SeoRankException::LIMIT_REACHED, fn () => $this->track($owner, $business, $extra));

        $this->assertSame(5, $e->used);
        $this->assertSame(5, $e->limit);
    }

    public function test_growth_accepts_twenty_and_rejects_the_twenty_first(): void
    {
        [$owner, $business] = $this->rankTenant(WorkspacePlanTier::Growth);
        $this->fill($owner, $business, 20);
        $this->assertSame(20, $this->manager()->slotsUsed($business));
        $extra = $this->keyword($owner, $business, 'twenty first');

        $e = $this->assertRankRefused(SeoRankException::LIMIT_REACHED, fn () => $this->track($owner, $business, $extra));

        $this->assertSame(20, $e->used);
        $this->assertSame(20, $e->limit);
    }

    public function test_the_fifty_active_keyword_ceiling_is_preserved_and_independent_of_the_tracked_allowance(): void
    {
        [$owner, $business] = $this->rankTenant(WorkspacePlanTier::Core);
        $keywords = $this->keywords($owner, $business, 50);

        try {
            $this->keyword($owner, $business, 'the 51st');
            $this->fail('The 51st active keyword must be refused.');
        } catch (SeoKeywordException $e) {
            $this->assertSame(SeoKeywordException::LIMIT_REACHED, $e->reason);
        }

        // 50 untracked keywords exist; tracking is a separate, smaller allowance.
        $this->assertSame(0, SeoRankTarget::query()->count());

        foreach (array_slice($keywords, 0, 5) as $k) {
            $this->track($owner, $business, $k);
        }

        $this->assertRankRefused(SeoRankException::LIMIT_REACHED, fn () => $this->track($owner, $business, $keywords[5]));
        $this->assertSame(50, SeoKeyword::query()->where('business_id', $business->id)->count());
    }

    public function test_a_full_tracking_allowance_never_blocks_keyword_creation(): void
    {
        [$owner, $business] = $this->rankTenant(WorkspacePlanTier::Core);
        $this->fill($owner, $business, 5);

        $untracked = $this->keyword($owner, $business, 'still allowed');

        $this->assertNotNull($untracked->id);
        $this->assertSame(0, SeoRankTarget::query()->where('seo_keyword_id', $untracked->id)->count());
        $this->assertSame(5, $this->manager()->slotsUsed($business));
    }

    public function test_two_sequential_tracks_at_limit_minus_one_only_one_succeeds(): void
    {
        [$owner, $business] = $this->rankTenant(WorkspacePlanTier::Core);
        $this->fill($owner, $business, 4);
        $a = $this->keyword($owner, $business, 'racer a');
        $b = $this->keyword($owner, $business, 'racer b');

        $this->track($owner, $business, $a);
        $this->assertRankRefused(SeoRankException::LIMIT_REACHED, fn () => $this->track($owner, $business, $b));

        $this->assertSame(5, SeoRankTarget::query()->tracking()->count());
    }

    // ----------------------- Identity of a target ------------------------

    public function test_same_keyword_in_a_different_city_is_two_targets_consuming_two_slots(): void
    {
        [$owner, $business] = $this->rankTenant();
        $k = $this->keyword($owner, $business);

        $a = $this->track($owner, $business, $k, self::CHICAGO);
        $b = $this->track($owner, $business, $k, self::NAPERVILLE);

        $this->assertNotSame($a->id, $b->id);
        $this->assertSame(2, $this->manager()->slotsUsed($business));
        $this->assertSame(1, SeoKeyword::query()->count());
    }

    public function test_same_keyword_and_city_twice_is_idempotent_and_uses_one_slot(): void
    {
        [$owner, $business] = $this->rankTenant();
        $k = $this->keyword($owner, $business);

        $a = $this->track($owner, $business, $k);
        $b = $this->track($owner, $business, $k);

        $this->assertSame($a->id, $b->id);
        $this->assertSame($a->uid, $b->uid);
        $this->assertSame(1, SeoRankTarget::query()->count());
        $this->assertSame(1, $this->manager()->slotsUsed($business));
    }

    public function test_tracking_never_creates_a_business_location(): void
    {
        [$owner, $business] = $this->rankTenant();
        $k = $this->keyword($owner, $business);
        $before = DB::table('business_locations')->count();

        $this->track($owner, $business, $k, self::CHICAGO);
        $this->track($owner, $business, $k, self::NAPERVILLE);

        $this->assertSame($before, DB::table('business_locations')->count());
    }

    public function test_an_invalid_location_code_is_refused_before_any_write(): void
    {
        [$owner, $business] = $this->rankTenant();
        $k = $this->keyword($owner, $business);

        $this->assertRankRefused(SeoRankException::INVALID_LOCATION, fn () => $this->track($owner, $business, $k, 999999999));

        $this->assertSame(0, SeoRankTarget::query()->count());
        $this->assertSame(0, SeoRankCheckRun::query()->count());
    }

    // ------------------------- Stop / restart ----------------------------

    public function test_stop_keeps_history_and_frees_the_slot(): void
    {
        [$owner, $business] = $this->rankTenant(WorkspacePlanTier::Core);
        $targets = $this->fill($owner, $business, 5);
        $this->obs($targets[0], 'organic', 7);
        $this->obs($targets[0], 'local', 2);

        $stopped = $this->manager()->stop($this->actor($owner), $business, $targets[0]->uid);

        $this->assertFalse($stopped->isTracking());
        $this->assertNotNull($stopped->stopped_at);
        $this->assertSame(2, SeoRankObservation::query()->where('seo_rank_target_id', $targets[0]->id)->count());
        $this->assertSame(4, $this->manager()->slotsUsed($business));

        // The freed slot is usable.
        $this->track($owner, $business, $this->keyword($owner, $business, 'fresh'));
        $this->assertSame(5, $this->manager()->slotsUsed($business));
    }

    public function test_restart_resumes_the_same_row_and_history(): void
    {
        [$owner, $business] = $this->rankTenant();
        $k = $this->keyword($owner, $business);
        $target = $this->track($owner, $business, $k);
        $this->obs($target, 'organic', 9);

        $this->manager()->stop($this->actor($owner), $business, $target->uid);
        $restarted = $this->manager()->restart($this->actor($owner), $business, $target->uid);

        $this->assertTrue($restarted->isTracking());
        $this->assertSame($target->uid, $restarted->uid);
        $this->assertNull($restarted->stopped_at);
        $this->assertSame(1, SeoRankTarget::query()->count());
        $this->assertSame(1, SeoKeyword::query()->count());
        $this->assertSame(1, SeoRankObservation::query()->count());

        // Tracking the same keyword/city again after a stop also resumes the SAME row.
        $this->manager()->stop($this->actor($owner), $business, $target->uid);
        $viaTrack = $this->track($owner, $business, $k);
        $this->assertSame($target->uid, $viaTrack->uid);
        $this->assertSame(1, SeoRankTarget::query()->count());
    }

    public function test_restart_is_refused_when_the_slots_are_full(): void
    {
        [$owner, $business] = $this->rankTenant(WorkspacePlanTier::Core);
        $targets = $this->fill($owner, $business, 5);

        $this->manager()->stop($this->actor($owner), $business, $targets[0]->uid);
        $this->track($owner, $business, $this->keyword($owner, $business, 'took the slot'));

        $e = $this->assertRankRefused(SeoRankException::LIMIT_REACHED, fn () => $this->manager()->restart($this->actor($owner), $business, $targets[0]->uid));

        $this->assertSame(5, $e->used);
        $this->assertFalse($targets[0]->fresh()->isTracking());
    }

    public function test_an_archived_keyword_cannot_be_tracked_and_archived_keywords_do_not_consume_slots(): void
    {
        [$owner, $business] = $this->rankTenant(WorkspacePlanTier::Core);
        $targets = $this->fill($owner, $business, 5);

        $archivedKeyword = $this->keyword($owner, $business, 'archived one');
        app(SeoKeywordManager::class)->archive($this->actor($owner), $business, $archivedKeyword);
        $this->assertRankRefused(SeoRankException::KEYWORD_NOT_ACTIVE, fn () => $this->track($owner, $business, $archivedKeyword->fresh()));

        // Archiving a TRACKED keyword frees its slot.
        app(SeoKeywordManager::class)->archive($this->actor($owner), $business, $targets[0]->keyword);
        $this->assertSame(4, $this->manager()->slotsUsed($business));
        $this->track($owner, $business, $this->keyword($owner, $business, 'uses freed slot'));
        $this->assertSame(5, $this->manager()->slotsUsed($business));

        // A stopped target of an archived keyword cannot be restarted.
        $this->manager()->stop($this->actor($owner), $business, $targets[1]->uid);
        app(SeoKeywordManager::class)->archive($this->actor($owner), $business, $targets[1]->keyword);
        $this->assertRankRefused(SeoRankException::KEYWORD_NOT_ACTIVE, fn () => $this->manager()->restart($this->actor($owner), $business, $targets[1]->uid));
    }

    // ---------------------------- Entitlement ----------------------------

    public function test_a_plan_without_the_feature_is_not_entitled(): void
    {
        [$owner, $business] = $this->rankTenant(WorkspacePlanTier::Growth);
        $k = $this->keyword($owner, $business);

        $catalogId = DB::table('workspace_plan_catalog')->where('tier', 'growth')->value('id');
        DB::table('workspace_plan_features')->where('workspace_plan_catalog_id', $catalogId)->where('feature_key', 'seo_rank_tracking')->delete();
        app(RequestScopedCache::class)->flush();

        $this->assertRankRefused(SeoRankException::NOT_ENTITLED, fn () => $this->manager()->track($this->actor($owner), $business->fresh(), $k->uid, self::CHICAGO));
        $this->assertSame(0, SeoRankTarget::query()->count());
    }

    public function test_a_workspace_with_no_plan_assigned_is_not_entitled(): void
    {
        [$owner, $business, $workspace] = $this->rankTenant(WorkspacePlanTier::Growth);
        $k = $this->keyword($owner, $business);

        DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->delete();
        app(RequestScopedCache::class)->flush();

        $this->assertRankRefused(SeoRankException::NOT_ENTITLED, fn () => $this->manager()->track($this->actor($owner), $business->fresh(), $k->uid, self::CHICAGO));
    }

    // ------------------------------- ACL ---------------------------------

    public function test_another_business_cannot_see_or_touch_targets_or_keywords(): void
    {
        [$ownerA, $businessA] = $this->rankTenant(WorkspacePlanTier::Growth, 'a-tenant.com');
        [$ownerB, $businessB] = $this->rankTenant(WorkspacePlanTier::Growth, 'b-tenant.com');
        $keywordB = $this->keyword($ownerB, $businessB, 'b only');
        $targetB = $this->track($ownerB, $businessB, $keywordB);
        $keywordA = $this->keyword($ownerA, $businessA, 'a only');
        $targetA = $this->track($ownerA, $businessA, $keywordA);

        // B's keyword uid under A's Business.
        $this->assertRankRefused(SeoRankException::ACCESS_DENIED, fn () => $this->manager()->track($this->actor($ownerA), $businessA, $keywordB->uid, self::CHICAGO));
        // A is not a member of B's Business at all.
        $this->assertRankRefused(SeoRankException::ACCESS_DENIED, fn () => $this->manager()->track($this->actor($ownerA), $businessB, $keywordB->uid, self::CHICAGO));
        // B's target uid under A's Business.
        $this->assertNull($this->manager()->findAccessible($this->actor($ownerA), $businessA, $targetB->uid));
        $this->assertRankRefused(SeoRankException::ACCESS_DENIED, fn () => $this->manager()->stop($this->actor($ownerA), $businessA, $targetB->uid));
        $this->assertRankRefused(SeoRankException::ACCESS_DENIED, fn () => $this->manager()->restart($this->actor($ownerA), $businessA, $targetB->uid));

        $this->assertTrue($targetB->fresh()->isTracking());
        $this->assertNotNull($this->manager()->findAccessible($this->actor($ownerA), $businessA, $targetA->uid));
    }

    public function test_forged_and_unknown_uids_fail_closed(): void
    {
        [$owner, $business] = $this->rankTenant();

        foreach (['not-a-uid', '00000000-0000-0000-0000-000000000000', '', "' OR 1=1 --"] as $forged) {
            $this->assertRankRefused(SeoRankException::ACCESS_DENIED, fn () => $this->manager()->track($this->actor($owner), $business, $forged, self::CHICAGO));
            $this->assertRankRefused(SeoRankException::ACCESS_DENIED, fn () => $this->manager()->stop($this->actor($owner), $business, $forged));
            $this->assertRankRefused(SeoRankException::ACCESS_DENIED, fn () => $this->manager()->restart($this->actor($owner), $business, $forged));
            $this->assertNull($this->manager()->findAccessible($this->actor($owner), $business, $forged));
        }

        $this->assertSame(0, SeoRankTarget::query()->count());
    }

    public function test_an_inactive_business_is_denied(): void
    {
        [$owner, $business] = $this->rankTenant();
        $k = $this->keyword($owner, $business);
        $target = $this->track($owner, $business, $k);

        DB::table('businesses')->where('id', $business->id)->update(['status' => BusinessStatus::Inactive->value]);
        $business = $business->fresh();

        $this->assertRankRefused(SeoRankException::ACCESS_DENIED, fn () => $this->manager()->track($this->actor($owner), $business, $k->uid, self::NAPERVILLE));
        $this->assertRankRefused(SeoRankException::ACCESS_DENIED, fn () => $this->manager()->stop($this->actor($owner), $business, $target->uid));
        $this->assertTrue($target->fresh()->isTracking());
    }

    public function test_location_acl_hides_a_locations_keyword_from_a_selected_scope_member_without_it(): void
    {
        [$owner, $business, $workspace, $locX] = $this->growthTenantWithLocation();
        $this->giveDomain($business, 'photoboothco.com');
        $locY = $this->extraLocation($business, 'Site Y');

        $keywordX = app(SeoKeywordManager::class)->create($this->actor($owner), $business, 'location x phrase', $locX);
        $targetX = $this->manager()->track($this->actor($owner), $business, $keywordX->uid, self::CHICAGO);

        $withoutX = $this->selectedScopeMember($workspace, [$locY]);
        $withX = $this->selectedScopeMember($workspace, [$locX]);

        // Without X: invisible and untouchable, and indistinguishable from missing.
        $this->assertRankRefused(SeoRankException::ACCESS_DENIED, fn () => $this->manager()->track($this->actor($withoutX), $business, $keywordX->uid, self::NAPERVILLE));
        $this->assertRankRefused(SeoRankException::ACCESS_DENIED, fn () => $this->manager()->stop($this->actor($withoutX), $business, $targetX->uid));
        $this->assertRankRefused(SeoRankException::ACCESS_DENIED, fn () => $this->manager()->restart($this->actor($withoutX), $business, $targetX->uid));
        $this->assertNull($this->manager()->findAccessible($this->actor($withoutX), $business, $targetX->uid));
        $this->assertTrue($targetX->fresh()->isTracking());

        // With X: allowed.
        $this->assertNotNull($this->manager()->findAccessible($this->actor($withX), $business, $targetX->uid));
        $this->assertFalse($this->manager()->stop($this->actor($withX), $business, $targetX->uid)->isTracking());
        $this->assertTrue($this->manager()->restart($this->actor($withX), $business, $targetX->uid)->isTracking());
        $second = $this->manager()->track($this->actor($withX), $business, $keywordX->uid, self::NAPERVILLE);
        $this->assertNotSame($targetX->id, $second->id);

        // A Business-wide keyword needs only Business access.
        $wide = $this->keyword($owner, $business, 'business wide');
        $this->assertNotNull($this->manager()->track($this->actor($withoutX), $business, $wide->uid, self::CHICAGO));
    }
}

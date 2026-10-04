<?php

namespace Tests\Feature\MetaAds\Sync;

use App\Enums\MetaAds\MetaAdsSyncRunState;
use App\Library\MetaAds\MetaAdsAccountSelector;
use App\Library\MetaAds\Sync\MetaAdsSyncGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\MetaAds\Sync\Concerns\PreparesMetaSync;
use Tests\TestCase;

/**
 * Meta Ads Module V1 contract 24 §6 — "one sync per account at a time":
 * compare-and-set claim, 15-minute stale takeover, heartbeat, release only
 * while the stored stamp is still ours.
 */
class MetaAdsSyncGuardTest extends TestCase
{
    use PreparesMetaSync;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareSync();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function guard(): MetaAdsSyncGuard
    {
        return app(MetaAdsSyncGuard::class);
    }

    private function stored(int $accountId): ?string
    {
        $value = DB::table('meta_ads_accounts')->where('id', $accountId)->value('sync_claimed_at');

        return $value === null ? null : (string) $value;
    }

    private function seedRun(int $accountId, int $businessId, string $state, ?Carbon $startedAt = null, ?Carbon $createdAt = null): int
    {
        return (int) DB::table('meta_ads_sync_runs')->insertGetId([
            'uid' => (string) Str::uuid(), 'business_id' => $businessId, 'meta_ads_account_id' => $accountId,
            'state' => $state, 'trigger' => 'manual', 'started_at' => $startedAt,
            'created_at' => $createdAt ?? now(), 'updated_at' => $createdAt ?? now(),
        ]);
    }

    public function test_only_one_of_two_concurrent_claimants_wins(): void
    {
        [, , $account] = $this->syncableAccount();

        $first = $this->guard()->acquire($account->id);
        $second = $this->guard()->acquire($account->id);

        $this->assertNotNull($first);
        $this->assertNull($second, 'the loser gets no claim');
        $this->assertSame('2026-10-04 12:00:00', $this->stored($account->id));
        $this->assertSame($account->id, $first->accountId);
        $this->assertSame('2026-10-04 12:00:00', $first->stamp);
    }

    public function test_the_claim_is_one_conditional_update_not_a_read_then_write(): void
    {
        [, , $account] = $this->syncableAccount();
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = strtolower($query->sql);
        });

        $this->guard()->acquire($account->id);

        $claimUpdates = array_values(array_filter($statements, fn (string $sql) => str_starts_with($sql, 'update') && str_contains($sql, 'meta_ads_accounts') && str_contains($sql, 'sync_claimed_at')));
        $this->assertCount(1, $claimUpdates);
        $this->assertMatchesRegularExpression('/where.*`id` = \?.*sync_claimed_at.*is null.*or.*sync_claimed_at.*< \?/s', $claimUpdates[0], 'compare-and-set: free or stale, decided inside the UPDATE');
        $this->assertSame([], array_values(array_filter($statements, fn (string $sql) => str_starts_with($sql, 'select') && str_contains($sql, 'sync_claimed_at'))), 'no read-then-write window');
    }

    public function test_a_stale_claim_is_taken_over_but_a_fresh_one_is_not(): void
    {
        [, , $account] = $this->syncableAccount();

        DB::table('meta_ads_accounts')->where('id', $account->id)->update(['sync_claimed_at' => now()->subMinutes(14)]);
        $this->assertNull($this->guard()->acquire($account->id), '14 minutes old is still live');

        DB::table('meta_ads_accounts')->where('id', $account->id)->update(['sync_claimed_at' => now()->subMinutes(16)]);
        $taken = $this->guard()->acquire($account->id);

        $this->assertNotNull($taken);
        $this->assertSame('2026-10-04 12:00:00', $this->stored($account->id));
    }

    public function test_the_stale_window_is_fifteen_minutes(): void
    {
        $this->assertSame(15, MetaAdsSyncGuard::CLAIM_STALE_MINUTES);
        $this->assertSame(MetaAdsAccountSelector::CLAIM_STALE_MINUTES, MetaAdsSyncGuard::CLAIM_STALE_MINUTES, 'the selector and the guard agree');
        $this->assertSame(MetaAdsAccountSelector::QUEUED_RUN_WINDOW_MINUTES, MetaAdsSyncGuard::QUEUED_RUN_WINDOW_MINUTES);
    }

    public function test_a_claim_on_one_account_never_blocks_another(): void
    {
        [, , $a] = $this->syncableAccount('A');
        [, , $b] = $this->syncableAccount('B');

        $this->assertNotNull($this->guard()->acquire($a->id));
        $this->assertNotNull($this->guard()->acquire($b->id));
        $this->assertNull($this->stored(999_999));
    }

    public function test_a_heartbeat_moves_our_stamp_forward(): void
    {
        [, , $account] = $this->syncableAccount();
        $claim = $this->guard()->acquire($account->id);
        $this->advance(10);

        $this->assertTrue($this->guard()->heartbeat($claim));

        $this->assertSame('2026-10-04 12:10:00', $claim->stamp);
        $this->assertSame('2026-10-04 12:10:00', $this->stored($account->id));
        $this->assertNull($this->guard()->acquire($account->id), 'a refreshed claim is live for another 15 minutes');
    }

    public function test_a_same_second_heartbeat_reads_ownership_back(): void
    {
        [, , $account] = $this->syncableAccount();
        $claim = $this->guard()->acquire($account->id);

        $this->assertTrue($this->guard()->heartbeat($claim), 'same second, still ours');

        DB::table('meta_ads_accounts')->where('id', $account->id)->update(['sync_claimed_at' => '2026-10-04 11:59:00']);
        $this->assertFalse($this->guard()->heartbeat($claim), 'same second, no longer ours');
    }

    public function test_a_heartbeat_fails_once_the_claim_was_taken_over(): void
    {
        [, , $account] = $this->syncableAccount();
        $old = $this->guard()->acquire($account->id);
        $this->advance(16);
        $new = $this->guard()->acquire($account->id);
        $this->assertNotNull($new);

        $this->assertFalse($this->guard()->heartbeat($old), 'the old owner can no longer extend');
        $this->assertSame($new->stamp, $this->stored($account->id), 'and did not disturb the new one');
    }

    public function test_release_clears_only_our_own_claim(): void
    {
        [, , $account] = $this->syncableAccount();
        $old = $this->guard()->acquire($account->id);
        $this->advance(16);
        $new = $this->guard()->acquire($account->id);

        $this->guard()->release($old);
        $this->assertSame($new->stamp, $this->stored($account->id), 'the old job cannot release the new owner');

        $this->guard()->release($new);
        $this->assertNull($this->stored($account->id));

        $this->assertNotNull($this->guard()->acquire($account->id), 'a released account can be claimed again');
    }

    public function test_release_after_a_heartbeat_uses_the_moved_stamp(): void
    {
        [, , $account] = $this->syncableAccount();
        $claim = $this->guard()->acquire($account->id);
        $this->advance(5);
        $this->guard()->heartbeat($claim);

        $this->guard()->release($claim);

        $this->assertNull($this->stored($account->id));
    }

    public function test_taking_a_claim_retires_a_dead_workers_running_row_only(): void
    {
        [$business, , $account] = $this->syncableAccount();
        $dead = $this->seedRun($account->id, $business->id, 'running', now()->subMinutes(30));
        $young = $this->seedRun($account->id, $business->id, 'running', now()->subMinutes(5));
        DB::table('meta_ads_accounts')->where('id', $account->id)->update(['sync_claimed_at' => now()->subMinutes(20)]);

        $this->assertNotNull($this->guard()->acquire($account->id));

        $this->assertSame(MetaAdsSyncRunState::Failed->value, DB::table('meta_ads_sync_runs')->where('id', $dead)->value('state'));
        $this->assertSame('abandoned', DB::table('meta_ads_sync_runs')->where('id', $dead)->value('failure_code'));
        $this->assertSame(MetaAdsSyncRunState::Running->value, DB::table('meta_ads_sync_runs')->where('id', $young)->value('state'));
    }

    public function test_live_work_is_a_live_claim_a_recent_running_run_or_a_plausibly_waiting_queued_run(): void
    {
        [$business, , $account] = $this->syncableAccount();
        $selector = app(MetaAdsAccountSelector::class);
        $fresh = fn () => $account->fresh();

        $this->assertFalse($this->guard()->hasLiveWork($fresh()));
        $this->assertFalse($selector->hasLiveWork($fresh()));

        DB::table('meta_ads_accounts')->where('id', $account->id)->update(['sync_claimed_at' => now()->subMinutes(3)]);
        $this->assertTrue($this->guard()->hasLiveWork($fresh()));
        $this->assertSame($selector->hasLiveWork($fresh()), $this->guard()->hasLiveWork($fresh()));

        DB::table('meta_ads_accounts')->where('id', $account->id)->update(['sync_claimed_at' => now()->subMinutes(20)]);
        $this->assertFalse($this->guard()->hasLiveWork($fresh()), 'a stale claim is not live work');

        $queued = $this->seedRun($account->id, $business->id, 'queued', null, now()->subHours(1));
        $this->assertTrue($this->guard()->hasLiveWork($fresh()));
        $this->assertSame($selector->hasLiveWork($fresh()), $this->guard()->hasLiveWork($fresh()));

        DB::table('meta_ads_sync_runs')->where('id', $queued)->update(['created_at' => now()->subHours(4)]);
        $this->assertFalse($this->guard()->hasLiveWork($fresh()), 'a queued run older than 3 hours is lost');

        $running = $this->seedRun($account->id, $business->id, 'running', now()->subMinutes(2));
        $this->assertTrue($this->guard()->hasLiveWork($fresh()));

        DB::table('meta_ads_sync_runs')->where('id', $running)->update(['started_at' => now()->subMinutes(20)]);
        $this->assertFalse($this->guard()->hasLiveWork($fresh()), 'a running row older than the stale window is a dead worker');
        $this->assertSame($selector->hasLiveWork($fresh()), $this->guard()->hasLiveWork($fresh()));
    }

    public function test_a_terminal_run_is_not_live_work(): void
    {
        [$business, , $account] = $this->syncableAccount();

        foreach (['succeeded', 'partial', 'failed', 'skipped'] as $state) {
            $this->seedRun($account->id, $business->id, $state, now()->subMinutes(1));
        }

        $this->assertFalse($this->guard()->hasLiveWork($account->fresh()));
    }
}

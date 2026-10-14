<?php

namespace Tests\Feature\Assistant;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Assistant\AssistantActionService;
use App\Library\Assistant\AssistantContext;
use App\Models\AssistantActionAudit;
use App\Models\AssistantActionProposal;
use App\Models\BookingType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Assistant\Concerns\AssistantTestSupport;
use Tests\TestCase;

/**
 * Correction round 1, item 5: a fenced execution lease. A live lease is never taken; an expired one may be re-claimed
 * only with a new token and fence; and an executor that has been superseded can neither execute nor record anything.
 */
class AssistantActionLeaseTest extends TestCase
{
    use RefreshDatabase;
    use AssistantTestSupport;

    private AssistantContext $ctx;

    private AssistantActionService $svc;

    private AssistantActionProposal $proposal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAssistant();

        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Lease Venue', 'Lease Account');
        $this->authenticateAs($customer);
        $this->modelSays(['action' => 'propose', 'tool' => 'create_booking_type', 'args' => ['name' => 'Wedding package', 'duration_minutes' => 180], 'message' => 'Ready.']);
        $reply = $this->lastReply($this->say($workspace, $business, 'Create a wedding package booking type')->assertOk());

        $shell = app(\App\Library\Navigation\CustomerShellComposer::class);
        $customerContext = $shell->currentContext($customer->user);
        $entitlements = $shell->currentMenuEntitlements($customerContext);
        $this->ctx = new AssistantContext(
            $workspace, $business, $customer->user, $customerContext, $entitlements,
            app(\App\Library\Dashboard\Home\HomeActionRouter::class)->forActor($customerContext, $customer->user, $entitlements, [$workspace->uid, $business->uid], null),
            [$workspace->uid, $business->uid],
        );
        // forActor needs the first Location uid for Calendar actions.
        $location = \App\Models\BusinessLocation::query()->where('business_id', $business->id)->firstOrFail();
        $this->ctx = new AssistantContext($workspace, $business, $customer->user, $customerContext, $entitlements,
            app(\App\Library\Dashboard\Home\HomeActionRouter::class)->forActor($customerContext, $customer->user, $entitlements, [$workspace->uid, $business->uid], $location->uid),
            [$workspace->uid, $business->uid]);

        $this->svc = app(AssistantActionService::class);
        $this->proposal = AssistantActionProposal::query()->where('uid', $reply['proposal']['uid'])->firstOrFail();
    }

    private function created(): int
    {
        return BookingType::query()->where('name', 'Wedding package')->count();
    }

    public function test_a_live_lease_is_never_taken_over_by_a_competing_executor(): void
    {
        $a = $this->svc->claimForExecution($this->ctx, $this->proposal);
        $this->assertSame('claimed', $a['kind']);
        $this->assertSame(1, $a['fence']);
        $this->assertNotNull($a['token']);

        $b = $this->svc->claimForExecution($this->ctx, $this->proposal->fresh());
        $this->assertSame('busy', $b['kind'], 'A running executor is not superseded by a timer.');
        $this->assertSame(1, $this->proposal->fresh()->fence);
        $this->assertSame($a['token'], $this->proposal->fresh()->lease_token);
        $this->assertSame(0, $this->created());
    }

    public function test_slow_but_valid_execution_is_never_reclaimed_merely_because_a_threshold_passed(): void
    {
        $a = $this->svc->claimForExecution($this->ctx, $this->proposal);
        Carbon::setTestNow(now()->addSeconds(30)); // old code reclaimed after 120s of silence; the lease is 60s and still live
        $this->assertSame('busy', $this->svc->claimForExecution($this->ctx, $this->proposal->fresh())['kind']);

        // The slow executor finishes inside its own (renewed) lease: it completes, exactly once.
        $done = $this->svc->executeClaimed($this->ctx, $a['proposal'], $a['token'], $a['fence']);
        $this->assertSame('completed', $done['state']);
        $this->assertTrue($done['transitioned']);
        $this->assertSame(1, $this->created());
        Carbon::setTestNow();
    }

    public function test_expired_ownership_is_reclaimed_with_a_new_fence_and_the_old_executor_is_fenced_out(): void
    {
        $a = $this->svc->claimForExecution($this->ctx, $this->proposal);

        // Executor A stalls; its lease lapses; B claims.
        AssistantActionProposal::query()->whereKey($this->proposal->id)->update(['lease_expires_at' => now()->subSecond()]);
        $b = $this->svc->claimForExecution($this->ctx, $this->proposal->fresh());
        $this->assertSame('claimed', $b['kind']);
        $this->assertSame(2, $b['fence']);
        $this->assertNotSame($a['token'], $b['token']);

        // A wakes up late and tries to complete: it finds a different token/fence, writes NOTHING.
        $late = $this->svc->executeClaimed($this->ctx, $a['proposal'], $a['token'], $a['fence']);
        $this->assertFalse($late['transitioned']);
        $this->assertSame('busy', $late['state'], 'Still B\'s: executing.');
        $this->assertSame(0, $this->created(), 'The superseded executor created nothing.');
        $this->assertSame('executing', $this->proposal->fresh()->status);

        // B completes: one effect.
        $done = $this->svc->executeClaimed($this->ctx, $b['proposal'], $b['token'], $b['fence']);
        $this->assertSame('completed', $done['state']);
        $this->assertSame(1, $this->created());

        // A, even later still, after completion: nothing more.
        $again = $this->svc->executeClaimed($this->ctx, $a['proposal'], $a['token'], $a['fence']);
        $this->assertFalse($again['transitioned']);
        $this->assertSame('completed', $again['state']);
        $this->assertSame(1, $this->created(), 'Never a duplicated effect.');
        $this->assertSame(1, AssistantActionAudit::query()->where('event', 'executed')->count());
    }

    public function test_a_superseded_executor_that_fails_records_no_failure(): void
    {
        $a = $this->svc->claimForExecution($this->ctx, $this->proposal);
        AssistantActionProposal::query()->whereKey($this->proposal->id)->update(['lease_expires_at' => now()->subSecond()]);
        $b = $this->svc->claimForExecution($this->ctx, $this->proposal->fresh());

        $failedBefore = AssistantActionAudit::query()->where('event', 'failed')->count();
        $r = $this->svc->executeClaimed($this->ctx, $a['proposal'], $a['token'], $a['fence']);

        $this->assertSame($failedBefore, AssistantActionAudit::query()->where('event', 'failed')->count());
        $this->assertSame($b['token'], $this->proposal->fresh()->lease_token, 'B still owns it.');
        $this->assertFalse($r['transitioned']);
    }

    public function test_a_failed_attempt_can_be_retried_under_a_new_fence_and_completes_once(): void
    {
        // Force a genuine recorded failure: the Location disappears from reach for the first attempt only.
        $a = $this->svc->claimForExecution($this->ctx, $this->proposal);
        $broken = $this->proposal->params;
        AssistantActionProposal::query()->whereKey($this->proposal->id)->update(['params' => array_merge($broken, ['location_uid' => 'gone'])]);
        $failed = $this->svc->executeClaimed($this->ctx, $a['proposal']->fresh(), $a['token'], $a['fence']);
        $this->assertSame('failed', $failed['state']);
        $this->assertTrue($failed['transitioned']);
        $this->assertNull($this->proposal->fresh()->lease_token);

        AssistantActionProposal::query()->whereKey($this->proposal->id)->update(['params' => $broken]);
        $retry = $this->svc->claimForExecution($this->ctx, $this->proposal->fresh());
        $this->assertSame('claimed', $retry['kind']);
        $this->assertSame(2, $retry['fence']);

        $done = $this->svc->executeClaimed($this->ctx, $retry['proposal'], $retry['token'], $retry['fence']);
        $this->assertSame('completed', $done['state']);
        $this->assertSame(1, $this->created());
        $this->assertSame(2, $this->proposal->fresh()->attempts);
        $this->assertSame(['proposed', 'approved', 'failed', 'retried', 'executed'], AssistantActionAudit::query()->orderBy('id')->pluck('event')->all());
    }

    public function test_lock_contention_from_a_running_executor_is_reported_as_busy_not_acted_on(): void
    {
        $m = new \ReflectionMethod(AssistantActionService::class, 'isLockContention');
        $m->setAccessible(true);
        $svc = $this->svc;
        $lockWait = new \Illuminate\Database\QueryException('mysql', 'select 1', [], new \PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded'));
        $this->assertTrue($m->invoke($svc, $lockWait));
        $other = new \Illuminate\Database\QueryException('mysql', 'select 1', [], new \PDOException('SQLSTATE[23000]: Integrity constraint violation'));
        $this->assertFalse($m->invoke($svc, $other), 'Any other database error still surfaces.');
    }
}

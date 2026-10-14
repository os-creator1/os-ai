<?php

namespace Tests\Feature\Assistant;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Ai\AiCompletionRequest;
use App\Library\Ai\AiCompletionResult;
use App\Library\Ai\Contracts\AiCompletionClient;
use App\Models\AssistantConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Assistant\Concerns\AssistantTestSupport;
use Tests\TestCase;

/** Correction round 1, item 4: one wall-clock budget per turn, a lock lease that outlives it, owner-only release. */
class AssistantTurnLockTest extends TestCase
{
    use RefreshDatabase;
    use AssistantTestSupport;

    /** @var object{seconds: int, during: ?\Closure} */
    private object $provider;

    /**
     * A provider whose every call "takes" N seconds of the advanceable clock and lets the test look around mid-call. It is bound ONCE,
     * before the first request: the route caches its controller (and so its gateway) across requests, so it is configured by
     * mutating this object, never by rebinding.
     */
    private function installProvider(): void
    {
        $inner = $this->fake;
        $this->provider = new class($inner) implements AiCompletionClient {
            public int $seconds = 0;

            public ?\Closure $during = null;

            public function __construct(private $inner)
            {
            }

            public function complete(AiCompletionRequest $request): AiCompletionResult
            {
                if ($this->during !== null) {
                    ($this->during)();
                }

                Carbon::setTestNow(Carbon::now()->addSeconds($this->seconds));

                return $this->inner->complete($request);
            }
        };
        $this->app->instance(AiCompletionClient::class, $this->provider);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAssistant();
        Carbon::setTestNow(Carbon::parse('2026-09-10 15:00:00', 'UTC'));
        $this->installProvider();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_turn_that_runs_out_of_its_budget_stops_safely_and_stays_retryable(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Slow Venue', 'Slow Account');
        $this->authenticateAs($customer);
        config(['assistant.turn_budget_seconds' => 75, 'assistant.min_seconds_for_model_call' => 30]);
        $this->provider->seconds = 30; // each provider call takes 30s of the 75s budget

        for ($i = 0; $i < 4; $i++) {
            $this->modelSays(['action' => 'call_tools', 'calls' => [['tool' => 'recent_work', 'args' => []]]]);
        }

        $r = $this->say($workspace, $business, 'Take your time')->assertOk();
        $reply = $this->lastReply($r);

        $this->assertSame('error', $reply['kind']);
        $this->assertSame('That took too long', $reply['payload']['title']);
        $this->assertTrue($reply['payload']['retryable']);
        $this->assertSame(2, $this->aiCalls(), 'The third call was never started: 15s left is less than a call needs.');

        // Retryable, and nothing is left locked.
        $conversation = AssistantConversation::query()->firstOrFail();
        $lock = Cache::lock('assistant:conversation:' . $conversation->id, 5);
        $this->assertTrue($lock->get(), 'The lock was released.');
        $lock->release();

        Carbon::setTestNow(Carbon::parse('2026-09-10 16:00:00', 'UTC'));
        $this->provider->seconds = 0;
        $this->modelSays(['action' => 'answer', 'message' => 'Back on track.']);
        $retry = $this->say($workspace, $business, 'Take your time', ['conversation' => $r->json('uid')])->assertOk();
        $this->assertSame('Back on track.', $this->lastReply($retry)['body'], json_encode($this->lastReply($retry)));
    }

    public function test_the_lock_is_held_for_the_whole_turn_its_lease_exceeds_the_budget_and_a_second_request_waits_its_turn(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Lock Venue', 'Lock Account');
        $this->authenticateAs($customer);
        config(['assistant.turn_budget_seconds' => 75, 'assistant.turn_lock_margin_seconds' => 45]);
        $this->modelSays(['action' => 'answer', 'message' => 'Ready.']);
        $first = $this->say($workspace, $business, 'Set up the conversation');
        $uid = $first->json('uid');
        $conversation = AssistantConversation::query()->where('uid', $uid)->firstOrFail();
        $key = 'assistant:conversation:' . $conversation->id;

        $seen = [];
        $this->provider->seconds = 1;
        $this->provider->during = function () use ($key, &$seen): void {
            $competitor = Cache::lock($key, 5);
            $seen['second_request_gets_lock'] = $competitor->get();
            $store = Cache::store()->getStore();
            $locks = (new \ReflectionProperty($store, 'locks'))->getValue($store);
            $seen['lease'] = (int) $locks[$key]['expiresAt']->getTimestamp() - Carbon::now()->getTimestamp();
            $this->provider->during = null;
        };
        $this->modelSays(['action' => 'answer', 'message' => 'Fine.']);
        $this->say($workspace, $business, 'Now a real one', ['conversation' => $uid])->assertOk();

        $this->assertFalse($seen['second_request_gets_lock'], 'Same-conversation work is serialized while a turn is running.');
        $this->assertGreaterThan(75, $seen['lease'], 'The lease outlives the turn budget.');
        $this->assertGreaterThanOrEqual(75 + 45 - 1, $seen['lease']);
    }

    public function test_a_second_request_for_a_busy_conversation_is_refused_without_calling_the_model(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Busy Venue', 'Busy Account');
        $this->authenticateAs($customer);
        $this->modelSays(['action' => 'answer', 'message' => 'Hi.']);
        $uid = $this->say($workspace, $business, 'Hello')->json('uid');
        $callsBefore = $this->aiCalls();

        $holder = Cache::lock('assistant:conversation:' . AssistantConversation::query()->value('id'), 120);
        $this->assertTrue($holder->get());

        $r = $this->say($workspace, $business, 'Concurrent', ['conversation' => $uid]);
        $r->assertStatus(409);
        $this->assertSame('busy', $r->json('status'));
        $this->assertSame($callsBefore, $this->aiCalls());
        $holder->release();
    }

    public function test_a_stale_owner_cannot_release_the_lock_a_newer_owner_holds(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Stale Venue', 'Stale Account');
        $this->authenticateAs($customer);
        $this->modelSays(['action' => 'answer', 'message' => 'Hi.']);
        $uid = $this->say($workspace, $business, 'Hello')->json('uid');
        $key = 'assistant:conversation:' . AssistantConversation::query()->value('id');

        // A previous request took the lock, then died: its lease lapses.
        $stale = Cache::lock($key, 1);
        $this->assertTrue($stale->get());
        Carbon::setTestNow(Carbon::now()->addSeconds(5));

        $outcome = [];
        $this->provider->seconds = 0;
        $this->provider->during = function () use ($stale, $key, &$outcome): void {
            // The dead request wakes up mid-way through the NEW owner's turn and tries to release.
            $outcome['stale_release'] = $stale->release();
            $probe = Cache::lock($key, 5);
            $outcome['still_held_by_new_owner'] = ! $probe->get();
            $this->provider->during = null;
        };
        $this->modelSays(['action' => 'answer', 'message' => 'Handled.']);
        $r = $this->say($workspace, $business, 'Next', ['conversation' => $uid])->assertOk();

        $this->assertSame('Handled.', $this->lastReply($r)['body'], 'The expired lease was cleaned up and the turn ran.');
        $this->assertFalse($outcome['stale_release'], 'Only the current owner can release.');
        $this->assertTrue($outcome['still_held_by_new_owner']);
    }
}

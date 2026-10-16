<?php

namespace Tests\Feature\Assistant;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Ai\AiCompletionRequest;
use App\Library\Ai\AiCompletionResult;
use App\Library\Ai\Contracts\AiCompletionClient;
use App\Library\Assistant\AssistantRetention;
use App\Models\AssistantActionProposal;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\BookingType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Feature\Assistant\Concerns\AssistantTestSupport;
use Tests\TestCase;

/**
 * Correction round 2, item 2: a deletion (the customer's or the scheduled sweep) coordinates with an active turn through the
 * conversation's owned turn lock. It never removes rows underneath a running turn, never holds a database lock across a provider
 * call, answers "busy" instead of hanging, and a stale worker can never recreate content beneath a deleted conversation.
 */
class AssistantDeleteTurnRaceTest extends TestCase
{
    use RefreshDatabase;
    use AssistantTestSupport;

    /** @var object{during: ?\Closure} */
    private object $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAssistant();
        config(['assistant.delete_wait_seconds' => 0]);
        $inner = $this->fake;
        $this->provider = new class($inner) implements AiCompletionClient {
            public ?\Closure $during = null;

            public function __construct(private $inner)
            {
            }

            public function complete(AiCompletionRequest $request): AiCompletionResult
            {
                if ($this->during !== null) {
                    $hook = $this->during;
                    $this->during = null;
                    $hook();
                }

                return $this->inner->complete($request);
            }
        };
        $this->app->instance(AiCompletionClient::class, $this->provider);
    }

    private function start(): array
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Race Venue', 'Race Account');
        $this->authenticateAs($customer);
        $this->modelSays(['action' => 'answer', 'message' => 'Hello.']);
        $uid = $this->say($workspace, $business, 'Hi')->assertOk()->json('uid');

        return [$customer, $business, $workspace, AssistantConversation::query()->where('uid', $uid)->firstOrFail()];
    }

    private function key(AssistantConversation $c): string
    {
        return 'assistant:conversation:' . $c->id;
    }

    public function test_a_delete_during_a_running_turn_is_refused_busy_and_the_turn_still_commits_its_reply(): void
    {
        [, $business, $workspace, $conversation] = $this->start();
        $during = [];

        $this->provider->during = function () use ($conversation, &$during): void {
            $during['service_delete'] = app(AssistantRetention::class)->delete($conversation, 'customer_request', 0);
            $during['still_there'] = AssistantConversation::query()->whereKey($conversation->id)->exists();
        };
        $this->modelSays(['action' => 'answer', 'message' => 'Finished.']);
        $r = $this->say($workspace, $business, 'Second question', ['conversation' => $conversation->uid])->assertOk();

        $this->assertFalse($during['service_delete'], 'Nothing is deleted underneath a running turn.');
        $this->assertTrue($during['still_there']);
        $this->assertSame('Finished.', $this->lastReply($r)['body'], 'The turn committed its reply: no foreign-key error, no 500.');

        // Once the turn is done the same delete goes through (customer request: friendly, complete, nothing left).
        $this->postJson($this->assistantUrl($workspace, $business, 'delete', [$conversation->uid]))->assertOk()->assertJson(['deleted' => true]);
        $this->assertSame(0, AssistantConversation::query()->count());
        $this->assertSame(0, AssistantMessage::query()->count());
    }

    public function test_the_customers_delete_over_http_answers_a_bounded_busy_while_a_turn_holds_the_lock_and_deletes_nothing(): void
    {
        [, $business, $workspace, $conversation] = $this->start();
        $holder = Cache::lock($this->key($conversation), 120);
        $this->assertTrue($holder->get());

        $r = $this->postJson($this->assistantUrl($workspace, $business, 'delete', [$conversation->uid]));

        $r->assertStatus(409)->assertJson(['deleted' => false]);
        $this->assertStringContainsString('Nothing was deleted', (string) $r->json('message'), 'Never appears to have succeeded while data remains.');
        $this->assertSame(1, AssistantConversation::query()->count());
        $this->assertGreaterThan(0, AssistantMessage::query()->count());

        $holder->release();
        $this->postJson($this->assistantUrl($workspace, $business, 'delete', [$conversation->uid]))->assertOk();
        $this->assertSame(0, AssistantConversation::query()->count());
    }

    public function test_a_stale_worker_whose_conversation_was_deleted_writes_nothing_and_does_not_crash(): void
    {
        [, $business, $workspace, $conversation] = $this->start();
        $messagesBefore = AssistantMessage::query()->count();

        // The worker's lease lapsed (it should never) and a deletion legitimately took over mid-call.
        $this->provider->during = function () use ($conversation): void {
            Cache::lock($this->key($conversation), 1)->forceRelease();
            $this->assertTrue(app(AssistantRetention::class)->delete($conversation, 'customer_request', 0));
        };
        $this->modelSays(['action' => 'answer', 'message' => 'Too late to be written.']);
        $r = $this->say($workspace, $business, 'Another one', ['conversation' => $conversation->uid]);

        $this->assertLessThan(500, $r->status(), 'No server error.');
        $this->assertSame(0, AssistantConversation::query()->count());
        $this->assertSame(0, AssistantMessage::query()->count(), 'The stale worker recreated no content ('.$messagesBefore.' messages existed before the delete).');
        $this->assertStringNotContainsString('Too late to be written.', (string) $r->getContent());
    }

    public function test_a_stale_worker_that_proposes_an_action_after_its_conversation_was_deleted_creates_no_orphan(): void
    {
        [, $business, $workspace, $conversation] = $this->start();

        $this->provider->during = function () use ($conversation): void {
            Cache::lock($this->key($conversation), 1)->forceRelease();
            app(AssistantRetention::class)->delete($conversation, 'customer_request', 0);
        };
        $this->modelSays(['action' => 'propose', 'tool' => 'create_booking_type', 'args' => ['name' => 'Ghost package', 'duration_minutes' => 60], 'message' => 'Ready.']);
        $r = $this->say($workspace, $business, 'Create a ghost package booking type', ['conversation' => $conversation->uid]);

        $this->assertLessThan(500, $r->status(), 'The foreign-key failure is not surfaced as a 500.');
        $this->assertSame(0, AssistantActionProposal::query()->count(), 'No proposal was left behind for a conversation that no longer exists.');
        $this->assertSame(0, AssistantMessage::query()->count());
        $this->assertSame(0, BookingType::query()->where('name', 'Ghost package')->count());
    }

    public function test_an_action_approved_after_its_conversation_was_deleted_is_final_and_has_no_effect(): void
    {
        [, $business, $workspace, $conversation] = $this->start();
        $this->modelSays(['action' => 'propose', 'tool' => 'create_booking_type', 'args' => ['name' => 'Late package', 'duration_minutes' => 60], 'message' => 'Ready.']);
        $proposalUid = $this->lastReply($this->say($workspace, $business, 'Create a late package booking type', ['conversation' => $conversation->uid])->assertOk())['proposal']['uid'];

        $this->postJson($this->assistantUrl($workspace, $business, 'delete', [$conversation->uid]))->assertOk();
        $this->postJson(route('customer.workspaces.businesses.home.assistant.approve', [$workspace->uid, $business->uid, $proposalUid]));

        $this->assertSame(0, BookingType::query()->where('name', 'Late package')->count());
        $p = AssistantActionProposal::query()->where('uid', $proposalUid)->firstOrFail();
        $this->assertSame('declined', $p->status);
        $this->assertNull($p->conversation_id);
    }

    public function test_the_scheduled_sweep_never_waits_for_a_conversation_whose_turn_is_running_and_gets_it_next_time(): void
    {
        [, , , $conversation] = $this->start();
        config(['assistant.retention_days' => 90]); // the sweep only acts under a positive retention period (default is keep-until-deleted)
        AssistantConversation::query()->whereKey($conversation->id)->update(['last_message_at' => now()->subDays(120)]);
        $holder = Cache::lock($this->key($conversation), 120);
        $this->assertTrue($holder->get());

        $t = microtime(true);
        $deleted = app(AssistantRetention::class)->purgeExpired();

        $this->assertLessThan(2.0, microtime(true) - $t, 'No wait.');
        $this->assertSame(0, $deleted);
        $this->assertSame(1, AssistantConversation::query()->count(), 'Left for the next run, not half-deleted.');

        $holder->release();
        $this->assertSame(1, app(AssistantRetention::class)->purgeExpired());
        $this->assertSame(0, app(AssistantRetention::class)->purgeExpired(), 'Idempotent.');
        $this->assertSame(0, AssistantMessage::query()->count());
    }

    public function test_the_turn_lock_is_released_after_a_delete_so_a_crash_cannot_wedge_the_conversation(): void
    {
        [, , , $conversation] = $this->start();

        $this->assertTrue(app(AssistantRetention::class)->delete($conversation, 'customer_request', 0));
        $probe = Cache::lock($this->key($conversation), 5);
        $this->assertTrue($probe->get(), 'The deletion released its lock.');
        $probe->release();
    }
}

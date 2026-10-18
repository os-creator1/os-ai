<?php

namespace Tests\Feature\Assistant;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Ai\AiCompletionRequest;
use App\Library\Ai\AiCompletionResult;
use App\Library\Ai\Contracts\AiCompletionClient;
use App\Library\Assistant\AssistantContext;
use App\Library\Assistant\AssistantService;
use App\Models\AiUsageLedgerEntry;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\BusinessLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Assistant\Concerns\AssistantTestSupport;
use Tests\TestCase;

/**
 * PR #460 review (round 2), P2: `assistant.conversation_max_messages` applies to every request that would add to a conversation —
 * addressed (the browser's normal follow-up, which names the conversation) as well as unaddressed. At the cap a genuinely new turn
 * starts a new conversation, a replayed message id still gets its original result, and two requests cannot make two replacements.
 */
class AssistantConversationCapTest extends TestCase
{
    use RefreshDatabase;
    use AssistantTestSupport;

    private const CAP = 6;

    /** @var object{during: ?\Closure} */
    private object $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAssistant();
        config(['assistant.conversation_max_messages' => self::CAP, 'assistant.first_message_lock_wait_seconds' => 1]);
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

    /** A conversation of this actor that has reached the cap. */
    private function fullConversation($customer, $business, $workspace): AssistantConversation
    {
        $c = AssistantConversation::create([
            'uid' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'business_id' => $business->id, 'user_id' => $customer->user_id,
            'title' => 'Long chat', 'message_count' => self::CAP, 'last_message_at' => now(),
        ]);
        AssistantMessage::create(['conversation_id' => $c->id, 'uid' => (string) Str::uuid(), 'role' => 'user', 'kind' => 'text', 'body' => 'Earlier question', 'created_at' => now()]);

        return $c;
    }

    private function send($workspace, $business, string $uid, string $id, string $q = 'Next question')
    {
        return $this->postJson($this->assistantUrl($workspace, $business), ['q' => $q, 'client_message_id' => $id, 'conversation' => $uid]);
    }

    private function charged(): int
    {
        return AiUsageLedgerEntry::query()->where('status', 'committed')->count();
    }

    private function ctx($customer, $business, $workspace): AssistantContext
    {
        $location = BusinessLocation::query()->where('business_id', $business->id)->firstOrFail();
        $shell = app(\App\Library\Navigation\CustomerShellComposer::class);
        $cc = $shell->currentContext($customer->user);
        $ent = $shell->currentMenuEntitlements($cc);

        return new AssistantContext($workspace, $business, $customer->user, $cc, $ent,
            app(\App\Library\Dashboard\Home\HomeActionRouter::class)->forActor($cc, $customer->user, $ent, [$workspace->uid, $business->uid], $location->uid), [$workspace->uid, $business->uid]);
    }

    public function test_an_addressed_follow_up_at_the_cap_starts_a_new_conversation_and_returns_its_uid(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Cap Venue', 'Cap Account');
        $this->authenticateAs($customer);
        $full = $this->fullConversation($customer, $business, $workspace);
        $this->modelSays(['action' => 'answer', 'message' => 'Fresh start.']);

        $r = $this->send($workspace, $business, $full->uid, (string) Str::uuid())->assertOk();

        $this->assertNotSame($full->uid, $r->json('uid'), 'The browser is told the NEW conversation uid.');
        $this->assertSame(self::CAP, (int) $full->fresh()->message_count, 'The full conversation was not grown past the cap.');
        $this->assertSame(1, AssistantMessage::query()->where('conversation_id', $full->id)->count(), 'And nothing was deleted from it.');
        $new = AssistantConversation::query()->where('uid', $r->json('uid'))->firstOrFail();
        $this->assertSame($customer->user_id, $new->user_id);
        $this->assertSame($business->id, $new->business_id);
        $this->assertSame('Fresh start.', $this->lastReply($r)['body']);
        $this->assertSame(2, AssistantConversation::query()->count());
        $this->assertSame(1, $this->aiCalls());
    }

    public function test_a_repeated_request_id_after_the_rollover_returns_the_original_result_without_another_call_or_charge(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Replay Venue', 'Replay Account');
        $this->authenticateAs($customer);
        $full = $this->fullConversation($customer, $business, $workspace);
        $id = (string) Str::uuid();
        $this->modelSays(['action' => 'answer', 'message' => 'Answered once.']);

        $first = $this->send($workspace, $business, $full->uid, $id)->assertOk();
        // The response was lost: the browser still believes it is in the OLD, now full, conversation.
        $retryOld = $this->send($workspace, $business, $full->uid, $id)->assertOk();
        $retryNew = $this->send($workspace, $business, $first->json('uid'), $id)->assertOk();
        $retryNone = $this->postJson($this->assistantUrl($workspace, $business), ['q' => 'Next question', 'client_message_id' => $id])->assertOk();

        foreach ([$retryOld, $retryNew, $retryNone] as $retry) {
            $this->assertSame($first->json('uid'), $retry->json('uid'), 'Always the conversation that holds the original result.');
            $this->assertSame('replayed', $retry->json('status'));
            $this->assertSame('Answered once.', $this->lastReply($retry)['body']);
        }
        $this->assertSame(1, $this->aiCalls(), 'No second model call.');
        $this->assertSame(1, $this->charged(), 'No second charge.');
        $this->assertSame(2, AssistantConversation::query()->count());
        $this->assertSame(1, AssistantMessage::query()->where('client_message_id', $id)->count());
    }

    public function test_below_the_cap_an_addressed_follow_up_stays_in_its_conversation(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Below Venue', 'Below Account');
        $this->authenticateAs($customer);
        $this->modelSays(['action' => 'answer', 'message' => 'One.']);
        $uid = $this->say($workspace, $business, 'Hello')->json('uid');
        $this->modelSays(['action' => 'answer', 'message' => 'Two.']);

        $r = $this->send($workspace, $business, $uid, (string) Str::uuid())->assertOk();

        $this->assertSame($uid, $r->json('uid'));
        $this->assertSame(1, AssistantConversation::query()->count());
    }

    public function test_two_different_requests_at_the_cap_make_one_replacement_not_two(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Twin Venue', 'Twin Account');
        $this->authenticateAs($customer);
        $full = $this->fullConversation($customer, $business, $workspace);
        $ctx = $this->ctx($customer, $business, $workspace);
        $nested = [];
        $idB = (string) Str::uuid();

        // While the first request is mid-turn in its replacement conversation, a second, different request names the old one.
        $this->provider->during = function () use ($ctx, $full, $idB, &$nested): void {
            $nested = app(AssistantService::class)->send($ctx, $full->uid, 'Second question', $idB);
        };
        $this->modelSays(['action' => 'answer', 'message' => 'First.']);
        $this->modelSays(['action' => 'answer', 'message' => 'Second.']);

        $first = $this->send($workspace, $business, $full->uid, (string) Str::uuid(), 'First question')->assertOk();

        $this->assertSame(2, AssistantConversation::query()->count(), 'Exactly one replacement conversation.');
        $this->assertSame('busy', $nested['status'] ?? null, 'The second turn is held while the first works on the replacement.');
        $this->assertSame($first->json('uid'), $nested['uid'] ?? null, 'It was pointed at the same replacement, not a third conversation.');
        $this->assertSame(1, $this->aiCalls());
    }

    public function test_two_requests_racing_to_create_the_replacement_do_not_make_two(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Race Cap Venue', 'Race Cap Account');
        $this->authenticateAs($customer);
        $full = $this->fullConversation($customer, $business, $workspace);
        $ctx = $this->ctx($customer, $business, $workspace);
        $fired = false;
        $nested = [];

        AssistantConversation::creating(function () use (&$fired, &$nested, $ctx, $full): void {
            if ($fired) {
                return;
            }
            $fired = true;
            $nested = app(AssistantService::class)->send($ctx, $full->uid, 'Twin question', (string) Str::uuid());
        });
        $this->modelSays(['action' => 'answer', 'message' => 'A.']);
        $this->modelSays(['action' => 'answer', 'message' => 'B.']);

        $this->send($workspace, $business, $full->uid, (string) Str::uuid(), 'Original question');

        $this->assertSame(2, AssistantConversation::query()->count(), 'One full + exactly one replacement.');
        $this->assertSame(1, $this->aiCalls());
        $this->assertNotSame('ok', $nested['status'] ?? 'ok');
    }

    public function test_the_cap_is_per_actor_and_business_and_never_touches_anyone_elses_conversation(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Mine Venue', 'Mine Account');
        [$other, $otherBusiness, $otherWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'Theirs Venue', 'Theirs Account');
        $theirs = $this->fullConversation($other, $otherBusiness, $otherWorkspace);

        $this->authenticateAs($customer);
        $this->modelSays(['action' => 'answer', 'message' => 'Mine.']);
        $mine = $this->say($workspace, $business, 'Hello')->assertOk()->json('uid');
        $this->assertSame(1, AssistantConversation::query()->where('business_id', $business->id)->count(), 'Another Business being full does not affect this one.');

        // Naming someone else's (full) conversation is the same "not found" as ever - it neither rolls over nor reveals it.
        $this->send($workspace, $business, $theirs->uid, (string) Str::uuid())->assertNotFound();
        $this->assertSame(self::CAP, (int) $theirs->fresh()->message_count);
        $this->assertSame(1, AssistantConversation::query()->where('business_id', $business->id)->count());
        $this->assertSame($mine, AssistantConversation::query()->where('business_id', $business->id)->value('uid'));
    }
}

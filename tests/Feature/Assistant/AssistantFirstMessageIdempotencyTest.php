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
 * PR #460 review, P2: a message is identified by its client_message_id within the actor's Workspace/Business, not only within a
 * conversation. A retry whose first response was lost — with no conversation uid, or with new=true — is the same message: same
 * conversation, same reply, and the model (and the allowance) is used once.
 */
class AssistantFirstMessageIdempotencyTest extends TestCase
{
    use RefreshDatabase;
    use AssistantTestSupport;

    /** @var object{during: ?\Closure} */
    private object $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAssistant();
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

    private function sendMsg($workspace, $business, string $id, string $q = 'Hello there', array $extra = [])
    {
        return $this->postJson($this->assistantUrl($workspace, $business), array_merge(['q' => $q, 'client_message_id' => $id], $extra));
    }

    private function charged(): int
    {
        return AiUsageLedgerEntry::query()->where('status', 'committed')->count();
    }

    public function test_a_retry_of_the_first_message_after_a_lost_response_is_the_same_message(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Idem Venue', 'Idem Account');
        $this->authenticateAs($customer);
        $id = (string) Str::uuid();
        $this->modelSays(['action' => 'answer', 'message' => 'First and only answer.']);

        $first = $this->sendMsg($workspace, $business, $id)->assertOk();
        $retry = $this->sendMsg($workspace, $business, $id)->assertOk(); // the response was lost: no conversation uid this time

        $this->assertSame($first->json('uid'), $retry->json('uid'), 'The same conversation.');
        $this->assertSame(1, AssistantConversation::query()->count(), 'No second conversation.');
        $this->assertSame(1, $this->aiCalls(), 'The model was used once.');
        $this->assertSame(1, $this->charged(), 'Charged once.');
        $this->assertSame('replayed', $retry->json('status'));
        $this->assertSame('First and only answer.', $this->lastReply($retry)['body']);
        $this->assertSame(1, AssistantMessage::query()->where('client_message_id', $id)->count());
    }

    public function test_a_retry_with_new_true_is_idempotent_but_a_genuinely_new_message_starts_a_new_conversation(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'New Venue', 'New Account');
        $this->authenticateAs($customer);
        $this->modelSays(['action' => 'answer', 'message' => 'One.']);
        $this->modelSays(['action' => 'answer', 'message' => 'Two.']);
        $idOne = (string) Str::uuid();

        $a = $this->sendMsg($workspace, $business, $idOne, 'Hello', ['new' => true])->assertOk();
        $b = $this->sendMsg($workspace, $business, $idOne, 'Hello', ['new' => true])->assertOk();
        $this->assertSame($a->json('uid'), $b->json('uid'));
        $this->assertSame(1, AssistantConversation::query()->count());
        $this->assertSame(1, $this->aiCalls());

        $c = $this->sendMsg($workspace, $business, (string) Str::uuid(), 'A different question', ['new' => true])->assertOk();
        $this->assertNotSame($a->json('uid'), $c->json('uid'), 'A genuinely new id with new=true is an intentional new conversation.');
        $this->assertSame(2, AssistantConversation::query()->count());
        $this->assertSame(2, $this->aiCalls());
        $this->assertSame(2, $this->charged());
    }

    public function test_the_same_client_id_in_another_business_or_by_another_user_is_a_different_message(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Scope A', 'Scope Account A');
        [$other, $otherBusiness, $otherWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'Scope B', 'Scope Account B');
        $id = (string) Str::uuid();
        $this->modelSays(['action' => 'answer', 'message' => 'For A.']);
        $this->modelSays(['action' => 'answer', 'message' => 'For B.']);

        $this->authenticateAs($customer);
        $a = $this->sendMsg($workspace, $business, $id)->assertOk();
        $this->authenticateAs($other);
        $b = $this->sendMsg($otherWorkspace, $otherBusiness, $id)->assertOk();

        $this->assertNotSame($a->json('uid'), $b->json('uid'));
        $this->assertSame(2, AssistantConversation::query()->count());
        $this->assertSame(2, $this->aiCalls(), 'Neither request is mistaken for the other.');
        $this->assertSame('For B.', $this->lastReply($b)['body']);
        $this->assertSame(1, AssistantConversation::query()->where('business_id', $business->id)->count());
        $this->assertSame(1, AssistantConversation::query()->where('business_id', $otherBusiness->id)->count());
    }

    public function test_concurrent_first_messages_with_the_same_id_make_one_conversation_and_one_charge(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Race Venue', 'Race Account');
        $this->authenticateAs($customer);
        $id = (string) Str::uuid();
        $location = BusinessLocation::query()->where('business_id', $business->id)->firstOrFail();
        $shell = app(\App\Library\Navigation\CustomerShellComposer::class);
        $cc = $shell->currentContext($customer->user);
        $ent = $shell->currentMenuEntitlements($cc);
        $ctx = new AssistantContext($workspace, $business, $customer->user, $cc, $ent,
            app(\App\Library\Dashboard\Home\HomeActionRouter::class)->forActor($cc, $customer->user, $ent, [$workspace->uid, $business->uid], $location->uid), [$workspace->uid, $business->uid]);

        $nested = [];
        // While the first request is mid-turn (holding its conversation), the identical request arrives again.
        $this->provider->during = function () use ($ctx, $id, &$nested): void {
            $nested = app(AssistantService::class)->send($ctx, null, 'Hello there', $id);
        };
        $this->modelSays(['action' => 'answer', 'message' => 'Only once.']);

        $first = $this->sendMsg($workspace, $business, $id)->assertOk();

        $this->assertSame('busy', $nested['status'] ?? null, 'The duplicate is told the conversation is busy; it does not run.');
        $this->assertSame($first->json('uid'), $nested['uid'] ?? null, 'It resolved to the same conversation.');
        $this->assertSame(1, AssistantConversation::query()->count());
        $this->assertSame(1, $this->aiCalls());
        $this->assertSame(1, $this->charged());
        $this->assertSame(1, AssistantMessage::query()->where('client_message_id', $id)->count());
    }

    public function test_two_first_requests_racing_to_create_the_conversation_make_one_conversation_and_one_charge(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Create Venue', 'Create Account');
        $this->authenticateAs($customer);
        $id = (string) Str::uuid();
        $location = BusinessLocation::query()->where('business_id', $business->id)->firstOrFail();
        $shell = app(\App\Library\Navigation\CustomerShellComposer::class);
        $cc = $shell->currentContext($customer->user);
        $ent = $shell->currentMenuEntitlements($cc);
        $ctx = new AssistantContext($workspace, $business, $customer->user, $cc, $ent,
            app(\App\Library\Dashboard\Home\HomeActionRouter::class)->forActor($cc, $customer->user, $ent, [$workspace->uid, $business->uid], $location->uid), [$workspace->uid, $business->uid]);

        config(['assistant.first_message_lock_wait_seconds' => 1]);

        // The duplicate arrives at the exact moment the first request is creating its conversation: neither yet exists for the other.
        $fired = false;
        $nested = [];
        AssistantConversation::creating(function () use (&$fired, &$nested, $ctx, $id): void {
            if ($fired) {
                return;
            }
            $fired = true;
            $nested = app(AssistantService::class)->send($ctx, null, 'Hello there', $id);
        });
        $this->modelSays(['action' => 'answer', 'message' => 'Only once.']);
        $this->modelSays(['action' => 'answer', 'message' => 'Twice would be a double charge.']);

        $first = $this->sendMsg($workspace, $business, $id);

        $this->assertLessThan(500, $first->status());
        $this->assertSame(1, AssistantConversation::query()->count(), 'One conversation for one message.');
        $this->assertSame(1, $this->aiCalls(), 'The model ran once.');
        $this->assertSame(1, $this->charged(), 'Charged once.');
        $this->assertSame(1, AssistantMessage::query()->where('client_message_id', $id)->count());
        $this->assertNotSame('ok', $nested['status'] ?? 'ok', 'The duplicate did not run a turn of its own.');
    }
}

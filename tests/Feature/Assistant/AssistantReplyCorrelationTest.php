<?php

namespace Tests\Feature\Assistant;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Ai\AiCompletionRequest;
use App\Library\Ai\AiCompletionResult;
use App\Library\Ai\Contracts\AiCompletionClient;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Assistant\Concerns\AssistantTestSupport;
use Tests\TestCase;

/**
 * PR #460 review, P2: only a real ANSWER to a user's message counts as its reply. An action outcome (or the first-use notice)
 * that lands after the user's row — as it can when an approval races a message turn — is not an answer and must not make the
 * service skip the model and report the question as already replied-to.
 */
class AssistantReplyCorrelationTest extends TestCase
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

    private function outcome(AssistantConversation $c, string $kind = 'outcome'): AssistantMessage
    {
        return AssistantMessage::create(['conversation_id' => $c->id, 'uid' => (string) Str::uuid(), 'role' => 'assistant', 'kind' => $kind, 'body' => 'The booking type was created.', 'payload' => ['state' => 'completed'], 'created_at' => now()]);
    }

    private function answers(AssistantConversation $c): int
    {
        return AssistantMessage::query()->where('conversation_id', $c->id)->where('role', 'assistant')->whereNotIn('kind', ['outcome', 'notice'])->count();
    }

    public function test_an_outcome_recorded_after_the_user_message_does_not_stand_in_for_its_answer(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Corr Venue', 'Corr Account');
        $this->authenticateAs($customer);
        $this->modelSays(['action' => 'answer', 'message' => 'Setup.']);
        $uid = $this->say($workspace, $business, 'Hello')->json('uid');
        $conversation = AssistantConversation::query()->where('uid', $uid)->firstOrFail();

        // The user's question has been stored (its turn has begun) and an approval's outcome lands right after it.
        $id = (string) Str::uuid();
        $user = AssistantMessage::create(['conversation_id' => $conversation->id, 'uid' => (string) Str::uuid(), 'client_message_id' => $id, 'role' => 'user', 'kind' => 'text', 'body' => 'How many bookings this week?', 'created_at' => now()]);
        $this->outcome($conversation);
        $callsBefore = $this->aiCalls();
        $this->modelSays(['action' => 'answer', 'message' => 'Three bookings.']);

        $r = $this->postJson($this->assistantUrl($workspace, $business), ['q' => 'How many bookings this week?', 'client_message_id' => $id, 'conversation' => $uid])->assertOk();

        $this->assertSame($callsBefore + 1, $this->aiCalls(), 'The question was really answered by the model.');
        $this->assertSame('ok', $r->json('status'), 'Not reported as already replied-to.');
        $this->assertSame('Three bookings.', $this->lastReply($r)['body']);
        $answersAfter = AssistantMessage::query()->where('conversation_id', $conversation->id)->where('role', 'assistant')->where('id', '>', $user->id)->whereNotIn('kind', ['outcome', 'notice'])->count();
        $this->assertSame(1, $answersAfter, 'Exactly one actual answer.');
    }

    public function test_an_outcome_arriving_during_the_turn_leaves_exactly_one_answer_and_a_repeat_replays_it(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Mid Venue', 'Mid Account');
        $this->authenticateAs($customer);
        $this->modelSays(['action' => 'answer', 'message' => 'Setup.']);
        $uid = $this->say($workspace, $business, 'Hello')->json('uid');
        $conversation = AssistantConversation::query()->where('uid', $uid)->firstOrFail();
        $answersBefore = $this->answers($conversation);

        $this->provider->during = fn () => $this->outcome($conversation); // an approval completes while the model is thinking
        $this->modelSays(['action' => 'answer', 'message' => 'The one answer.']);
        $id = (string) Str::uuid();
        $first = $this->postJson($this->assistantUrl($workspace, $business), ['q' => 'What now?', 'client_message_id' => $id, 'conversation' => $uid])->assertOk();

        $this->assertSame($answersBefore + 1, $this->answers($conversation));
        $this->assertSame('The one answer.', collect($first->json('messages'))->where('kind', 'answer')->last()['body']);

        $calls = $this->aiCalls();
        $again = $this->postJson($this->assistantUrl($workspace, $business), ['q' => 'What now?', 'client_message_id' => $id, 'conversation' => $uid])->assertOk();
        $this->assertSame('replayed', $again->json('status'), 'A repeat of the same message id replays the one answer.');
        $this->assertSame($calls, $this->aiCalls(), 'No second model call.');
        $this->assertSame($answersBefore + 1, $this->answers($conversation));
    }

    public function test_a_real_answer_still_marks_a_turn_as_replied_and_a_later_users_answer_is_not_borrowed(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Own Venue', 'Own Account');
        $this->authenticateAs($customer);
        $this->modelSays(['action' => 'answer', 'message' => 'A1.']);
        $first = $this->say($workspace, $business, 'Q1')->assertOk();
        $uid = $first->json('uid');
        $this->modelSays(['action' => 'answer', 'message' => 'A2.']);
        $this->say($workspace, $business, 'Q2', ['conversation' => $uid])->assertOk();

        // Re-sending Q1's own id replays A1 — not A2 (which answers a later question).
        $q1 = AssistantMessage::query()->where('body', 'Q1')->firstOrFail();
        $calls = $this->aiCalls();
        $r = $this->postJson($this->assistantUrl($workspace, $business), ['q' => 'Q1', 'client_message_id' => $q1->client_message_id, 'conversation' => $uid])->assertOk();

        $this->assertSame('replayed', $r->json('status'));
        $this->assertSame($calls, $this->aiCalls());
    }
}

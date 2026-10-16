<?php

namespace Tests\Feature\Assistant;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\AiUsageLedgerEntry;
use App\Models\AssistantMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Assistant\Concerns\AssistantTestSupport;
use Tests\TestCase;

/**
 * PR #460 review, P2 (server side of the retry binding): the browser now retries an OLD failed question with its ORIGINAL client id,
 * possibly after newer questions succeeded. The server must answer that retry from the stored turn — A's own reply, not B's — with no
 * second model call and no second charge.
 */
class AssistantRetryReplayTest extends TestCase
{
    use RefreshDatabase;
    use AssistantTestSupport;

    private function charged(): int
    {
        return AiUsageLedgerEntry::query()->where('status', 'committed')->count();
    }

    public function test_retrying_an_older_completed_question_after_a_newer_one_replays_its_own_answer_without_a_second_charge(): void
    {
        $this->bootAssistant();
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Replay Venue', 'Replay Account');
        $this->authenticateAs($customer);
        [$idA, $idB] = [(string) Str::uuid(), (string) Str::uuid()];

        $this->modelSays(['action' => 'answer', 'message' => 'Answer to A.']);
        $a = $this->postJson($this->assistantUrl($workspace, $business), ['q' => 'Question A', 'client_message_id' => $idA])->assertOk();
        $uid = $a->json('uid');
        $this->modelSays(['action' => 'answer', 'message' => 'Answer to B.']);
        $this->postJson($this->assistantUrl($workspace, $business), ['q' => 'Question B', 'client_message_id' => $idB, 'conversation' => $uid])->assertOk();
        [$calls, $charged, $rows] = [$this->aiCalls(), $this->charged(), AssistantMessage::query()->count()];

        // A's response was lost on the way back; the browser retries A (not B) with A's original id, after B succeeded.
        $retry = $this->postJson($this->assistantUrl($workspace, $business), ['q' => 'Question A', 'client_message_id' => $idA, 'conversation' => $uid])->assertOk();

        $this->assertSame('replayed', $retry->json('status'));
        $this->assertSame($uid, $retry->json('uid'));
        $bodies = array_column($retry->json('messages'), 'body');
        $this->assertSame(1, count(array_keys($bodies, 'Answer to A.', true)), "A's own answer is there exactly once (the conversation is returned whole).");
        $this->assertSame(1, count(array_keys($bodies, 'Answer to B.', true)));
        $this->assertSame($calls, $this->aiCalls(), 'No further model call.');
        $this->assertSame($charged, $this->charged(), 'No second charge.');
        $this->assertSame($rows, AssistantMessage::query()->count(), 'No duplicate messages.');
    }
}

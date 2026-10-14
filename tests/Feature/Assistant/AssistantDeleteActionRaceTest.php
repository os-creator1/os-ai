<?php

namespace Tests\Feature\Assistant;

use App\Library\Assistant\AssistantRetention;
use App\Models\AssistantActionProposal;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Correction round 2, item 2 (delete versus an executing action). A row lock held by an executor on ANOTHER database
 * connection needs rows both connections can see, i.e. COMMITTED ones — so this class does not wrap tests in a transaction.
 * (Collection of such a conversation on a later run, once no turn or executor holds it, is covered by AssistantDeleteTurnRaceTest.)
 * It writes only to the three assistant tables (with the parents' foreign keys switched off for the insert, so no tenant
 * fixtures are needed) and removes exactly the rows it created.
 *
 * An executor holds its proposal's row lock until its transaction commits — even after its lease timestamp has passed. A
 * deletion must neither wait on it nor remove anything under it.
 */
class AssistantDeleteActionRaceTest extends TestCase
{
    /** @var array<int, int> */
    private array $conversationIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.executor' => config('database.connections.' . config('database.default'))]);
    }

    protected function tearDown(): void
    {
        try {
            DB::connection('executor')->rollBack();
        } catch (\Throwable) {
        }
        DB::purge('executor');

        if ($this->conversationIds !== []) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            // With foreign keys off the cascade does not run: remove every dependant explicitly.
            AssistantMessage::query()->whereIn('conversation_id', $this->conversationIds)->delete();
            AssistantActionProposal::query()->whereIn('conversation_id', $this->conversationIds)->orWhere('workspace_id', 987001)->delete();
            AssistantConversation::query()->whereIn('id', $this->conversationIds)->delete();
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        parent::tearDown();
    }

    /** @return array{0: AssistantConversation, 1: AssistantActionProposal} an old conversation whose approved action is mid-execution on another connection */
    private function executingElsewhere(): array
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        $c = AssistantConversation::create([
            'uid' => (string) Str::uuid(), 'workspace_id' => 987001, 'business_id' => 987002, 'user_id' => 987003,
            'summary' => 'Owner asked about Jane Doe', 'message_count' => 1, 'last_message_at' => now()->subDays(120),
        ]);
        $this->conversationIds[] = $c->id;
        AssistantMessage::create(['conversation_id' => $c->id, 'uid' => (string) Str::uuid(), 'role' => 'user', 'kind' => 'text', 'body' => 'Book Jane Doe', 'created_at' => now()->subDays(120)]);
        $p = AssistantActionProposal::create([
            'uid' => (string) Str::uuid(), 'conversation_id' => $c->id, 'workspace_id' => 987001, 'business_id' => 987002, 'user_id' => 987003,
            'tool_key' => 'create_appointment', 'params' => ['message' => 'Hi Jane Doe'], 'preview' => ['title' => 'Book Jane Doe'],
            'params_hash' => str_repeat('a', 64), 'state_fingerprint' => str_repeat('b', 64), 'status' => 'executing', 'expires_at' => now()->addMinutes(30),
            'idempotency_key' => hash('sha256', (string) Str::uuid()),
            // The lease timestamp is already in the past: only the row lock tells the truth about the live executor.
            'lease_token' => (string) Str::uuid(), 'lease_expires_at' => now()->subMinute(), 'fence' => 1,
        ]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        // The executor: a separate connection, inside its transaction, holding the proposal's row lock.
        $executor = DB::connection('executor');
        $executor->beginTransaction();
        $executor->table('assistant_action_proposals')->where('id', $p->id)->lockForUpdate()->first();

        return [$c, $p];
    }

    public function test_a_customer_delete_does_not_wait_on_or_remove_anything_under_a_running_executor(): void
    {
        [$c, $p] = $this->executingElsewhere();

        $t = microtime(true);
        $deleted = app(AssistantRetention::class)->delete($c, 'customer_request', 0);
        $elapsed = microtime(true) - $t;

        $this->assertFalse($deleted, 'Busy, retryable — not a silent success.');
        $this->assertLessThan(3.0, $elapsed, 'A bounded answer, not a lock wait.');
        $this->assertTrue(AssistantConversation::query()->whereKey($c->id)->exists());
        $this->assertSame(1, AssistantMessage::query()->where('conversation_id', $c->id)->count());
        $fresh = AssistantActionProposal::query()->whereKey($p->id)->firstOrFail();
        $this->assertSame('executing', $fresh->status);
        $this->assertSame(['message' => 'Hi Jane Doe'], $fresh->params, 'The running action was not scrubbed or declined underneath its executor.');
    }

    public function test_the_scheduled_sweep_skips_a_conversation_with_a_running_executor_without_waiting(): void
    {
        [$c, $p] = $this->executingElsewhere();

        $t = microtime(true);
        app(AssistantRetention::class)->purgeExpired();
        $this->assertLessThan(3.0, microtime(true) - $t, 'The sweep never waits on an executing proposal.');
        $this->assertTrue(AssistantConversation::query()->whereKey($c->id)->exists(), 'Left for the next run, untouched.');
        $this->assertSame('executing', AssistantActionProposal::query()->whereKey($p->id)->value('status'));
    }
}

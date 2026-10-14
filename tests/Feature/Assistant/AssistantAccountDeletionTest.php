<?php

namespace Tests\Feature\Assistant;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\AssistantActionAudit;
use App\Models\AssistantActionProposal;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Assistant\Concerns\AssistantTestSupport;
use Tests\TestCase;

/**
 * Correction round 1, item 1: the assistant's tables inside account deletion.
 *
 * Staging's PlatformOwner account deletion (AccountRecordGraph, merged AFTER this branch's base, so absent here) discovers
 * owned rows from the database's own foreign keys — every row that cascades from an owned user or Workspace is owned — and
 * its drift test requires every non-SET-NULL foreign key INTO `users` to be classified. This branch therefore:
 *   - makes every assistant table reachable by cascade from `workspaces` (and from `users` where it holds a user's own
 *     content), so no second deletion path is needed;
 *   - pins the two foreign keys into `users` that staging must classify as USER_OWNED (see
 *     docs/product/ASK-MOTIONGROVE-V1.md, "Integration dependency");
 *   - exercises the real graph when it is present (a merged tree) — skipped on this branch alone.
 */
class AssistantAccountDeletionTest extends TestCase
{
    use RefreshDatabase;
    use AssistantTestSupport;

    /** @return array<int, object> */
    private function foreignKeys(): array
    {
        return DB::select(
            'select kcu.table_name as child, kcu.column_name as col, kcu.referenced_table_name as parent, rc.delete_rule as rule
               from information_schema.key_column_usage kcu
               join information_schema.referential_constraints rc on rc.constraint_schema = kcu.constraint_schema and rc.constraint_name = kcu.constraint_name and rc.table_name = kcu.table_name
              where kcu.table_schema = ? and kcu.table_name like ? and kcu.referenced_table_name is not null',
            [DB::connection()->getDatabaseName(), 'assistant\_%'],
        );
    }

    public function test_every_foreign_key_into_users_from_an_assistant_table_is_the_users_own_content_and_is_the_documented_pair(): void
    {
        $intoUsers = collect($this->foreignKeys())->where('parent', 'users')->where('rule', '!=', 'SET NULL')->map(fn ($fk) => $fk->child . '.' . $fk->col)->sort()->values()->all();

        $this->assertSame(['assistant_action_proposals.user_id', 'assistant_conversations.user_id'], $intoUsers, 'Staging must classify exactly these as AccountRecordGraph::USER_OWNED.');
        $this->assertSame(['CASCADE'], collect($this->foreignKeys())->whereIn('child', ['assistant_conversations', 'assistant_action_proposals'])->where('parent', 'users')->pluck('rule')->unique()->values()->all());
    }

    public function test_every_assistant_table_is_reachable_by_cascade_from_a_workspace(): void
    {
        $fks = collect($this->foreignKeys())->reject(fn ($fk) => $fk->rule === 'SET NULL');
        $reach = ['workspaces' => true];

        do {
            $grew = false;

            foreach ($fks as $fk) {
                if (isset($reach[$fk->parent]) && ! isset($reach[$fk->child])) {
                    $reach[$fk->child] = true;
                    $grew = true;
                }
            }
        } while ($grew);

        foreach (['assistant_conversations', 'assistant_messages', 'assistant_action_proposals', 'assistant_action_audits'] as $table) {
            $this->assertArrayHasKey($table, $reach, "{$table} must be inside the account deletion graph via cascade.");
        }

        $this->assertSame('SET NULL', collect($this->foreignKeys())->where('child', 'assistant_action_proposals')->where('col', 'conversation_id')->value('rule'), 'Deleting a conversation never deletes the audit fact.');
    }

    public function test_deleting_the_business_leaves_no_assistant_content_behind(): void
    {
        [$customer, $business, $workspace] = $this->bareTenant(WorkspacePlanTier::Growth, 'Gone Venue', 'Gone Account');
        $c = AssistantConversation::create(['uid' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'business_id' => $business->id, 'user_id' => $customer->user_id, 'summary' => 'private', 'message_count' => 1]);
        AssistantMessage::create(['conversation_id' => $c->id, 'uid' => (string) Str::uuid(), 'role' => 'user', 'kind' => 'text', 'body' => 'private text', 'created_at' => now()]);
        $p = AssistantActionProposal::create([
            'uid' => (string) Str::uuid(), 'conversation_id' => $c->id, 'workspace_id' => $workspace->id, 'business_id' => $business->id, 'user_id' => $customer->user_id,
            'tool_key' => 'create_booking_type', 'params' => ['name' => 'private'], 'preview' => ['title' => 'private'], 'params_hash' => str_repeat('a', 64),
            'state_fingerprint' => str_repeat('b', 64), 'status' => 'completed', 'expires_at' => now()->addMinutes(5), 'idempotency_key' => hash('sha256', 'k'),
        ]);
        AssistantActionAudit::create(['proposal_id' => $p->id, 'event' => 'proposed', 'actor_user_id' => $customer->user_id, 'detail' => null, 'created_at' => now()]);

        // The database's own cascade (what staging's graph mirrors): the Business's rows go with it. (A Workspace itself is
        // removed only by the account-closure manager — it has RESTRICT children — which walks the same FK graph.)
        DB::table('businesses')->where('id', $business->id)->delete();

        $this->assertSame(0, AssistantConversation::query()->count());
        $this->assertSame(0, AssistantMessage::query()->count());
        $this->assertSame(0, AssistantActionProposal::query()->count());
        $this->assertSame(0, AssistantActionAudit::query()->count());
    }

    public function test_the_real_account_deletion_graph_owns_the_assistant_tables_when_it_is_present(): void
    {
        $graph = 'App\\Library\\PlatformOwner\\AccountDeletion\\AccountRecordGraph';

        if (! class_exists($graph)) {
            $this->markTestSkipped('Integration dependency: staging\'s AccountRecordGraph is not on this branch (merged after its base). Apply docs/product/ASK-MOTIONGROVE-V1.md "Integration dependency" when merging.');
        }

        $owned = $graph::USER_OWNED;
        $this->assertContains('assistant_conversations.user_id', $owned);
        $this->assertContains('assistant_action_proposals.user_id', $owned);

        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Graph Venue', 'Graph Account');
        $c = AssistantConversation::create(['uid' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'business_id' => $business->id, 'user_id' => $customer->user_id, 'summary' => 's', 'message_count' => 1]);
        $m = AssistantMessage::create(['conversation_id' => $c->id, 'uid' => (string) Str::uuid(), 'role' => 'user', 'kind' => 'text', 'body' => 'hello', 'created_at' => now()]);
        $p = AssistantActionProposal::create([
            'uid' => (string) Str::uuid(), 'conversation_id' => $c->id, 'workspace_id' => $workspace->id, 'business_id' => $business->id, 'user_id' => $customer->user_id,
            'tool_key' => 'create_booking_type', 'params' => [], 'preview' => [], 'params_hash' => str_repeat('a', 64), 'state_fingerprint' => str_repeat('b', 64),
            'status' => 'completed', 'expires_at' => now()->addMinutes(5), 'idempotency_key' => hash('sha256', 'g'),
        ]);
        $a = AssistantActionAudit::create(['proposal_id' => $p->id, 'event' => 'proposed', 'actor_user_id' => $customer->user_id, 'detail' => null, 'created_at' => now()]);

        $selection = app($graph)->discover((int) $customer->user_id, [(int) $workspace->id]);

        foreach ([['assistant_conversations', $c->id], ['assistant_messages', $m->id], ['assistant_action_proposals', $p->id], ['assistant_action_audits', $a->id]] as [$table, $id]) {
            $this->assertContains((int) $id, $selection->ids[$table] ?? [], "{$table} row is owned by the deleted account.");
        }

        $this->assertNotContains('assistant_conversations', $selection->unsupported);
        foreach ($selection->blockers as $blocker) {
            $this->assertStringNotContainsString('assistant_', json_encode($blocker));
        }
    }
}

<?php

namespace Tests\Feature\Conversations;

use App\Enums\Messaging\MessagingOperationStatus;
use App\Library\Conversations\ConversationHistoryWriter;
use App\Library\Messaging\ManagedMessageDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

/**
 * Correction round 4, item 4 — ConversationHistoryWriter::recordManagedOutbound()
 * must never leave a bubble stranded 'failed' when its own INSERT loses a
 * genuine race to a concurrent recordManualSendFailure() write for the
 * identical (box_id, send_uid).
 *
 * THE EXACT RACE THIS PROVES: 1) the accepted writer's own read finds no
 * row yet; 2) a racing failure writer's own read ALSO finds no row;
 * 3) the failure writer inserts first; 4) the accepted writer's own
 * insert then collides with it on the (box_id, send_uid) unique index.
 * Before this correction, the catch block only ever looked the row up by
 * business_messaging_operation_id — which the failure row never carries —
 * so the lookup returned null and the visible bubble stayed 'failed' with
 * a Retry control, despite the provider having genuinely accepted the
 * send.
 *
 * WHY THIS FILE DOES NOT USE RefreshDatabase. Reproducing the race
 * deterministically (not merely "eventually, if you run it enough times")
 * requires the racing failure row to become durable — actually
 * COMMITTED, on a database session independent of this test's own
 * transaction — inside the precise window between recordManagedOutbound()'s
 * own read and its own write. An open RefreshDatabase transaction makes
 * that impossible twice over: the separate connection's write cannot be
 * made durable within a transaction this test controls and never
 * commits, and worse, its INSERT would actually deadlock — the foreign
 * key from chat_box_messages.box_id to chat_boxes.id requires a shared
 * lock on the parent row, which stays exclusively held by RefreshDatabase's
 * own transaction until teardown, which never arrives mid-test. This
 * mirrors ConversationsConcurrencyTest.php's own established precedent
 * for exactly this class of problem: fixture rows are created and
 * cleaned up explicitly instead.
 *
 * HOW THE EXACT ORDERING IS REPRODUCED, WITHOUT A SEPARATE OS PROCESS.
 * Genuine cross-process concurrency (as ConversationsConcurrencyTest.php
 * uses for a same-instant race) only controls when two processes START —
 * it cannot pin one statement to land in a specific few-millisecond gap
 * between two OTHER statements. A DB::listen() hook can: it fires
 * synchronously the instant recordManagedOutbound()'s own read query
 * EXECUTES, before control returns to its caller. From inside that hook
 * this test commits the racing failure row through a second, fully
 * independent database connection — durable immediately, regardless of
 * what recordManagedOutbound()'s own transaction later does. Its
 * subsequent ChatBoxMessage::create() call, back on the original
 * connection, then genuinely collides with a row that provably did not
 * exist at the moment its own read ran.
 */
class AcceptedInsertRaceTest extends TestCase
{
    use CreatesMessagingFixtures;

    private const RACE_CONNECTION = 'accepted_insert_race_probe';

    private array $createdBusinessIds = [];

    private array $createdWorkspaceIds = [];

    private array $createdUserIds = [];

    protected function tearDown(): void
    {
        if ($this->createdBusinessIds !== []) {
            DB::table('chat_box_messages')
                ->whereIn('box_id', DB::table('chat_boxes')->whereIn('business_id', $this->createdBusinessIds)->pluck('id'))
                ->delete();
            DB::table('chat_boxes')->whereIn('business_id', $this->createdBusinessIds)->delete();
            DB::table('business_messaging_operations')->whereIn('business_id', $this->createdBusinessIds)->delete();
            DB::table('business_messaging_numbers')
                ->whereIn('business_messaging_identity_id', DB::table('business_messaging_identities')->whereIn('business_id', $this->createdBusinessIds)->pluck('id'))
                ->delete();
            DB::table('business_messaging_identities')->whereIn('business_id', $this->createdBusinessIds)->delete();
            DB::table('businesses')->whereIn('id', $this->createdBusinessIds)->delete();
        }

        if ($this->createdWorkspaceIds !== []) {
            DB::table('workspace_plan_assignments')->whereIn('workspace_id', $this->createdWorkspaceIds)->delete();
            DB::table('workspace_entitlement_transitions')->whereIn('workspace_id', $this->createdWorkspaceIds)->delete();
            DB::table('workspace_entitlement_overrides')->whereIn('workspace_id', $this->createdWorkspaceIds)->delete();
            DB::table('workspaces')->whereIn('id', $this->createdWorkspaceIds)->delete();
        }

        if ($this->createdUserIds !== []) {
            DB::table('customers')->whereIn('user_id', $this->createdUserIds)->delete();
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }

        DB::purge(self::RACE_CONNECTION);

        parent::tearDown();
    }

    public function test_an_accepted_insert_that_loses_a_race_to_a_failure_write_still_reconciles_to_sent(): void
    {
        Http::fake();
        $this->bindFakeAdapter();

        $customer = $this->createCustomer();
        $this->createdUserIds[] = $customer->user_id;

        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $this->createdBusinessIds[] = $business->id;
        $this->createdWorkspaceIds[] = $business->workspace_id;

        $identity = $this->attachIdentity($business);
        $this->attachNumber($identity, '+14155550199', true);

        $writer = app(ConversationHistoryWriter::class);
        $box = $writer->conversationFor($business, '14155550199', '14155552671');
        $box->save();

        $sendUid = (string) Str::uuid();
        $operationKey = 'conversation:' . $box->id . ':' . $sendUid . ':initial';

        // The ACCEPTED operation this send belongs to — exactly what
        // ManagedMessageDispatcher::finalize() would already have written
        // before ManagedDispatchDelegate::attempt() ever calls the history
        // writer.
        $operationId = DB::table(ManagedMessageDispatcher::TABLE)->insertGetId([
            'business_id' => $business->id,
            'business_messaging_identity_id' => $identity->id,
            'transport_mode' => 'managed',
            'provider' => 'telnyx',
            'direction' => 'outbound',
            'message_type' => 'sms',
            'operation_key' => $operationKey,
            'status' => MessagingOperationStatus::Accepted->value,
            'provider_message_id' => 'fake_msg_race',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        config(['database.connections.' . self::RACE_CONNECTION => config('database.connections.' . config('database.default'))]);

        $injected = false;

        $listener = function ($query) use (&$injected, $box, $sendUid): void {
            if ($injected) {
                return;
            }

            if (! str_contains($query->sql, '`chat_box_messages`') || ! str_contains($query->sql, '`send_uid`')) {
                return;
            }

            if (! in_array($sendUid, $query->bindings, true)) {
                return;
            }

            $injected = true;

            // Committed on a genuinely SEPARATE connection — durable the
            // instant this returns, unaffected by whatever
            // recordManagedOutbound()'s own transaction (on the default
            // connection) does afterward.
            // recordManagedOutbound()'s own transaction has, by this point,
            // already updated the SAME chat_boxes row this insert's FK
            // references (reply_by_customer, set moments earlier in the
            // same transaction) — holding an exclusive lock on it until
            // that transaction commits. A normal FK-checked insert on the
            // race connection would need a shared lock on that exact row
            // and deadlock waiting for a transaction that cannot itself
            // proceed (and so cannot commit) until this synchronous
            // listener returns. FK checks are disabled for this one
            // statement, on this soon-to-be-discarded connection only, to
            // avoid that unresolvable wait — the parent row's existence is
            // already guaranteed by this test's own fixture setup.
            DB::connection(self::RACE_CONNECTION)->statement('SET FOREIGN_KEY_CHECKS=0');

            DB::connection(self::RACE_CONNECTION)->table('chat_box_messages')->insert([
                'box_id' => $box->id,
                'message' => 'Are you open?',
                'sms_type' => 'plain',
                'direction' => 'outgoing',
                'send_by' => 'from',
                'send_uid' => $sendUid,
                'send_status' => 'failed',
                'send_failure_reason' => 'send_failed',
                'retry_count' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        };

        DB::listen($listener);

        $result = $writer->recordManagedOutbound(
            $business,
            '14155552671',
            'Are you open?',
            [],
            'plain',
            $operationKey,
            ConversationHistoryWriter::SOURCE_CONVERSATIONS,
            $sendUid,
        );

        $this->assertTrue($injected, 'The race was actually reproduced — the racing failure row was injected between the read and the write.');

        $this->assertNotNull($result, 'The failure row is found and promoted — never silently dropped.');
        $this->assertSame('sent', $result->send_status, 'The provider DID accept this send, regardless of which write landed first.');
        $this->assertSame($operationId, (int) $result->business_messaging_operation_id);
        $this->assertNull($result->send_failure_reason);
        $this->assertSame(
            1,
            DB::table('chat_box_messages')->where('box_id', $box->id)->count(),
            'One bubble — never a second row for the same logical message.',
        );
    }
}

<?php

namespace Tests\Feature\Assistant;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Assistant\AssistantRetention;
use App\Models\AssistantActionAudit;
use App\Models\AssistantActionProposal;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Assistant\Concerns\AssistantTestSupport;
use Tests\TestCase;

/** Correction round 1, item 3: retention, customer deletion, the AI-data notice — and what survives deletion. */
class AssistantRetentionTest extends TestCase
{
    use RefreshDatabase;
    use AssistantTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAssistant();
    }

    /** @return array{0: AssistantConversation, 1: AssistantActionProposal} a conversation with text, a summary and a prepared action */
    private function conversationWithAction($customer, $business, $workspace, string $status = 'completed', ?\DateTimeInterface $last = null): array
    {
        $c = AssistantConversation::create([
            'uid' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'business_id' => $business->id, 'user_id' => $customer->user_id,
            'summary' => 'Owner asked about Jane Doe 15551234567', 'message_count' => 2, 'last_message_at' => $last ?? now(),
        ]);
        foreach ([['user', 'Book Jane Doe 15551234567'], ['assistant', 'I can do that for Jane Doe']] as [$role, $body]) {
            AssistantMessage::create(['conversation_id' => $c->id, 'uid' => (string) Str::uuid(), 'role' => $role, 'kind' => 'text', 'body' => $body, 'payload' => ['facts' => [['label' => 'Customer', 'value' => 'Jane Doe']]], 'created_at' => now()]);
        }
        $p = AssistantActionProposal::create([
            'uid' => (string) Str::uuid(), 'conversation_id' => $c->id, 'workspace_id' => $workspace->id, 'business_id' => $business->id, 'user_id' => $customer->user_id,
            'tool_key' => 'create_appointment', 'params' => ['contact_uid' => 'x', 'message' => 'Hi Jane Doe'], 'preview' => ['title' => 'Book Jane Doe', 'lines' => [['label' => 'Customer', 'value' => 'Jane Doe 15551234567']]],
            'params_hash' => str_repeat('a', 64), 'state_fingerprint' => str_repeat('b', 64), 'status' => $status, 'expires_at' => now()->addMinutes(30),
            'idempotency_key' => hash('sha256', (string) Str::uuid()), 'result' => ['ok' => true, 'message' => 'Booked Jane Doe', 'url' => '/x'],
        ]);
        foreach (['proposed' => ['tool' => 'create_appointment', 'message' => 'uid-of-a-message', 'mode' => 'execute'], 'executed' => ['message' => 'Booked Jane Doe 15551234567']] as $event => $detail) {
            AssistantActionAudit::create(['proposal_id' => $p->id, 'event' => $event, 'actor_user_id' => $customer->user_id, 'detail' => $detail, 'created_at' => now()]);
        }

        return [$c, $p];
    }

    public function test_customer_deletion_removes_text_and_scrubs_the_action_to_minimal_audit_facts(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Del Venue', 'Del Account');
        $this->authenticateAs($customer);
        [$c, $pending] = $this->conversationWithAction($customer, $business, $workspace, 'pending');

        $this->postJson($this->assistantUrl($workspace, $business, 'delete', [$c->uid]))->assertOk()->assertJson(['deleted' => true]);

        $this->assertSame(0, AssistantConversation::query()->count());
        $this->assertSame(0, AssistantMessage::query()->count(), 'Messages (text, facts) are gone with the conversation.');
        $p = AssistantActionProposal::query()->firstOrFail();
        $this->assertNull($p->conversation_id);
        $this->assertSame('declined', $p->status, 'An open action can never be approved after its conversation is deleted.');
        $this->assertSame('conversation_deleted', $p->failure_code);
        $this->assertSame([], $p->params);
        $this->assertSame([], $p->preview);
        $this->assertSame(['ok' => true], $p->result, 'Result text (names) removed; only the outcome flag stays.');
        $this->assertNotNull($p->scrubbed_at);
        $this->assertSame('create_appointment', $p->tool_key);
        $this->assertSame($customer->user_id, $p->user_id);

        $audits = AssistantActionAudit::query()->orderBy('id')->get();
        $this->assertSame(['proposed', 'executed', 'content_deleted'], $audits->pluck('event')->all(), 'Audit events survive, independently of the conversation.');
        $blob = json_encode($audits->pluck('detail'));
        $this->assertStringNotContainsString('Jane', $blob);
        $this->assertStringNotContainsString('15551234567', $blob);
        $this->assertStringNotContainsString('uid-of-a-message', $blob);
        $this->assertStringContainsString('create_appointment', $blob, 'Minimal facts (tool, mode, code) are kept.');
        $this->assertStringNotContainsString('Jane', json_encode(DB::table('assistant_action_proposals')->get()));
    }

    public function test_only_the_owner_in_the_right_business_can_delete_and_a_running_action_blocks_it(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Own Venue', 'Own Account');
        [$other, $otherBusiness, $otherWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'Other Venue', 'Other Account');
        [$c, $p] = $this->conversationWithAction($customer, $business, $workspace);

        $this->authenticateAs($other);
        $this->postJson($this->assistantUrl($otherWorkspace, $otherBusiness, 'delete', [$c->uid]))->assertNotFound();
        $this->postJson($this->assistantUrl($workspace, $business, 'delete', [$c->uid]))->assertNotFound();
        $this->assertSame(1, AssistantConversation::query()->count());

        $this->authenticateAs($customer);
        $p->forceFill(['status' => 'executing', 'lease_token' => 't', 'fence' => 1, 'lease_expires_at' => now()->addSeconds(30)])->save();
        $this->postJson($this->assistantUrl($workspace, $business, 'delete', [$c->uid]))->assertStatus(409)->assertJson(['deleted' => false]);
        $this->assertSame(1, AssistantConversation::query()->count());

        $p->forceFill(['lease_expires_at' => now()->subMinute()])->save();
        $this->postJson($this->assistantUrl($workspace, $business, 'delete', [$c->uid]))->assertOk();
        $this->assertSame(0, AssistantConversation::query()->count());
    }

    public function test_retention_deletes_only_expired_conversations_and_is_idempotent(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Ret Venue', 'Ret Account');
        $this->assertSame(90, AssistantRetention::retentionDays(), 'Default retention is 90 days.');
        [$old] = $this->conversationWithAction($customer, $business, $workspace, 'completed', now()->subDays(91));
        [$fresh] = $this->conversationWithAction($customer, $business, $workspace, 'completed', now()->subDays(89));

        $this->artisan('assistant:purge-expired')->assertExitCode(0);

        $this->assertSame([$fresh->uid], AssistantConversation::query()->pluck('uid')->all());
        $this->assertSame(2, AssistantActionProposal::query()->count(), 'Both audit facts remain.');
        $this->assertSame(1, AssistantActionProposal::query()->whereNull('conversation_id')->whereNotNull('scrubbed_at')->count());

        $this->artisan('assistant:purge-expired')->assertExitCode(0);
        $this->assertSame(1, AssistantConversation::query()->count(), 'Running again changes nothing.');
        $this->assertSame(0, (new AssistantRetention())->purgeExpired());

        config(['assistant.retention_days' => 30]);
        $this->assertSame(1, (new AssistantRetention())->purgeExpired(), 'The period is configurable.');
        config(['assistant.retention_days' => 100000]);
        $this->assertSame(365, AssistantRetention::retentionDays(), 'And bounded.');
    }

    public function test_the_sweep_can_be_scoped_to_one_business_and_skips_a_running_action(): void
    {
        [$a, $businessA, $workspaceA] = $this->tenant(WorkspacePlanTier::Growth, 'Scope A', 'Scope Account A');
        [$b, $businessB, $workspaceB] = $this->tenant(WorkspacePlanTier::Growth, 'Scope B', 'Scope Account B');
        [$ca] = $this->conversationWithAction($a, $businessA, $workspaceA, 'completed', now()->subDays(120));
        [$cb] = $this->conversationWithAction($b, $businessB, $workspaceB, 'completed', now()->subDays(120));

        $this->artisan('assistant:purge-expired', ['--business' => $businessA->uid])->assertExitCode(0);
        $this->assertSame([$cb->uid], AssistantConversation::query()->pluck('uid')->all(), 'Only Business A was touched.');

        [$busyConv, $busy] = $this->conversationWithAction($b, $businessB, $workspaceB, 'executing', now()->subDays(120));
        $busy->forceFill(['lease_token' => 't', 'fence' => 1, 'lease_expires_at' => now()->addSeconds(30)])->save();
        $this->artisan('assistant:purge-expired', ['--business' => $businessB->uid])->assertExitCode(0);
        $this->assertTrue(AssistantConversation::query()->where('uid', $busyConv->uid)->exists(), 'A live execution is never torn down; it is picked up next run.');
        $this->assertFalse(AssistantConversation::query()->where('uid', $cb->uid)->exists());
    }

    public function test_the_purge_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'assistant:purge-expired'));

        $this->assertCount(1, $events);
        $this->assertSame('0 0 * * *', $events->first()->expression);
        $this->assertTrue($events->first()->withoutOverlapping);
    }

    public function test_the_ai_data_notice_appears_at_first_use_says_what_is_true_and_is_not_repeated(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Notice Venue', 'Notice Account');
        $this->authenticateAs($customer);

        $before = $this->getJson($this->assistantUrl($workspace, $business, 'show'))->assertOk();
        $this->assertSame('How Ask MotionGrove uses AI', $before->json('notice.title'));
        $this->assertSame(0, $this->aiCalls());

        $this->modelSays(['action' => 'answer', 'message' => 'Hello.']);
        $first = $this->say($workspace, $business, 'Hi')->assertOk();
        $messages = $first->json('messages');
        $this->assertSame('notice', $messages[0]['kind'], 'The notice is the first thing in the first conversation.');
        $body = $messages[0]['body'];
        $this->assertStringContainsString('third-party provider', $body);
        $this->assertStringContainsString('saved in MotionGrove for 90 days', $body);
        $this->assertStringContainsString('Delete conversation', $body);
        $this->assertStringContainsString('removes its messages and summary from MotionGrove', $body);
        $this->assertStringContainsString('minimal record of any action you approved', $body, 'Says plainly that action/audit records may be kept.');
        $this->assertStringContainsString('cannot delete what the AI provider has already received', $body);
        $this->assertDoesNotMatchRegularExpression('/GDPR|compliant|certified|encrypted|provider (deletes|will delete|removes)|zero retention|never stored/i', $body, 'No legal promise is invented.');

        // The notice itself never goes to the model.
        $this->assertStringNotContainsString('How Ask MotionGrove uses AI', implode("\n", array_column($this->fake->requests()[0]->messages, 'content')));

        $this->modelSays(['action' => 'answer', 'message' => 'Again.']);
        $second = $this->say($workspace, $business, 'More', ['new' => true])->assertOk();
        $this->assertNotSame($first->json('uid'), $second->json('uid'));
        $this->assertNotContains('notice', array_column($second->json('messages'), 'kind'), 'A second conversation does not repeat it.');
        $this->assertNull($this->getJson($this->assistantUrl($workspace, $business, 'show'))->json('notice'));
    }

    public function test_a_draft_handoff_stops_working_once_its_conversation_is_deleted(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Hand Venue', 'Hand Account');
        $this->conversationWith($business, [['incoming', \Carbon\CarbonImmutable::now()->subMinutes(40)->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i:s')]]);
        $this->authenticateAs($customer);
        $boxUid = (string) DB::table('chat_boxes')->value('uid');

        $this->modelSays(['action' => 'propose', 'tool' => 'draft_reply', 'args' => ['conversation_ref' => $boxUid, 'message' => 'See you Friday!'], 'message' => 'Draft.']);
        $r = $this->say($workspace, $business, 'Reply to them')->assertOk();
        $uid = $this->lastReply($r)['proposal']['uid'];
        $done = $this->postJson($this->assistantUrl($workspace, $business, 'approve', [$uid]))->assertOk();
        $drawer = $done->json('url');

        $this->assertStringContainsString('See you Friday!', $this->get($drawer)->assertOk()->getContent());

        $this->postJson($this->assistantUrl($workspace, $business, 'delete', [$r->json('uid')]))->assertOk();
        $this->assertStringNotContainsString('See you Friday!', $this->get($drawer)->assertOk()->getContent(), 'Deleting the conversation revokes the hand-off.');
    }
}

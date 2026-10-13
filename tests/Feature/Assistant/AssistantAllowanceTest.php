<?php

namespace Tests\Feature\Assistant;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Ai\AiCompletionResult;
use App\Library\Entitlement\EntitlementManager;
use App\Models\AiUsageLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Assistant\Concerns\AssistantTestSupport;
use Tests\TestCase;

/** What the customer is told — and not told — when their included AI runs short, and that it never blocks anything else. */
class AssistantAllowanceTest extends TestCase
{
    use RefreshDatabase;
    use AssistantTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAssistant();
    }

    private function exhaust($workspace, int $committed = 3_000_000, int $interactive = 2_400_000): void
    {
        $this->onTrial($workspace);
        app(\App\Library\Support\RequestScopedCache::class)->forgetPrefixed('workspace_plan_assignment:');
        DB::table('ai_usage_periods')->insert([
            'scope_type' => 'workspace', 'scope_id' => $workspace->id, 'workspace_id' => $workspace->id, 'period_key' => 'trial',
            'policy_key' => 'trial', 'policy_version' => 1, 'cap_microusd' => 3_000_000, 'reserved_microusd' => 0,
            'committed_microusd' => $committed, 'interactive_reserved_microusd' => 0, 'interactive_committed_microusd' => $interactive,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_an_exhausted_trial_gets_the_upgrade_message_and_the_real_plan_link_without_a_model_call(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Limit Venue', 'Limit Account');
        $this->exhaust($workspace);
        $this->authenticateAs($customer);

        $reply = $this->lastReply($this->say($workspace, $business, 'How is my business doing this month?')->assertOk());

        $this->assertSame('limit', $reply['kind']);
        $this->assertSame("You've used your trial's included AI", $reply['payload']['title']);
        $this->assertStringContainsString("You've reached the AI usage limit included with your free trial.", $reply['body']);
        $this->assertStringContainsString('Upgrade to a paid MotionGrove plan to unlock a significantly higher monthly AI allowance', $reply['body']);
        $this->assertStringContainsString('Your other MotionGrove features will keep working.', $reply['body']);
        $this->assertSame('Upgrade plan', $reply['payload']['upgrade']['label']);
        $this->assertSame(route('customer.workspaces.plan.show', [$workspace->uid]), $reply['payload']['upgrade']['url']);
        $this->assertSame(0, $this->aiCalls(), 'No model call, so no invented summary after the limit.');
        $this->assertDoesNotMatchRegularExpression('/\$\s?\d|token|gpt|openai|model|microusd|unlimited/i', json_encode($reply));
    }

    public function test_the_upgrade_destination_is_a_real_authorised_page_for_the_actor(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Plan Venue', 'Plan Account');
        $this->exhaust($workspace);
        $this->authenticateAs($customer);

        $this->get(route('customer.workspaces.plan.show', [$workspace->uid]))->assertOk();
    }

    public function test_someone_who_cannot_manage_the_subscription_is_not_offered_an_upgrade_button(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Staff Venue', 'Staff Account');
        $this->exhaust($workspace);
        $staff = $this->createCustomer();
        $this->member($workspace, $staff->user, \App\Enums\Workspace\WorkspaceMembershipRole::Staff, \App\Enums\Workspace\WorkspaceBusinessAccessScope::All);
        $this->authenticateAs($staff->fresh());

        $reply = $this->lastReply($this->say($workspace, $business, 'What should I focus on next?')->assertOk());

        $this->assertSame('limit', $reply['kind']);
        $this->assertNull($reply['payload']['upgrade']);
        $this->assertTrue($reply['payload']['ownerOnly']);
        $this->assertSame(0, $this->aiCalls());
    }

    public function test_an_unrelated_provider_error_never_shows_the_upgrade_prompt(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Err Venue', 'Err Account');
        $this->onTrial($workspace);
        $this->authenticateAs($customer);
        $this->modelFails();
        $this->modelFails();

        $reply = $this->lastReply($this->say($workspace, $business, 'Why did my leads decrease?')->assertOk());

        $this->assertSame('error', $reply['kind']);
        $this->assertStringNotContainsString('Upgrade', json_encode($reply));
        $this->assertStringNotContainsString('included AI', json_encode($reply));
    }

    public function test_interactive_share_exhaustion_is_not_described_as_all_ai_used_up(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Share Venue', 'Share Account');
        $this->exhaust($workspace, 2_400_000, 2_400_000);
        $this->authenticateAs($customer);

        $reply = $this->lastReply($this->say($workspace, $business, 'Explain why my ads are not performing')->assertOk());

        $this->assertSame('limit', $reply['kind']);
        $this->assertSame('Assistant conversations are paused for now', $reply['payload']['title']);
        $this->assertSame('interactive_limit', $reply['payload']['status']);
        $this->assertStringNotContainsString("You've used your trial's included AI", json_encode($reply));
    }

    public function test_non_ai_help_keeps_working_when_the_allowance_is_gone(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Keep Venue', 'Keep Account');
        $this->conversationWith($business, [['incoming', \Carbon\CarbonImmutable::now()->subMinutes(40)->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i:s')]]);
        $this->exhaust($workspace);
        $this->authenticateAs($customer);

        $reply = $this->lastReply($this->say($workspace, $business, "Show customers who haven't received a reply")->assertOk());

        $this->assertSame('answer', $reply['kind']);
        $this->assertTrue($reply['payload']['deterministic']);
        $this->assertStringContainsString('1 customer is waiting', $reply['body']);
        $this->assertSame(0, $this->aiCalls());
        $this->get(route('user.home'))->assertOk();
    }

    public function test_the_approaching_limit_notice_shows_at_the_threshold_without_blocking(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Near Venue', 'Near Account');
        $this->exhaust($workspace, 2_450_000, 100_000);
        $this->authenticateAs($customer);
        $this->modelSays(['action' => 'answer', 'message' => 'Fine.']);

        $r = $this->say($workspace, $business, 'Hello there')->assertOk();

        $this->assertSame('You have used most of your included AI. Everything else keeps working.', $this->lastReply($r)['payload']['notice']);
        $this->assertTrue($r->json('allowance.nearing'));
    }

    public function test_after_a_confirmed_upgrade_the_exhausted_presentation_clears_and_the_assistant_answers_again(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Up Venue', 'Up Account');
        $this->exhaust($workspace);
        $this->authenticateAs($customer);
        $limited = $this->say($workspace, $business, 'First question')->assertOk();
        $this->assertSame('limit', $this->lastReply($limited)['kind']);
        $this->assertFalse($limited->json('allowance.usable'));

        app(EntitlementManager::class)->recoverAccess($workspace, null, 'Provider-confirmed conversion.');

        $resumed = $this->getJson($this->assistantUrl($workspace, $business, 'show'))->assertOk();
        $this->assertTrue($resumed->json('allowance.usable'));
        $this->assertNull($resumed->json('allowance.blocked'));
        $old = collect($resumed->json('messages'))->firstWhere('kind', 'limit');
        $this->assertNull($old['payload']['upgrade'], 'The old limit card keeps no call to action.');
        $this->assertTrue($old['payload']['resolved']);

        $this->modelSays(['action' => 'answer', 'message' => 'Welcome back — I can help again.']);
        $this->assertSame('answer', $this->lastReply($this->say($workspace, $business, 'Are you there?', ['conversation' => $resumed->json('uid')])->assertOk())['kind']);
    }

    public function test_the_ledger_records_assistant_calls_as_interactive_and_compaction_is_accounted_separately(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Ledger Venue', 'Ledger Account');
        $this->authenticateAs($customer);
        config(['assistant.history_window_messages' => 2, 'assistant.compaction_trigger_chars' => 10]);

        $uid = null;
        foreach (['one', 'two', 'three'] as $i => $word) {
            $this->modelSays(['action' => 'answer', 'message' => "Answer {$word} with a reasonable amount of text so compaction has something to fold."]);
            if ($i === 2) {
                $this->modelSays(['action' => 'answer', 'message' => 'Final.']);
                $this->fake->queueResult(AiCompletionResult::success('Owner asked about topics one and two.', 'gpt-4o-mini', 400, 30));
            }
            $uid = $this->say($workspace, $business, "Question {$word} about my business", $uid ? ['conversation' => $uid] : [])->json('uid');
        }

        $this->assertSame(['ask_assistant'], AiUsageLedgerEntry::query()->where('category', 'ask_assistant')->pluck('category')->unique()->map->value->all());
        $this->assertTrue(AiUsageLedgerEntry::query()->where('category', 'conversation_compaction')->exists(), 'Compaction is a ledgered, budgeted call.');
        $this->assertSame(0, AiUsageLedgerEntry::query()->where('lane', '!=', 'interactive')->count());
        $this->assertNotNull(\App\Models\AssistantConversation::query()->value('summary'));
    }
}

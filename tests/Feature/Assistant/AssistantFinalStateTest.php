<?php

namespace Tests\Feature\Assistant;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Ai\AiAllowance;
use App\Models\AssistantActionProposal;
use App\Models\AssistantMessage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Assistant\Concerns\AssistantTestSupport;
use Tests\TestCase;

/** Correction round 1, item 8: final-state idempotence, hand-off authorization, and the upgrade call to action. */
class AssistantFinalStateTest extends TestCase
{
    use RefreshDatabase;
    use AssistantTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAssistant();
    }

    private function proposeType($workspace, $business): string
    {
        $this->modelSays(['action' => 'propose', 'tool' => 'create_booking_type', 'args' => ['name' => 'Wedding package', 'duration_minutes' => 180], 'message' => 'Ready.']);

        return $this->lastReply($this->say($workspace, $business, 'Create a wedding package')->assertOk())['proposal']['uid'];
    }

    private function outcomes(): int
    {
        return AssistantMessage::query()->where('kind', 'outcome')->count();
    }

    public function test_repeated_approve_on_a_completed_proposal_adds_no_duplicate_message_or_effect(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Final Venue', 'Final Account');
        $this->authenticateAs($customer);
        $uid = $this->proposeType($workspace, $business);

        $this->postJson($this->assistantUrl($workspace, $business, 'approve', [$uid]))->assertOk()->assertJsonPath('state', 'completed');
        $this->assertSame(1, $this->outcomes());

        foreach ([1, 2, 3] as $_) {
            $this->postJson($this->assistantUrl($workspace, $business, 'approve', [$uid]))->assertOk()->assertJsonPath('state', 'replayed');
            $this->postJson($this->assistantUrl($workspace, $business, 'decline', [$uid]))->assertOk()->assertJsonPath('state', 'completed');
        }

        $this->assertSame(1, $this->outcomes(), 'No duplicate conversation message.');
        $this->assertSame(1, DB::table('booking_types')->where('name', 'Wedding package')->count());
        $this->assertSame(1, \App\Models\AssistantActionAudit::query()->where('event', 'executed')->count());
    }

    public function test_repeated_decline_and_approve_after_decline_add_nothing(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Decl Venue', 'Decl Account');
        $this->authenticateAs($customer);
        $uid = $this->proposeType($workspace, $business);

        $this->postJson($this->assistantUrl($workspace, $business, 'decline', [$uid]))->assertOk()->assertJsonPath('state', 'declined');
        $this->assertSame(1, $this->outcomes());
        $audits = \App\Models\AssistantActionAudit::query()->count();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson($this->assistantUrl($workspace, $business, 'decline', [$uid]))->assertOk()->assertJsonPath('state', 'declined');
            $this->postJson($this->assistantUrl($workspace, $business, 'approve', [$uid]))->assertOk()->assertJsonPath('state', 'declined');
        }

        $this->assertSame(1, $this->outcomes());
        $this->assertSame($audits, \App\Models\AssistantActionAudit::query()->count(), 'No duplicate audit rows either.');
        $this->assertSame(0, DB::table('booking_types')->where('name', 'Wedding package')->count());
    }

    public function test_an_expired_or_stale_proposal_records_its_outcome_once(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Exp Venue', 'Exp Account');
        $this->authenticateAs($customer);
        $uid = $this->proposeType($workspace, $business);
        AssistantActionProposal::query()->where('uid', $uid)->update(['expires_at' => now()->subMinute()]);

        foreach ([1, 2, 3] as $_) {
            $this->postJson($this->assistantUrl($workspace, $business, 'approve', [$uid]))->assertOk()->assertJsonPath('state', 'expired');
        }

        $this->assertSame(1, $this->outcomes());
    }

    // ── hand-off authorization ───────────────────────────────────────────────────────

    private function handedOff(): array
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Hand Venue', 'Hand Account');
        $this->conversationWith($business, [['incoming', CarbonImmutable::now()->subMinutes(40)->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i:s')]]);
        $this->authenticateAs($customer);
        $box = (string) DB::table('chat_boxes')->value('uid');
        $this->modelSays(['action' => 'propose', 'tool' => 'draft_reply', 'args' => ['conversation_ref' => $box, 'message' => 'Friday works for us!'], 'message' => 'Draft.']);
        $uid = $this->lastReply($this->say($workspace, $business, 'Reply to them')->assertOk())['proposal']['uid'];
        $done = $this->postJson($this->assistantUrl($workspace, $business, 'approve', [$uid]))->assertOk();

        return [$customer, $business, $workspace, $uid, $done->json('url')];
    }

    private function drawerBody(string $url): string
    {
        return $this->get($url)->assertOk()->getContent();
    }

    public function test_a_valid_handoff_prefills_and_nothing_else_does(): void
    {
        [$customer, $business, $workspace, $uid, $url] = $this->handedOff();

        $this->assertStringContainsString('Friday works for us!', $this->drawerBody($url));

        $base = strtok($url, '?');
        $this->assertStringNotContainsString('Friday works for us!', $this->drawerBody($base . '?proposal=nonexistent'));
        $this->assertStringNotContainsString('Friday works for us!', $this->drawerBody($base));
    }

    public function test_an_expired_handoff_does_not_prefill(): void
    {
        [, , , $uid, $url] = $this->handedOff();
        config(['assistant.handoff_ttl_minutes' => 60]);
        AssistantActionProposal::query()->where('uid', $uid)->update(['executed_at' => now()->subMinutes(61)]);

        $this->assertStringNotContainsString('Friday works for us!', $this->drawerBody($url));
    }

    public function test_a_revoked_or_scrubbed_or_wrong_status_handoff_does_not_prefill(): void
    {
        [, , , $uid, $url] = $this->handedOff();

        AssistantActionProposal::query()->where('uid', $uid)->update(['status' => 'declined']);
        $this->assertStringNotContainsString('Friday works for us!', $this->drawerBody($url), 'Revoked (status).');
        AssistantActionProposal::query()->where('uid', $uid)->update(['status' => 'handed_off', 'scrubbed_at' => now()]);
        $this->assertStringNotContainsString('Friday works for us!', $this->drawerBody($url), 'Scrubbed.');
        AssistantActionProposal::query()->where('uid', $uid)->update(['scrubbed_at' => null, 'conversation_id' => null]);
        $this->assertStringNotContainsString('Friday works for us!', $this->drawerBody($url), 'Detached from its conversation.');
    }

    public function test_another_actor_workspace_or_business_cannot_use_a_handoff(): void
    {
        [$customer, $business, $workspace, $uid, $url] = $this->handedOff();
        $query = '?' . parse_url($url, PHP_URL_QUERY);

        // Another user in the same Business (a staff member) with the same proposal uid.
        $staff = $this->createCustomer();
        $this->member($workspace, $staff->user, \App\Enums\Workspace\WorkspaceMembershipRole::Staff, \App\Enums\Workspace\WorkspaceBusinessAccessScope::All);
        $this->authenticateAs($staff->fresh());
        $this->assertStringNotContainsString('Friday works for us!', $this->drawerBody($url), 'Not their proposal.');

        // Another Workspace/Business entirely, replaying the uid in its own drawer.
        [$other, $otherBusiness, $otherWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'Elsewhere', 'Elsewhere Account');
        $this->conversationWith($otherBusiness, [['incoming', CarbonImmutable::now()->subMinutes(40)->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i:s')]]);
        $this->authenticateAs($other);
        $drawer = route('customer.workspaces.businesses.home.drawer', [$otherWorkspace->uid, $otherBusiness->uid, 'follow-ups']) . $query;
        $this->assertStringNotContainsString('Friday works for us!', $this->drawerBody($drawer));

        // A different tool's proposal is never a draft.
        $this->authenticateAs($customer);
        AssistantActionProposal::query()->where('uid', $uid)->update(['tool_key' => 'create_booking_type']);
        $this->assertStringNotContainsString('Friday works for us!', $this->drawerBody($url));
    }

    // ── the upgrade call to action ───────────────────────────────────────────────────

    private function trialPeriod($workspace, int $committed, int $interactive): void
    {
        $this->onTrial($workspace);
        app(\App\Library\Support\RequestScopedCache::class)->forgetPrefixed('workspace_plan_assignment:');
        DB::table('ai_usage_periods')->insert([
            'scope_type' => 'workspace', 'scope_id' => $workspace->id, 'workspace_id' => $workspace->id, 'period_key' => 'trial', 'policy_key' => 'trial',
            'policy_version' => 1, 'cap_microusd' => 3_000_000, 'reserved_microusd' => 0, 'committed_microusd' => $committed,
            'interactive_reserved_microusd' => 0, 'interactive_committed_microusd' => $interactive, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_upgrade_button_appears_only_for_a_genuinely_exhausted_trial_allowance(): void
    {
        // Genuine exhaustion: the whole trial allowance is used.
        [$c1, $b1, $w1] = $this->tenant(WorkspacePlanTier::Growth, 'Exhaust Venue', 'Exhaust Account');
        $this->trialPeriod($w1, 3_000_000, 2_400_000);
        $this->authenticateAs($c1);
        $full = $this->lastReply($this->say($w1, $b1, 'Question one')->assertOk());
        $this->assertSame('limit', $full['kind']);
        $this->assertSame('Upgrade plan', $full['payload']['upgrade']['label']);
        $this->assertSame("You've used your trial's included AI", $full['payload']['title']);

        // Interactive share only: its own wording, no upgrade button.
        [$c2, $b2, $w2] = $this->tenant(WorkspacePlanTier::Growth, 'Share Venue', 'Share Account');
        $this->trialPeriod($w2, 2_400_000, 2_400_000);
        $this->authenticateAs($c2);
        $share = $this->lastReply($this->say($w2, $b2, 'Question two')->assertOk());
        $this->assertSame('limit', $share['kind']);
        $this->assertSame('interactive_limit', $share['payload']['status']);
        $this->assertNull($share['payload']['upgrade']);
        $this->assertFalse($share['payload']['ownerOnly']);
        $this->assertStringNotContainsString('Upgrade', json_encode($share));
        $this->assertStringNotContainsString("trial's included AI", $share['payload']['title']);

        // Expired trial access: its own wording, no upgrade button.
        [$c3, $b3, $w3] = $this->tenant(WorkspacePlanTier::Growth, 'Expired Venue', 'Expired Account');
        $this->onTrial($w3, now()->subDay());
        app(\App\Library\Support\RequestScopedCache::class)->forgetPrefixed('workspace_plan_assignment:');
        $this->authenticateAs($c3);
        $expired = $this->lastReply($this->say($w3, $b3, 'Question three')->assertOk());
        $this->assertSame('limit', $expired['kind']);
        $this->assertNull($expired['payload']['upgrade']);
        $this->assertStringContainsString('free trial has ended', $expired['body']);
        $this->assertStringNotContainsString('Upgrade', json_encode($expired));
        $this->assertSame(0, $this->aiCalls());
    }

    public function test_allowance_semantics_only_a_trial_limit_is_an_upgrade_remedy(): void
    {
        foreach ([AiAllowance::AVAILABLE, AiAllowance::NEARING_LIMIT, AiAllowance::LIMIT_REACHED, AiAllowance::INTERACTIVE_LIMIT, AiAllowance::UNAVAILABLE] as $status) {
            foreach ([true, false] as $trial) {
                foreach (['trial', 'trial_expired', 'growth'] as $key) {
                    $this->assertFalse((new AiAllowance($status, $trial, $key, null))->upgradeIsRemedy(), "{$status}/{$key}");
                }
            }
        }

        $this->assertTrue((new AiAllowance(AiAllowance::TRIAL_LIMIT_REACHED, true, 'trial', null))->upgradeIsRemedy());
    }
}

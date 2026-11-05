<?php

namespace Tests\Feature\Assistant;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Assistant\Concerns\AssistantTestSupport;
use Tests\TestCase;

/**
 * "Explain this with MotionGrove": the assistant (fake provider here) is handed the SAME head recommendation Home selects, with only
 * the evidence the engine persisted for it — and nothing for an actor who may not read recommendations.
 */
class GrowthExplainRecommendationTest extends TestCase
{
    use RefreshDatabase;
    use AssistantTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAssistant();
    }

    private function toolMessage(int $request): string
    {
        $messages = $this->fake->requests()[$request]->messages;

        return (string) end($messages)['content'];
    }

    private function seedRecommendations($business): void
    {
        $this->recommendation($business, [
            'worker_key' => 'website', 'type' => 'website.not_published:v1', 'title' => 'Publish your website', 'priority_score' => 90, 'confidence' => 0.80,
            'evidence' => json_encode([['summary' => 'Persisted proof: never published.', 'observed_value' => [], 'retrieved_at' => now()->toIso8601String()]]),
        ]);
        $this->recommendation($business, [
            'worker_key' => 'website', 'type' => 'website.not_published:v1', 'title' => 'Second recommendation', 'priority_score' => 40,
            'evidence' => json_encode([['summary' => 'Second item proof.', 'observed_value' => [], 'retrieved_at' => now()->toIso8601String()]]),
        ]);
    }

    public function test_the_model_receives_the_primary_recommendation_first_with_only_its_persisted_evidence(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Explain Venue', 'Explain Account');
        $this->seedRecommendations($business);
        $this->authenticateAs($customer);

        $this->modelSays(['action' => 'call_tools', 'calls' => [['tool' => 'growth_recommendations', 'args' => []]]]);
        $this->modelSays(['action' => 'answer', 'message' => 'Publishing your website comes first.']);
        $this->say($workspace, $business, 'Explain my top recommendation: what evidence supports it, why it matters, and exactly what to do first.')->assertOk();

        $this->assertSame(2, $this->aiCalls(), 'One planning call and one answer call — metered, no hidden extra provider calls.');
        $tool = $this->toolMessage(1);
        $this->assertStringContainsString('"is_primary":true', $tool);
        $this->assertLessThan(strpos($tool, 'Second recommendation'), strpos($tool, 'Publish your website'), 'The Home primary leads.');
        $this->assertStringContainsString('Persisted proof: never published.', $tool);
        $this->assertStringContainsString('Moderate confidence', $tool);
        $this->assertStringNotContainsString('Second item proof.', $tool, 'Evidence is supplied for the primary only (data minimisation).');
    }

    public function test_missing_evidence_is_absent_not_invented(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Thin Venue', 'Thin Account');
        $this->recommendation($business, ['worker_key' => 'website', 'type' => 'website.not_published:v1', 'title' => 'Publish your website', 'priority_score' => 90, 'evidence' => json_encode([])]);
        $this->authenticateAs($customer);

        $this->modelSays(['action' => 'call_tools', 'calls' => [['tool' => 'growth_recommendations', 'args' => []]]]);
        $this->modelSays(['action' => 'answer', 'message' => 'There is little evidence yet.']);
        $this->say($workspace, $business, 'Explain my top recommendation')->assertOk();

        $this->assertStringNotContainsString('"evidence":', $this->toolMessage(1));
    }

    public function test_an_actor_without_recommendation_permission_gives_the_model_nothing(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Denied Venue', 'Denied Account');
        $this->seedRecommendations($business);
        $staff = $this->createCustomer();
        $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $this->authenticateAs($staff->fresh(), ['access_backend']);

        $this->modelSays(['action' => 'call_tools', 'calls' => [['tool' => 'growth_recommendations', 'args' => []]]]);
        $this->modelSays(['action' => 'answer', 'message' => 'I cannot see recommendations.']);
        $this->say($workspace, $business, 'Explain my top recommendation')->assertOk();

        $all = implode("\n", array_map(fn ($r) => implode("\n", array_column($r->messages, 'content')), $this->fake->requests()));
        $this->assertStringNotContainsString('Persisted proof', $all);
        $this->assertStringNotContainsString('Publish your website', $all);
    }

    /** The "Explain this with MotionGrove" prompt, exactly as Home sends it, is read-only: only the (metered) model call happens. */
    public function test_explain_this_is_read_only_no_send_no_publish_no_charge_no_approval(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        \Illuminate\Support\Facades\Notification::fake();
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'ReadOnly Venue', 'ReadOnly Account');
        $this->seedRecommendations($business);
        $this->authenticateAs($customer);

        $snapshot = fn (): array => [
            'proposals' => \App\Models\AssistantActionProposal::query()->count(),
            'audits' => \App\Models\AssistantActionAudit::query()->count(),
            'customer_charges' => \App\Models\BusinessUsageLedgerEntry::query()->count(),
            'messages_sent' => \Illuminate\Support\Facades\DB::table('chat_box_messages')->count(),
            'opportunity_states' => \Illuminate\Support\Facades\DB::table('opportunities')->orderBy('id')->pluck('status')->all(),
            'opportunity_transitions' => \Illuminate\Support\Facades\DB::table('opportunity_transitions')->count(),
            'website_revisions' => \Illuminate\Support\Facades\Schema::hasTable('website_revisions') ? \Illuminate\Support\Facades\DB::table('website_revisions')->count() : 0,
            'websites' => \Illuminate\Support\Facades\DB::table('websites')->orderBy('id')->pluck('status')->all(),
        ];
        $before = $snapshot();

        $this->modelSays(['action' => 'call_tools', 'calls' => [['tool' => 'growth_recommendations', 'args' => []]]]);
        $this->modelSays(['action' => 'answer', 'message' => 'Publishing your website comes first.']);
        $this->say($workspace, $business, 'Explain my top recommendation: what evidence supports it, why it matters, and exactly what to do first.')->assertOk();

        $this->assertSame($before, $snapshot(), 'Nothing was proposed, approved, sent, published, charged or changed.');
        \Illuminate\Support\Facades\Mail::assertNothingSent();
        \Illuminate\Support\Facades\Notification::assertNothingSent();
        $this->assertSame(2, $this->aiCalls(), 'Only the metered model calls happened (metering is allowed).');
    }
}

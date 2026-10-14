<?php

namespace Tests\Feature\Assistant;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Ai\AiModelRouter;
use App\Library\Ai\Enums\AiModelRoute;
use App\Library\Ai\Enums\AiUsageEntryStatus;
use App\Library\Assistant\AssistantContext;
use App\Library\Assistant\AssistantToolRegistry;
use App\Library\Assistant\ToolResult;
use App\Library\Assistant\Tools;
use App\Models\AiUsageLedgerEntry;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Assistant\Concerns\AssistantTestSupport;
use Tests\TestCase;

/**
 * Correction round 2, item 1. The stronger `reasoning` route has a larger input ceiling (6,000) than the `routine` route
 * the gateway downgrades it to when the Workspace's remaining allowance is thin (4,000). The assistant fits its request
 * BEFORE the gateway decides, so the fitted request must be acceptable on whichever route is chosen.
 *
 * Everything here goes through the real AiGateway (reserve / commit ledger, routing, ceilings) with the fake provider.
 */
class AssistantRouteDowngradeTest extends TestCase
{
    use RefreshDatabase;
    use AssistantTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAssistant();
    }

    private function bigRegistry(): void
    {
        $big = new class implements Tools\ReadTool {
            public function key(): string { return 'big_report'; }
            public function description(): string { return 'A very large report. No args.'; }
            public function rules(): array { return []; }
            public function available(AssistantContext $c): bool { return true; }
            public function read(AssistantContext $c, array $args): ToolResult
            {
                return new ToolResult('big_report', ['rows' => str_repeat('lorem ipsum dolor sit amet ', 400)]);
            }
        };

        $this->app->instance(AssistantToolRegistry::class, new AssistantToolRegistry(
            [$big, app(Tools\OpenPageTool::class), app(Tools\RecentWorkTool::class), app(Tools\BusinessSnapshotTool::class)],
            [],
        ));
    }

    /** A Growth Workspace with `$remaining` micro-USD left of its $10 month. */
    private function thinBudgetTenant(int $remaining): array
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Thin Venue', 'Thin Account');
        $this->authenticateAs($customer);
        $cap = 10_000_000;
        DB::table('ai_usage_periods')->insert([
            'scope_type' => 'workspace', 'scope_id' => $workspace->id, 'workspace_id' => $workspace->id, 'period_key' => now()->format('Y-m'), 'policy_key' => 'growth',
            'policy_version' => 1, 'cap_microusd' => $cap, 'reserved_microusd' => 0, 'committed_microusd' => $cap - $remaining,
            'interactive_reserved_microusd' => 0, 'interactive_committed_microusd' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$customer, $business, $workspace, $cap];
    }

    private function longConversation($customer, $business, $workspace): AssistantConversation
    {
        $c = AssistantConversation::create([
            'uid' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'business_id' => $business->id, 'user_id' => $customer->user_id,
            'summary' => str_repeat('s', 900), 'message_count' => 0, 'last_message_at' => now(),
        ]);

        for ($i = 0; $i < 30; $i++) {
            AssistantMessage::create(['conversation_id' => $c->id, 'uid' => (string) Str::uuid(), 'role' => $i % 2 ? 'assistant' : 'user', 'kind' => 'text', 'body' => str_repeat('x', 600), 'created_at' => now()]);
        }

        return $c;
    }

    /** @return iterable<string, array{0: int}> */
    public static function ceilings(): iterable
    {
        yield 'the 4,500-token fixture' => [4500];
        yield 'the production 7,000-token configuration' => [7000];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ceilings')]
    public function test_a_reasoning_request_downgraded_to_routine_by_a_thin_budget_still_fits_and_completes(int $assistantCeiling): void
    {
        [$customer, $business, $workspace, $cap] = $this->thinBudgetTenant(600_000); // < 3 x reasoning request cap (900,000)
        $this->bigRegistry();
        config(['assistant.compaction_trigger_chars' => 10_000_000, 'ai.routes.assistant.max_input_tokens' => $assistantCeiling]);
        $conversation = $this->longConversation($customer, $business, $workspace);

        $call = ['tool' => 'big_report', 'args' => []];
        $this->modelSays(['action' => 'call_tools', 'calls' => [$call, $call, $call]]);   // reads: assistant route
        $this->modelSays(['action' => 'call_tools', 'calls' => [$call, $call, $call]]);   // 3+ results & "why": asks for reasoning
        $this->modelSays(['action' => 'answer', 'message' => 'Because of the quiet week.']);

        $this->say($workspace, $business, 'Why did my enquiries drop this month?', ['conversation' => $conversation->uid])->assertOk();

        $entries = AiUsageLedgerEntry::query()->where('workspace_id', $workspace->id)->where('status', '!=', AiUsageEntryStatus::Refused->value)->orderBy('id')->get();
        $this->assertCount($this->aiCalls(), $entries, 'Every provider call has exactly one committed ledger entry.');
        $this->assertGreaterThanOrEqual(3, $this->aiCalls(), 'The turn ran to its answer — it did not die on a refusal.');

        $final = AssistantMessage::query()->where('conversation_id', $conversation->id)->orderByDesc('id')->first();
        $this->assertSame('answer', $final->kind, 'The customer got the answer, not a generic failure.');
        $this->assertSame('Because of the quiet week.', $final->body);

        $router = app(AiModelRouter::class);
        $routineCeiling = (int) $router->config(AiModelRoute::Routine)['max_input_tokens'];
        $routineBudget = (int) floor($routineCeiling * (10_000 - (int) config('assistant.input_headroom_bps')) / 10_000);
        $sawRoutine = false;
        $report = [];

        foreach ($this->fake->requests() as $i => $req) {
            $tokens = $router->estimateInputTokens($req->messages);
            $route = $entries[$i]->model_route;
            $ceiling = (int) $router->config($route)['max_input_tokens'];
            $report[] = sprintf('call %d route=%s estimate=%d ceiling=%d headroom=%d', $i, $route->value, $tokens, $ceiling, $ceiling - $tokens);
            $this->assertLessThan($ceiling, $tokens, "Call {$i} is within the ceiling of the route the gateway actually used.");

            if ($route === AiModelRoute::Routine) {
                $sawRoutine = true;
                $this->assertLessThanOrEqual($routineBudget, $tokens, "Call {$i} respects routine's budget ({$routineBudget}).");
            }
        }

        fwrite(STDERR, "\nMEASURE assistant_ceiling={$assistantCeiling} routine_budget={$routineBudget}: " . implode(' | ', $report) . "\n");
        $this->assertTrue($sawRoutine, 'The thin budget really did downgrade a request to routine.');
        $this->assertSame(0, AiUsageLedgerEntry::query()->where('workspace_id', $workspace->id)->where('refusal_reason', 'input_too_large')->count());

        // Charged correctly, inside the allowance.
        $period = DB::table('ai_usage_periods')->where('workspace_id', $workspace->id)->where('scope_type', 'workspace')->first();
        $actual = (int) $entries->sum('actual_cost_microusd');
        $this->assertSame($cap - 600_000 + $actual, (int) $period->committed_microusd, 'Committed = the prior spend + the real cost of these calls, nothing else.');
        $this->assertSame(0, (int) $period->reserved_microusd, 'Nothing is left reserved.');
        $this->assertLessThanOrEqual($cap, (int) $period->committed_microusd);
        $this->assertGreaterThan(0, $actual);
    }

    public function test_a_downgrade_does_not_let_the_request_past_an_exhausted_allowance(): void
    {
        [$customer, $business, $workspace, $cap] = $this->thinBudgetTenant(40); // less than any single call's reservation
        $this->bigRegistry();
        $conversation = $this->longConversation($customer, $business, $workspace);
        $this->modelSays(['action' => 'answer', 'message' => 'Should never be reached.']);

        $this->say($workspace, $business, 'Why did my enquiries drop this month?', ['conversation' => $conversation->uid])->assertOk();

        $this->assertSame(0, $this->aiCalls(), 'No provider call: the remaining allowance cannot cover it, route or no route.');
        $period = DB::table('ai_usage_periods')->where('workspace_id', $workspace->id)->where('scope_type', 'workspace')->first();
        $this->assertSame($cap - 40, (int) $period->committed_microusd);
        $final = AssistantMessage::query()->where('conversation_id', $conversation->id)->orderByDesc('id')->first();
        $this->assertSame('limit', $final->kind);
    }

    public function test_the_router_reports_the_smallest_ceiling_a_route_can_land_on(): void
    {
        $router = app(AiModelRouter::class);

        $this->assertSame(min((int) config('ai.routes.reasoning.max_input_tokens'), (int) config('ai.routes.routine.max_input_tokens')), $router->smallestInputCeiling(AiModelRoute::Reasoning));
        $this->assertSame((int) config('ai.routes.assistant.max_input_tokens'), $router->smallestInputCeiling(AiModelRoute::Assistant), 'The assistant route is never downgraded.');
        $this->assertSame((int) config('ai.routes.routine.max_input_tokens'), $router->smallestInputCeiling(AiModelRoute::Routine));
    }
}

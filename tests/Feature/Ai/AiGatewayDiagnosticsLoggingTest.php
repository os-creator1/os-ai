<?php

namespace Tests\Feature\Ai;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Ai\AiCompletionResult;
use App\Library\Ai\AiGateway;
use App\Library\Ai\AiModelRouter;
use App\Library\Ai\AiRequest;
use App\Library\Ai\Enums\AiLane;
use App\Library\Ai\Enums\AiUsageCategory;
use App\Library\Ai\Providers\FakeAiCompletionClient;
use App\Models\AiUsageLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/** Permanent AI diagnostics: searchable motiongrove.ai.* log events, correlated with the ai_usage_ledger entry, free of sensitive data. */
class AiGatewayDiagnosticsLoggingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    private const SECRET_PROMPT = 'CUSTOMER-PROMPT-TEXT-12345';

    private FakeAiCompletionClient $fake;

    /** @var list<array{0: string, 1: string, 2: array}> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.openai.active' => true, 'services.openai.api_key' => 'sk-test-SECRETKEY-999']);
        $this->fake = new FakeAiCompletionClient();
        $this->app->instance(\App\Library\Ai\Contracts\AiCompletionClient::class, $this->fake);

        Log::shouldReceive('info', 'warning', 'error')->andReturnUsing(function (string $message, array $context = []) {
            $this->logs[] = [$message, $context];
        });
    }

    private function request($workspace, string $key = null): AiRequest
    {
        $category = AiUsageCategory::WebsiteGeneration;

        return new AiRequest(
            workspace: $workspace, business: null, category: $category, lane: AiLane::Product,
            route: app(AiModelRouter::class)->defaultRouteFor($category),
            messages: [['role' => 'user', 'content' => self::SECRET_PROMPT]],
            maxOutputTokens: 100, idempotencyKey: $key ?? (string) Str::uuid(), actorUserId: null,
        );
    }

    private function events(string $name): array
    {
        return array_values(array_filter($this->logs, fn ($l) => $l[0] === $name));
    }

    private function assertNothingSensitive(): void
    {
        $dump = json_encode($this->logs);
        $this->assertStringNotContainsString(self::SECRET_PROMPT, $dump);
        $this->assertStringNotContainsString('SECRETKEY', $dump);
        $this->assertStringNotContainsString('Bearer', $dump);
    }

    public function test_success_logs_completed_with_model_tokens_cost_and_ledger_id(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Growth);

        $result = app(AiGateway::class)->complete($this->request($workspace));

        $this->assertTrue($result->ok);
        $entry = AiUsageLedgerEntry::query()->findOrFail($result->ledgerEntry->id);
        $events = $this->events('motiongrove.ai.completed');
        $this->assertCount(1, $events);
        $ctx = $events[0][1];
        $this->assertSame($entry->id, $ctx['ledger_entry_id']);
        $this->assertSame('committed', $ctx['status']);
        $this->assertSame($entry->provider_model, $ctx['provider_model']);
        $this->assertSame(100, $ctx['input_tokens']);
        $this->assertSame(20, $ctx['output_tokens']);
        $this->assertSame((int) $entry->actual_cost_microusd, $ctx['cost_microusd']);
        $this->assertNothingSensitive();
    }

    public function test_budget_refusal_logs_a_safe_category_and_still_refuses(): void
    {
        // An unassigned workspace resolves a zero cap: a budget refusal before any reservation or provider call.
        $workspace = $this->createWorkspace($this->createCustomer()->user);

        $result = app(AiGateway::class)->complete($this->request($workspace));

        $this->assertFalse($result->ok);
        $this->assertNotNull($result->refusalReason);
        $events = $this->events('motiongrove.ai.refused');
        $this->assertCount(1, $events);
        $this->assertSame($result->refusalReason->value, $events[0][1]['refusal']);
        $this->assertSame(0, $this->fake->callCount(), 'A refusal never reaches the provider.');
        $this->assertNothingSensitive();
    }

    public function test_provider_failure_logs_safe_exception_details_and_the_ledger_entry(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->fake->queueResult(AiCompletionResult::failure(errorClass: \RuntimeException::class, httpStatus: 401, errorCode: 'invalid_api_key'));

        $result = app(AiGateway::class)->complete($this->request($workspace));

        $this->assertFalse($result->ok);
        $this->assertNull($result->refusalReason);
        $events = $this->events('motiongrove.ai.provider_failed');
        $this->assertCount(1, $events);
        $ctx = $events[0][1];
        $this->assertSame($result->ledgerEntry->id, $ctx['ledger_entry_id']);
        $this->assertSame(\RuntimeException::class, $ctx['exception_class']);
        $this->assertSame(401, $ctx['http_status']);
        $this->assertSame('invalid_api_key', $ctx['provider_error_code']);
        $this->assertSame(0, $ctx['cost_microusd'] ?? 0);
        $this->assertNothingSensitive();
    }
}

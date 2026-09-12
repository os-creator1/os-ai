<?php

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

/**
 * Unified Business Home and COO Decision Engine Contract §10.1 step 4,
 * T-BUD-4 — a concurrent reservation race against the same Workspace's AI
 * budget period never lets the sum of reserved + committed microUSD
 * overshoot the period's own cap, no matter how many callers race the
 * exact same row at once.
 *
 * Deliberately does NOT use RefreshDatabase — real cross-process
 * concurrency needs committed rows a sibling OS process can actually see,
 * mirroring ConcurrentTopUpConcurrencyTest's own established precedent
 * exactly (see that file's class docblock). Every fixture row is built
 * directly with real commits and tracked here for manual cleanup.
 *
 * The race is built so that, whichever order the racing transactions are
 * actually granted `lockForUpdate()` in, the outcome is deterministic:
 * every accepted request is made to cost EXACTLY the same amount at both
 * estimate and settlement time (§10.1 steps 3 and 6 — see
 * FIXED_COST_MICROUSD's derivation below), so accepting one request never
 * frees room for another beyond what the cap allows. That turns "at most
 * floor(cap / cost) requests are ever accepted, and the period's own
 * reserved + committed total never exceeds its cap" into an assertion
 * this test can make exactly, not merely approximately.
 */
class AiGatewayConcurrencyTest extends TestCase
{
    /**
     * The race must be against a category whose cap is genuinely enforced,
     * because a bypassed cap proves nothing about the reservation lock.
     *
     * It used to use `coo_diagnosis`, which is always hard-enforced. After
     * Correction 1 the COO categories sit behind the `ai_coo_basic`
     * entitlement, which stays Planned until AI-3 — so every child would
     * now be refused for entitlement and the race would never reach the
     * lock at all. `website_generation` is used instead, with the child
     * processes turning enforcement ON for the pre-existing categories
     * (§19.3 rule 3), which makes its cap every bit as hard.
     */
    private const CATEGORY = 'website_generation';

    /**
     * routine's own per-request cap (`max_request_cost_microusd`,
     * default 50_000) and output ceiling (`max_output_tokens`, default
     * 800) are both far above what this test spends per call — see the
     * derivation below.
     */
    private const MAX_OUTPUT_TOKENS = 800;

    /**
     * One fixed prompt size for every racing child.
     *
     * The cost it produces is no longer hardcoded. Correction 9 made the
     * estimator account for per-message and per-request framing as well as
     * content, and pinning arithmetic that the estimator owns would mean
     * this proof silently stops testing the real reservation the day that
     * policy changes again. The exact figures are therefore derived from
     * AiModelRouter itself (see fixedCostMicrousd() below), and each child
     * reports back precisely the token counts the estimate was built from —
     * so estimate and actual are equal by construction, releasing the
     * reservation and committing the actual is a wash, and accepting one
     * request never creates headroom for another beyond the cap.
     */
    private const INPUT_CHARS = 10_395;

    private const RACING_PROCESS_COUNT = 8;

    private const EXPECTED_ACCEPTED_COUNT = 5;

    /** The exact messages every racing child sends. */
    private function racingMessages(): array
    {
        return [['role' => 'user', 'content' => str_repeat('a', self::INPUT_CHARS)]];
    }

    /** The input tokens the estimator will bill this request's shape at. */
    private function estimatedInputTokens(): int
    {
        return app(\App\Library\Ai\AiModelRouter::class)->estimateInputTokens($this->racingMessages());
    }

    /** What one racing request costs, at both estimate and settlement. */
    private function fixedCostMicrousd(): int
    {
        return app(\App\Library\Ai\AiModelRouter::class)->estimateCostMicrousd(
            \App\Library\Ai\Enums\AiModelRoute::Routine,
            $this->racingMessages(),
            self::MAX_OUTPUT_TOKENS,
        );
    }

    /** Exactly enough for EXPECTED_ACCEPTED_COUNT of the racing requests. */
    private function workspaceCapMicrousd(): int
    {
        return $this->fixedCostMicrousd() * self::EXPECTED_ACCEPTED_COUNT;
    }

    private array $createdWorkspaceIds = [];

    private array $createdUserIds = [];

    private ?string $runnerPath = null;

    private ?string $signalPath = null;

    protected function tearDown(): void
    {
        if ($this->runnerPath !== null && file_exists($this->runnerPath)) {
            @unlink($this->runnerPath);
        }

        if ($this->signalPath !== null && file_exists($this->signalPath)) {
            @unlink($this->signalPath);
        }

        if ($this->createdWorkspaceIds !== []) {
            DB::table('ai_usage_ledger')->whereIn('workspace_id', $this->createdWorkspaceIds)->delete();
            DB::table('ai_usage_periods')->whereIn('workspace_id', $this->createdWorkspaceIds)->delete();
            DB::table('workspace_plan_assignments')->whereIn('workspace_id', $this->createdWorkspaceIds)->delete();
            DB::table('workspaces')->whereIn('id', $this->createdWorkspaceIds)->delete();
        }

        if ($this->createdUserIds !== []) {
            DB::table('customers')->whereIn('user_id', $this->createdUserIds)->delete();
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }

        parent::tearDown();
    }

    private function createOwnerUserId(): int
    {
        $id = DB::table('users')->insertGetId([
            'uid' => (string) Str::uuid(), 'first_name' => 'Owner', 'last_name' => 'User',
            'email' => 'ai-gateway-race-'.uniqid().'@example.test', 'status' => true, 'is_admin' => false,
            'is_customer' => true, 'active_portal' => 'customer', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->createdUserIds[] = $id;
        DB::table('customers')->insert(['user_id' => $id, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    /**
     * A Workspace on an active Core plan assignment — real committed rows,
     * exactly as `AiBudgetPolicyResolver::resolveFor()` (via
     * `EntitlementManager::getWorkspaceEntitlementSummary()`) reads it.
     */
    private function createActiveCoreWorkspaceId(): int
    {
        $ownerId = $this->createOwnerUserId();

        $workspaceId = DB::table('workspaces')->insertGetId([
            'uid' => (string) Str::uuid(), 'name' => 'AI Gateway Race WS '.uniqid(), 'owner_user_id' => $ownerId,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->createdWorkspaceIds[] = $workspaceId;

        $coreCatalogId = DB::table('workspace_plan_catalog')->where('tier', 'core')->value('id');
        DB::table('workspace_plan_assignments')->insert([
            'workspace_id' => $workspaceId, 'workspace_plan_catalog_id' => $coreCatalogId, 'status' => 'active',
            'is_complimentary' => true, 'additional_business_slots' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $workspaceId;
    }

    /**
     * @return array<string, string>
     */
    private function childEnvironment(): array
    {
        $database = TestDatabaseSafety::activeTestDatabase();

        return [
            'DB_DATABASE' => $database,
            'EXPECTED_TEST_DATABASE' => $database,
            'OPENAI_ACTIVE' => 'true',
            'AI_BUDGET_CORE_WORKSPACE_CAP_MICROUSD' => (string) $this->workspaceCapMicrousd(),
        ];
    }

    private function phpBinary(): string
    {
        return (new PhpExecutableFinder())->find() ?: 'php';
    }

    private function runnerScript(): string
    {
        $vendorAutoload = base_path('vendor/autoload.php');
        $bootstrapApp = base_path('bootstrap/app.php');
        $escapedVendor = str_replace('\\', '\\\\', $vendorAutoload);
        $escapedBootstrap = str_replace('\\', '\\\\', $bootstrapApp);
        $inputChars = self::INPUT_CHARS;
        $maxOutputTokens = self::MAX_OUTPUT_TOKENS;
        $category = self::CATEGORY;
        // The child reports exactly the tokens the estimate was built from,
        // so estimate and actual settle to the same figure (see INPUT_CHARS).
        $reportedInputTokens = $this->estimatedInputTokens();

        return <<<PHP
<?php
require '{$escapedVendor}';
putenv('APP_ENV=testing');
\$_ENV['APP_ENV'] = 'testing';
\$_SERVER['APP_ENV'] = 'testing';
\$app = require '{$escapedBootstrap}';
\$kernel = \$app->make(Illuminate\Contracts\Console\Kernel::class);
\$kernel->bootstrap();

const WRONG_DATABASE_EXIT_CODE = 3;

\$expectedDatabase = getenv('EXPECTED_TEST_DATABASE');

if (\$expectedDatabase === false || \$expectedDatabase === '') {
    fwrite(STDERR, "Refusing to run: EXPECTED_TEST_DATABASE was not handed down by the parent test. Aborting before any database write.\n");
    exit(WRONG_DATABASE_EXIT_CODE);
}

try {
    \Tests\Support\TestDatabaseSafety::assertMatchesActiveTestDatabase(\$expectedDatabase);
} catch (\RuntimeException \$e) {
    fwrite(STDERR, 'Refusing to run: ' . \$e->getMessage() . " Aborting before any database write.\n");
    exit(WRONG_DATABASE_EXIT_CODE);
}

function waitForSignal(string \$path): void
{
    \$deadline = microtime(true) + 10.0;
    while (! file_exists(\$path)) {
        if (microtime(true) >= \$deadline) {
            fwrite(STDOUT, "TIMEOUT\\n");
            exit(1);
        }
        usleep(5000);
    }
}

\$workspaceId = (int) \$argv[1];
\$signalPath = \$argv[2];
\$idempotencyKey = \$argv[3];

// §19.3 rule 3 — enforce the pre-existing categories inside the child, so
// the raced category's cap is genuinely hard rather than observation-only.
config(['ai.enforce_budgets_for_existing_categories' => true]);

\$fake = new App\Library\Ai\Providers\FakeAiCompletionClient();
\$fake->setDefaultResult(App\Library\Ai\AiCompletionResult::success(
    content: '{"summary":"race"}',
    providerModel: 'gpt-4o-mini',
    inputTokens: {$reportedInputTokens},
    outputTokens: {$maxOutputTokens},
));
app()->instance(App\Library\Ai\Contracts\AiCompletionClient::class, \$fake);

fwrite(STDOUT, "WAITING\n");
fflush(STDOUT);
waitForSignal(\$signalPath);

\$workspace = App\Models\Workspace::find(\$workspaceId);
\$gateway = app(App\Library\Ai\AiGateway::class);

\$request = new App\Library\Ai\AiRequest(
    workspace: \$workspace,
    business: null,
    category: App\Library\Ai\Enums\AiUsageCategory::from('{$category}'),
    lane: App\Library\Ai\Enums\AiLane::Product,
    route: App\Library\Ai\Enums\AiModelRoute::Routine,
    messages: [['role' => 'user', 'content' => str_repeat('a', {$inputChars})]],
    maxOutputTokens: {$maxOutputTokens},
    idempotencyKey: \$idempotencyKey,
    actorUserId: null,
);

\$result = \$gateway->complete(\$request);

if (\$result->ok) {
    fwrite(STDOUT, "COMMITTED\n");
} else {
    fwrite(STDOUT, 'REFUSED:' . (\$result->refusalReason?->value ?? 'null') . "\n");
}
PHP;
    }

    /**
     * T-BUD-4 — race `RACING_PROCESS_COUNT` genuinely concurrent OS
     * processes against the exact same Workspace AI usage period, each
     * asking for exactly `FIXED_COST_MICROUSD`. Only
     * `EXPECTED_ACCEPTED_COUNT` (`WORKSPACE_CAP_MICROUSD /
     * FIXED_COST_MICROUSD`) may ever be accepted, whichever ones win the
     * race — never more, regardless of interleaving.
     */
    public function test_concurrent_reservations_against_the_same_workspace_never_overshoot_the_cap(): void
    {
        $workspaceId = $this->createActiveCoreWorkspaceId();

        $this->runnerPath = sys_get_temp_dir().'/ai_gateway_race_runner_'.uniqid().'.php';
        file_put_contents($this->runnerPath, $this->runnerScript());
        $this->signalPath = sys_get_temp_dir().'/ai_gateway_race_signal_'.uniqid().'.flag';

        $processes = [];
        $buffers = [];

        for ($i = 0; $i < self::RACING_PROCESS_COUNT; $i++) {
            $idempotencyKey = (string) Str::uuid();
            $process = new Process(
                [$this->phpBinary(), $this->runnerPath, (string) $workspaceId, $this->signalPath, $idempotencyKey],
                null,
                $this->childEnvironment(),
            );
            $process->setTimeout(20.0);
            $processes[] = $process;
            $buffers[] = '';
        }

        foreach ($processes as $process) {
            $process->start();
        }

        $deadline = microtime(true) + 15.0;

        while (microtime(true) < $deadline) {
            $allWaiting = true;

            foreach ($processes as $i => $process) {
                $buffers[$i] .= $process->getIncrementalOutput();

                if (! str_contains($buffers[$i], 'WAITING')) {
                    $allWaiting = false;
                }
            }

            if ($allWaiting) {
                break;
            }

            usleep(2000);
        }

        foreach ($buffers as $i => $buffer) {
            $this->assertStringContainsString('WAITING', $buffer, "Process {$i} never announced readiness before the race was triggered.");
        }

        file_put_contents($this->signalPath, '1');

        foreach ($processes as $process) {
            $process->wait();
        }

        $committedCount = 0;
        $refusedBudgetExhaustedCount = 0;

        foreach ($processes as $i => $process) {
            $this->assertTrue($process->isSuccessful(), "Process {$i} did not complete: ".$process->getErrorOutput());
            $output = $process->getOutput();

            if (str_contains($output, 'COMMITTED')) {
                $committedCount++;
            } elseif (str_contains($output, 'REFUSED:budget_exhausted')) {
                $refusedBudgetExhaustedCount++;
            } else {
                $this->fail("Process {$i} produced an unexpected outcome: {$output}");
            }
        }

        $this->assertSame(
            self::EXPECTED_ACCEPTED_COUNT,
            $committedCount,
            'Exactly floor(cap / fixed cost) racing requests must be accepted, regardless of which ones won the race.'
        );
        $this->assertSame(
            self::RACING_PROCESS_COUNT - self::EXPECTED_ACCEPTED_COUNT,
            $refusedBudgetExhaustedCount,
            'Every racing request beyond the cap must be refused as budget_exhausted, never silently dropped or double-accepted.'
        );

        $periodKey = now('UTC')->format('Y-m');
        $period = DB::table('ai_usage_periods')
            ->where('scope_type', 'workspace')
            ->where('scope_id', $workspaceId)
            ->where('period_key', $periodKey)
            ->first();

        $this->assertNotNull($period, 'The workspace usage period row must exist after the race.');
        $this->assertSame(0, (int) $period->reserved_microusd, 'No reservation may remain outstanding once every racing request has settled.');
        $this->assertSame(
            self::EXPECTED_ACCEPTED_COUNT * $this->fixedCostMicrousd(),
            (int) $period->committed_microusd,
            'Committed spend must equal exactly the accepted requests worth, never more than the cap.'
        );
        $this->assertLessThanOrEqual(
            $this->workspaceCapMicrousd(),
            (int) $period->reserved_microusd + (int) $period->committed_microusd,
            'reserved + committed must never exceed the period cap at settlement, the core T-BUD-4 invariant.'
        );

        $committedLedgerCount = DB::table('ai_usage_ledger')
            ->where('workspace_id', $workspaceId)
            ->where('status', 'committed')
            ->count();
        $this->assertSame(self::EXPECTED_ACCEPTED_COUNT, $committedLedgerCount);

        $refusedLedgerCount = DB::table('ai_usage_ledger')
            ->where('workspace_id', $workspaceId)
            ->where('status', 'refused')
            ->count();
        $this->assertSame(self::RACING_PROCESS_COUNT - self::EXPECTED_ACCEPTED_COUNT, $refusedLedgerCount);
    }
}

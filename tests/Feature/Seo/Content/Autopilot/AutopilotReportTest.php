<?php

namespace Tests\Feature\Seo\Content\Autopilot;

use App\Library\Ai\Contracts\AiCompletionClient;
use App\Library\Ai\Providers\FakeAiCompletionClient;
use App\Library\Seo\Content\Autopilot\AutopilotSwitch;
use App\Models\AiUsageLedgerEntry;
use App\Models\ContentAutopilotDecision as Decision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Seo\Content\Concerns\CreatesContentFixtures;
use Tests\TestCase;

/** Content Autopilot, Slice 9 - the read-only ops report: status, articles started and spend against the target and ceiling. */
class AutopilotReportTest extends TestCase
{
    use CreatesContentFixtures;
    use RefreshDatabase;

    public function test_it_reports_status_and_spend_without_writing_or_calling_a_model(): void
    {
        $fake = new FakeAiCompletionClient();
        $this->app->instance(AiCompletionClient::class, $fake);
        $t = $this->photoBoothContentTenant(withBlueprint: true);
        app(AutopilotSwitch::class)->enable($t[1], (int) $t[0]->user_id);
        Decision::query()->create(['business_id' => $t[1]->id, 'kind' => 'create', 'decision' => 'create', 'state' => 'awaiting_approval', 'period_key' => now('UTC')->format('Y-m'), 'evaluated_at' => now()]);
        AiUsageLedgerEntry::query()->create([
            'uid' => (string) Str::uuid(), 'workspace_id' => $t[2]->id, 'business_id' => $t[1]->id, 'category' => 'content_autopilot', 'lane' => 'product',
            'model_route' => 'content_writer', 'provider' => 'openai', 'price_version' => 1, 'status' => 'committed', 'period_key' => now('UTC')->format('Y-m'),
            'idempotency_key' => (string) Str::uuid(), 'estimated_cost_microusd' => 60000, 'actual_cost_microusd' => 33000,
        ]);
        $before = Decision::query()->count();

        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('content:autopilot-report'));
        $output = \Illuminate\Support\Facades\Artisan::output();
        $this->assertStringContainsString('$0.0330', $output);
        $this->assertStringContainsString('awaiting_approval:1', $output);
        $this->assertStringContainsString('ok', $output);
        $this->assertStringContainsString('a safety limit, not a target', $output);

        $this->assertSame($before, Decision::query()->count());
        $this->assertSame(0, $fake->callCount());

        app(AutopilotSwitch::class)->disable($t[1]);
        \Illuminate\Support\Facades\Artisan::call('content:autopilot-report');
        $this->assertStringContainsString('No Business has Content Autopilot switched on.', \Illuminate\Support\Facades\Artisan::output());
    }
}

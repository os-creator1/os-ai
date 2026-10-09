<?php

namespace App\Console\Commands;

use App\Library\Ai\AiBudgetPolicyResolver;
use App\Library\Ai\AiBusinessCategoryCeiling;
use App\Library\Ai\Enums\AiUsageCategory;
use App\Models\Business;
use App\Models\ContentAutopilotDecision;
use App\Models\ContentAutopilotSetting;
use Illuminate\Console\Command;

/**
 * Content Autopilot - ops visibility, read-only and free: for each switched-on Business (or one), its status, how many
 * articles it started, and what the AI has cost this budget period against the $ target and the hard ceiling.
 * Nothing is written, no model is called.
 */
class ReportContentAutopilot extends Command
{
    protected $signature = 'content:autopilot-report {business? : Business id or uid (default: every Business with Autopilot on)}';

    protected $description = 'Show Content Autopilot status and AI spend against the target and ceiling (read-only)';

    public function handle(AiBusinessCategoryCeiling $ceiling, AiBudgetPolicyResolver $policies): int
    {
        $ref = $this->argument('business');
        $settings = ContentAutopilotSetting::query()->where('enabled', true)
            ->when($ref !== null, fn ($q) => $q->whereIn('business_id', Business::query()->where(ctype_digit((string) $ref) ? 'id' : 'uid', $ref)->pluck('id')))
            ->orderBy('business_id')->get();

        if ($settings->isEmpty()) {
            $this->info('No Business has Content Autopilot switched on.');

            return self::SUCCESS;
        }

        $category = AiUsageCategory::ContentAutopilot;
        $target = $ceiling->targetMicrousd($category);
        $hard = $ceiling->hardCeilingMicrousd($category);
        $rows = [];

        foreach ($settings as $setting) {
            $business = Business::query()->find($setting->business_id);

            if ($business === null) {
                continue;
            }

            $period = $policies->resolveFor($business->workspace)->periodKey;
            $spent = $ceiling->spentMicrousd($business, $category, $period);
            $byState = ContentAutopilotDecision::query()->where('business_id', $business->id)->where('period_key', $period)->where('kind', ContentAutopilotDecision::KIND_CREATE)
                ->where('decision', ContentAutopilotDecision::DECISION_CREATE)->selectRaw('state, count(*) as n')->groupBy('state')->pluck('n', 'state');

            $rows[] = [
                $business->id,
                $setting->paused_reason ?? 'running',
                $period,
                $byState->sum(),
                $byState->map(fn ($n, $state) => "{$state}:{$n}")->implode(' ') ?: '-',
                $this->dollars($spent),
                $spent > (int) $hard ? 'OVER CEILING' : ($target !== null && $spent > $target ? 'over target' : 'ok'),
            ];
        }

        $this->table(['business', 'status', 'period', 'started', 'by state', 'AI spend', 'budget'], $rows);
        $this->line('Target ' . $this->dollars((int) $target) . ' - hard ceiling ' . $this->dollars((int) $hard) . ' per Business per period. The ceiling is a safety limit, not a target.');

        return self::SUCCESS;
    }

    private function dollars(int $microusd): string
    {
        return '$' . number_format($microusd / 1_000_000, 4);
    }
}

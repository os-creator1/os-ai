<?php

declare(strict_types=1);

namespace App\Library\Growth\Readers;

use App\Enums\Entitlement\PlatformFeature;
use App\Library\Growth\GrowthFactReader;
use App\Library\Growth\GrowthFactSet;
use App\Library\Growth\GrowthThresholds;
use App\Models\Business;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Automation FAILURE facts (domain `automations`) — only what current main
 * records durably and queryably: automation_step_runs.status = 'failed' (the
 * workflow engine) and automation_executions.status = 'failed' (the legacy
 * managed-automation runs), each with a safe_error_summary.
 *
 * Absence is never evidence. A Business with no workflow has no failures
 * here, and "no follow-up workflow exists" is NOT a fact this reader reports
 * (Growth Center §9, §24: never infer a broken automation from the absence of
 * one).
 *
 * Two queries.
 *
 * Fact shape:
 *   window_days     int   7
 *   failed_steps    int   failed engine step runs in the window
 *   failed_runs     int   failed legacy executions in the window
 *   workflows       int   distinct workflows/automations that failed
 *   attempted       int   step runs + executions of ANY status in the window (the population)
 */
final class GrowthAutomationFactReader implements GrowthFactReader
{
    private const WINDOW_DAYS = 7;

    public function domain(): string
    {
        return 'automations';
    }

    public function feature(): ?PlatformFeature
    {
        return PlatformFeature::Automations;
    }

    public function read(Business $business, CarbonImmutable $now, GrowthThresholds $thresholds): GrowthFactSet
    {
        $since = $now->subDays(self::WINDOW_DAYS);

        $steps = DB::table('automation_step_runs as s')
            ->join('automation_enrollments as e', 'e.id', '=', 's.enrollment_id')
            ->where('s.business_id', $business->id)
            ->where('s.created_at', '>=', $since)
            ->selectRaw("COUNT(*) AS attempted, SUM(CASE WHEN s.status = 'failed' THEN 1 ELSE 0 END) AS failed")
            ->selectRaw("COUNT(DISTINCT CASE WHEN s.status = 'failed' THEN e.workflow_id END) AS workflows")
            ->first();

        $runs = DB::table('automation_executions')
            ->where('business_id', $business->id)
            ->where('created_at', '>=', $since)
            ->selectRaw("COUNT(*) AS attempted, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed")
            ->selectRaw("COUNT(DISTINCT CASE WHEN status = 'failed' THEN automation_id END) AS automations")
            ->first();

        return GrowthFactSet::available($this->domain(), [
            'window_days' => self::WINDOW_DAYS,
            'failed_steps' => (int) ($steps->failed ?? 0),
            'failed_runs' => (int) ($runs->failed ?? 0),
            'workflows' => (int) ($steps->workflows ?? 0) + (int) ($runs->automations ?? 0),
            'attempted' => (int) ($steps->attempted ?? 0) + (int) ($runs->attempted ?? 0),
        ]);
    }
}

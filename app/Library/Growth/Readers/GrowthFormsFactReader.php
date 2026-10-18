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
 * Forms facts (domain `forms`) from the canonical `forms` and `form_submissions` tables — two
 * statements however many forms exist. A form's INTENT is never inferred from its title: the only
 * claims are "this live form has not received a submission since it went live" and a plain
 * 30-day submission count.
 *
 * Fact shape:
 *   active                 forms that are live (lifecycle_state = active)
 *   quiet                  live forms live for `forms_quiet_days`+ days with NO submission ever
 *   submissions_30d        submissions received in the last 30 days (all forms)
 */
final class GrowthFormsFactReader implements GrowthFactReader
{
    public function domain(): string
    {
        return 'forms';
    }

    public function feature(): ?PlatformFeature
    {
        return PlatformFeature::Forms;
    }

    public function read(Business $business, CarbonImmutable $now, GrowthThresholds $thresholds): GrowthFactSet
    {
        $liveBefore = $now->subDays($thresholds->get('forms_quiet_days'));

        $forms = DB::table('forms as f')
            ->where('f.business_id', $business->id)
            ->where('f.lifecycle_state', 'active')
            ->selectRaw('COUNT(*) as active')
            ->selectRaw('SUM(CASE WHEN f.activated_at IS NOT NULL AND f.activated_at <= ? AND NOT EXISTS (SELECT 1 FROM form_submissions s WHERE s.form_id = f.id) THEN 1 ELSE 0 END) as quiet', [$liveBefore])
            ->first();

        $recent = (int) DB::table('form_submissions')
            ->where('business_id', $business->id)
            ->where('created_at', '>=', $now->subDays(30))
            ->count();

        return GrowthFactSet::available($this->domain(), [
            'active' => (int) ($forms->active ?? 0),
            'quiet' => (int) ($forms->quiet ?? 0),
            'submissions_30d' => $recent,
        ]);
    }
}

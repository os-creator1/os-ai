<?php

namespace Tests\Feature\Seo\Rank\Concerns;

use App\Models\SeoRankCheckRun;
use App\Models\SeoRankObservation;
use App\Models\SeoRankTarget;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Direct observation fixtures (a completed run + its observation) for suites
 * that exercise the read side without driving the paid pipeline.
 */
trait CreatesRankObservations
{
    protected function obs(SeoRankTarget $target, string $type, ?int $position, ?CarbonInterface $at = null, ?string $status = null): SeoRankObservation
    {
        $at ??= now();
        $status ??= $position !== null ? 'found' : 'not_found';

        $run = new SeoRankCheckRun();
        $run->forceFill([
            'business_id' => $target->business_id,
            'workspace_id' => null,
            'seo_rank_target_id' => $target->id,
            'check_type' => $type,
            'trigger' => 'scheduled',
            'idempotency_key' => $target->id . ':' . $type . ':' . Str::uuid(),
            'state' => 'completed',
            'provider' => $target->provider,
            'depth' => $type === 'organic' ? 100 : 10,
            'completed_at' => $at,
        ])->save();

        $o = new SeoRankObservation();
        $o->forceFill([
            'business_id' => $target->business_id,
            'seo_rank_target_id' => $target->id,
            'seo_rank_check_run_id' => $run->id,
            'check_type' => $type,
            'status' => $status,
            'position' => $status === 'found' ? $position : null,
            'result_url' => $status === 'found' ? 'https://photoboothco.com/' : null,
            'result_domain' => $status === 'found' ? 'photoboothco.com' : null,
            'result_path' => $status === 'found' ? '/' : null,
            'match_basis' => $status === 'found' ? 'domain' : null,
            'depth_checked' => $type === 'organic' ? 100 : 10,
            'provider' => $target->provider,
            'search_location_code' => $target->search_location_code,
            'device' => $target->device,
            'checked_at' => $at,
        ])->save();

        return $o->fresh();
    }

    /** An unsaved observation for pure-function tests. */
    protected function memObs(?int $position, string $status = 'found', string $type = 'organic', ?CarbonInterface $at = null): SeoRankObservation
    {
        $o = new SeoRankObservation();
        $o->forceFill([
            'check_type' => $type,
            'status' => $status,
            'position' => $position,
            'checked_at' => $at ?? now(),
        ]);

        return $o;
    }
}

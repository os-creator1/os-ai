<?php

namespace App\Library\Seo\Rank;

use App\Enums\Seo\SeoRankCheckType;
use App\Enums\Seo\SeoRankRunState;
use App\Library\Seo\Rank\Provider\SeoRankTaskResult;
use App\Models\SeoRankCheckRun;
use App\Models\SeoRankObservation;
use App\Models\SeoRankTarget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Turns one COMPLETED provider result into one normalized observation. The
 * only writer of seo_rank_observations. No network I/O; the result has already
 * been fetched. Idempotent: an observation exists at most once per run (unique
 * run id), so reprocessing a result can never duplicate history.
 */
final class SeoRankObservationRecorder
{
    public function __construct(
        private readonly SeoRankMatcher $matcher,
        private readonly SeoRankTrackingBudget $budget,
    ) {
    }

    public function record(SeoRankCheckRun $run, SeoRankTaskResult $result, ?CarbonImmutable $now = null): ?SeoRankObservation
    {
        $now ??= CarbonImmutable::now('UTC');

        return DB::transaction(function () use ($run, $result, $now) {
            $locked = SeoRankCheckRun::query()->whereKey($run->id)->lockForUpdate()->first();

            if ($locked === null || $locked->state !== SeoRankRunState::Submitted) {
                return null;
            }

            $target = SeoRankTarget::query()->whereKey($locked->seo_rank_target_id)->with('business')->first();

            if ($target === null) {
                return null;
            }

            $identity = SeoRankIdentity::forBusiness($target->business);
            $depth = (int) $locked->depth;

            $match = $locked->check_type === SeoRankCheckType::Organic
                ? $this->matcher->organic($identity, $result->items, $depth)
                : $this->matcher->local($identity, $result->items, $depth);

            $observation = new SeoRankObservation();
            $observation->forceFill([
                'business_id' => $locked->business_id,
                'seo_rank_target_id' => $locked->seo_rank_target_id,
                'seo_rank_check_run_id' => $locked->id,
                'check_type' => $locked->check_type->value,
                'status' => $match['status']->value,
                'position' => $match['position'],
                'result_url' => $match['url'] !== null ? mb_substr($match['url'], 0, 2048) : null,
                'result_domain' => $match['domain'],
                'result_path' => $match['path'],
                'match_basis' => $match['basis'],
                'depth_checked' => $depth,
                'provider' => $locked->provider,
                'search_location_code' => $target->search_location_code,
                'device' => $target->device,
                'checked_at' => $now,
            ])->save();

            $target->forceFill(['last_checked_at' => $now])->save();

            $locked->forceFill([
                'state' => SeoRankRunState::Completed->value,
                'completed_at' => $now,
                'error_code' => null,
            ])->save();

            $this->budget->commit($locked, $result->costMicros);

            return $observation;
        });
    }
}

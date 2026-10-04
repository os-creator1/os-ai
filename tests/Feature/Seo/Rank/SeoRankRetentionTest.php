<?php

namespace Tests\Feature\Seo\Rank;

use App\Jobs\Seo\PruneSeoRankObservations;
use App\Library\Seo\SeoConfig;
use App\Models\SeoRankCheckRun;
use App\Models\SeoRankObservation;
use App\Models\SeoRankProviderLedger;
use App\Models\SeoRankTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Seo\Rank\Concerns\CreatesRankFixtures;
use Tests\Feature\Seo\Rank\Concerns\CreatesRankObservations;
use Tests\TestCase;

class SeoRankRetentionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRankFixtures;
    use CreatesRankObservations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rankSetUp();
    }

    private function prune(): void
    {
        (new PruneSeoRankObservations())->handle(app(SeoConfig::class));
    }

    private function target(): SeoRankTarget
    {
        [$owner, $business] = $this->rankTenant();

        return $this->track($owner, $business, $this->keyword($owner, $business));
    }

    public function test_observations_older_than_thirteen_months_are_deleted_and_newer_are_kept(): void
    {
        $target = $this->target();
        $old = $this->obs($target, 'organic', 5, now()->subMonths(14));
        $older = $this->obs($target, 'local', 2, now()->subMonths(30));
        $recent = $this->obs($target, 'organic', 4, now()->subMonths(12));
        $today = $this->obs($target, 'organic', 3, now());

        $this->prune();

        $remaining = SeoRankObservation::query()->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$recent->id, $today->id], $remaining);
        $this->assertNotContains($old->id, $remaining);
        $this->assertNotContains($older->id, $remaining);
    }

    public function test_a_lowered_retention_is_honoured(): void
    {
        config(['seo.rank_tracking.retention_months' => 6]);
        $target = $this->target();
        $this->obs($target, 'organic', 5, now()->subMonths(7));
        $kept = $this->obs($target, 'organic', 4, now()->subMonths(5));

        $this->prune();

        $this->assertSame([$kept->id], SeoRankObservation::query()->pluck('id')->all());
    }

    public function test_retention_can_never_be_raised_above_thirteen_months(): void
    {
        config(['seo.rank_tracking.retention_months' => 36]);
        $this->assertSame(13, app(SeoConfig::class)->rankRetentionMonths());

        $target = $this->target();
        $this->obs($target, 'organic', 5, now()->subMonths(14));
        $kept = $this->obs($target, 'organic', 4, now()->subMonths(12));

        $this->prune();

        $this->assertSame([$kept->id], SeoRankObservation::query()->pluck('id')->all());
    }

    public function test_the_ledger_and_runs_are_untouched(): void
    {
        $target = $this->target();
        $old = $this->obs($target, 'organic', 5, now()->subMonths(20));
        $run = SeoRankCheckRun::query()->findOrFail($old->seo_rank_check_run_id);

        $ledger = new SeoRankProviderLedger();
        $ledger->forceFill([
            'business_id' => $target->business_id, 'workspace_id' => null, 'provider' => $target->provider,
            'operation' => 'organic', 'seo_rank_check_run_id' => $run->id,
            'usage_month' => now()->subMonths(20)->format('Y-m'), 'usage_day' => now()->subMonths(20)->toDateString(),
            'reserved_micros' => 6000, 'actual_micros' => 6000, 'status' => 'committed',
        ])->save();

        $runsBefore = SeoRankCheckRun::query()->count();
        $ledgerBefore = SeoRankProviderLedger::query()->orderBy('id')->get()->toArray();

        $this->prune();

        $this->assertSame(0, SeoRankObservation::query()->count());
        $this->assertSame($runsBefore, SeoRankCheckRun::query()->count());
        $this->assertSame($ledgerBefore, SeoRankProviderLedger::query()->orderBy('id')->get()->toArray());
        $this->assertSame(1, SeoRankTarget::query()->count());
    }

    public function test_pruning_with_nothing_old_is_a_no_op(): void
    {
        $target = $this->target();
        $this->obs($target, 'organic', 5, now()->subDays(3));

        $this->prune();

        $this->assertSame(1, SeoRankObservation::query()->count());
    }
}

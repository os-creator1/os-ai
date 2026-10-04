<?php

namespace App\Console\Commands;

use App\Library\Growth\GrowthEvaluationTrigger;
use App\Models\GrowthScoreSnapshot;
use App\Repositories\Contracts\OpportunityProducerDispatchRepository;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * The daily Growth Center evaluation sweep (Growth Center §52): every active
 * Business gets one full evaluation a day, whether or not any event fired.
 *
 * It is the same bounded, idempotent shape as the Business Advisor sweep it
 * sits beside: keyset pages ordered by Business id, a hard --limit per
 * invocation, and a per-Business per-day claim held in the database, so
 * running it twice (or on two servers) dispatches nothing the second time.
 * A Business that already had a successful sales-worker run inside the
 * staleness window is filtered out in SQL, not loaded and rejected in PHP.
 *
 * It owns the engine-enabled no-op itself, like every other Opportunity
 * command, and does its housekeeping (score-snapshot retention) here rather
 * than on a second schedule.
 */
class DispatchGrowthEvaluations extends Command
{
    protected $signature = 'growth:evaluate
        {--limit=500 : Maximum number of Businesses to dispatch for in this invocation}
        {--page=100 : Candidates read per keyset page}';

    protected $description = 'Dispatch the daily Growth Center evaluation for eligible Businesses';

    public function handle(GrowthEvaluationTrigger $trigger, OpportunityProducerDispatchRepository $dispatches): int
    {
        if (! config('opportunity.enabled', false)) {
            $this->info('Opportunity engine is disabled; Growth evaluation sweep skipped.');

            return self::SUCCESS;
        }

        $limit = $this->positiveOption('limit');
        $page = $this->positiveOption('page');

        if ($limit === null || $page === null) {
            $this->error('The --limit and --page options must be positive integers.');

            return self::INVALID;
        }

        $now = CarbonImmutable::now();
        $staleBefore = $now->subHours(max(1, (int) config('opportunity.sweep_stale_hours', 24)));
        $after = 0;
        $dispatched = 0;
        $considered = 0;

        while ($considered < $limit) {
            $candidates = $dispatches->sweepCandidates(
                GrowthEvaluationTrigger::CLAIM_WORKER,
                $now,
                $staleBefore,
                $after,
                min($page, $limit - $considered),
            );

            if ($candidates->isEmpty()) {
                break;
            }

            foreach ($candidates as $businessId) {
                $considered++;
                $after = max($after, $businessId);

                if ($trigger->triggerDailySweep($businessId, $now)) {
                    $dispatched++;
                }
            }
        }

        $pruned = GrowthScoreSnapshot::query()
            ->where('snapshot_date', '<', $now->subDays(max(400, (int) config('growth.score.retention_days', 430)))->toDateString())
            ->delete();

        $this->info("Considered {$considered} Business(es); dispatched {$dispatched} Growth evaluation(s); pruned {$pruned} old score snapshot(s).");

        return self::SUCCESS;
    }

    private function positiveOption(string $name): ?int
    {
        $raw = $this->option($name);

        if (is_int($raw) && $raw > 0) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '' && ctype_digit($raw) && (int) $raw > 0) {
            return (int) $raw;
        }

        return null;
    }
}

<?php

namespace App\Console\Commands;

use App\Library\Opportunity\OpportunityProducerTrigger;
use App\Repositories\Contracts\OpportunityProducerDispatchRepository;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * COO C-1 — the daily safety net behind the event-driven trigger: a bounded
 * sweep that gives every active Business one Business Advisor producer run a
 * day even if nothing about it changed.
 *
 * BOUNDED AND DETERMINISTIC. Candidates arrive in keyset pages ordered by
 * business id (id > the last one seen), never by OFFSET and never as one big
 * result set, so memory is a page at a time and the order cannot drift between
 * pages. --limit caps the whole invocation; the scheduler's next run continues
 * where a capped one stopped, because the day's claims record what was already
 * handled.
 *
 * IDEMPOTENT. Every dispatch is gated by a per-Business, per-day claim held in
 * the database (OpportunityProducerTrigger::triggerDailySweep()), so running
 * this command twice on the same day dispatches nothing the second time. That
 * also holds if two schedulers, two servers or a human and the scheduler run
 * it at once.
 *
 * CHEAP WHERE IT SHOULD BE. A Business that already produced a successful run
 * inside the staleness window is filtered out in SQL, not loaded and rejected
 * in PHP, and the per-Business work is a claim plus at most one dispatch — no
 * query per Business beyond the eligibility read the trigger performs.
 *
 * Like every other Opportunity command (RFC-002 §33), it owns its own
 * engine-enabled no-op rather than relying on the scheduler to decide.
 */
class DispatchBusinessAdvisorOpportunityProducers extends Command
{
    protected $signature = 'opportunity:dispatch-business-advisor
        {--limit=500 : Maximum number of Businesses to dispatch for in this invocation}
        {--page=100 : Candidates read per keyset page}';

    protected $description = 'Dispatch the daily Business Advisor Opportunity producer run for eligible Businesses';

    public function handle(
        OpportunityProducerTrigger $trigger,
        OpportunityProducerDispatchRepository $dispatches,
    ): int {
        if (! config('opportunity.enabled', false)) {
            $this->info('Opportunity engine is disabled; Business Advisor producer sweep skipped.');

            return self::SUCCESS;
        }

        $limit = $this->positiveOption('limit');
        $page = $this->positiveOption('page');

        if ($limit === null || $page === null) {
            $this->error('The --limit and --page options must be positive integers.');

            return self::INVALID;
        }

        $now = CarbonImmutable::now();
        $staleBefore = $now->subHours($this->staleHours());
        $afterBusinessId = 0;
        $dispatched = 0;
        $considered = 0;

        while ($considered < $limit) {
            $candidates = $dispatches->sweepCandidates(
                OpportunityProducerTrigger::WORKER_KEY,
                $now,
                $staleBefore,
                $afterBusinessId,
                min($page, $limit - $considered),
            );

            if ($candidates->isEmpty()) {
                break;
            }

            foreach ($candidates as $businessId) {
                $considered++;
                $afterBusinessId = max($afterBusinessId, $businessId);

                if ($trigger->triggerDailySweep($businessId, $now)) {
                    $dispatched++;
                }
            }
        }

        $this->info("Considered {$considered} Business(es); dispatched {$dispatched} Business Advisor producer run(s).");

        return self::SUCCESS;
    }

    private function staleHours(): int
    {
        $hours = (int) config('opportunity.sweep_stale_hours', 24);

        return $hours > 0 ? $hours : 24;
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

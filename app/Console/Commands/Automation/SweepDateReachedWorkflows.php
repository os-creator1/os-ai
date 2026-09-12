<?php

namespace App\Console\Commands\Automation;

use App\Library\Automation\Workflow\Triggers\DateReachedTriggerSource;
use App\Library\Automation\Workflow\WorkflowLimits;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Automations V2 §8.2/§9 — `automation:workflows-date-sweep`.
 *
 * The only thing that can start a date-reached journey, because a date arriving
 * is not an event anything emits: somebody has to look. It runs on the
 * scheduler, and every rule about WHAT is due lives in DateReachedTriggerSource
 * — this command only reads its bounds, calls it once, and reports.
 *
 * BOUNDED BY CONSTRUCTION. `--limit` caps enrollments per invocation and
 * `WorkflowLimits::SWEEP_CHUNK_SIZE` caps every page it reads, so neither the
 * number of workflows nor the size of a contact group can turn one tick into
 * unbounded work. A capped run is not a stuck run: contacts already holding
 * this occurrence's enrollment are excluded in SQL, so the next tick continues
 * where this one stopped.
 *
 * IDEMPOTENT BY THE CLAIM, NOT BY BOOKKEEPING. Running it twice in one minute,
 * or from two servers, enrolls nobody twice — the enrollment key for this
 * occurrence is unique (§7.5), so the second attempt loses the insert. That is
 * why the command needs no cursor, no lock and no "last run" row.
 */
class SweepDateReachedWorkflows extends Command
{
    protected $signature = 'automation:workflows-date-sweep
        {--limit= : Maximum enrollments to create in this invocation}';

    protected $description = 'Enroll contacts whose configured date has arrived into their Business workflows';

    public function handle(DateReachedTriggerSource $trigger): int
    {
        $limit = $this->resolveLimit();

        if ($limit === null) {
            $this->error('The --limit option must be a positive integer.');

            return self::INVALID;
        }

        $result = $trigger->sweep(CarbonImmutable::now(), $limit);

        $this->info(sprintf(
            'Inspected %d date workflow(s); enrolled %d contact(s).',
            $result['considered'],
            $result['enrolled'],
        ));

        return self::SUCCESS;
    }

    private function resolveLimit(): ?int
    {
        $raw = $this->option('limit');

        if ($raw === null || $raw === '') {
            return WorkflowLimits::SWEEP_ENROLLMENTS_PER_RUN;
        }

        if (is_int($raw) && $raw > 0) {
            return $raw;
        }

        if (is_string($raw) && ctype_digit($raw) && (int) $raw > 0) {
            return (int) $raw;
        }

        return null;
    }
}

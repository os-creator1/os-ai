<?php

namespace App\Console\Commands;

use App\Enums\Business\BusinessStatus;
use App\Enums\Coo\CooInsightTrigger;
use App\Jobs\Coo\GenerateCooInsight;
use App\Models\Business;
use Illuminate\Console\Command;

/**
 * Contract §8.2 (slice AI-3) — the scheduled COO insight triggers.
 *
 *   daily                   → E-1, "multi-signal change"
 *   daily --monthly         → E-3, "monthly deeper review" (scheduled once a month)
 *
 * It only queues. Each GenerateCooInsight decides for itself, off-request, in
 * the generator's cheapest-refusal-first order: AI off, not entitled, dormant,
 * condition not met (E-1: fewer than two material metrics or a rule explains
 * them; E-3: fingerprint unchanged since the last insight), identical facts
 * already cached. Most Businesses cost one entitlement check and nothing else.
 *
 * BOUNDED. Active Business ids arrive in keyset pages (id > the last seen),
 * never by OFFSET; --limit caps one invocation. With AI switched off the
 * command queues nothing at all. Running it twice queues the same work twice,
 * and pays for it once: the insight identity and the ledger's idempotency
 * family both refuse identical facts a second time.
 */
class DispatchCooInsightReviews extends Command
{
    protected $signature = 'coo:dispatch-insight-reviews
        {--monthly : Queue the E-3 monthly review instead of the daily E-1 check}
        {--limit=1000 : Maximum number of Businesses to queue for in this invocation}
        {--page=200 : Business ids read per keyset page}';

    protected $description = 'Queue the scheduled COO insight checks (E-1 daily, E-3 monthly) for active Businesses';

    public function handle(): int
    {
        if (! (bool) config('services.openai.active')) {
            $this->info('AI is switched off; no COO insight review queued.');

            return self::SUCCESS;
        }

        $limit = $this->positiveOption('limit');
        $page = $this->positiveOption('page');

        if ($limit === null || $page === null) {
            $this->error('The --limit and --page options must be positive integers.');

            return self::INVALID;
        }

        $trigger = $this->option('monthly') ? CooInsightTrigger::MonthlyReview : CooInsightTrigger::MultiSignalChange;
        $afterId = 0;
        $queued = 0;

        while ($queued < $limit) {
            $ids = Business::query()
                ->where('status', BusinessStatus::Active->value)
                ->where('id', '>', $afterId)
                ->orderBy('id')
                ->limit(min($page, $limit - $queued))
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            foreach ($ids as $id) {
                $afterId = (int) $id;
                GenerateCooInsight::dispatch((int) $id, $trigger->value);
                $queued++;
            }
        }

        $this->info("Queued {$queued} COO insight check(s) ({$trigger->value}).");

        return self::SUCCESS;
    }

    private function positiveOption(string $name): ?int
    {
        $raw = $this->option($name);

        if (is_string($raw) && ctype_digit($raw) && (int) $raw > 0) {
            return (int) $raw;
        }

        return is_int($raw) && $raw > 0 ? $raw : null;
    }
}

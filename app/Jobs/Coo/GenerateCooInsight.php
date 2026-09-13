<?php

namespace App\Jobs\Coo;

use App\Enums\Coo\CooInsightTrigger;
use App\Library\Analytics\AnalyticsDateRange;
use App\Library\Analytics\BusinessDashboardAnalyticsPresenter;
use App\Library\Coo\Insight\CooInsightGenerator;
use App\Models\Business;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Contract §8.2, §9 (slice AI-3) — the ONLY place a COO insight is ever paid for.
 *
 * Queued for every trigger, the customer's own "Explain this change" included:
 * §8.1 keeps COO AI out of web requests, and E-4 needs no synchronous answer —
 * it keeps no thread and returns no text to the request that asked for it.
 *
 * Runs once. A refusal, a provider failure or a rejected output is final for
 * this attempt (no retry loop, §11.4); the next eligible trigger may try again.
 *
 * Entitlement is judged as of this job, never an earlier one in the same
 * worker: the global queue-job boundary, ResetRequestScopedCacheAtJobBoundary,
 * flushes the request-scoped memo before every worker job starts, so this job
 * keeps no cache lifecycle of its own.
 */
class GenerateCooInsight implements ShouldQueue, ShouldQueueAfterCommit
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    /**
     * @param  array{range?: string, start?: string, end?: string}  $range  the
     *   Business performance window as query parameters; empty means Home's
     *   default window
     */
    public function __construct(
        public readonly int $businessId,
        public readonly string $trigger,
        public readonly array $range = [],
        public readonly ?int $actorUserId = null,
    ) {
        $this->onQueue((string) config('coo.insight.queue', 'default'));
    }

    public function handle(CooInsightGenerator $generator): void
    {
        $trigger = CooInsightTrigger::tryFrom($this->trigger);
        $business = Business::query()->find($this->businessId);

        if ($trigger === null || $business === null) {
            return;
        }

        $generator->generate($business, $trigger, $this->range($business), $this->actorUserId);
    }

    private function range(Business $business): AnalyticsDateRange
    {
        $timezone = (string) ($business->timezone ?: config('app.timezone', 'UTC'));

        if (($this->range['range'] ?? null) !== null) {
            try {
                return AnalyticsDateRange::fromInput($this->range, $timezone);
            } catch (ValidationException|InvalidArgumentException) {
                // Fall through to the default window rather than guess.
            }
        }

        return AnalyticsDateRange::preset(BusinessDashboardAnalyticsPresenter::DEFAULT_PRESET, $timezone);
    }
}

<?php

namespace App\Library\Seo\Rank;

use App\Enums\Seo\SeoRankCheckType;
use App\Enums\Seo\SeoRankRunState;
use App\Enums\Seo\SeoRankTrackingState;
use App\Library\Seo\Rank\Provider\SeoRankProvider;
use App\Library\Seo\Rank\Provider\SeoRankProviderException;
use App\Library\Seo\Rank\Provider\SeoRankTaskRequest;
use App\Library\Seo\Rank\Provider\SeoRankTaskResult;
use App\Library\Seo\SeoConfig;
use App\Models\SeoRankCheckRun;
use App\Models\SeoRankProviderLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Drives reserved runs through the provider's Standard queue:
 *
 *   scheduled -> submitting -> submitted -> completed | failed_terminal
 *                   \-> held (submit outcome unknown)
 *
 * RULES THAT PROTECT SPEND
 *  - Every network call happens OUTSIDE a database transaction; state moves are
 *    short compare-and-set transactions.
 *  - submit() claims the run (`scheduled` -> `submitting`) before calling, so a
 *    duplicate job cannot submit twice.
 *  - Rejected (provider definitively created nothing): retried a bounded number
 *    of times with the SAME reservation, then released.
 *  - Ambiguous (timeout / 5xx / unreadable): the run is HELD, the reservation
 *    keeps counting, and nothing is resubmitted. reconcileHeld() can recover the
 *    provider's task id by our run-uid tag; otherwise the run is closed after the
 *    poll window with its cost left counted.
 *  - The provider task id is stored the moment it is returned; polling uses only
 *    that id. A failed or pending poll never resubmits.
 *  - Provider messages never leave this class: only closed error codes are stored.
 */
class SeoRankCheckExecutor
{
    private const SUBMIT_BACKOFF_SECONDS = [60, 300, 900];
    private const POLL_BACKOFF_SECONDS = [300, 600, 1200, 1800, 3600];
    private const POLL_LEASE_SECONDS = 120;
    private const STUCK_SUBMITTING_MINUTES = 15;

    public function __construct(
        private readonly SeoRankProvider $provider,
        private readonly SeoRankTrackingBudget $budget,
        private readonly SeoRankObservationRecorder $recorder,
        private readonly SeoConfig $config,
    ) {
    }

    public function submit(int $runId, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now('UTC');

        $run = $this->claimForSubmit($runId, $now);

        if ($run === null) {
            return;
        }

        $target = $run->target()->with('keyword')->first();

        $request = new SeoRankTaskRequest(
            $run->check_type->value,
            (string) $target->keyword->phrase,
            (int) $target->search_location_code,
            (string) $target->language_code,
            (string) $target->device,
            (int) $run->depth,
            (string) $run->uid,
        );

        try {
            $submission = $this->provider->submit($request);
        } catch (SeoRankProviderException $e) {
            $this->afterSubmitFailure($run, $e, $now);

            return;
        } catch (Throwable) {
            $this->afterSubmitFailure($run, SeoRankProviderException::ambiguous(SeoRankProviderException::CODE_UNAVAILABLE), $now);

            return;
        }

        DB::transaction(function () use ($run, $submission, $now) {
            SeoRankCheckRun::query()->whereKey($run->id)->update([
                'state' => SeoRankRunState::Submitted->value,
                'provider_task_id' => $submission->taskId,
                'submitted_at' => $now,
                'next_attempt_at' => $now->addSeconds(self::POLL_BACKOFF_SECONDS[0]),
                'error_code' => null,
            ]);

            $this->budget->commit($run, $submission->costMicros);
        });
    }

    public function poll(int $runId, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now('UTC');

        $run = $this->claimForPoll($runId, $now);

        if ($run === null) {
            return;
        }

        try {
            $result = $this->provider->fetch($run->check_type->value, (string) $run->provider_task_id);
        } catch (Throwable) {
            // Collecting is free and repeatable; a failed poll only waits.
            $this->scheduleNextPoll($run, $now);

            return;
        }

        if ($result->state === SeoRankTaskResult::PENDING) {
            $this->scheduleNextPoll($run, $now);

            return;
        }

        if ($result->state === SeoRankTaskResult::COMPLETED) {
            $this->recorder->record($run, $result, $now);

            return;
        }

        // Failed after the provider accepted it: the charge is presumed, keep it counted.
        $this->closeFailed($run, $result->errorCode ?? SeoRankProviderException::CODE_TASK_FAILED, $now, $result->costMicros);
    }

    /**
     * Recover held runs by the run-uid tag the provider echoes; close those older
     * than the poll window without releasing their cost. Returns runs adopted.
     */
    public function reconcileHeld(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now('UTC');
        $adopted = 0;
        $ready = [];

        foreach (SeoRankCheckType::cases() as $type) {
            $held = SeoRankCheckRun::query()
                ->where('state', SeoRankRunState::Held->value)
                ->where('check_type', $type->value)
                ->get();

            if ($held->isEmpty()) {
                continue;
            }

            try {
                $ready[$type->value] = $this->provider->readyTasksByTag($type->value);
            } catch (Throwable) {
                $ready[$type->value] = [];
            }

            foreach ($held as $run) {
                $taskId = $ready[$type->value][$run->uid] ?? null;

                if ($taskId !== null) {
                    $claimed = SeoRankCheckRun::query()
                        ->whereKey($run->id)
                        ->where('state', SeoRankRunState::Held->value)
                        ->update([
                            'state' => SeoRankRunState::Submitted->value,
                            'provider_task_id' => $taskId,
                            'submitted_at' => $now,
                            'next_attempt_at' => $now,
                            'error_code' => null,
                        ]);

                    if ($claimed === 1) {
                        $this->budget->commit($run);
                        $adopted++;
                    }

                    continue;
                }

                $started = $run->started_at ?? $run->created_at;

                if ($started->addHours($this->config->rankMaxPollHours())->lt($now)) {
                    $this->closeFailed($run, 'submit_unconfirmed', $now, null, SeoRankRunState::Held);
                }
            }
        }

        return $adopted;
    }

    /** A crash between claim and answer is an ambiguous submit: hold, never resubmit. */
    public function holdStuckSubmissions(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now('UTC');

        $stuck = SeoRankCheckRun::query()
            ->where('state', SeoRankRunState::Submitting->value)
            ->where('updated_at', '<', $now->subMinutes(self::STUCK_SUBMITTING_MINUTES))
            ->get();

        foreach ($stuck as $run) {
            $moved = SeoRankCheckRun::query()
                ->whereKey($run->id)
                ->where('state', SeoRankRunState::Submitting->value)
                ->update(['state' => SeoRankRunState::Held->value, 'error_code' => SeoRankProviderException::CODE_UNAVAILABLE]);

            if ($moved === 1) {
                $this->budget->hold($run);
            }
        }

        return $stuck->count();
    }

    private function claimForSubmit(int $runId, CarbonImmutable $now): ?SeoRankCheckRun
    {
        return DB::transaction(function () use ($runId, $now) {
            $run = SeoRankCheckRun::query()->whereKey($runId)->lockForUpdate()->first();

            if ($run === null || $run->state !== SeoRankRunState::Scheduled) {
                return null;
            }

            if ($run->next_attempt_at !== null && $run->next_attempt_at->gt($now)) {
                return null;
            }

            $target = $run->target()->first();

            // Never spend for something the owner has since stopped, or when the
            // operator has switched the provider off: release and close.
            // ...or for a Business that has been switched off since the job was queued.
            if (! $this->budget->enabled()
                || $target === null
                || $target->tracking_state !== SeoRankTrackingState::Tracking
                || $target->business === null
                || ! $this->budget->businessMaySpend($target->business)) {
                $run->forceFill([
                    'state' => SeoRankRunState::FailedTerminal->value,
                    'failed_at' => $now,
                    'error_code' => $this->budget->enabled() ? 'cancelled' : 'provider_disabled',
                ])->save();
                $this->budget->release($run);

                return null;
            }

            $run->forceFill([
                'state' => SeoRankRunState::Submitting->value,
                'attempts' => $run->attempts + 1,
                'started_at' => $run->started_at ?? $now,
            ])->save();

            return $run;
        });
    }

    private function afterSubmitFailure(SeoRankCheckRun $run, SeoRankProviderException $e, CarbonImmutable $now): void
    {
        if ($e->isAmbiguous()) {
            SeoRankCheckRun::query()->whereKey($run->id)->update([
                'state' => SeoRankRunState::Held->value,
                'error_code' => $e->errorCode,
            ]);
            $this->budget->hold($run);

            return;
        }

        if ($run->attempts < $this->config->rankMaxSubmitAttempts()) {
            $delay = self::SUBMIT_BACKOFF_SECONDS[min($run->attempts, count(self::SUBMIT_BACKOFF_SECONDS)) - 1];

            SeoRankCheckRun::query()->whereKey($run->id)->update([
                'state' => SeoRankRunState::Scheduled->value,
                'next_attempt_at' => $now->addSeconds($delay),
                'error_code' => $e->errorCode,
            ]);

            return;
        }

        SeoRankCheckRun::query()->whereKey($run->id)->update([
            'state' => SeoRankRunState::FailedTerminal->value,
            'failed_at' => $now,
            'error_code' => $e->errorCode,
        ]);
        $this->budget->release($run);
    }

    private function claimForPoll(int $runId, CarbonImmutable $now): ?SeoRankCheckRun
    {
        return DB::transaction(function () use ($runId, $now) {
            $run = SeoRankCheckRun::query()->whereKey($runId)->lockForUpdate()->first();

            if ($run === null || $run->state !== SeoRankRunState::Submitted || $run->provider_task_id === null) {
                return null;
            }

            if ($run->next_attempt_at !== null && $run->next_attempt_at->gt($now)) {
                return null;
            }

            $submitted = $run->submitted_at ?? $run->created_at;

            if ($submitted->addHours($this->config->rankMaxPollHours())->lt($now)) {
                $this->closeFailed($run, 'poll_timeout', $now, null);

                return null;
            }

            // A short lease so two workers do not poll the same task at once.
            $run->forceFill(['next_attempt_at' => $now->addSeconds(self::POLL_LEASE_SECONDS)])->save();

            return $run;
        });
    }

    private function scheduleNextPoll(SeoRankCheckRun $run, CarbonImmutable $now): void
    {
        $attempts = (int) $run->poll_attempts + 1;
        $delay = self::POLL_BACKOFF_SECONDS[min($attempts, count(self::POLL_BACKOFF_SECONDS)) - 1];

        SeoRankCheckRun::query()->whereKey($run->id)->where('state', SeoRankRunState::Submitted->value)->update([
            'poll_attempts' => $attempts,
            'next_attempt_at' => $now->addSeconds($delay),
        ]);
    }

    /** Closes a run whose provider task may have been charged: cost stays counted. */
    private function closeFailed(SeoRankCheckRun $run, string $code, CarbonImmutable $now, ?int $costMicros, SeoRankRunState $from = SeoRankRunState::Submitted): void
    {
        $moved = SeoRankCheckRun::query()
            ->whereKey($run->id)
            ->where('state', $from->value)
            ->update([
                'state' => SeoRankRunState::FailedTerminal->value,
                'failed_at' => $now,
                'error_code' => mb_substr($code, 0, 40),
            ]);

        if ($moved === 1) {
            $this->budget->commit($run, $costMicros);
        }
    }

    /** Ledger status for a run, for tests and the operator page. */
    public function ledgerStatus(SeoRankCheckRun $run): ?string
    {
        return SeoRankProviderLedger::query()->where('seo_rank_check_run_id', $run->id)->value('status');
    }
}

<?php

namespace App\Library\PlatformAutomation;

use App\Enums\PlatformAutomation\PlatformRunState;
use App\Enums\PlatformAutomation\PlatformStepState;
use App\Jobs\PlatformAutomation\ExecutePlatformAutomationRun;
use App\Models\PlatformAutomationRun;
use App\Models\PlatformAutomationStep;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Walks one run's steps in order.
 *
 *  - serialised per run by a cache lock (queue jobs may overlap; a step never runs twice at once)
 *  - `wait` parks the run and re-queues it for the due time
 *  - ACCOUNT_STATE / BILLING / ENTITLEMENT steps park the run at `awaiting_approval`; only
 *    approve() lets them run, and then WITH the approver as actor
 *  - a failing step is retried (MAX_ATTEMPTS, growing back-off) and then fails the run with a
 *    SAFE error; raw exception text is reported to the log, never stored or shown
 */
class PlatformRunExecutor
{
    public const MAX_ATTEMPTS = 3;
    private const BACKOFF_MINUTES = [0, 2, 10];

    public function __construct(private readonly PlatformActionExecutor $actions)
    {
    }

    public function advance(int $runId): void
    {
        $lock = Cache::lock('platform-automation-run:' . $runId, 120);

        if (! $lock->get()) {
            // Another worker is on it; the job it re-queues (or its retry) will continue.
            ExecutePlatformAutomationRun::dispatch($runId)->delay(now()->addSeconds(30));

            return;
        }

        try {
            $this->walk($runId);
        } finally {
            $lock->release();
        }
    }

    public function approve(PlatformAutomationStep $step, int $actorUserId): void
    {
        if ($step->state !== PlatformStepState::AwaitingApproval) {
            return;
        }

        $step->forceFill([
            'state' => PlatformStepState::Pending->value,
            'decided_by_user_id' => $actorUserId,
            'decided_at' => now(),
        ])->save();

        PlatformAutomationRun::query()->whereKey($step->run_id)
            ->where('state', PlatformRunState::AwaitingApproval->value)
            ->update(['state' => PlatformRunState::Queued->value]);

        ExecutePlatformAutomationRun::dispatch($step->run_id);
    }

    public function reject(PlatformAutomationStep $step, int $actorUserId): void
    {
        if ($step->state !== PlatformStepState::AwaitingApproval) {
            return;
        }

        $step->forceFill([
            'state' => PlatformStepState::Rejected->value,
            'decided_by_user_id' => $actorUserId,
            'decided_at' => now(),
            'safe_error' => 'Rejected by a Platform Owner.',
        ])->save();

        PlatformAutomationRun::query()->whereKey($step->run_id)->update([
            'state' => PlatformRunState::Cancelled->value,
            'finished_at' => now(),
            'safe_error' => 'A step was rejected.',
        ]);
    }

    /** Re-queues a failed run from its failed step. Earlier, already-succeeded steps never repeat. */
    public function retry(PlatformAutomationRun $run): bool
    {
        if ($run->state !== PlatformRunState::Failed) {
            return false;
        }

        $run->steps()->where('state', PlatformStepState::Failed->value)->update([
            'state' => PlatformStepState::Pending->value,
            'attempts' => 0,
            'run_at' => null,
            'safe_error' => null,
        ]);
        $run->forceFill(['state' => PlatformRunState::Queued->value, 'finished_at' => null, 'safe_error' => null])->save();

        ExecutePlatformAutomationRun::dispatch($run->id);

        return true;
    }

    private function walk(int $runId): void
    {
        $run = PlatformAutomationRun::query()->find($runId);

        if ($run === null || $run->state->isTerminal() || $run->state === PlatformRunState::AwaitingApproval) {
            return;
        }

        $run->forceFill([
            'state' => PlatformRunState::Running->value,
            'started_at' => $run->started_at ?? now(),
        ])->save();

        $ctx = new PlatformRunContext($run);

        foreach ($run->steps()->get() as $step) {
            if (in_array($step->state, [PlatformStepState::Succeeded, PlatformStepState::Skipped], true)) {
                continue;
            }

            if ($step->state === PlatformStepState::Rejected) {
                return;
            }

            if ($step->state === PlatformStepState::AwaitingApproval) {
                $this->park($run, PlatformRunState::AwaitingApproval, null);

                return;
            }

            if ($step->run_at !== null && $step->run_at->isFuture()) {
                $this->park($run, PlatformRunState::Waiting, $step->run_at);

                return;
            }

            if ($step->action_type === 'wait') {
                if ($step->state === PlatformStepState::Pending) {
                    $due = now()->addMinutes(PlatformDefinitionValidator::waitMinutes((array) $step->config));
                    $step->forceFill(['state' => PlatformStepState::Waiting->value, 'run_at' => $due])->save();
                    $this->park($run, PlatformRunState::Waiting, $due);

                    return;
                }

                $this->finish($step, PlatformStepState::Succeeded, ['waited' => true], null);

                continue;
            }

            if ($step->safety_class->requiresApproval() && $step->decided_by_user_id === null) {
                $step->forceFill(['state' => PlatformStepState::AwaitingApproval->value])->save();
                $this->park($run, PlatformRunState::AwaitingApproval, null);

                return;
            }

            if (! $this->runStep($run, $step, $ctx)) {
                return; // retry scheduled or run failed
            }
        }

        $run->forceFill(['state' => PlatformRunState::Succeeded->value, 'finished_at' => now(), 'safe_error' => null])->save();
    }

    /** @return bool true when the step is done (succeeded or skipped) and the walk may continue */
    private function runStep(PlatformAutomationRun $run, PlatformAutomationStep $step, PlatformRunContext $ctx): bool
    {
        $step->forceFill(['attempts' => $step->attempts + 1])->save();

        try {
            $outcome = $this->actions->execute($step, $ctx, $step->decided_by_user_id);
        } catch (PlatformStepSkipped $e) {
            $this->finish($step, PlatformStepState::Skipped, ['reason' => $e->getMessage()], null);

            return true;
        } catch (Throwable $e) {
            report($e);
            $safe = 'The step could not be completed (' . Str::limit(class_basename($e), 60, '') . ').';

            if ($step->attempts < self::MAX_ATTEMPTS) {
                $due = now()->addMinutes(self::BACKOFF_MINUTES[$step->attempts] ?? 10);
                $step->forceFill(['state' => PlatformStepState::Pending->value, 'run_at' => $due, 'safe_error' => $safe])->save();
                $this->park($run, PlatformRunState::Waiting, $due);

                return false;
            }

            $step->forceFill(['state' => PlatformStepState::Failed->value, 'safe_error' => $safe])->save();
            $run->forceFill(['state' => PlatformRunState::Failed->value, 'finished_at' => now(), 'safe_error' => $safe])->save();

            return false;
        }

        $this->finish($step, PlatformStepState::Succeeded, $outcome['result'], $outcome['operation_ref']);

        return true;
    }

    /** @param array<string, mixed> $result */
    private function finish(PlatformAutomationStep $step, PlatformStepState $state, array $result, ?string $operationRef): void
    {
        $step->forceFill([
            'state' => $state->value,
            'executed_at' => now(),
            'result' => $result,
            'operation_ref' => $operationRef,
            'safe_error' => null,
        ])->save();
    }

    private function park(PlatformAutomationRun $run, PlatformRunState $state, ?\DateTimeInterface $until): void
    {
        $run->forceFill(['state' => $state->value, 'scheduled_at' => $until])->save();

        // A 'sync' queue would run the delayed job immediately and recurse; there the sweep
        // (resumeDue) is what picks the run up when it is due.
        if ($state === PlatformRunState::Waiting && $until !== null && config('queue.default') !== 'sync') {
            ExecutePlatformAutomationRun::dispatch($run->id)->delay($until);
        }
    }
}

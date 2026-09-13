<?php

namespace App\Library\Automation\Workflow\Executors;

use App\Enums\Automation\Workflow\WorkflowNodeType;
use App\Library\Automation\Workflow\Contracts\NodeExecutionOutcome;
use App\Library\Automation\Workflow\Contracts\NodeExecutor;
use App\Library\Automation\Workflow\WorkflowLimits;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflowNode;
use App\Models\Business;
use App\Models\Contacts;
use Illuminate\Support\Carbon;

/**
 * Automations V2 §12 — the Wait step.
 *
 * Waiting is a DATABASE STATE, not a timer. This executor computes one instant
 * and hands it back; the advancer writes `status = waiting` and `resume_at` in
 * the same transaction that closes the step run, and
 * `automation:workflows-resume-due` wakes it a minute or so later. Nothing here
 * sleeps, schedules a delayed job, or depends on a process staying alive — which
 * is precisely why a wait survives a deploy, a queue restart or a crashed
 * worker. A `wait 30 days` step is thirty days of a row in a table.
 *
 * TIMEZONE, AND WHY IT IS THE BUSINESS'S. A small business setting "wait until
 * 9:00" means nine o'clock where they are. So both modes are computed in
 * `businesses.timezone` (B4 §6.A's precedent), falling back to the app timezone
 * only when a Business has none, and the resulting instant is stored in UTC like
 * every other timestamp.
 *
 * DST FALLS OUT OF THAT, in the direction people expect. Carbon does calendar
 * arithmetic on a zone-aware instant, so "wait 1 day" from 18:00 the evening
 * before the clocks change lands at 18:00 the next day — 23 or 25 real hours
 * later, whichever it takes to still be six in the evening. "Wait 24 hours" from
 * the same moment lands 24 real hours later, at 17:00 or 19:00. Both are
 * correct, and they are deliberately different: a day is a calendar unit and an
 * hour is not.
 *
 * Side-effect class None: an interrupted wait step is safe for recovery to
 * re-derive, because re-deriving it computes the same kind of instant again and
 * nothing has left the building.
 */
class WaitNodeExecutor implements NodeExecutor
{
    public function handles(): WorkflowNodeType
    {
        return WorkflowNodeType::Wait;
    }

    public function execute(
        AutomationWorkflowNode $node,
        AutomationEnrollment $enrollment,
        Business $business,
        Contacts $contact,
    ): NodeExecutionOutcome {
        $config = is_array($node->config) ? $node->config : [];
        $timezone = $this->timezoneFor($business);
        $now = Carbon::now($timezone);

        $resumeAt = match ((string) ($config['mode'] ?? '')) {
            'duration' => $this->fromDuration($config, $now),
            'until_datetime' => $this->fromDatetime($config, $timezone),
            default => null,
        };

        if ($resumeAt === null) {
            // A wait that cannot say when it ends would park the journey for
            // ever. Skipping moves past it instead of stranding a customer's
            // contact in a state nothing will ever wake.
            return NodeExecutionOutcome::skipped('wait_config_invalid');
        }

        // §12 — an instant already past on arrival resumes immediately. The
        // journey continues in this same advance rather than making a round trip
        // through the sweep to be told the wait is over.
        if (! $resumeAt->greaterThan(Carbon::now())) {
            return NodeExecutionOutcome::succeeded('Wait already elapsed');
        }

        return NodeExecutionOutcome::waitUntil($resumeAt->clone()->utc());
    }

    /**
     * The Business's own timezone, or the application's when it has none.
     *
     * An unrecognised zone falls back too rather than throwing: a bad string in
     * one Business's settings must not be able to stop its automations.
     */
    private function timezoneFor(Business $business): string
    {
        $timezone = trim((string) ($business->timezone ?? ''));

        if ($timezone === '') {
            return (string) config('app.timezone', 'UTC');
        }

        return in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : (string) config('app.timezone', 'UTC');
    }

    /** Arrival plus a duration, in calendar units where the unit is calendar. */
    private function fromDuration(array $config, Carbon $now): ?Carbon
    {
        $amount = $config['amount'] ?? null;

        if (! (is_int($amount) || (is_string($amount) && ctype_digit($amount))) || (int) $amount < 1) {
            return null;
        }

        $amount = (int) $amount;

        $resumeAt = match ((string) ($config['unit'] ?? '')) {
            'minutes' => $now->clone()->addMinutes($amount),
            'hours' => $now->clone()->addHours($amount),
            'days' => $now->clone()->addDays($amount),
            default => null,
        };

        if ($resumeAt === null) {
            return null;
        }

        // The publish-time validator already refuses anything outside
        // §8.4's bounds. This is the runtime backstop for a version that was
        // compiled before a bound changed, or edited around the validator.
        $minutes = $now->diffInMinutes($resumeAt);

        if ($minutes < WorkflowLimits::MIN_WAIT_MINUTES
            || $minutes > WorkflowLimits::MAX_WAIT_DAYS * 1440) {
            return null;
        }

        return $resumeAt;
    }

    /** A wall-clock instant, read in the Business's own timezone. */
    private function fromDatetime(array $config, string $timezone): ?Carbon
    {
        $raw = trim((string) ($config['at'] ?? ''));

        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw, $timezone);
        } catch (\Throwable) {
            return null;
        }
    }
}

<?php

namespace App\Library\Automation\Workflow\Contracts;

use App\Enums\Automation\Workflow\StepRunStatus;
use App\Enums\Automation\Workflow\WorkflowEdgeKind;
use Illuminate\Support\Carbon;

/**
 * Automations V2 — what executing one step reports back.
 *
 * Part of the V2-0 contract surface rather than of any executor, because the
 * advancer (V2-A), the logic executors (V2-A) and the action executors (V2-B) are
 * built in parallel lanes and must agree on this shape before any of them exists.
 *
 * Deliberately small. An executor decides three things and nothing more: did the
 * step succeed, which lane did it take (If/Else only), and until when is the
 * journey parked (Wait only). Moving the cursor, writing the step run and
 * applying the failure policy are the advancer's job, never an executor's — so
 * an action can never advance a journey or skip a checkpoint.
 *
 * Summaries are bounded, human-safe strings (B4 §4.3): never a provider response
 * body, never a credential, never the full outbound message.
 */
final readonly class NodeExecutionOutcome
{
    private function __construct(
        public StepRunStatus $status,
        public ?WorkflowEdgeKind $branchTaken = null,
        public ?Carbon $resumeAt = null,
        public ?string $safeResultSummary = null,
        public ?string $safeErrorSummary = null,
    ) {
    }

    /** A step that did its work and hands the journey to its single successor. */
    public static function succeeded(?string $safeResultSummary = null): self
    {
        return new self(StepRunStatus::Succeeded, safeResultSummary: $safeResultSummary);
    }

    /** An If/Else result: which lane the journey continues down. */
    public static function branched(WorkflowEdgeKind $branch, ?string $safeResultSummary = null): self
    {
        return new self(StepRunStatus::Succeeded, branchTaken: $branch, safeResultSummary: $safeResultSummary);
    }

    /** A Wait result: park the journey until this instant. */
    public static function waitUntil(Carbon $resumeAt): self
    {
        return new self(StepRunStatus::Waiting, resumeAt: $resumeAt);
    }

    /** The step failed. The advancer applies the version's failure policy. */
    public static function failed(string $safeErrorSummary): self
    {
        return new self(StepRunStatus::Failed, safeErrorSummary: $safeErrorSummary);
    }

    /**
     * The step was not performed because eligibility changed — an unsubscribed
     * contact, a reference that no longer resolves. Never retried.
     */
    public static function skipped(string $safeErrorSummary): self
    {
        return new self(StepRunStatus::Skipped, safeErrorSummary: $safeErrorSummary);
    }

    /** Whether the journey ends here rather than continuing to a successor. */
    public function endsEnrollment(): bool
    {
        return in_array($this->status, [StepRunStatus::Failed, StepRunStatus::Skipped], true);
    }
}

<?php

namespace App\Library\Forms;

use App\Models\FormSubmission;

/**
 * Forms V1 — the outcome of one FormSubmissionService::submit() call.
 *
 * Either the FINAL result — the one durable submission, and whether this call
 * merely converged on one that already existed (a replay, which raised no event
 * and created nothing) — or, for a questionnaire page that is not the last,
 * PROGRESS: no submission yet, and the key of the page to show next.
 */
final class FormSubmissionResult
{
    public function __construct(
        public readonly ?FormSubmission $submission,
        public readonly bool $replayed,
        public readonly ?string $nextPage = null,
    ) {
    }

    /** A questionnaire page was accepted; nothing final exists yet. */
    public static function progress(string $nextPage): self
    {
        return new self(null, false, $nextPage);
    }

    public function isFinal(): bool
    {
        return $this->submission !== null;
    }
}

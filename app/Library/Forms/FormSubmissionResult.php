<?php

namespace App\Library\Forms;

use App\Models\FormSubmission;

/**
 * Forms V1 — the outcome of FormSubmissionService::submit(): the one durable
 * submission, and whether this call merely converged on one that already
 * existed (a replay). A replay raised no event and created nothing.
 */
final class FormSubmissionResult
{
    public function __construct(
        public readonly FormSubmission $submission,
        public readonly bool $replayed,
    ) {
    }
}

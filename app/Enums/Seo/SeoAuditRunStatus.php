<?php

namespace App\Enums\Seo;

/**
 * Contract 18 §8.7 — the two terminal states of an audit run.
 *
 * There is no `running` case by design: a run row is INSERTED once, already
 * complete, inside the job that produced it (§8.7's immutable run). Nothing
 * observes a half-finished audit, so nothing needs a state for one.
 */
enum SeoAuditRunStatus: string
{
    case Completed = 'completed';
    case Failed = 'failed';
}

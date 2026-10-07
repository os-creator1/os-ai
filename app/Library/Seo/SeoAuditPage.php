<?php

namespace App\Library\Seo;

use App\Enums\Seo\SeoAuditRunStatus;
use App\Models\SeoAuditRun;

/**
 * Contract 18 §8.7 — everything the Website SEO audit screen shows.
 *
 * The INDEXABILITY is carried here beside the findings, and is deliberately
 * not one of them: whether search engines can find the site is a property of
 * how it is set up today, reported as a status, never as something the
 * customer did wrong.
 *
 * `latestRun` is the run for the PUBLISHED revision (rule set current), not
 * simply the newest row: after a rollback the newest run can belong to a
 * revision that is no longer live, and its findings must not be shown as if
 * they described the site as it is now. `history` stays newest-first.
 */
final class SeoAuditPage
{
    /** No website is published, so there is nothing to check. */
    public const STATE_NO_WEBSITE = 'no_website';

    /** A website is published but this version has not been checked yet. */
    public const STATE_NOT_CHECKED = 'not_checked';

    /** The check of this version could not be completed: NOT "nothing to fix". */
    public const STATE_FAILED = 'failed';

    /** Checked, nothing found. */
    public const STATE_CLEAN = 'clean';

    /** Checked, at least one finding. */
    public const STATE_FINDINGS = 'findings';

    /**
     * @param  array<int, SeoAuditFindingView>  $findings
     * @param  array<int, SeoAuditRun>  $history  newest first, at most the retained window
     */
    public function __construct(
        public readonly SeoIndexability $indexability,
        public readonly ?SeoAuditRun $latestRun,
        public readonly array $findings,
        public readonly array $history,
        public readonly bool $hasPublishedWebsite = false,
    ) {
    }

    /** A run exists for the currently published version. */
    public function hasRun(): bool
    {
        return $this->latestRun !== null;
    }

    public function state(): string
    {
        if (! $this->hasPublishedWebsite) {
            return self::STATE_NO_WEBSITE;
        }

        if ($this->latestRun === null) {
            return self::STATE_NOT_CHECKED;
        }

        if ($this->latestRun->status === SeoAuditRunStatus::Failed) {
            return self::STATE_FAILED;
        }

        // Clean means the run itself counted nothing, not merely that no row
        // here is renderable (a row of a rule set this code no longer knows).
        return ($this->findings === [] && $this->latestRun->totalFindings() === 0) ? self::STATE_CLEAN : self::STATE_FINDINGS;
    }

    /** True only for a COMPLETED check of the currently published version. */
    public function completedForPublishedVersion(): bool
    {
        return $this->hasPublishedWebsite
            && $this->latestRun !== null
            && $this->latestRun->status === SeoAuditRunStatus::Completed;
    }
}

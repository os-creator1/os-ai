<?php

namespace App\Enums\NicheBlueprint;

/**
 * Contract 20 §5.4 — the outcome a Blueprint installation run recorded for one
 * component of one Business.
 *
 * ONLY `Installed` IS AUTHORITY. The three others are provenance: they record
 * why a past run declined to install, and nothing more. No skip state grants,
 * withholds or suppresses visibility on a later render — `Installed` is the
 * only state that permanently removes a component from the addable query, and
 * every other state is re-decided by `EntitlementManager::decide()` every time
 * (§8.1). A component skipped while its feature was `Planned` becomes addable
 * the moment that feature is `Available` and the plan entitles it, with no
 * record rewrite, migration or backfill.
 */
enum BlueprintComponentInstallationState: string
{
    /** The adapter ran and the Business owns the resulting rows. */
    case Installed = 'installed';

    /** The plan did not entitle the component's feature at decision time. */
    case SkippedUnentitled = 'skipped_unentitled';

    /** The component's PlatformFeature was not `Available` at decision time. */
    case SkippedUnavailable = 'skipped_unavailable';

    /** The adapter threw; its own transaction rolled back. Retried on re-run. */
    case Failed = 'failed';

    /**
     * Whether this state permanently removes the component from the addable
     * query (§8.1). True for `Installed` alone — every other state is
     * re-evaluated against the entitlement authority on every render.
     */
    public function isTerminalForSurfacing(): bool
    {
        return $this === self::Installed;
    }

    /**
     * Whether an automated installation run (§7.2) may attempt this component
     * again. A skip is never reversed by a run — only by the owner's explicit
     * action (§7.3) — so only a failure is retryable.
     */
    public function isRetryableByAutomatedRun(): bool
    {
        return $this === self::Failed;
    }
}

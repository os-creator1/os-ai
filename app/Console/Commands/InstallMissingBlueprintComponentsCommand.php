<?php

namespace App\Console\Commands;

use App\Library\NicheBlueprint\BlueprintInstallationRunResult;
use App\Library\NicheBlueprint\NicheBlueprintInstaller;
use App\Models\Business;
use Illuminate\Console\Command;

/**
 * Contract 20 §9.2 — the idempotent operator recovery path, and nothing more.
 *
 * A THIN WRAPPER: it contains no installation algorithm of its own and is
 * bounded by exactly the same §7.2 rules as the automatic triggers, because it
 * calls exactly the same entry point they do.
 *
 * IT IS NOT THE ORDINARY MECHANISM FOR ANY VALID ACCOUNT. A legitimate first
 * plan assignment is handled automatically by §9.1's `WorkspacePlanAssigned`
 * trigger and never needs this command. It exists for the four situations
 * where no entitlement decision was ever recorded: a listener that failed or
 * was never dispatched, a Business predating this slice or its Blueprint's
 * publication, components recorded `failed` (§7.4), and operator-diagnosed
 * gaps.
 *
 * IT CANNOT REVERSE A SKIP, and that is deliberate: `skipped_unentitled` and
 * `skipped_unavailable` are decisions, and under Addendum §16 the only thing
 * that turns a skip into an install is the owner's own explicit action (§8.1)
 * — never a command, a plan change, or a new Blueprint version. Running this
 * against an upgraded Workspace therefore installs nothing, by design.
 *
 * Per `CLAUDE.md`'s route-3 rules this command is never run against anything
 * but a `TestDatabaseSafety`-approved database during development.
 */
class InstallMissingBlueprintComponentsCommand extends Command
{
    protected $signature = 'blueprint:install-missing
        {--business= : Restrict the run to one Business, by numeric id or uid}';

    protected $description = 'Install any never-attempted or failed niche Blueprint components for existing Businesses (Contract 20 §9.2)';

    public function handle(NicheBlueprintInstaller $installer): int
    {
        $businesses = $this->targets();

        if ($businesses === null) {
            return self::FAILURE;
        }

        $processed = 0;
        $installed = 0;
        $skippedUnentitled = 0;
        $skippedUnavailable = 0;
        $failed = 0;
        $aborted = 0;

        foreach ($businesses as $business) {
            $result = $installer->installForBusiness($business);
            $processed++;

            if ($result->wasAborted()) {
                $aborted++;

                // Only the genuinely actionable aborts are surfaced. A
                // Business in a niche with no Blueprint is an ordinary,
                // supported state (§7.1) and is not worth an operator's
                // attention; an ambiguous broad-industry match and an
                // unassigned plan are.
                if ($result->abortReason !== BlueprintInstallationRunResult::ABORT_NO_BLUEPRINT) {
                    $this->warn("  business_id={$business->id} aborted: {$result->abortReason}");
                }

                continue;
            }

            $installed += $result->installed;
            $skippedUnentitled += $result->skippedUnentitled;
            $skippedUnavailable += $result->skippedUnavailable;
            $failed += $result->failed;

            if ($result->failed > 0) {
                $this->warn("  business_id={$business->id} components failed: {$result->failed}");
            }
        }

        $this->info('Niche Blueprint installation sweep complete.');
        $this->line("businesses processed={$processed}");
        $this->line("runs aborted before any write={$aborted}");
        $this->line("components installed={$installed}");
        $this->line("components skipped (unentitled)={$skippedUnentitled}");
        $this->line("components skipped (unavailable)={$skippedUnavailable}");
        $this->line("components failed={$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return iterable<Business>|null null when an explicit --business could not be resolved
     */
    private function targets(): ?iterable
    {
        $selector = $this->option('business');

        if ($selector === null) {
            return Business::query()->orderBy('id')->cursor();
        }

        $selector = trim((string) $selector);

        $business = ctype_digit($selector)
            ? Business::query()->whereKey((int) $selector)->first()
            : Business::query()->where('uid', $selector)->first();

        if ($business === null) {
            $this->error('No Business matches the supplied --business selector.');

            return null;
        }

        return [$business];
    }
}

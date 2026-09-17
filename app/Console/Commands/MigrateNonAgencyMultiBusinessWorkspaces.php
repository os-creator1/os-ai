<?php

namespace App\Console\Commands;

use App\Library\Workspace\Migration\NonAgencyBusinessSplitV1;
use App\Models\Workspace;
use Illuminate\Console\Command;

/**
 * Implementation Contract 12 §6/§8 — the operator-facing entry point for
 * the non-Agency multi-Business split. Contains no migration algorithm of
 * its own; it only resolves options, invokes NonAgencyBusinessSplitV1, and
 * prints its report. Mirrors MigrateAgencyClientBusinesses's own
 * established Contract 10 pattern. This is a controlled, one-shot
 * operator command (§6) — never invoked by any customer actor through the
 * product, and never run here against a real/production-looking database
 * by an automated process.
 *
 * Modes:
 *   --preflight   Read-only report (§8.1). Default when neither
 *                 --preflight nor --dry-run nor --execute is passed.
 *   --dry-run     Every decision branch, zero writes (§8.2).
 *   --execute     Real writes, resumable (§8.3).
 *
 * --dry-run and --execute are mutually exclusive — passing both is a
 * usage error, not silently resolved by picking one.
 *
 * --operator is REQUIRED for --dry-run and --execute (never a fabricated
 * "system" actor, §6). It is used ONLY where NonAgencyBusinessSplitV1
 * itself uses the operator actor (plan assignment) — it is never
 * substituted for the source Workspace's genuine owner, who remains the
 * actor for Workspace creation/reassignment wherever WorkspaceManager's
 * own authority requires it. This command does not, and must not,
 * change that actor split.
 */
class MigrateNonAgencyMultiBusinessWorkspaces extends Command
{
    protected $signature = 'workspaces:migrate-nonagency-multibusiness
        {--preflight : Read-only report of every candidate non-Agency Workspace and Business (default mode)}
        {--dry-run : Report every decision branch without writing anything}
        {--execute : Perform the real, resumable migration}
        {--operator= : The real, authorized migration operator\'s User id (required for --dry-run/--execute)}
        {--workspace=* : Restrict to these Workspace uids (repeatable); omit for every candidate}';

    protected $description = 'Implementation Contract 12 — split remaining non-Agency multi-Business Workspaces into independent plain Workspaces';

    /**
     * Workspace statuses that mean this operator run did not fully and
     * cleanly succeed for that Workspace, and must therefore fail the
     * command's exit code — an operator migration must never let tooling
     * read a blocked/failed/unverified Workspace as a green run.
     *
     * @var array<int, string>
     */
    private const FAILING_STATUSES = ['blocked', 'failed', 'verification_failed'];

    public function handle(NonAgencyBusinessSplitV1 $migration): int
    {
        if ($this->option('dry-run') && $this->option('execute')) {
            $this->error('--dry-run and --execute are mutually exclusive — pass exactly one write mode, not both.');

            return self::FAILURE;
        }

        $workspaceIds = $this->resolveWorkspaceIds();

        if ($workspaceIds === false) {
            return self::FAILURE;
        }

        if ($this->option('execute')) {
            return $this->runWriteMode($migration, dryRun: false, workspaceIds: $workspaceIds);
        }

        if ($this->option('dry-run')) {
            return $this->runWriteMode($migration, dryRun: true, workspaceIds: $workspaceIds);
        }

        return $this->runPreflight($migration, $workspaceIds);
    }

    /**
     * @return array<int, int>|null|false false on a bad --workspace uid
     */
    private function resolveWorkspaceIds(): array|null|false
    {
        $uids = (array) $this->option('workspace');

        if ($uids === []) {
            return null;
        }

        $ids = [];

        foreach ($uids as $uid) {
            $workspace = Workspace::query()->where('uid', $uid)->first();

            if ($workspace === null) {
                $this->error("No Workspace found for --workspace={$uid}.");

                return false;
            }

            $ids[] = (int) $workspace->id;
        }

        return $ids;
    }

    private function runPreflight(NonAgencyBusinessSplitV1 $migration, ?array $workspaceIds): int
    {
        $report = $migration->preflight($workspaceIds);

        return $this->printReport($report, 'PREFLIGHT');
    }

    private function runWriteMode(NonAgencyBusinessSplitV1 $migration, bool $dryRun, ?array $workspaceIds): int
    {
        $operatorOption = $this->option('operator');

        if ($operatorOption === null || trim((string) $operatorOption) === '') {
            $this->error('--operator=<user id> is required for --dry-run and --execute — the real, authorized human running this migration must be named explicitly.');

            return self::FAILURE;
        }

        $report = $migration->run((int) $operatorOption, $dryRun, $workspaceIds);

        return $this->printReport($report, $dryRun ? 'DRY RUN' : 'EXECUTE');
    }

    /**
     * @param  array{dry_run?: bool, workspaces: array<int, array<string, mixed>>}  $report
     */
    private function printReport(array $report, string $mode): int
    {
        $this->info("Contract 12 non-Agency multi-Business split — {$mode}");

        if ($report['workspaces'] === []) {
            $this->line('No candidate non-Agency multi-Business Workspaces found.');

            return self::SUCCESS;
        }

        $failingWorkspaces = 0;

        foreach ($report['workspaces'] as $workspaceReport) {
            $this->line("Workspace #{$workspaceReport['workspace_id']} ({$workspaceReport['workspace_uid']}): {$workspaceReport['status']}");

            if (in_array($workspaceReport['status'], self::FAILING_STATUSES, true)) {
                $failingWorkspaces++;
            }

            if ($workspaceReport['status'] === 'blocked') {
                if ($workspaceReport['reason'] === 'ambiguous_primary_business') {
                    $this->warn("  blocked: {$workspaceReport['reason']} (primary_business_count={$workspaceReport['primary_business_count']})");
                } else {
                    $this->warn("  blocked: {$workspaceReport['reason']} — this Workspace's entire batch performs ZERO writes while any candidate is unresolved:");

                    foreach ($workspaceReport['blocked_businesses'] ?? [] as $blocked) {
                        $this->warn("    Business #{$blocked['business_id']} ({$blocked['business_uid']}): {$blocked['reason']}");
                    }
                }

                continue;
            }

            if ($workspaceReport['status'] === 'failed' || $workspaceReport['status'] === 'verification_failed') {
                $this->error("  {$workspaceReport['status']}: {$workspaceReport['reason']}");

                continue;
            }

            if ($workspaceReport['status'] === 'migrated') {
                $this->comment('  verified=' . ($workspaceReport['verified'] ? 'true' : 'false'));
            }

            foreach ($workspaceReport['businesses'] as $businessReport) {
                // Two distinct report shapes reach this loop: preflight/
                // dry-run's read-only classification (payer_type_current/
                // primary_location_present) and execute's real outcome
                // (status/new_workspace_id) — printed accordingly.
                $status = $businessReport['status'] ?? $businessReport['payer_type_current'] ?? 'unknown';
                $this->line("  Business #{$businessReport['business_id']} ({$businessReport['business_name']}): {$status}");

                if (($businessReport['blocking'] ?? false)) {
                    $this->warn('    blocked: ' . ($businessReport['blocking_reason'] ?? 'unresolved'));
                }

                if (($businessReport['new_workspace_id'] ?? null) !== null) {
                    $this->comment(sprintf(
                        '    moved to new Workspace #%d (%s)',
                        $businessReport['new_workspace_id'],
                        $businessReport['new_workspace_uid'] ?? 'n/a',
                    ));
                }
            }
        }

        if ($failingWorkspaces > 0) {
            $this->warn("Completed with {$failingWorkspaces} blocked/failed/unverified Workspace(s) — see above. This run did not fully succeed.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

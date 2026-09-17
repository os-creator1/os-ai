<?php

namespace App\Console\Commands;

use App\Library\Workspace\Migration\AgencyBusinessMigrationV1;
use App\Models\Workspace;
use Illuminate\Console\Command;

/**
 * Implementation Contract 10 §8/§12 — the operator-facing entry point for
 * the Agency data migration. Contains no migration algorithm of its own;
 * it only resolves options, invokes AgencyBusinessMigrationV1, and prints
 * its report. This is a controlled, one-shot operator command (§6) — never
 * invoked by any Agency actor through the product, and never run here
 * against a real/production-looking database by an automated process.
 *
 * Modes:
 *   --preflight   Read-only report (§8.1). Default when neither
 *                 --preflight nor --dry-run nor --execute is passed.
 *   --dry-run     Every decision branch, zero writes (§8.2).
 *   --execute     Real writes, resumable (§8.3).
 *
 * --operator is REQUIRED for --dry-run and --execute (never a fabricated
 * "system" actor, §6) and must resolve to a User who genuinely holds
 * AgencyClientRelationshipManager's platform-relationship-operator
 * authority — enforced by createForMigration() itself, not re-implemented
 * here.
 */
class MigrateAgencyClientBusinesses extends Command
{
    protected $signature = 'agency:migrate-client-businesses
        {--preflight : Read-only report of every candidate Agency Workspace and Business (default mode)}
        {--dry-run : Report every decision branch without writing anything}
        {--execute : Perform the real, resumable migration}
        {--operator= : The real, authorized migration operator\'s User id (required for --dry-run/--execute)}
        {--agency=* : Restrict to these Agency Workspace uids (repeatable); omit for every candidate}';

    protected $description = 'Implementation Contract 10 — migrate legacy multi-Business Agency Workspaces into independent Client Workspaces';

    public function handle(AgencyBusinessMigrationV1 $migration): int
    {
        $agencyWorkspaceIds = $this->resolveAgencyWorkspaceIds();

        if ($agencyWorkspaceIds === false) {
            return self::FAILURE;
        }

        if ($this->option('execute')) {
            return $this->runWriteMode($migration, dryRun: false, agencyWorkspaceIds: $agencyWorkspaceIds);
        }

        if ($this->option('dry-run')) {
            return $this->runWriteMode($migration, dryRun: true, agencyWorkspaceIds: $agencyWorkspaceIds);
        }

        return $this->runPreflight($migration, $agencyWorkspaceIds);
    }

    /**
     * @return array<int, int>|null|false false on a bad --agency uid
     */
    private function resolveAgencyWorkspaceIds(): array|null|false
    {
        $uids = (array) $this->option('agency');

        if ($uids === []) {
            return null;
        }

        $ids = [];

        foreach ($uids as $uid) {
            $workspace = Workspace::query()->where('uid', $uid)->first();

            if ($workspace === null) {
                $this->error("No Workspace found for --agency={$uid}.");

                return false;
            }

            $ids[] = (int) $workspace->id;
        }

        return $ids;
    }

    private function runPreflight(AgencyBusinessMigrationV1 $migration, ?array $agencyWorkspaceIds): int
    {
        $report = $migration->preflight($agencyWorkspaceIds);

        return $this->printReport($report, 'PREFLIGHT');
    }

    private function runWriteMode(AgencyBusinessMigrationV1 $migration, bool $dryRun, ?array $agencyWorkspaceIds): int
    {
        $operatorOption = $this->option('operator');

        if ($operatorOption === null || trim((string) $operatorOption) === '') {
            $this->error('--operator=<user id> is required for --dry-run and --execute — the real, authorized human running this migration must be named explicitly.');

            return self::FAILURE;
        }

        $report = $migration->run((int) $operatorOption, $dryRun, $agencyWorkspaceIds);

        return $this->printReport($report, $dryRun ? 'DRY RUN' : 'EXECUTE');
    }

    /**
     * Agency Workspace statuses that mean this operator run did not fully
     * and cleanly succeed for that Agency, and must therefore fail the
     * command's exit code — an operator migration must never let tooling
     * read a blocked/failed/unverified Agency as a green run.
     *
     * @var array<int, string>
     */
    private const FAILING_AGENCY_STATUSES = ['blocked', 'failed', 'verification_failed'];

    /**
     * @param  array{schema_ready: bool, dry_run?: bool, agencies: array<int, array<string, mixed>>}  $report
     */
    private function printReport(array $report, string $mode): int
    {
        $this->info("Contract 10 Agency data migration — {$mode}");

        if (! $report['schema_ready']) {
            $this->error('business_payer_assignments is missing one or more of managing_agency_relationship_id / agency_rebill_consented_at / agency_rebill_consented_by_user_id. Contract 09\'s migration must be applied before this command can run.');

            return self::FAILURE;
        }

        if ($report['agencies'] === []) {
            $this->line('No candidate Agency Workspaces found.');

            return self::SUCCESS;
        }

        $failingAgencies = 0;

        foreach ($report['agencies'] as $agencyReport) {
            $this->line("Agency Workspace #{$agencyReport['agency_workspace_id']} ({$agencyReport['agency_workspace_uid']}): {$agencyReport['status']}");

            if (($agencyReport['business_count'] ?? null) !== null) {
                $this->line("  business_count={$agencyReport['business_count']} candidate_business_count={$agencyReport['candidate_business_count']} primary_location_missing_count=" . ($agencyReport['primary_location_missing_count'] ?? 'n/a'));
            }

            if (in_array($agencyReport['status'], self::FAILING_AGENCY_STATUSES, true)) {
                $failingAgencies++;
            }

            if ($agencyReport['status'] === 'blocked') {
                if ($agencyReport['reason'] === 'ambiguous_primary_business') {
                    $this->warn("  blocked: {$agencyReport['reason']} (primary_business_count={$agencyReport['primary_business_count']})");
                } else {
                    $this->warn("  blocked: {$agencyReport['reason']} — this Agency's entire batch performs ZERO writes while any candidate is unresolved:");

                    foreach ($agencyReport['blocked_businesses'] ?? [] as $blocked) {
                        $this->warn("    Business #{$blocked['business_id']} ({$blocked['business_uid']}): {$blocked['reason']}");
                    }
                }

                continue;
            }

            if ($agencyReport['status'] === 'failed' || $agencyReport['status'] === 'verification_failed') {
                $this->error("  {$agencyReport['status']}: {$agencyReport['reason']}");

                continue;
            }

            foreach ($agencyReport['businesses'] as $businessReport) {
                $status = $businessReport['status'] ?? $businessReport['payer_action'] ?? 'unknown';
                $this->line("  Business #{$businessReport['business_id']} ({$businessReport['business_name']}): {$status}");

                if (($businessReport['blocking'] ?? false)) {
                    $this->warn('    blocked: ' . ($businessReport['blocking_reason'] ?? 'unresolved'));
                }

                if (($businessReport['payer_action'] ?? null) === 'convert_to_pending_agency_rebill') {
                    $this->comment(sprintf(
                        '    pending Agency owner confirmation for AgencyRebill funding (Contract 09) — Business #%d (%s), Client Workspace %s, relationship #%s',
                        $businessReport['business_id'],
                        $businessReport['business_uid'],
                        $businessReport['client_workspace_uid'] ?? 'n/a',
                        $businessReport['relationship_id'] ?? 'n/a',
                    ));
                }
            }
        }

        if ($failingAgencies > 0) {
            $this->warn("Completed with {$failingAgencies} blocked/failed/unverified Agency Workspace(s) — see above. This run did not fully succeed.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

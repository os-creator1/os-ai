<?php

namespace App\Console\Commands;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\Business;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Implementation Contract 12 §4 Step 1 / §8.1 — the mandatory, read-only
 * data report that MUST run and be reported before any split migration is
 * even considered, never assumed to find zero rows.
 *
 * Reports every Core/Growth (non-Agency) Workspace currently holding more
 * than one Business. Performs ZERO writes and calls no manager/provider
 * of any kind — a plain read.
 *
 * Environment-neutral by design: this command has no way to know whether
 * it is being run against a disposable development/test database or a
 * real target environment, so it never claims either — it reports
 * exactly which environment/connection/database it is actually connected
 * to (name only, never a credential-bearing DSN) and leaves the operator
 * to verify that is the intended target before relying on the result.
 */
class ReportNonAgencyMultiBusinessWorkspaces extends Command
{
    protected $signature = 'workspaces:report-nonagency-multibusiness';

    protected $description = 'Implementation Contract 12 — read-only report of Core/Growth (non-Agency) Workspaces holding more than one Business';

    public function handle(): int
    {
        $report = $this->buildReport();

        $this->printReport($report);

        return self::SUCCESS;
    }

    /**
     * @return array{query: string, workspace_count: int, workspaces: array<int, array<string, mixed>>}
     */
    public function buildReport(): array
    {
        $workspaces = $this->candidateWorkspaces();

        return [
            'query' => "SELECT workspace_id, COUNT(*) FROM businesses GROUP BY workspace_id HAVING COUNT(*) > 1, joined against workspace_plan_catalog.tier IN ('core','growth')",
            'workspace_count' => $workspaces->count(),
            'workspaces' => $workspaces->map(fn (Workspace $workspace) => $this->reportForWorkspace($workspace))->values()->all(),
        ];
    }

    /**
     * Every Core/Growth-tier Workspace currently holding more than one
     * Business — never a heuristic, never inferred from entitlement
     * expectations (§3's own point: the entitlement-layer
     * business_slot_max=1 seed constrains NEW Business creation, it says
     * nothing about Workspaces that already hold >1 today).
     *
     * @return \Illuminate\Support\Collection<int, Workspace>
     */
    private function candidateWorkspaces(): \Illuminate\Support\Collection
    {
        $nonAgencyTierWorkspaceIds = DB::table('workspaces')
            ->join('workspace_plan_assignments', 'workspace_plan_assignments.workspace_id', '=', 'workspaces.id')
            ->join('workspace_plan_catalog', 'workspace_plan_catalog.id', '=', 'workspace_plan_assignments.workspace_plan_catalog_id')
            ->whereIn('workspace_plan_catalog.tier', [WorkspacePlanTier::Core->value, WorkspacePlanTier::Growth->value])
            ->pluck('workspaces.id');

        $multiBusinessWorkspaceIds = DB::table('businesses')
            ->select('workspace_id')
            ->whereIn('workspace_id', $nonAgencyTierWorkspaceIds)
            ->whereNotNull('workspace_id')
            ->groupBy('workspace_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('workspace_id');

        return Workspace::query()
            ->whereIn('id', $multiBusinessWorkspaceIds->values())
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function reportForWorkspace(Workspace $workspace): array
    {
        $assignment = DB::table('workspace_plan_assignments')
            ->join('workspace_plan_catalog', 'workspace_plan_catalog.id', '=', 'workspace_plan_assignments.workspace_plan_catalog_id')
            ->where('workspace_plan_assignments.workspace_id', $workspace->id)
            ->select('workspace_plan_catalog.tier')
            ->first();

        $businesses = Business::query()
            ->where('workspace_id', $workspace->id)
            ->orderBy('id')
            ->get();

        $primaries = $businesses->where('is_primary', true)->values();

        return [
            'workspace_id' => $workspace->id,
            'workspace_uid' => $workspace->uid,
            'workspace_name' => $workspace->name,
            'plan_tier' => $assignment?->tier,
            'business_count' => $businesses->count(),
            'primary_business_count' => $primaries->count(),
            'ambiguous_primary' => $primaries->count() !== 1,
            'retained_business_id' => $primaries->count() === 1 ? $primaries->first()->id : null,
            'businesses' => $businesses->map(fn (Business $business) => [
                'business_id' => $business->id,
                'business_uid' => $business->uid,
                'business_name' => $business->name,
                'is_primary' => (bool) $business->is_primary,
            ])->values()->all(),
        ];
    }

    private function printReport(array $report): void
    {
        $this->info('Contract 12 — non-Agency multi-Business Workspace report');
        $this->warn(sprintf(
            'This report reflects the database/environment this command is currently connected to (app.env=%s, connection=%s, database=%s). Verify that you are running it against the intended target environment before relying on the result.',
            config('app.env'),
            DB::connection()->getName(),
            DB::connection()->getDatabaseName(),
        ));
        $this->line("Query: {$report['query']}");
        $this->line("Non-Agency multi-Business Workspace count: {$report['workspace_count']}");

        if ($report['workspace_count'] === 0) {
            $this->info('Zero non-Agency Workspaces with more than one Business — nothing to migrate.');

            return;
        }

        foreach ($report['workspaces'] as $workspaceReport) {
            $this->line(sprintf(
                'Workspace #%d (%s, %s) tier=%s business_count=%d',
                $workspaceReport['workspace_id'],
                $workspaceReport['workspace_uid'],
                $workspaceReport['workspace_name'],
                $workspaceReport['plan_tier'] ?? 'unknown',
                $workspaceReport['business_count'],
            ));

            if ($workspaceReport['ambiguous_primary']) {
                $this->warn("  ambiguous primary Business (count={$workspaceReport['primary_business_count']}) — must be resolved before any split.");
            } else {
                $this->comment("  retained Business #{$workspaceReport['retained_business_id']}");
            }

            foreach ($workspaceReport['businesses'] as $business) {
                $marker = $business['is_primary'] ? '[primary/retained]' : '[split candidate]';
                $this->line("    Business #{$business['business_id']} ({$business['business_uid']}, {$business['business_name']}) {$marker}");
            }
        }
    }
}

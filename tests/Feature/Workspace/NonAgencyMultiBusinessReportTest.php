<?php

namespace Tests\Feature\Workspace;

use App\Console\Commands\ReportNonAgencyMultiBusinessWorkspaces;
use App\Enums\Entitlement\WorkspacePlanTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 12 §4 Step 1 / §13 — the mandatory, read-only
 * data report. Proven against genuine seeded fixtures, not merely against
 * an empty test database (which would prove nothing per §14 acceptance
 * criterion 1) — and explicitly NOT claimed to represent any real
 * production population (this repository is pre-production).
 */
class NonAgencyMultiBusinessReportTest extends TestCase
{
    use CreatesCustomerContextFixtures;
    use RefreshDatabase;

    private function report(): ReportNonAgencyMultiBusinessWorkspaces
    {
        return app(ReportNonAgencyMultiBusinessWorkspaces::class);
    }

    public function test_zero_rows_reports_cleanly(): void
    {
        $report = $this->report()->buildReport();

        $this->assertSame(0, $report['workspace_count']);
        $this->assertSame([], $report['workspaces']);
    }

    public function test_a_genuine_seeded_core_multibusiness_workspace_is_reported(): void
    {
        [$customer, $primary, $workspace] = $this->tenant(WorkspacePlanTier::Core, 'Core Primary', 'Core Account');
        $second = $this->addBusiness($customer, $workspace, 'Core Secondary');

        $report = $this->report()->buildReport();

        $this->assertSame(1, $report['workspace_count']);
        $reported = $report['workspaces'][0];
        $this->assertSame($workspace->id, $reported['workspace_id']);
        $this->assertSame($workspace->uid, $reported['workspace_uid']);
        $this->assertSame('core', $reported['plan_tier']);
        $this->assertSame(2, $reported['business_count']);
        $this->assertFalse($reported['ambiguous_primary']);
        $this->assertSame($primary->id, $reported['retained_business_id']);

        $businessIds = collect($reported['businesses'])->pluck('business_id')->all();
        $this->assertEqualsCanonicalizing([$primary->id, $second->id], $businessIds);
    }

    public function test_a_genuine_seeded_growth_multibusiness_workspace_is_reported(): void
    {
        [$customer, $primary, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Growth Primary', 'Growth Account');
        $this->addBusiness($customer, $workspace, 'Growth Secondary');

        $report = $this->report()->buildReport();

        $this->assertSame(1, $report['workspace_count']);
        $this->assertSame('growth', $report['workspaces'][0]['plan_tier']);
        $this->assertSame($primary->id, $report['workspaces'][0]['retained_business_id']);
    }

    public function test_an_agency_workspace_with_multiple_businesses_is_excluded(): void
    {
        [$customer, , $agencyWorkspace] = $this->tenant(WorkspacePlanTier::Agency, 'Agency Primary', 'Agency Account');
        $this->addBusiness($customer, $agencyWorkspace, 'Agency Client');

        $report = $this->report()->buildReport();

        $this->assertSame(0, $report['workspace_count'], 'Agency-tier Workspaces are Contract 10\'s own scope, never this one.');
    }

    public function test_single_business_core_and_growth_workspaces_are_excluded(): void
    {
        $this->tenant(WorkspacePlanTier::Core, 'Solo Core Biz', 'Solo Core Account');
        $this->tenant(WorkspacePlanTier::Growth, 'Solo Growth Biz', 'Solo Growth Account');

        $report = $this->report()->buildReport();

        $this->assertSame(0, $report['workspace_count']);
    }

    public function test_the_report_performs_zero_writes(): void
    {
        [$customer, , $workspace] = $this->tenant(WorkspacePlanTier::Core, 'Core Primary', 'Core Account');
        $this->addBusiness($customer, $workspace, 'Core Secondary');

        $businessesBefore = DB::table('businesses')->orderBy('id')->get();
        $workspacesBefore = DB::table('workspaces')->orderBy('id')->get();
        $planAssignmentsBefore = DB::table('workspace_plan_assignments')->orderBy('id')->get();

        $this->report()->buildReport();
        $this->artisan('workspaces:report-nonagency-multibusiness')->assertExitCode(0);

        $this->assertEquals($businessesBefore, DB::table('businesses')->orderBy('id')->get());
        $this->assertEquals($workspacesBefore, DB::table('workspaces')->orderBy('id')->get());
        $this->assertEquals($planAssignmentsBefore, DB::table('workspace_plan_assignments')->orderBy('id')->get());
    }
}

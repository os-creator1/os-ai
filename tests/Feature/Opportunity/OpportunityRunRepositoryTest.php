<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Opportunity\OpportunityRunStatus;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Repositories\Contracts\OpportunityRunRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\TestCase;

class OpportunityRunRepositoryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    public function test_uid_is_automatically_generated_and_unique(): void
    {
        $business = $this->createBusinessForOpportunities();

        $first = $this->createOpportunityRun($business);
        $second = $this->createOpportunityRun($business);

        $this->assertNotNull($first->uid);
        $this->assertNotNull($second->uid);
        $this->assertNotSame($first->uid, $second->uid);
    }

    public function test_find_for_update_returns_the_correct_run(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $other = $this->createOpportunityRun($business);
        $repository = app(OpportunityRunRepository::class);

        $found = $repository->findForUpdate($run->id);

        $this->assertNotNull($found);
        $this->assertSame($run->id, $found->id);
        $this->assertNotSame($other->id, $found->id);
    }

    public function test_find_for_update_returns_null_for_unknown_id(): void
    {
        $repository = app(OpportunityRunRepository::class);

        $this->assertNull($repository->findForUpdate(999999));
    }

    public function test_find_running_for_update_is_scoped_to_business_and_worker(): void
    {
        $business = $this->createBusinessForOpportunities();
        $otherBusiness = $this->createBusinessForOpportunities();
        $repository = app(OpportunityRunRepository::class);

        $running = $this->createOpportunityRun($business, ['status' => OpportunityRunStatus::Running->value]);
        $this->createOpportunityRun($business, ['status' => OpportunityRunStatus::Succeeded->value]);
        $this->createOpportunityRun($otherBusiness, ['status' => OpportunityRunStatus::Running->value]);

        $found = $repository->findRunningForUpdate($business->id, OpportunityWorkerKey::BusinessAdvisor);

        $this->assertNotNull($found);
        $this->assertSame($running->id, $found->id);
    }

    public function test_find_running_for_update_returns_null_when_no_run_is_running(): void
    {
        $business = $this->createBusinessForOpportunities();
        $this->createOpportunityRun($business, ['status' => OpportunityRunStatus::Succeeded->value]);
        $repository = app(OpportunityRunRepository::class);

        $this->assertNull($repository->findRunningForUpdate($business->id, OpportunityWorkerKey::BusinessAdvisor));
    }

    public function test_update_ignores_immutable_identity_fields(): void
    {
        $business = $this->createBusinessForOpportunities();
        $otherBusiness = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $repository = app(OpportunityRunRepository::class);
        $originalStartedAt = $run->started_at;

        $repository->update($run, [
            'business_id' => $otherBusiness->id,
            'worker_key' => OpportunityWorkerKey::Seo->value,
            'producer_version' => 999,
            'started_at' => now()->addDay(),
            'status' => OpportunityRunStatus::Succeeded->value,
            'reason_code' => 'completed_normally',
        ]);

        $run->refresh();

        $this->assertSame($business->id, $run->business_id);
        $this->assertSame(OpportunityWorkerKey::BusinessAdvisor, $run->worker_key);
        $this->assertSame(1, $run->producer_version);
        $this->assertTrue($originalStartedAt->equalTo($run->started_at));
        $this->assertSame(OpportunityRunStatus::Succeeded, $run->status);
        $this->assertSame('completed_normally', $run->reason_code);
    }

    public function test_run_is_scoped_to_its_owning_business(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);

        $this->assertSame($business->id, $run->business()->first()->id);
        $this->assertTrue($business->opportunityRuns->contains($run));
    }
}

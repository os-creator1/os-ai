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

    public function test_paginate_for_business_returns_only_the_selected_businesses_runs(): void
    {
        $business = $this->createBusinessForOpportunities();
        $otherBusiness = $this->createBusinessForOpportunities();
        $matching = $this->createOpportunityRun($business);
        $this->createOpportunityRun($otherBusiness);
        $repository = app(OpportunityRunRepository::class);

        $page = $repository->paginateForBusiness($business->id, 25);

        $this->assertSame(1, $page->total());
        $this->assertSame($matching->id, $page->items()[0]->id);
    }

    public function test_paginate_for_business_includes_failed_and_abandoned_runs(): void
    {
        $business = $this->createBusinessForOpportunities();
        $this->createOpportunityRun($business, ['status' => OpportunityRunStatus::Failed->value]);
        $this->createOpportunityRun($business, [
            'status' => OpportunityRunStatus::Failed->value,
            'abandoned_at' => now(),
            'reason_code' => 'heartbeat_timeout',
        ]);
        $repository = app(OpportunityRunRepository::class);

        $page = $repository->paginateForBusiness($business->id, 25);

        $this->assertSame(2, $page->total());
    }

    public function test_paginate_for_business_orders_by_started_at_then_id_descending(): void
    {
        $business = $this->createBusinessForOpportunities();
        $repository = app(OpportunityRunRepository::class);

        $older = $this->createOpportunityRun($business, ['started_at' => now()->subHour()]);
        $newer = $this->createOpportunityRun($business, ['started_at' => now()]);

        $page = $repository->paginateForBusiness($business->id, 25);

        $this->assertSame($newer->id, $page->items()[0]->id);
        $this->assertSame($older->id, $page->items()[1]->id);
    }

    public function test_paginate_for_business_is_bounded_by_per_page(): void
    {
        $business = $this->createBusinessForOpportunities();

        for ($i = 0; $i < 3; $i++) {
            $this->createOpportunityRun($business, ['started_at' => now()->subMinutes($i)]);
        }

        $repository = app(OpportunityRunRepository::class);

        $page = $repository->paginateForBusiness($business->id, 2);

        $this->assertSame(3, $page->total());
        $this->assertCount(2, $page->items());
    }

    public function test_paginate_for_business_performs_no_mutation(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $repository = app(OpportunityRunRepository::class);
        $updatedAtBefore = $run->updated_at;

        $repository->paginateForBusiness($business->id, 25);

        $this->assertTrue($updatedAtBefore->equalTo($run->fresh()->updated_at));
    }

    public function test_find_for_admin_returns_a_cross_tenant_run(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $repository = app(OpportunityRunRepository::class);

        $found = $repository->findForAdmin($run->id);

        $this->assertNotNull($found);
        $this->assertSame($run->id, $found->id);
    }

    public function test_find_for_admin_returns_null_for_a_missing_run(): void
    {
        $repository = app(OpportunityRunRepository::class);

        $this->assertNull($repository->findForAdmin(999999));
    }

    public function test_find_for_admin_eager_loads_business_customer_and_user(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $repository = app(OpportunityRunRepository::class);

        $found = $repository->findForAdmin($run->id);

        $this->assertNotNull($found);
        $this->assertTrue($found->relationLoaded('business'));
        $this->assertTrue($found->business->relationLoaded('customer'));
        $this->assertTrue($found->business->customer->relationLoaded('user'));
    }

    public function test_find_for_admin_does_not_exclude_a_failed_or_abandoned_run(): void
    {
        $business = $this->createBusinessForOpportunities();
        $failed = $this->createOpportunityRun($business, ['status' => OpportunityRunStatus::Failed->value]);
        $abandoned = $this->createOpportunityRun($business, [
            'status' => OpportunityRunStatus::Failed->value,
            'abandoned_at' => now(),
            'reason_code' => 'heartbeat_timeout',
        ]);
        $repository = app(OpportunityRunRepository::class);

        $this->assertNotNull($repository->findForAdmin($failed->id));
        $this->assertNotNull($repository->findForAdmin($abandoned->id));
    }

    public function test_find_for_admin_performs_no_mutation(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $repository = app(OpportunityRunRepository::class);
        $updatedAtBefore = $run->updated_at;

        $repository->findForAdmin($run->id);

        $this->assertTrue($updatedAtBefore->equalTo($run->fresh()->updated_at));
    }
}

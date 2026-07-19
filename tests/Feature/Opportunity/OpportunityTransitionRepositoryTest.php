<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Opportunity\OpportunityTransitionCategory;
use App\Repositories\Contracts\OpportunityTransitionRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\TestCase;

class OpportunityTransitionRepositoryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    public function test_create_populates_created_at_automatically(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'transition-created-at')]);
        $repository = app(OpportunityTransitionRepository::class);

        $transition = $repository->create($this->opportunityTransitionAttributes($opportunity));

        $this->assertNotNull($transition->created_at);
    }

    public function test_repository_is_append_only_and_exposes_no_update_method(): void
    {
        $repository = app(OpportunityTransitionRepository::class);

        $this->assertFalse(method_exists($repository, 'update'));
    }

    public function test_for_opportunity_is_scoped_and_ordered(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'transition-scope-1')]);
        $otherOpportunity = $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'transition-scope-2')]);
        $repository = app(OpportunityTransitionRepository::class);

        $first = $repository->create($this->opportunityTransitionAttributes($opportunity, [
            'reason_code' => 'opportunity_created',
        ]));
        $second = $repository->create($this->opportunityTransitionAttributes($opportunity, [
            'category' => OpportunityTransitionCategory::Workflow->value,
            'from_status' => 'open',
            'to_status' => 'in_progress',
            'reason_code' => 'action_started',
        ]));
        $repository->create($this->opportunityTransitionAttributes($otherOpportunity, [
            'reason_code' => 'opportunity_created',
        ]));

        $result = $repository->forOpportunity($opportunity->id);

        $this->assertSame([$first->id, $second->id], $result->pluck('id')->all());
    }

    public function test_deleting_the_referenced_run_sets_opportunity_run_id_to_null(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'transition-run-fk')]);
        $run = $this->createOpportunityRun($business);
        $repository = app(OpportunityTransitionRepository::class);

        $transition = $repository->create($this->opportunityTransitionAttributes($opportunity, [
            'category' => OpportunityTransitionCategory::Freshness->value,
            'reason_code' => 'confirmed_by_run',
            'opportunity_run_id' => $run->id,
        ]));

        $run->delete();
        $transition->refresh();

        $this->assertNull($transition->opportunity_run_id);
    }

    public function test_deleting_the_referenced_action_execution_sets_action_execution_id_to_null(): void
    {
        $business = $this->createBusinessForOpportunities();
        $user = $this->createUser();
        $opportunity = $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'transition-exec-fk')]);
        $execution = $this->createOpportunityActionExecution($opportunity, $user, ['idempotency_key' => hash('sha256', 'transition-exec-fk-key')]);
        $repository = app(OpportunityTransitionRepository::class);

        $transition = $repository->create($this->opportunityTransitionAttributes($opportunity, [
            'reason_code' => 'action_completed',
            'action_execution_id' => $execution->id,
        ]));

        $execution->delete();
        $transition->refresh();

        $this->assertNull($transition->action_execution_id);
    }

    public function test_transition_is_scoped_to_its_owning_opportunity(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'transition-relationship')]);
        $repository = app(OpportunityTransitionRepository::class);

        $transition = $repository->create($this->opportunityTransitionAttributes($opportunity));

        $this->assertTrue($opportunity->transitions->contains($transition));
    }
}

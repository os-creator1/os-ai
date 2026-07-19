<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Opportunity\OpportunityActionExecutionStatus;
use App\Enums\Opportunity\OpportunityCompletionPolicy;
use App\Repositories\Contracts\OpportunityActionExecutionRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\TestCase;

class OpportunityActionExecutionRepositoryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    public function test_uid_is_automatically_generated_and_unique(): void
    {
        $business = $this->createBusinessForOpportunities();
        $user = $this->createUser();
        $opportunity = $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'exec-uid')]);

        $first = $this->createOpportunityActionExecution($opportunity, $user, ['idempotency_key' => hash('sha256', 'a')]);
        $second = $this->createOpportunityActionExecution($opportunity, $user, [
            'idempotency_key' => hash('sha256', 'b'),
            'attempt_number' => 2,
        ]);

        $this->assertNotNull($first->uid);
        $this->assertNotNull($second->uid);
        $this->assertNotSame($first->uid, $second->uid);
    }

    public function test_idempotency_key_must_be_unique(): void
    {
        $business = $this->createBusinessForOpportunities();
        $user = $this->createUser();
        $opportunity = $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'exec-idem')]);
        $key = hash('sha256', 'duplicate-idempotency-key');
        $this->createOpportunityActionExecution($opportunity, $user, ['idempotency_key' => $key]);

        $this->expectException(QueryException::class);

        $this->createOpportunityActionExecution($opportunity, $user, ['idempotency_key' => $key, 'attempt_number' => 2]);
    }

    public function test_find_by_idempotency_key(): void
    {
        $business = $this->createBusinessForOpportunities();
        $user = $this->createUser();
        $opportunity = $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'exec-find-idem')]);
        $key = hash('sha256', 'findable-key');
        $execution = $this->createOpportunityActionExecution($opportunity, $user, ['idempotency_key' => $key]);
        $repository = app(OpportunityActionExecutionRepository::class);

        $found = $repository->findByIdempotencyKey($key);

        $this->assertNotNull($found);
        $this->assertSame($execution->id, $found->id);
        $this->assertNull($repository->findByIdempotencyKey(hash('sha256', 'nonexistent')));
    }

    public function test_find_active_for_opportunity_only_matches_pending_or_running(): void
    {
        $business = $this->createBusinessForOpportunities();
        $user = $this->createUser();
        $opportunity = $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'exec-active')]);
        $repository = app(OpportunityActionExecutionRepository::class);

        $succeeded = $this->createOpportunityActionExecution($opportunity, $user, [
            'idempotency_key' => hash('sha256', 'succeeded'),
            'status' => OpportunityActionExecutionStatus::Succeeded->value,
        ]);

        $this->assertNull($repository->findActiveForOpportunity($opportunity->id));

        $pending = $this->createOpportunityActionExecution($opportunity, $user, [
            'idempotency_key' => hash('sha256', 'pending'),
            'attempt_number' => 2,
            'status' => OpportunityActionExecutionStatus::Pending->value,
        ]);

        $found = $repository->findActiveForOpportunity($opportunity->id);
        $this->assertNotNull($found);
        $this->assertSame($pending->id, $found->id);
        $this->assertNotSame($succeeded->id, $found->id);
    }

    public function test_next_attempt_number_for_update_increments_per_opportunity(): void
    {
        $business = $this->createBusinessForOpportunities();
        $user = $this->createUser();
        $opportunity = $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'exec-attempt')]);
        $repository = app(OpportunityActionExecutionRepository::class);

        $this->assertSame(1, $repository->nextAttemptNumberForUpdate($opportunity->id));

        $this->createOpportunityActionExecution($opportunity, $user, [
            'idempotency_key' => hash('sha256', 'attempt-1'),
            'attempt_number' => 1,
        ]);

        $this->assertSame(2, $repository->nextAttemptNumberForUpdate($opportunity->id));
    }

    public function test_find_for_update_returns_the_correct_execution(): void
    {
        $business = $this->createBusinessForOpportunities();
        $user = $this->createUser();
        $opportunity = $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'exec-find-update')]);
        $execution = $this->createOpportunityActionExecution($opportunity, $user, ['idempotency_key' => hash('sha256', 'find-update')]);
        $repository = app(OpportunityActionExecutionRepository::class);

        $found = $repository->findForUpdate($execution->id);

        $this->assertNotNull($found);
        $this->assertSame($execution->id, $found->id);
    }

    public function test_update_ignores_immutable_identity_fields(): void
    {
        $business = $this->createBusinessForOpportunities();
        $user = $this->createUser();
        $otherUser = $this->createUser();
        $opportunity = $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'exec-protected')]);
        $execution = $this->createOpportunityActionExecution($opportunity, $user, ['idempotency_key' => hash('sha256', 'protected-key')]);
        $repository = app(OpportunityActionExecutionRepository::class);

        $repository->update($execution, [
            'opportunity_id' => $opportunity->id + 999,
            'action_key' => 'add_email',
            'recommended_action_hash' => hash('sha256', 'attacker-hash'),
            'action_schema_version' => 99,
            'occurrence_number' => 99,
            'attempt_number' => 99,
            'idempotency_key' => hash('sha256', 'attacker-key'),
            'initiated_by_user_id' => $otherUser->id,
            'initiated_by_type' => 'admin',
            'completion_policy' => 'customer_attested',
            'status' => OpportunityActionExecutionStatus::Succeeded->value,
            'safe_result_summary' => 'Completed successfully.',
        ]);

        $execution->refresh();

        $this->assertSame($opportunity->id, $execution->opportunity_id);
        $this->assertSame('add_phone', $execution->action_key);
        $this->assertSame(1, $execution->action_schema_version);
        $this->assertSame(1, $execution->occurrence_number);
        $this->assertSame(1, $execution->attempt_number);
        $this->assertSame(hash('sha256', 'protected-key'), $execution->idempotency_key);
        $this->assertSame($user->id, $execution->initiated_by_user_id);
        $this->assertSame('customer', $execution->initiated_by_type);
        $this->assertSame(OpportunityCompletionPolicy::SystemVerified, $execution->completion_policy);
        $this->assertSame(OpportunityActionExecutionStatus::Succeeded, $execution->status);
        $this->assertSame('Completed successfully.', $execution->safe_result_summary);
    }

    public function test_execution_is_scoped_to_its_owning_opportunity(): void
    {
        $business = $this->createBusinessForOpportunities();
        $user = $this->createUser();
        $opportunity = $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'exec-relationship')]);
        $execution = $this->createOpportunityActionExecution($opportunity, $user, ['idempotency_key' => hash('sha256', 'relationship-key')]);

        $this->assertTrue($opportunity->actionExecutions->contains($execution));
    }
}

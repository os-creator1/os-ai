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

    public function test_find_latest_for_opportunity_returns_the_highest_attempt_number(): void
    {
        $business = $this->createBusinessForOpportunities();
        $user = $this->createUser();
        $opportunity = $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'exec-latest-1')]);
        $repository = app(OpportunityActionExecutionRepository::class);

        $this->createOpportunityActionExecution($opportunity, $user, [
            'idempotency_key' => hash('sha256', 'latest-attempt-1'),
            'attempt_number' => 1,
            'status' => OpportunityActionExecutionStatus::Failed->value,
        ]);
        $latest = $this->createOpportunityActionExecution($opportunity, $user, [
            'idempotency_key' => hash('sha256', 'latest-attempt-2'),
            'attempt_number' => 2,
            'status' => OpportunityActionExecutionStatus::Pending->value,
        ]);

        $found = $repository->findLatestForOpportunity($opportunity->id);

        $this->assertNotNull($found);
        $this->assertSame($latest->id, $found->id);
    }

    public function test_find_latest_for_opportunity_uses_id_descending_as_a_deterministic_tie_breaker(): void
    {
        $business = $this->createBusinessForOpportunities();
        $user = $this->createUser();
        $opportunity = $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'exec-latest-2')]);
        $repository = app(OpportunityActionExecutionRepository::class);

        $this->createOpportunityActionExecution($opportunity, $user, [
            'idempotency_key' => hash('sha256', 'latest-tie-1'),
            'attempt_number' => 1,
            'status' => OpportunityActionExecutionStatus::Failed->value,
        ]);
        $secondCreated = $this->createOpportunityActionExecution($opportunity, $user, [
            'idempotency_key' => hash('sha256', 'latest-tie-2'),
            'attempt_number' => 1,
            'status' => OpportunityActionExecutionStatus::Failed->value,
        ]);

        $found = $repository->findLatestForOpportunity($opportunity->id);

        $this->assertSame($secondCreated->id, $found->id);
    }

    public function test_find_latest_for_opportunity_is_eligible_regardless_of_status(): void
    {
        $business = $this->createBusinessForOpportunities();
        $user = $this->createUser();
        $repository = app(OpportunityActionExecutionRepository::class);

        foreach ([
            OpportunityActionExecutionStatus::Pending,
            OpportunityActionExecutionStatus::Running,
            OpportunityActionExecutionStatus::Failed,
            OpportunityActionExecutionStatus::Succeeded,
        ] as $status) {
            $opportunity = $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'exec-latest-status-' . $status->value)]);
            $execution = $this->createOpportunityActionExecution($opportunity, $user, [
                'idempotency_key' => hash('sha256', 'latest-status-' . $status->value),
                'status' => $status->value,
            ]);

            $found = $repository->findLatestForOpportunity($opportunity->id);

            $this->assertNotNull($found);
            $this->assertSame($execution->id, $found->id);
        }
    }

    public function test_find_latest_for_opportunity_excludes_another_opportunity(): void
    {
        $business = $this->createBusinessForOpportunities();
        $user = $this->createUser();
        $opportunity = $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'exec-latest-3')]);
        $otherOpportunity = $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'exec-latest-3-other')]);
        $repository = app(OpportunityActionExecutionRepository::class);

        $this->createOpportunityActionExecution($otherOpportunity, $user, [
            'idempotency_key' => hash('sha256', 'latest-other-opportunity'),
        ]);

        $this->assertNull($repository->findLatestForOpportunity($opportunity->id));
    }

    public function test_find_latest_for_opportunity_returns_null_when_none_exists(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'exec-latest-4')]);
        $repository = app(OpportunityActionExecutionRepository::class);

        $this->assertNull($repository->findLatestForOpportunity($opportunity->id));
    }

    public function test_find_latest_for_opportunity_does_not_modify_the_returned_execution(): void
    {
        $business = $this->createBusinessForOpportunities();
        $user = $this->createUser();
        $opportunity = $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'exec-latest-5')]);
        $repository = app(OpportunityActionExecutionRepository::class);

        $execution = $this->createOpportunityActionExecution($opportunity, $user, [
            'idempotency_key' => hash('sha256', 'latest-untouched'),
            'status' => OpportunityActionExecutionStatus::Failed->value,
        ]);
        $originalUpdatedAt = $execution->updated_at;

        $found = $repository->findLatestForOpportunity($opportunity->id);

        $this->assertNotNull($found);
        $this->assertTrue($originalUpdatedAt->equalTo($found->updated_at));
        $this->assertSame($execution->status, $found->status);
    }

    public function test_find_latest_failed_matching_returns_the_matching_execution(): void
    {
        [$repository, $opportunity, $user] = $this->retryRepositoryFixture();

        $failed = $this->createOpportunityActionExecution($opportunity, $user, array_merge(
            $this->matchingAttempt(),
            ['idempotency_key' => hash('sha256', 'match-1'), 'status' => OpportunityActionExecutionStatus::Failed->value]
        ));

        $found = $repository->findLatestFailedMatching($opportunity->id, 1, $this->matchingHash(), 1, 'add_phone');

        $this->assertNotNull($found);
        $this->assertSame($failed->id, $found->id);
    }

    public function test_find_latest_failed_matching_orders_by_attempt_number_descending(): void
    {
        [$repository, $opportunity, $user] = $this->retryRepositoryFixture();

        $this->createOpportunityActionExecution($opportunity, $user, array_merge(
            $this->matchingAttempt(),
            ['idempotency_key' => hash('sha256', 'attempt-1'), 'attempt_number' => 1, 'status' => OpportunityActionExecutionStatus::Failed->value]
        ));
        $latest = $this->createOpportunityActionExecution($opportunity, $user, array_merge(
            $this->matchingAttempt(),
            ['idempotency_key' => hash('sha256', 'attempt-2'), 'attempt_number' => 2, 'status' => OpportunityActionExecutionStatus::Failed->value]
        ));

        $found = $repository->findLatestFailedMatching($opportunity->id, 1, $this->matchingHash(), 1, 'add_phone');

        $this->assertSame($latest->id, $found->id);
    }

    public function test_find_latest_failed_matching_uses_id_descending_as_a_deterministic_tie_breaker(): void
    {
        [$repository, $opportunity, $user] = $this->retryRepositoryFixture();

        // Two rows deliberately share the same attempt_number (only possible
        // because they are never both real attempts of the same Opportunity
        // in production — this purely exercises the tie-breaker itself).
        $this->createOpportunityActionExecution($opportunity, $user, array_merge(
            $this->matchingAttempt(),
            ['idempotency_key' => hash('sha256', 'tie-1'), 'attempt_number' => 1, 'status' => OpportunityActionExecutionStatus::Failed->value]
        ));
        $secondCreated = $this->createOpportunityActionExecution($opportunity, $user, array_merge(
            $this->matchingAttempt(),
            ['idempotency_key' => hash('sha256', 'tie-2'), 'attempt_number' => 1, 'status' => OpportunityActionExecutionStatus::Failed->value]
        ));

        $found = $repository->findLatestFailedMatching($opportunity->id, 1, $this->matchingHash(), 1, 'add_phone');

        $this->assertSame($secondCreated->id, $found->id);
    }

    public function test_find_latest_failed_matching_excludes_pending(): void
    {
        [$repository, $opportunity, $user] = $this->retryRepositoryFixture();

        $this->createOpportunityActionExecution($opportunity, $user, array_merge(
            $this->matchingAttempt(),
            ['idempotency_key' => hash('sha256', 'pending-excluded'), 'status' => OpportunityActionExecutionStatus::Pending->value]
        ));

        $this->assertNull($repository->findLatestFailedMatching($opportunity->id, 1, $this->matchingHash(), 1, 'add_phone'));
    }

    public function test_find_latest_failed_matching_excludes_running(): void
    {
        [$repository, $opportunity, $user] = $this->retryRepositoryFixture();

        $this->createOpportunityActionExecution($opportunity, $user, array_merge(
            $this->matchingAttempt(),
            ['idempotency_key' => hash('sha256', 'running-excluded'), 'status' => OpportunityActionExecutionStatus::Running->value]
        ));

        $this->assertNull($repository->findLatestFailedMatching($opportunity->id, 1, $this->matchingHash(), 1, 'add_phone'));
    }

    public function test_find_latest_failed_matching_excludes_succeeded(): void
    {
        [$repository, $opportunity, $user] = $this->retryRepositoryFixture();

        $this->createOpportunityActionExecution($opportunity, $user, array_merge(
            $this->matchingAttempt(),
            ['idempotency_key' => hash('sha256', 'succeeded-excluded'), 'status' => OpportunityActionExecutionStatus::Succeeded->value]
        ));

        $this->assertNull($repository->findLatestFailedMatching($opportunity->id, 1, $this->matchingHash(), 1, 'add_phone'));
    }

    public function test_find_latest_failed_matching_excludes_another_opportunity(): void
    {
        [$repository, $opportunity, $user] = $this->retryRepositoryFixture();
        $otherOpportunity = $this->createOpportunity($opportunity->business, ['fingerprint' => hash('sha256', 'retry-other-opportunity')]);

        $this->createOpportunityActionExecution($otherOpportunity, $user, array_merge(
            $this->matchingAttempt(),
            ['idempotency_key' => hash('sha256', 'other-opportunity'), 'status' => OpportunityActionExecutionStatus::Failed->value, 'opportunity_id' => $otherOpportunity->id]
        ));

        $this->assertNull($repository->findLatestFailedMatching($opportunity->id, 1, $this->matchingHash(), 1, 'add_phone'));
    }

    public function test_find_latest_failed_matching_excludes_another_occurrence_number(): void
    {
        [$repository, $opportunity, $user] = $this->retryRepositoryFixture();

        $this->createOpportunityActionExecution($opportunity, $user, array_merge(
            $this->matchingAttempt(),
            ['idempotency_key' => hash('sha256', 'other-occurrence'), 'status' => OpportunityActionExecutionStatus::Failed->value, 'occurrence_number' => 2]
        ));

        $this->assertNull($repository->findLatestFailedMatching($opportunity->id, 1, $this->matchingHash(), 1, 'add_phone'));
    }

    public function test_find_latest_failed_matching_excludes_another_recommended_action_hash(): void
    {
        [$repository, $opportunity, $user] = $this->retryRepositoryFixture();

        $this->createOpportunityActionExecution($opportunity, $user, array_merge(
            $this->matchingAttempt(),
            ['idempotency_key' => hash('sha256', 'other-hash'), 'status' => OpportunityActionExecutionStatus::Failed->value, 'recommended_action_hash' => hash('sha256', 'a-different-hash')]
        ));

        $this->assertNull($repository->findLatestFailedMatching($opportunity->id, 1, $this->matchingHash(), 1, 'add_phone'));
    }

    public function test_find_latest_failed_matching_excludes_another_action_schema_version(): void
    {
        [$repository, $opportunity, $user] = $this->retryRepositoryFixture();

        $this->createOpportunityActionExecution($opportunity, $user, array_merge(
            $this->matchingAttempt(),
            ['idempotency_key' => hash('sha256', 'other-schema'), 'status' => OpportunityActionExecutionStatus::Failed->value, 'action_schema_version' => 2]
        ));

        $this->assertNull($repository->findLatestFailedMatching($opportunity->id, 1, $this->matchingHash(), 1, 'add_phone'));
    }

    public function test_find_latest_failed_matching_excludes_another_action_key(): void
    {
        [$repository, $opportunity, $user] = $this->retryRepositoryFixture();

        $this->createOpportunityActionExecution($opportunity, $user, array_merge(
            $this->matchingAttempt(),
            ['idempotency_key' => hash('sha256', 'other-action-key'), 'status' => OpportunityActionExecutionStatus::Failed->value, 'action_key' => 'add_email']
        ));

        $this->assertNull($repository->findLatestFailedMatching($opportunity->id, 1, $this->matchingHash(), 1, 'add_phone'));
    }

    public function test_find_latest_failed_matching_returns_null_when_no_match_exists(): void
    {
        [$repository, $opportunity] = $this->retryRepositoryFixture();

        $this->assertNull($repository->findLatestFailedMatching($opportunity->id, 1, $this->matchingHash(), 1, 'add_phone'));
    }

    /**
     * @return array{0: string}
     */
    private function matchingHash(): string
    {
        return hash('sha256', 'retry-matching-recommended-action-hash');
    }

    /**
     * @return array<string, mixed>
     */
    private function matchingAttempt(): array
    {
        return [
            'action_key' => 'add_phone',
            'recommended_action_hash' => $this->matchingHash(),
            'action_schema_version' => 1,
            'occurrence_number' => 1,
        ];
    }

    /**
     * @return array{0: OpportunityActionExecutionRepository, 1: Opportunity, 2: \App\Models\User}
     */
    private function retryRepositoryFixture(): array
    {
        $business = $this->createBusinessForOpportunities();
        $user = $this->createUser();
        $opportunity = $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'retry-repo-' . uniqid('', true))]);
        $repository = app(OpportunityActionExecutionRepository::class);

        return [$repository, $opportunity, $user];
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

    public function test_all_for_opportunity_returns_only_matching_executions(): void
    {
        $business = $this->createBusinessForOpportunities();
        $user = $this->createUser();
        $opportunity = $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'all-for-opportunity-1')]);
        $otherOpportunity = $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'all-for-opportunity-2')]);
        $matching = $this->createOpportunityActionExecution($opportunity, $user, ['idempotency_key' => hash('sha256', 'all-for-opportunity-matching')]);
        $this->createOpportunityActionExecution($otherOpportunity, $user, ['idempotency_key' => hash('sha256', 'all-for-opportunity-other')]);
        $repository = app(OpportunityActionExecutionRepository::class);

        $found = $repository->allForOpportunity($opportunity->id);

        $this->assertCount(1, $found);
        $this->assertSame($matching->id, $found->first()->id);
    }

    public function test_all_for_opportunity_orders_by_attempt_number_then_id_descending(): void
    {
        $business = $this->createBusinessForOpportunities();
        $user = $this->createUser();
        $opportunity = $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'all-for-opportunity-order')]);
        $repository = app(OpportunityActionExecutionRepository::class);

        $first = $this->createOpportunityActionExecution($opportunity, $user, [
            'idempotency_key' => hash('sha256', 'all-for-opportunity-order-1'),
            'attempt_number' => 1,
            'status' => OpportunityActionExecutionStatus::Failed->value,
        ]);
        $second = $this->createOpportunityActionExecution($opportunity, $user, [
            'idempotency_key' => hash('sha256', 'all-for-opportunity-order-2'),
            'attempt_number' => 2,
            'status' => OpportunityActionExecutionStatus::Succeeded->value,
        ]);

        $found = $repository->allForOpportunity($opportunity->id);

        $this->assertCount(2, $found);
        $this->assertSame($second->id, $found->first()->id);
        $this->assertSame($first->id, $found->last()->id);
    }

    public function test_all_for_opportunity_returns_an_empty_collection_when_none_exist(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business, ['fingerprint' => hash('sha256', 'all-for-opportunity-empty')]);
        $repository = app(OpportunityActionExecutionRepository::class);

        $found = $repository->allForOpportunity($opportunity->id);

        $this->assertTrue($found->isEmpty());
    }
}

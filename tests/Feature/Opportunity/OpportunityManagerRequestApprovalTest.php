<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Opportunity\OpportunityFreshness;
use App\Enums\Opportunity\OpportunityStatus;
use App\Enums\Opportunity\OpportunityTransitionActorType;
use App\Enums\Opportunity\OpportunityTransitionCategory;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Events\Opportunity\OpportunityApprovalRequested;
use App\Library\Opportunity\CanonicalJson;
use App\Library\Opportunity\Exceptions\InvalidOpportunityStateException;
use App\Library\Opportunity\Exceptions\OpportunityActionNotExecutableException;
use App\Library\Opportunity\OpportunityActionHash;
use App\Library\Opportunity\OpportunityManager;
use App\Models\Business;
use App\Models\Opportunity;
use App\Models\OpportunityActionExecution;
use App\Models\OpportunityTransition;
use App\Repositories\Contracts\OpportunityRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\TestCase;

/**
 * RFC-002 Phase 4B.2B note: tests for a registry with a missing
 * handler_identifier/verifier_identifier, mutates_business_data=false, or
 * approval_required=false are intentionally omitted. OpportunityActionRegistry
 * is a closed, static, source-controlled class (RFC-002 §13.1) called
 * directly by OpportunityManager — there is no container-binding or
 * repository seam to substitute alternate registry metadata without either
 * modifying the production registry or adding a new indirection layer, both
 * out of scope for this phase.
 */
class OpportunityManagerRequestApprovalTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    public function test_fully_configured_add_phone_becomes_awaiting_approval(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configuredApprovableOpportunity($business);
        $manager = app(OpportunityManager::class);

        $updated = $manager->requestApproval($opportunity, $business->customer);

        $this->assertSame(OpportunityStatus::AwaitingApproval, $updated->status);
    }

    public function test_configured_action_and_hash_remain_unchanged(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configuredApprovableOpportunity($business);
        $originalAction = $opportunity->recommended_action;
        $originalHash = $opportunity->recommended_action_hash;
        $manager = app(OpportunityManager::class);

        $updated = $manager->requestApproval($opportunity, $business->customer);

        $this->assertSame(
            CanonicalJson::encode($originalAction),
            CanonicalJson::encode($updated->recommended_action),
        );
        $this->assertSame($originalHash, $updated->recommended_action_hash);
    }

    public function test_freshness_and_unrelated_fields_remain_unchanged(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configuredApprovableOpportunity($business, [
            'freshness' => OpportunityFreshness::Stale->value,
            'occurrence_number' => 2,
            'stale_at' => now()->subDay(),
        ]);
        $originalFirstDetectedAt = $opportunity->first_detected_at;
        $originalLastConfirmedAt = $opportunity->last_confirmed_at;
        $originalLastConfirmedRunId = $opportunity->last_confirmed_run_id;
        $originalEvidence = $opportunity->evidence;
        $originalPriorityScore = $opportunity->priority_score;
        $originalActionSchemaVersion = $opportunity->action_schema_version;
        $originalStaleAt = $opportunity->stale_at;
        $manager = app(OpportunityManager::class);

        $updated = $manager->requestApproval($opportunity, $business->customer);

        $this->assertSame(OpportunityFreshness::Stale, $updated->freshness);
        $this->assertSame(2, $updated->occurrence_number);
        $this->assertNull($updated->snoozed_until);
        $this->assertNull($updated->dismissed_at);
        $this->assertNull($updated->completed_at);
        $this->assertTrue($originalStaleAt->equalTo($updated->stale_at));
        $this->assertTrue($originalFirstDetectedAt->equalTo($updated->first_detected_at));
        $this->assertTrue($originalLastConfirmedAt->equalTo($updated->last_confirmed_at));
        $this->assertSame($originalLastConfirmedRunId, $updated->last_confirmed_run_id);
        $this->assertSame($originalEvidence, $updated->evidence);
        $this->assertSame($originalPriorityScore, $updated->priority_score);
        $this->assertSame($originalActionSchemaVersion, $updated->action_schema_version);
    }

    public function test_exact_transition_fields(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configuredApprovableOpportunity($business);
        $manager = app(OpportunityManager::class);

        $manager->requestApproval($opportunity, $business->customer);

        $transition = OpportunityTransition::where('opportunity_id', $opportunity->id)->first();
        $this->assertNotNull($transition);
        $this->assertSame(OpportunityTransitionCategory::Workflow, $transition->category);
        $this->assertSame('open', $transition->from_status);
        $this->assertSame('awaiting_approval', $transition->to_status);
        $this->assertSame(OpportunityTransitionActorType::Customer, $transition->actor_type);
        $this->assertSame($business->customer->user_id, $transition->actor_user_id);
        $this->assertNull($transition->opportunity_run_id);
        $this->assertNull($transition->action_execution_id);
        $this->assertSame('customer_requested_approval', $transition->reason_code);
        $this->assertNull($transition->safe_note);
    }

    public function test_event_has_correct_scalar_payloads_including_the_configured_action_hash(): void
    {
        Event::fake([OpportunityApprovalRequested::class]);

        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configuredApprovableOpportunity($business);
        $manager = app(OpportunityManager::class);

        $manager->requestApproval($opportunity, $business->customer);

        Event::assertDispatched(OpportunityApprovalRequested::class, function (OpportunityApprovalRequested $event) use ($opportunity, $business) {
            return $event->opportunityId === $opportunity->id
                && $event->businessId === $business->id
                && $event->actorUserId === $business->customer->user_id
                && $event->recommendedActionHash === $opportunity->recommended_action_hash;
        });
    }

    public function test_event_is_delivered_after_a_genuine_commit(): void
    {
        Event::fake([OpportunityApprovalRequested::class]);

        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configuredApprovableOpportunity($business);
        $manager = app(OpportunityManager::class);

        $manager->requestApproval($opportunity, $business->customer);

        Event::assertDispatched(OpportunityApprovalRequested::class, 1);
    }

    /**
     * A delegating OpportunityRepository (not a partial mock): every method
     * except update() delegates to the real, fully initialized repository;
     * update() is forced to throw, rolling back the whole transaction
     * before OpportunityApprovalRequested is ever dispatched.
     */
    public function test_rolled_back_transaction_delivers_no_event(): void
    {
        Event::fake([OpportunityApprovalRequested::class]);

        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configuredApprovableOpportunity($business);

        $real = app(OpportunityRepository::class);

        $repository = new class($real) implements OpportunityRepository {
            public function __construct(
                private readonly OpportunityRepository $real
            ) {
            }

            public function query()
            {
                return $this->real->query();
            }

            public function search($query, $callback = null)
            {
                return $this->real->search($query, $callback);
            }

            public function select(array $columns = ['*'])
            {
                return $this->real->select($columns);
            }

            public function make(array $attributes = [])
            {
                return $this->real->make($attributes);
            }

            public function findByFingerprintForUpdate(string $fingerprint): ?Opportunity
            {
                return $this->real->findByFingerprintForUpdate($fingerprint);
            }

            public function findOwnedForUpdate(int $id, int $businessId): ?Opportunity
            {
                return $this->real->findOwnedForUpdate($id, $businessId);
            }

            public function findOwned(int $id, int $businessId): ?Opportunity
            {
                return $this->real->findOwned($id, $businessId);
            }

            public function currentMissingFromRunForUpdate(int $businessId, OpportunityWorkerKey $workerKey, int $excludeRunId): Collection
            {
                return $this->real->currentMissingFromRunForUpdate($businessId, $workerKey, $excludeRunId);
            }

            public function expiredSnoozesBatch(int $limit): Collection
            {
                return $this->real->expiredSnoozesBatch($limit);
            }

            public function paginateForCustomer(Business $business, array $filters): LengthAwarePaginator
            {
                return $this->real->paginateForCustomer($business, $filters);
            }

            public function topForCustomer(Business $business, int $limit): Collection
            {
                return $this->real->topForCustomer($business, $limit);
            }

            public function paginateForAdmin(array $filters): LengthAwarePaginator
            {
                return $this->real->paginateForAdmin($filters);
            }

            public function findForAdmin(int $opportunityId): ?Opportunity
            {
                return $this->real->findForAdmin($opportunityId);
            }

            public function create(array $attributes): Opportunity
            {
                return $this->real->create($attributes);
            }

            public function update(Opportunity $opportunity, array $attributes): Opportunity
            {
                throw new RuntimeException('Forced rollback.');
            }
        };

        $this->app->instance(OpportunityRepository::class, $repository);

        $manager = app(OpportunityManager::class);

        $caught = null;

        try {
            $manager->requestApproval($opportunity, $business->customer);
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught);
        $this->assertSame('Forced rollback.', $caught->getMessage());

        $opportunity->refresh();
        $this->assertSame(OpportunityStatus::Open, $opportunity->status);
        $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());

        Event::assertNotDispatched(OpportunityApprovalRequested::class);
    }

    public function test_initial_add_phone_action_with_empty_parameters_rejects_atomically(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->opportunityWithAction($business, [
            'schema_version' => 1,
            'action_key' => 'add_phone',
            'parameters' => [],
            'approval_required' => true,
            'completion_policy' => 'system_verified',
        ]);

        $this->assertRejectsAtomically($opportunity, $business, OpportunityActionNotExecutableException::class);
    }

    public function test_missing_parameters_value_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->opportunityWithAction($business, [
            'schema_version' => 1,
            'action_key' => 'add_phone',
            'parameters' => ['other' => 'x'],
            'approval_required' => true,
            'completion_policy' => 'system_verified',
        ]);

        $this->assertRejectsAtomically($opportunity, $business, OpportunityActionNotExecutableException::class);
    }

    public function test_null_value_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->opportunityWithAction($business, $this->validAddPhoneAction(null));

        $this->assertRejectsAtomically($opportunity, $business, OpportunityActionNotExecutableException::class);
    }

    public function test_non_string_value_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->opportunityWithAction($business, $this->validAddPhoneAction(5551234567));

        $this->assertRejectsAtomically($opportunity, $business, OpportunityActionNotExecutableException::class);
    }

    public function test_blank_value_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->opportunityWithAction($business, $this->validAddPhoneAction('    '));

        $this->assertRejectsAtomically($opportunity, $business, OpportunityActionNotExecutableException::class);
    }

    public function test_value_longer_than_50_characters_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->opportunityWithAction($business, $this->validAddPhoneAction(str_repeat('1', 51)));

        $this->assertRejectsAtomically($opportunity, $business, OpportunityActionNotExecutableException::class);
    }

    public function test_unexpected_parameter_key_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $recommendedAction = $this->validAddPhoneAction();
        $recommendedAction['parameters']['label'] = 'mobile';

        $opportunity = $this->opportunityWithAction($business, $recommendedAction);

        $this->assertRejectsAtomically($opportunity, $business, OpportunityActionNotExecutableException::class);
    }

    public function test_unexpected_top_level_recommended_action_key_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $recommendedAction = $this->validAddPhoneAction();
        $recommendedAction['unexpected_key'] = 'x';

        $opportunity = $this->opportunityWithAction($business, $recommendedAction);

        $this->assertRejectsAtomically($opportunity, $business, OpportunityActionNotExecutableException::class);
    }

    public function test_missing_expected_top_level_key_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $recommendedAction = $this->validAddPhoneAction();
        unset($recommendedAction['completion_policy']);

        $opportunity = $this->opportunityWithAction($business, $recommendedAction);

        $this->assertRejectsAtomically($opportunity, $business, OpportunityActionNotExecutableException::class);
    }

    public function test_unsupported_action_key_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $recommendedAction = $this->validAddPhoneAction();
        $recommendedAction['action_key'] = 'add_email';

        $opportunity = $this->opportunityWithAction($business, $recommendedAction);

        $this->assertRejectsAtomically($opportunity, $business, OpportunityActionNotExecutableException::class);
    }

    public function test_persisted_schema_version_mismatch_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $recommendedAction = $this->validAddPhoneAction();
        $recommendedAction['schema_version'] = 2;

        $opportunity = $this->opportunityWithAction($business, $recommendedAction);

        $this->assertRejectsAtomically($opportunity, $business, OpportunityActionNotExecutableException::class);
    }

    public function test_opportunity_action_schema_version_mismatch_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->opportunityWithAction($business, $this->validAddPhoneAction(), ['action_schema_version' => 2]);

        $this->assertRejectsAtomically($opportunity, $business, OpportunityActionNotExecutableException::class);
    }

    public function test_persisted_approval_required_mismatch_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $recommendedAction = $this->validAddPhoneAction();
        $recommendedAction['approval_required'] = false;

        $opportunity = $this->opportunityWithAction($business, $recommendedAction);

        $this->assertRejectsAtomically($opportunity, $business, OpportunityActionNotExecutableException::class);
    }

    public function test_persisted_completion_policy_mismatch_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $recommendedAction = $this->validAddPhoneAction();
        $recommendedAction['completion_policy'] = 'customer_attested';

        $opportunity = $this->opportunityWithAction($business, $recommendedAction);

        $this->assertRejectsAtomically($opportunity, $business, OpportunityActionNotExecutableException::class);
    }

    public function test_recommended_action_hash_mismatch_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->opportunityWithAction(
            $business,
            $this->validAddPhoneAction(),
            [],
            hash('sha256', 'a-hash-that-does-not-match')
        );

        $this->assertRejectsAtomically($opportunity, $business, OpportunityActionNotExecutableException::class);
    }

    public function test_another_customers_opportunity_rejects_atomically(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configuredApprovableOpportunity($business);

        $strangerBusiness = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);

        try {
            $this->expectException(AuthorizationException::class);

            $manager->requestApproval($opportunity, $strangerBusiness->customer);
        } finally {
            $opportunity->refresh();
            $this->assertSame(OpportunityStatus::Open, $opportunity->status);
            $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
        }
    }

    public function test_a_mismatched_supplied_business_id_cannot_bypass_ownership(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configuredApprovableOpportunity($business);

        $strangerBusiness = $this->createBusinessForOpportunities();
        $opportunity->business_id = $strangerBusiness->id;

        $manager = app(OpportunityManager::class);

        $this->expectException(AuthorizationException::class);

        $manager->requestApproval($opportunity, $strangerBusiness->customer);
    }

    public function test_missing_opportunity_rejects_safely(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configuredApprovableOpportunity($business);
        $opportunity->delete();

        $manager = app(OpportunityManager::class);

        $this->expectException(AuthorizationException::class);

        $manager->requestApproval($opportunity, $business->customer);
    }

    public function test_awaiting_approval_rejects_rather_than_silently_succeeding(): void
    {
        $this->assertStatusRejects(OpportunityStatus::AwaitingApproval);
    }

    public function test_in_progress_rejects(): void
    {
        $this->assertStatusRejects(OpportunityStatus::InProgress);
    }

    public function test_snoozed_rejects(): void
    {
        $this->assertStatusRejects(OpportunityStatus::Snoozed);
    }

    public function test_completed_rejects(): void
    {
        $this->assertStatusRejects(OpportunityStatus::Completed);
    }

    public function test_dismissed_rejects(): void
    {
        $this->assertStatusRejects(OpportunityStatus::Dismissed);
    }

    public function test_no_action_execution_row_is_created(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configuredApprovableOpportunity($business);
        $manager = app(OpportunityManager::class);

        $manager->requestApproval($opportunity, $business->customer);

        $this->assertSame(0, OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count());
    }

    public function test_no_execution_job_is_dispatched(): void
    {
        Queue::fake();

        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configuredApprovableOpportunity($business);
        $manager = app(OpportunityManager::class);

        $manager->requestApproval($opportunity, $business->customer);

        Queue::assertNothingPushed();
    }

    /**
     * @return array<string, mixed>
     */
    private function validAddPhoneAction(mixed $value = '+15551234567'): array
    {
        return [
            'schema_version' => 1,
            'action_key' => 'add_phone',
            'parameters' => ['value' => $value],
            'approval_required' => true,
            'completion_policy' => 'system_verified',
        ];
    }

    private function opportunityWithAction(Business $business, array $recommendedAction, array $overrides = [], ?string $hashOverride = null): Opportunity
    {
        return $this->createOpportunity($business, array_merge([
            'status' => OpportunityStatus::Open->value,
            'recommended_action' => $recommendedAction,
            'recommended_action_hash' => $hashOverride ?? (new OpportunityActionHash())->compute($recommendedAction),
            'action_schema_version' => 1,
        ], $overrides));
    }

    private function configuredApprovableOpportunity(Business $business, array $overrides = []): Opportunity
    {
        return $this->opportunityWithAction($business, $this->validAddPhoneAction(), $overrides);
    }

    private function assertRejectsAtomically(Opportunity $opportunity, Business $business, string $exceptionClass): void
    {
        $originalAction = $opportunity->recommended_action;
        $originalHash = $opportunity->recommended_action_hash;
        $manager = app(OpportunityManager::class);

        try {
            $this->expectException($exceptionClass);

            $manager->requestApproval($opportunity, $business->customer);
        } finally {
            $opportunity->refresh();
            $this->assertSame(OpportunityStatus::Open, $opportunity->status);
            $this->assertSame(
                CanonicalJson::encode($originalAction),
                CanonicalJson::encode($opportunity->recommended_action),
            );
            $this->assertSame($originalHash, $opportunity->recommended_action_hash);
            $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
            $this->assertSame(0, OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count());
        }
    }

    private function assertStatusRejects(OpportunityStatus $status): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configuredApprovableOpportunity($business, ['status' => $status->value]);
        $manager = app(OpportunityManager::class);

        try {
            $this->expectException(InvalidOpportunityStateException::class);

            $manager->requestApproval($opportunity, $business->customer);
        } finally {
            $opportunity->refresh();
            $this->assertSame($status, $opportunity->status);
            $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
        }
    }
}

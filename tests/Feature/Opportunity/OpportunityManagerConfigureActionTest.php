<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Opportunity\OpportunityFreshness;
use App\Enums\Opportunity\OpportunityStatus;
use App\Enums\Opportunity\OpportunityTransitionActorType;
use App\Enums\Opportunity\OpportunityTransitionCategory;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Events\Opportunity\OpportunityApprovalRequested;
use App\Events\Opportunity\OpportunityBecameCurrent;
use App\Events\Opportunity\OpportunityCreated;
use App\Events\Opportunity\OpportunityDismissed;
use App\Events\Opportunity\OpportunityMarkedStale;
use App\Events\Opportunity\OpportunityReaffirmed;
use App\Events\Opportunity\OpportunityReopened;
use App\Events\Opportunity\OpportunityRunFailed;
use App\Events\Opportunity\OpportunityRunStarted;
use App\Events\Opportunity\OpportunityRunSucceeded;
use App\Events\Opportunity\OpportunitySnoozed;
use App\Library\Opportunity\CanonicalJson;
use App\Library\Opportunity\Exceptions\InvalidOpportunityActionParametersException;
use App\Library\Opportunity\Exceptions\InvalidOpportunityStateException;
use App\Library\Opportunity\Exceptions\OpportunityActionNotConfigurableException;
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

class OpportunityManagerConfigureActionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    private const KNOWN_OPPORTUNITY_EVENTS = [
        OpportunityApprovalRequested::class,
        OpportunityBecameCurrent::class,
        OpportunityCreated::class,
        OpportunityDismissed::class,
        OpportunityMarkedStale::class,
        OpportunityReaffirmed::class,
        OpportunityReopened::class,
        OpportunityRunFailed::class,
        OpportunityRunStarted::class,
        OpportunityRunSucceeded::class,
        OpportunitySnoozed::class,
    ];

    public function test_add_phone_configuration_stores_parameters_value(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configurableOpportunity($business);
        $manager = app(OpportunityManager::class);

        $updated = $manager->configureAction($opportunity, $business->customer, ['value' => '+15551234567']);

        $this->assertSame('+15551234567', $updated->recommended_action['parameters']['value']);
    }

    public function test_rebuilt_recommended_action_uses_trusted_registry_values(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configurableOpportunity($business);
        $manager = app(OpportunityManager::class);

        $updated = $manager->configureAction($opportunity, $business->customer, ['value' => '+15551234567']);

        $this->assertSame(1, $updated->recommended_action['schema_version']);
        $this->assertSame('add_phone', $updated->recommended_action['action_key']);
        $this->assertTrue($updated->recommended_action['approval_required']);
        $this->assertSame('system_verified', $updated->recommended_action['completion_policy']);
    }

    public function test_deterministic_recommended_action_hash_is_correct(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configurableOpportunity($business);
        $manager = app(OpportunityManager::class);

        $updated = $manager->configureAction($opportunity, $business->customer, ['value' => '+15551234567']);

        $expectedHash = (new OpportunityActionHash())->compute([
            'schema_version' => 1,
            'action_key' => 'add_phone',
            'parameters' => ['value' => '+15551234567'],
            'approval_required' => true,
            'completion_policy' => 'system_verified',
        ]);

        $this->assertSame($expectedHash, $updated->recommended_action_hash);
    }

    public function test_original_phone_string_is_preserved_exactly(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configurableOpportunity($business);
        $manager = app(OpportunityManager::class);

        $rawValue = '  +1 555 123 4567  ';
        $updated = $manager->configureAction($opportunity, $business->customer, ['value' => $rawValue]);

        $this->assertSame($rawValue, $updated->recommended_action['parameters']['value']);
    }

    public function test_exactly_50_characters_succeeds(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configurableOpportunity($business);
        $manager = app(OpportunityManager::class);

        $value = str_repeat('1', 50);
        $updated = $manager->configureAction($opportunity, $business->customer, ['value' => $value]);

        $this->assertSame($value, $updated->recommended_action['parameters']['value']);
    }

    public function test_missing_value_rejects_atomically(): void
    {
        $this->assertParametersRejectAtomically([], InvalidOpportunityActionParametersException::class);
    }

    public function test_null_value_rejects_atomically(): void
    {
        $this->assertParametersRejectAtomically(['value' => null], InvalidOpportunityActionParametersException::class);
    }

    public function test_integer_value_rejects_atomically(): void
    {
        $this->assertParametersRejectAtomically(['value' => 5551234567], InvalidOpportunityActionParametersException::class);
    }

    public function test_boolean_value_rejects_atomically(): void
    {
        $this->assertParametersRejectAtomically(['value' => true], InvalidOpportunityActionParametersException::class);
    }

    public function test_array_value_rejects_atomically(): void
    {
        $this->assertParametersRejectAtomically(['value' => ['+15551234567']], InvalidOpportunityActionParametersException::class);
    }

    public function test_empty_string_rejects_atomically(): void
    {
        $this->assertParametersRejectAtomically(['value' => ''], InvalidOpportunityActionParametersException::class);
    }

    public function test_whitespace_only_string_rejects_atomically(): void
    {
        $this->assertParametersRejectAtomically(['value' => '    '], InvalidOpportunityActionParametersException::class);
    }

    public function test_51_characters_rejects_atomically(): void
    {
        $this->assertParametersRejectAtomically(['value' => str_repeat('1', 51)], InvalidOpportunityActionParametersException::class);
    }

    public function test_unexpected_additional_key_rejects_atomically(): void
    {
        $this->assertParametersRejectAtomically(
            ['value' => '+15551234567', 'label' => 'mobile'],
            InvalidOpportunityActionParametersException::class
        );
    }

    public function test_parameters_containing_only_an_unexpected_key_rejects_atomically(): void
    {
        $this->assertParametersRejectAtomically(['label' => 'mobile'], InvalidOpportunityActionParametersException::class);
    }

    public function test_non_add_phone_action_rejects_atomically(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configurableOpportunity($business, [
            'recommended_action' => [
                'schema_version' => 1,
                'action_key' => 'add_email',
                'parameters' => [],
                'approval_required' => true,
                'completion_policy' => 'system_verified',
            ],
            'recommended_action_hash' => hash('sha256', 'add-email-action'),
        ]);

        $this->assertConfigureRejectsAtomically($opportunity, $business, ['value' => 'test@example.test'], OpportunityActionNotConfigurableException::class);
    }

    public function test_missing_recommended_action_rejects_atomically(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configurableOpportunity($business, [
            'recommended_action' => null,
            'recommended_action_hash' => null,
        ]);

        $this->assertConfigureRejectsAtomically($opportunity, $business, ['value' => '+15551234567'], OpportunityActionNotConfigurableException::class);
    }

    public function test_malformed_recommended_action_rejects_atomically(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configurableOpportunity($business, [
            'recommended_action' => ['not_an_action_key' => true],
            'recommended_action_hash' => hash('sha256', 'malformed'),
        ]);

        $this->assertConfigureRejectsAtomically($opportunity, $business, ['value' => '+15551234567'], OpportunityActionNotConfigurableException::class);
    }

    public function test_exact_workflow_transition_fields_are_created_for_a_genuine_change(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configurableOpportunity($business);
        $manager = app(OpportunityManager::class);

        $manager->configureAction($opportunity, $business->customer, ['value' => '+15551234567']);

        $transition = OpportunityTransition::where('opportunity_id', $opportunity->id)->first();
        $this->assertNotNull($transition);
        $this->assertSame(OpportunityTransitionCategory::Workflow, $transition->category);
        $this->assertSame('open', $transition->from_status);
        $this->assertSame('open', $transition->to_status);
        $this->assertSame(OpportunityTransitionActorType::Customer, $transition->actor_type);
        $this->assertSame($business->customer->user_id, $transition->actor_user_id);
        $this->assertNull($transition->opportunity_run_id);
        $this->assertNull($transition->action_execution_id);
        $this->assertSame('customer_configured_action', $transition->reason_code);
        $this->assertNull($transition->safe_note);
    }

    public function test_identical_configuration_returns_an_idempotent_no_op(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configurableOpportunity($business);
        $manager = app(OpportunityManager::class);

        $first = $manager->configureAction($opportunity, $business->customer, ['value' => '+15551234567']);
        $second = $manager->configureAction($opportunity, $business->customer, ['value' => '+15551234567']);

        $this->assertSame(
            CanonicalJson::encode($first->recommended_action),
            CanonicalJson::encode($second->recommended_action),
        );
        $this->assertSame($first->recommended_action_hash, $second->recommended_action_hash);
    }

    public function test_identical_configuration_creates_no_second_transition(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configurableOpportunity($business);
        $manager = app(OpportunityManager::class);

        $manager->configureAction($opportunity, $business->customer, ['value' => '+15551234567']);
        $manager->configureAction($opportunity, $business->customer, ['value' => '+15551234567']);

        $this->assertSame(1, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
    }

    public function test_freshness_remains_unchanged(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configurableOpportunity($business, [
            'freshness' => OpportunityFreshness::Stale->value,
        ]);
        $manager = app(OpportunityManager::class);

        $updated = $manager->configureAction($opportunity, $business->customer, ['value' => '+15551234567']);

        $this->assertSame(OpportunityFreshness::Stale, $updated->freshness);
    }

    public function test_occurrence_number_remains_unchanged(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configurableOpportunity($business, ['occurrence_number' => 3]);
        $manager = app(OpportunityManager::class);

        $updated = $manager->configureAction($opportunity, $business->customer, ['value' => '+15551234567']);

        $this->assertSame(3, $updated->occurrence_number);
    }

    public function test_action_schema_version_remains_unchanged(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configurableOpportunity($business, ['action_schema_version' => 1]);
        $manager = app(OpportunityManager::class);

        $updated = $manager->configureAction($opportunity, $business->customer, ['value' => '+15551234567']);

        $this->assertSame(1, $updated->action_schema_version);
    }

    public function test_workflow_timestamps_and_unrelated_fields_remain_unchanged(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configurableOpportunity($business, [
            'stale_at' => now()->subDay(),
        ]);
        $originalFirstDetectedAt = $opportunity->first_detected_at;
        $originalLastConfirmedAt = $opportunity->last_confirmed_at;
        $originalLastConfirmedRunId = $opportunity->last_confirmed_run_id;
        $originalStaleAt = $opportunity->stale_at;
        $originalTitle = $opportunity->title;
        $originalSummary = $opportunity->summary;
        $originalEvidence = $opportunity->evidence;
        $originalPriorityScore = $opportunity->priority_score;
        $originalFingerprint = $opportunity->fingerprint;
        $manager = app(OpportunityManager::class);

        $updated = $manager->configureAction($opportunity, $business->customer, ['value' => '+15551234567']);

        $this->assertNull($updated->snoozed_until);
        $this->assertNull($updated->dismissed_at);
        $this->assertNull($updated->completed_at);
        $this->assertTrue($originalStaleAt->equalTo($updated->stale_at));
        $this->assertTrue($originalFirstDetectedAt->equalTo($updated->first_detected_at));
        $this->assertTrue($originalLastConfirmedAt->equalTo($updated->last_confirmed_at));
        $this->assertSame($originalLastConfirmedRunId, $updated->last_confirmed_run_id);
        $this->assertSame($originalTitle, $updated->title);
        $this->assertSame($originalSummary, $updated->summary);
        $this->assertSame($originalEvidence, $updated->evidence);
        $this->assertSame($originalPriorityScore, $updated->priority_score);
        $this->assertSame($originalFingerprint, $updated->fingerprint);
    }

    public function test_another_customers_opportunity_rejects_atomically(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configurableOpportunity($business);
        $strangerBusiness = $this->createBusinessForOpportunities();
        $originalAction = $opportunity->recommended_action;
        $originalHash = $opportunity->recommended_action_hash;

        try {
            $manager = app(OpportunityManager::class);
            $this->expectException(AuthorizationException::class);

            $manager->configureAction($opportunity, $strangerBusiness->customer, ['value' => '+15551234567']);
        } finally {
            $opportunity->refresh();
            $this->assertSame(
                CanonicalJson::encode($originalAction),
                CanonicalJson::encode($opportunity->recommended_action),
            );
            $this->assertSame($originalHash, $opportunity->recommended_action_hash);
            $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
        }
    }

    public function test_a_mismatched_supplied_business_id_cannot_bypass_ownership(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configurableOpportunity($business);
        $strangerBusiness = $this->createBusinessForOpportunities();
        $opportunity->business_id = $strangerBusiness->id;

        $manager = app(OpportunityManager::class);

        $this->expectException(AuthorizationException::class);

        $manager->configureAction($opportunity, $strangerBusiness->customer, ['value' => '+15551234567']);
    }

    public function test_missing_opportunity_rejects_safely(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configurableOpportunity($business);
        $opportunity->delete();

        $manager = app(OpportunityManager::class);

        $this->expectException(AuthorizationException::class);

        $manager->configureAction($opportunity, $business->customer, ['value' => '+15551234567']);
    }

    public function test_awaiting_approval_rejects(): void
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

    public function test_dismissed_rejects(): void
    {
        $this->assertStatusRejects(OpportunityStatus::Dismissed);
    }

    public function test_completed_rejects(): void
    {
        $this->assertStatusRejects(OpportunityStatus::Completed);
    }

    /**
     * A delegating OpportunityRepository (not a partial mock): every method
     * except update() delegates to the real, fully initialized repository;
     * update() is forced to throw after the hash has already been computed,
     * rolling back the whole transaction before any write is committed.
     */
    public function test_rollback_after_update_leaves_action_and_hash_unchanged_and_creates_no_transition(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configurableOpportunity($business);
        $originalAction = $opportunity->recommended_action;
        $originalHash = $opportunity->recommended_action_hash;

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
            $manager->configureAction($opportunity, $business->customer, ['value' => '+15551234567']);
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught);
        $this->assertSame('Forced rollback.', $caught->getMessage());

        $opportunity->refresh();
        $this->assertSame(
            CanonicalJson::encode($originalAction),
            CanonicalJson::encode($opportunity->recommended_action),
        );
        $this->assertSame($originalHash, $opportunity->recommended_action_hash);
        $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
    }

    public function test_no_action_execution_row_is_created(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configurableOpportunity($business);
        $manager = app(OpportunityManager::class);

        $manager->configureAction($opportunity, $business->customer, ['value' => '+15551234567']);

        $this->assertSame(0, OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count());
    }

    public function test_no_event_or_job_is_dispatched(): void
    {
        Event::fake(self::KNOWN_OPPORTUNITY_EVENTS);
        Queue::fake();

        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configurableOpportunity($business);
        $manager = app(OpportunityManager::class);

        $manager->configureAction($opportunity, $business->customer, ['value' => '+15551234567']);

        foreach (self::KNOWN_OPPORTUNITY_EVENTS as $eventClass) {
            Event::assertNotDispatched($eventClass);
        }

        Queue::assertNothingPushed();
    }

    private function configurableOpportunity(Business $business, array $overrides = []): Opportunity
    {
        $initialAction = [
            'schema_version' => 1,
            'action_key' => 'add_phone',
            'parameters' => [],
            'approval_required' => true,
            'completion_policy' => 'system_verified',
        ];

        return $this->createOpportunity($business, array_merge([
            'status' => OpportunityStatus::Open->value,
            'recommended_action' => $initialAction,
            'recommended_action_hash' => (new OpportunityActionHash())->compute($initialAction),
            'action_schema_version' => 1,
        ], $overrides));
    }

    private function assertParametersRejectAtomically(array $parameters, string $exceptionClass): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configurableOpportunity($business);

        $this->assertConfigureRejectsAtomically($opportunity, $business, $parameters, $exceptionClass);
    }

    private function assertConfigureRejectsAtomically(Opportunity $opportunity, Business $business, array $parameters, string $exceptionClass): void
    {
        $originalAction = $opportunity->recommended_action;
        $originalHash = $opportunity->recommended_action_hash;
        $manager = app(OpportunityManager::class);

        try {
            $this->expectException($exceptionClass);

            $manager->configureAction($opportunity, $business->customer, $parameters);
        } finally {
            $opportunity->refresh();
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
        $opportunity = $this->configurableOpportunity($business, ['status' => $status->value]);
        $originalAction = $opportunity->recommended_action;
        $originalHash = $opportunity->recommended_action_hash;
        $manager = app(OpportunityManager::class);

        try {
            $this->expectException(InvalidOpportunityStateException::class);

            $manager->configureAction($opportunity, $business->customer, ['value' => '+15551234567']);
        } finally {
            $opportunity->refresh();
            $this->assertSame($status, $opportunity->status);
            $this->assertSame(
                CanonicalJson::encode($originalAction),
                CanonicalJson::encode($opportunity->recommended_action),
            );
            $this->assertSame($originalHash, $opportunity->recommended_action_hash);
            $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
        }
    }
}

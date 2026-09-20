<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use App\Enums\Opportunity\OpportunityActionExecutionStatus;
use App\Enums\Opportunity\OpportunityInitiatedByType;
use App\Enums\Opportunity\OpportunityStatus;
use App\Jobs\Opportunity\ExecuteOpportunityAction;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Opportunity\Exceptions\OpportunityConfirmingPrincipalNotHumanException;
use App\Library\Opportunity\Exceptions\OpportunityApprovalExpiredException;
use App\Library\Opportunity\Exceptions\OpportunityLocationAccessRevokedException;
use App\Library\Opportunity\Exceptions\OpportunityEngineDisabledException;
use App\Library\Opportunity\Exceptions\OpportunityPaidEffectEstimateMissingException;
use App\Library\Opportunity\Exceptions\OpportunityRetryRequiresReapprovalException;
use App\Library\Opportunity\OpportunityActionExecutor;
use App\Library\Opportunity\OpportunityActionHash;
use App\Library\Opportunity\OpportunityActionRegistry;
use App\Library\Opportunity\OpportunityAuthorityGuard;
use App\Library\Opportunity\OpportunityManager;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Opportunity;
use App\Models\OpportunityActionExecution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\TestCase;

class OpportunityApprovalLifecycleHardeningTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('opportunity.enabled', true);
    }

    public function test_capability_revoked_after_approval_refuses_execution_without_effect(): void
    {
        [$business, , $execution] = $this->approved();
        $business->customer->update(['permissions' => json_encode([])]);

        $this->runExecution($execution);

        $this->assertRefusedWithoutEffect($business, $execution);
    }

    public function test_entitlement_revoked_after_approval_refuses_execution_without_effect(): void
    {
        [$business, , $execution] = $this->approved();
        $admin = User::query()->where('is_admin', true)->firstOrFail();
        app(EntitlementManager::class)->createOrChangeOverride(
            $business->workspace,
            PlatformFeature::AiCooBasic,
            WorkspaceEntitlementOverrideState::Deny,
            (int) $admin->id,
            '19D revocation test.',
        );

        $this->runExecution($execution);

        $this->assertRefusedWithoutEffect($business, $execution);
    }

    public function test_changed_action_payload_refuses_execution_without_effect(): void
    {
        [$business, $opportunity, $execution] = $this->approved();
        $action = $opportunity->recommended_action;
        $action['parameters']['value'] = '+15550000000';
        $opportunity->update(['recommended_action' => $action]);

        $this->runExecution($execution);

        $this->assertRefusedWithoutEffect($business, $execution);
    }

    public function test_expired_approval_refuses_execution_and_can_be_requested_again(): void
    {
        [$business, $opportunity, $execution] = $this->approved();
        $execution->update(['approval_expires_at' => now()->subMinute()]);

        $this->runExecution($execution);

        $this->assertRefusedWithoutEffect($business, $execution);
        $this->assertSame(OpportunityStatus::Open, $opportunity->fresh()->status);
        $requested = app(OpportunityManager::class)->requestApproval($opportunity, $business->customer);
        $this->assertSame(OpportunityStatus::AwaitingApproval, $requested->status);
    }

    public function test_expired_unconfirmed_approval_returns_to_open_with_a_transition(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configured($business);
        $manager = app(OpportunityManager::class);
        $manager->requestApproval($opportunity, $business->customer);
        $opportunity->update(['approval_expires_at' => now()->subMinute()]);

        try {
            $manager->confirmApproval($opportunity, $business->customer);
            $this->fail('Expired approval was confirmed.');
        } catch (OpportunityApprovalExpiredException) {
            $this->assertSame(OpportunityStatus::Open, $opportunity->fresh()->status);
            $this->assertNull($opportunity->fresh()->approval_expires_at);
            $this->assertDatabaseHas('opportunity_transitions', [
                'opportunity_id' => $opportunity->id,
                'reason_code' => 'approval_expired',
                'to_status' => 'open',
            ]);
            $this->assertSame(0, OpportunityActionExecution::query()->where('opportunity_id', $opportunity->id)->count());
        }
    }

    public function test_location_authority_is_read_again_for_a_location_bound_definition(): void
    {
        $business = $this->createBusinessForOpportunities();
        $location = BusinessLocation::create([
            'business_id' => $business->id,
            'service_mode' => 'storefront',
            'country_code' => 'US',
        ]);
        $opportunity = $this->createOpportunity($business, [
            'recommended_action' => ['parameters' => ['business_location_id' => $location->id]],
        ]);
        $guard = app(OpportunityAuthorityGuard::class);
        $actionKey = 'unregistered_location_action';
        $this->assertTrue(OpportunityActionRegistry::isLocationBound($actionKey));

        $guard->assertLocationAccess($opportunity, (int) $business->customer->user_id, $actionKey);
        $location->delete();

        $this->expectException(OpportunityLocationAccessRevokedException::class);
        $guard->assertLocationAccess($opportunity, (int) $business->customer->user_id, $actionKey);
    }

    public function test_failed_mutating_execution_cannot_retry_under_original_approval(): void
    {
        [$business, $opportunity, $execution] = $this->approved();
        $execution->update(['approval_expires_at' => now()->subMinute()]);
        $this->runExecution($execution);

        $this->expectException(OpportunityRetryRequiresReapprovalException::class);
        app(OpportunityManager::class)->retryFailedExecution($opportunity, $business->customer);
    }

    public function test_coo_proposal_human_confirmation_executes_once(): void
    {
        [$business, $opportunity, $execution] = $this->approved(OpportunityInitiatedByType::Coo);
        $this->assertSame('coo', $execution->initiated_by_type);
        $this->assertSame('customer', $execution->confirmed_by_type);
        $this->assertSame($business->customer->user_id, $execution->confirmed_by_user_id);

        $this->runExecution($execution);
        $this->runExecution($execution);

        $this->assertSame('+15551234567', $business->fresh()->phone);
        $this->assertSame(OpportunityActionExecutionStatus::Succeeded, $execution->fresh()->status);
        $this->assertNotNull($execution->fresh()->started_at);
        $this->assertSame(1, OpportunityActionExecution::query()->where('opportunity_id', $opportunity->id)->count());
    }

    public function test_missing_human_confirmer_refuses_execution(): void
    {
        [$business, , $execution] = $this->approved();
        $execution->update(['confirmed_by_user_id' => null]);

        $this->runExecution($execution);

        $this->assertRefusedWithoutEffect($business, $execution);
    }

    public function test_coo_confirming_principal_is_refused_even_for_human_proposal(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configured($business);
        $manager = app(OpportunityManager::class);
        $manager->requestApproval($opportunity, $business->customer);

        $this->expectException(OpportunityConfirmingPrincipalNotHumanException::class);
        $manager->confirmApproval($opportunity, $business->customer, OpportunityInitiatedByType::Coo);
    }

    public function test_paid_effect_without_snapshot_fails_closed(): void
    {
        [$business, $opportunity, $execution] = $this->approved();
        $this->assertTrue(OpportunityActionRegistry::hasPaidEffect('unregistered_paid_action'));

        $this->expectException(OpportunityPaidEffectEstimateMissingException::class);
        app(OpportunityAuthorityGuard::class)->assertPaidEffectIsCovered($opportunity, 'unregistered_paid_action', $execution);
    }

    public function test_kill_switch_refuses_all_four_lifecycle_entry_points(): void
    {
        [$business, $opportunity, $execution] = $this->approved();
        config()->set('opportunity.enabled', false);
        $manager = app(OpportunityManager::class);

        foreach ([
            fn () => $manager->requestApproval($opportunity, $business->customer),
            fn () => $manager->confirmApproval($opportunity, $business->customer),
            fn () => $manager->beginExecutionAttempt($execution),
            fn () => $manager->retryFailedExecution($opportunity, $business->customer),
        ] as $entry) {
            try {
                $entry();
                $this->fail('The disabled engine admitted a lifecycle entry.');
            } catch (OpportunityEngineDisabledException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(OpportunityActionExecutionStatus::Pending, $execution->fresh()->status);
        $this->assertNull($business->fresh()->phone);
    }

    /** @return array{Business, Opportunity, OpportunityActionExecution} */
    private function approved(OpportunityInitiatedByType $proposer = OpportunityInitiatedByType::Customer): array
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configured($business);
        $manager = app(OpportunityManager::class);
        $manager->requestApproval($opportunity, $business->customer, $proposer);
        $execution = $manager->confirmApproval($opportunity, $business->customer);

        return [$business, $opportunity, $execution];
    }

    private function configured(Business $business): Opportunity
    {
        $action = [
            'schema_version' => 1,
            'action_key' => 'add_phone',
            'parameters' => ['value' => '+15551234567'],
            'approval_required' => true,
            'completion_policy' => 'system_verified',
        ];

        return $this->createOpportunity($business, [
            'recommended_action' => $action,
            'recommended_action_hash' => (new OpportunityActionHash())->compute($action),
            'action_schema_version' => 1,
        ]);
    }

    private function runExecution(OpportunityActionExecution $execution): void
    {
        (new ExecuteOpportunityAction((int) $execution->id))->handle(
            app(OpportunityManager::class),
            app(OpportunityActionExecutor::class),
        );
    }

    private function assertRefusedWithoutEffect(Business $business, OpportunityActionExecution $execution): void
    {
        $this->assertNull($business->fresh()->phone);
        $this->assertSame(OpportunityActionExecutionStatus::Failed, $execution->fresh()->status);
        $this->assertNull($execution->fresh()->started_at);
    }
}

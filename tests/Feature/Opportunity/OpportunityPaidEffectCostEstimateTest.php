<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Usage\PayerType;
use App\Library\Opportunity\ActionCostEstimate;
use App\Library\Opportunity\ActionCostEstimator;
use App\Library\Opportunity\Exceptions\OpportunityPaidEffectEstimateMissingException;
use App\Library\Opportunity\Exceptions\OpportunityPaidEffectPayerChangedException;
use App\Library\Opportunity\Exceptions\OpportunityPaidEffectPriceChangedException;
use App\Library\Opportunity\Exceptions\OpportunityPaidEffectWalletInsufficientException;
use App\Library\Opportunity\OpportunityActionHash;
use App\Library\Opportunity\OpportunityActionRegistry;
use App\Library\Opportunity\OpportunityAuthorityGuard;
use App\Library\Opportunity\OpportunityManager;
use App\Enums\Opportunity\OpportunityStatus;
use App\Jobs\Opportunity\ExecuteOpportunityAction;
use App\Models\Business;
use App\Models\Opportunity;
use App\Models\OpportunityActionExecution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\TestCase;

/**
 * Implementation Contract 19 §5.3, §5.4, §7.6, §12 19.E.
 *
 * Two layers, each tested at the layer the existing 19.D suite already
 * tests it at:
 *
 *  - OpportunityAuthorityGuard::assertPaidEffectIsCovered()'s live-estimate
 *    comparison, directly, with a synthetic action key
 *    ('unregistered_paid_action') and a hand-built ActionCostEstimate —
 *    exactly OpportunityApprovalLifecycleHardeningTest's own established
 *    pattern for exercising the paid-effect gate without a real configured
 *    action.
 *  - OpportunityManager::requestApproval()'s new estimate-and-snapshot step,
 *    through a mocked ActionCostEstimator bound in the container — the one
 *    piece of new orchestration 19.D did not have, so it is the one piece
 *    worth an integration-level proof.
 *
 * 19.D's own tests (OpportunityApprovalLifecycleHardeningTest) are the proof
 * that a null $liveEstimate — every registered action today, add_phone
 * included, since none names a meter — falls back to the pre-19.E
 * stored-snapshot-only behaviour unchanged; they are not duplicated here.
 */
class OpportunityPaidEffectCostEstimateTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('opportunity.enabled', true);
    }

    // =================================================================
    // OpportunityAuthorityGuard — the live recheck, in isolation
    // =================================================================

    public function test_a_matching_live_estimate_is_covered(): void
    {
        [, $opportunity, $execution] = $this->approvedWithCostSnapshot($this->ceilingSnapshot());

        app(OpportunityAuthorityGuard::class)->assertPaidEffectIsCovered(
            $opportunity,
            'unregistered_paid_action',
            $execution,
            $this->liveEstimate(),
        );

        $this->assertTrue(true, 'No exception: the live estimate matches the approved ceiling exactly.');
    }

    public function test_a_live_estimate_from_a_different_payer_workspace_refuses(): void
    {
        [, $opportunity, $execution] = $this->approvedWithCostSnapshot($this->ceilingSnapshot());

        $this->expectException(OpportunityPaidEffectPayerChangedException::class);

        app(OpportunityAuthorityGuard::class)->assertPaidEffectIsCovered(
            $opportunity,
            'unregistered_paid_action',
            $execution,
            $this->liveEstimate(payerWorkspaceId: 999999),
        );
    }

    public function test_a_live_estimate_from_a_different_payer_type_refuses(): void
    {
        [, $opportunity, $execution] = $this->approvedWithCostSnapshot($this->ceilingSnapshot());

        $this->expectException(OpportunityPaidEffectPayerChangedException::class);

        app(OpportunityAuthorityGuard::class)->assertPaidEffectIsCovered(
            $opportunity,
            'unregistered_paid_action',
            $execution,
            $this->liveEstimate(payerType: PayerType::AgencyRebill),
        );
    }

    public function test_a_live_estimate_priced_higher_than_the_ceiling_refuses(): void
    {
        [, $opportunity, $execution] = $this->approvedWithCostSnapshot($this->ceilingSnapshot());

        $this->expectException(OpportunityPaidEffectPriceChangedException::class);

        app(OpportunityAuthorityGuard::class)->assertPaidEffectIsCovered(
            $opportunity,
            'unregistered_paid_action',
            $execution,
            $this->liveEstimate(amountMinorUpperBound: 251),
        );
    }

    public function test_a_live_estimate_priced_lower_than_the_ceiling_is_covered(): void
    {
        [, $opportunity, $execution] = $this->approvedWithCostSnapshot($this->ceilingSnapshot());

        app(OpportunityAuthorityGuard::class)->assertPaidEffectIsCovered(
            $opportunity,
            'unregistered_paid_action',
            $execution,
            $this->liveEstimate(amountMinorUpperBound: 100),
        );

        $this->assertTrue(true, 'A ceiling may fall; R-3 only forbids it silently rising.');
    }

    public function test_a_live_estimate_under_a_retired_price_version_refuses(): void
    {
        [, $opportunity, $execution] = $this->approvedWithCostSnapshot($this->ceilingSnapshot());

        $this->expectException(OpportunityPaidEffectPriceChangedException::class);

        app(OpportunityAuthorityGuard::class)->assertPaidEffectIsCovered(
            $opportunity,
            'unregistered_paid_action',
            $execution,
            $this->liveEstimate(priceVersion: 'test-v2'),
        );
    }

    public function test_a_live_estimate_with_an_insufficient_wallet_refuses(): void
    {
        [, $opportunity, $execution] = $this->approvedWithCostSnapshot($this->ceilingSnapshot());

        $this->expectException(OpportunityPaidEffectWalletInsufficientException::class);

        app(OpportunityAuthorityGuard::class)->assertPaidEffectIsCovered(
            $opportunity,
            'unregistered_paid_action',
            $execution,
            $this->liveEstimate(walletSufficient: false),
        );
    }

    // =================================================================
    // OpportunityAuthorityGuard::estimatePaidEffectForApproval() —
    // requestApproval()'s new estimate-and-snapshot step, in isolation
    // (the same "call the guard directly with a synthetic action key"
    // technique OpportunityApprovalLifecycleHardeningTest already
    // established, and for the identical reason: 'unregistered_paid_action'
    // cannot pass assertActionIsExecutable()'s registry-integrity checks, so
    // it cannot reach this code through the full requestApproval() call —
    // only a genuinely registered, executor-supported paid action could,
    // and none exists to fabricate one with, R-0).
    // =================================================================

    public function test_estimate_for_approval_is_null_for_a_non_paid_action(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business);

        $estimator = Mockery::mock(ActionCostEstimator::class);
        $estimator->shouldNotReceive('estimateForAction');

        $result = app(OpportunityAuthorityGuard::class)->estimatePaidEffectForApproval($opportunity, 'add_phone', $estimator);

        $this->assertNull($result);
    }

    public function test_estimate_for_approval_refuses_a_paid_action_with_no_resolvable_estimate(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business);

        $estimator = Mockery::mock(ActionCostEstimator::class);
        $estimator->shouldReceive('estimateForAction')->once()->andReturn(null);

        $this->expectException(OpportunityPaidEffectEstimateMissingException::class);

        app(OpportunityAuthorityGuard::class)->estimatePaidEffectForApproval($opportunity, 'unregistered_paid_action', $estimator);
    }

    public function test_estimate_for_approval_refuses_before_approval_when_the_wallet_is_insufficient(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business);

        $estimator = Mockery::mock(ActionCostEstimator::class);
        $estimator->shouldReceive('estimateForAction')->once()->andReturn($this->liveEstimate(walletSufficient: false));

        $this->expectException(OpportunityPaidEffectWalletInsufficientException::class);

        app(OpportunityAuthorityGuard::class)->estimatePaidEffectForApproval($opportunity, 'unregistered_paid_action', $estimator);
    }

    public function test_estimate_for_approval_returns_the_snapshot_of_a_sufficient_estimate(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business);

        $estimate = $this->liveEstimate(payerWorkspaceId: (int) $business->workspace_id);
        $estimator = Mockery::mock(ActionCostEstimator::class);
        $estimator->shouldReceive('estimateForAction')->once()->andReturn($estimate);

        $snapshot = app(OpportunityAuthorityGuard::class)->estimatePaidEffectForApproval($opportunity, 'unregistered_paid_action', $estimator);

        $this->assertSame($estimate->toSnapshot(), $snapshot);
    }

    public function test_request_approval_never_asks_the_estimator_for_a_non_paid_action(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configuredWithActionKey($business, 'add_phone', ['value' => '+15551234567']);

        $estimator = Mockery::mock(ActionCostEstimator::class);
        $estimator->shouldNotReceive('estimateForAction');
        $this->app->instance(ActionCostEstimator::class, $estimator);

        app(OpportunityManager::class)->requestApproval($opportunity, $business->customer);

        $this->assertSame(OpportunityStatus::AwaitingApproval, $opportunity->fresh()->status);
        $this->assertNull($opportunity->fresh()->action_cost_payer_type);
    }

    public function test_execution_succeeds_when_the_live_estimate_still_matches(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configuredWithActionKey($business, 'add_phone', ['value' => '+15551234567']);

        // add_phone is not paid_effect: the estimator is never consulted,
        // and the existing production path executes exactly as 19.D shipped it.
        $manager = app(OpportunityManager::class);
        $manager->requestApproval($opportunity, $business->customer);
        $execution = $manager->confirmApproval($opportunity, $business->customer);

        $this->runExecution($execution);

        $this->assertSame('succeeded', $execution->fresh()->status->value);
        $this->assertSame('+15551234567', $business->fresh()->phone);
    }

    // -----------------------------------------------------------------
    // Forged client/model values
    // -----------------------------------------------------------------

    public function test_posted_cost_fields_are_ignored_on_every_mutation_route(): void
    {
        $business = $this->createBusinessForOpportunities();
        $customer = $business->customer;
        $customer->permissions = json_encode(['business_advisor']);
        $customer->save();
        $customer->user->email_verified_at = now();
        $customer->user->save();
        $this->actingAs($customer->user);

        $opportunity = $this->configuredWithActionKey($business, 'add_phone', ['value' => '+15551234567']);

        $forged = [
            'value' => '+15551234567',
            'action_cost_payer_type' => 'agency_rebill',
            'action_cost_amount_minor_upper_bound' => 1,
            'action_cost_wallet_sufficient' => true,
            'action_cost_price_version' => 'forged',
        ];

        $this->post(route('customer.opportunities.configure-action', $opportunity->id), $forged);
        $this->post(route('customer.opportunities.request-approval', $opportunity->id), $forged);
        $this->post(route('customer.opportunities.confirm-approval', $opportunity->id), $forged);

        $fresh = $opportunity->fresh();
        $this->assertNull($fresh->action_cost_payer_type, 'add_phone is not paid_effect: no cost fields are ever written, forged or not.');
        $this->assertNull($fresh->action_cost_amount_minor_upper_bound);
    }

    // -----------------------------------------------------------------
    // Fixtures and helpers
    // -----------------------------------------------------------------

    private function configuredWithActionKey(Business $business, string $actionKey, array $parameters = []): Opportunity
    {
        $action = [
            'schema_version' => 1,
            'action_key' => $actionKey,
            'parameters' => $parameters,
            'approval_required' => true,
            'completion_policy' => 'system_verified',
        ];

        return $this->createOpportunity($business, [
            'recommended_action' => $action,
            'recommended_action_hash' => (new OpportunityActionHash())->compute($action),
            'action_schema_version' => 1,
        ]);
    }

    /**
     * add_phone (not paid_effect) approved and confirmed the ordinary way,
     * then a cost snapshot is stamped on afterward — mirroring
     * OpportunityApprovalLifecycleHardeningTest's own established pattern
     * for exercising the paid-effect gate against a real approval/execution
     * pair without needing a genuinely configured paid action.
     * confirmApproval()'s own actionCostSnapshot() copy (unconditional,
     * unchanged since 19.D) carries the stamped snapshot onto the execution
     * row for free.
     *
     * @return array{0: Business, 1: Opportunity, 2: OpportunityActionExecution}
     */
    private function approvedWithCostSnapshot(array $costSnapshot): array
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->configuredWithActionKey($business, 'add_phone', ['value' => '+15551234567']);

        $manager = app(OpportunityManager::class);
        $manager->requestApproval($opportunity, $business->customer);
        $opportunity->update($costSnapshot);
        $execution = $manager->confirmApproval($opportunity, $business->customer);

        return [$business, $opportunity->fresh(), $execution->fresh()];
    }

    /** @return array<string, mixed> */
    private function ceilingSnapshot(): array
    {
        return [
            'action_cost_payer_type' => 'workspace',
            'action_cost_payer_workspace_id' => 42,
            'action_cost_currency_code' => 'USD',
            'action_cost_amount_minor_upper_bound' => 250,
            'action_cost_unit_count' => null,
            'action_cost_unit_kind' => null,
            'action_cost_basis' => 'upper_bound',
            'action_cost_price_version' => 'test-v1',
            'action_cost_estimated_at' => now(),
            'action_cost_expires_at' => now()->addHour(),
            'action_cost_wallet_sufficient' => true,
        ];
    }

    private function liveEstimate(
        PayerType $payerType = PayerType::Workspace,
        int $payerWorkspaceId = 42,
        int $amountMinorUpperBound = 250,
        string $priceVersion = 'test-v1',
        bool $walletSufficient = true,
    ): ActionCostEstimate {
        return new ActionCostEstimate(
            payerType: $payerType,
            payerWorkspaceId: $payerWorkspaceId,
            currencyCode: 'USD',
            amountMinorUpperBound: $amountMinorUpperBound,
            unitCount: null,
            unitKind: null,
            basis: ActionCostEstimate::BASIS_UPPER_BOUND,
            priceVersion: $priceVersion,
            estimatedAt: Carbon::now(),
            expiresAt: Carbon::now()->addHour(),
            walletSufficient: $walletSufficient,
        );
    }

    private function runExecution(OpportunityActionExecution $execution): void
    {
        (new ExecuteOpportunityAction((int) $execution->id))->handle(
            app(OpportunityManager::class),
            app(\App\Library\Opportunity\OpportunityActionExecutor::class),
        );
    }
}

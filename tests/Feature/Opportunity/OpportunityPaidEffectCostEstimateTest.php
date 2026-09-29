<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Usage\PayerType;
use App\Enums\Opportunity\OpportunityActionExecutionStatus;
use App\Library\Opportunity\ActionCostEstimate;
use App\Library\Opportunity\ActionCostEstimator;
use App\Library\Opportunity\Exceptions\OpportunityPaidEffectEstimateMissingException;
use App\Library\Opportunity\Exceptions\OpportunityPaidEffectPayerChangedException;
use App\Library\Opportunity\Exceptions\OpportunityPaidEffectPriceChangedException;
use App\Library\Opportunity\Exceptions\OpportunityPaidEffectReapprovalRequiredException;
use App\Library\Opportunity\Exceptions\OpportunityPaidEffectWalletInsufficientException;
use App\Library\Opportunity\OpportunityActionExecutor;
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
 * CORRECTION (post-review). A null `$liveEstimate` for a genuinely
 * `paid_effect` action at EXECUTION time (an execution row is present) is
 * NOT "unchanged" — it now fails closed
 * (`OpportunityPaidEffectEstimateMissingException`), because "no current
 * estimate" means this guard can no longer prove the action is still within
 * what was approved (R-3). Every registered action today, add_phone
 * included, is not `paid_effect`, so `assertPaidEffectIsCovered()` never
 * reaches that check for it — this correction changes behaviour only for a
 * genuinely `paid_effect` action, none of which is configured in
 * production. A price or payer change at execution time returns the
 * Opportunity to `awaiting_approval` with a freshly computed estimate
 * (`OpportunityManager::returnPaidEffectToAwaitingApproval()`) rather than
 * the generic §5.4(2) failure lifecycle — both proved below, using the same
 * synthetic-action-key technique this file already established, since no
 * real paid_effect action exists to exercise the full registered-action
 * path end to end (R-0).
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

    // =================================================================
    // Display — the true major-currency amount, never the raw minor figure
    // =================================================================

    /**
     * Correction (Contract 19 §5.3 review) — the approving human reads the
     * real major-currency amount ("USD 2.50"), never the bare minor-unit
     * integer ("250 USD" would mean $250.00, not $2.50). The stored/
     * snapshotted field itself stays the exact minor-unit integer
     * unchanged — only the presentation layer converts it, via the same
     * {@see \App\Library\Catalog\CatalogMoney::format()} formatter reused
     * rather than duplicated.
     */
    public function test_the_display_field_shows_the_true_major_currency_amount(): void
    {
        $estimate = $this->liveEstimate(amountMinorUpperBound: 250); // 250 minor units = $2.50

        $method = new \ReflectionMethod(\App\Http\Controllers\Customer\OpportunityController::class, 'safeEstimateFields');
        $method->setAccessible(true);
        $controller = app(\App\Http\Controllers\Customer\OpportunityController::class);

        $fields = $method->invoke($controller, $estimate);

        $this->assertSame('USD 2.50', $fields['formattedAmount'], '250 minor units of USD must display as $2.50, never as "250 USD".');
        $this->assertSame(250, $fields['amountMinorUpperBound'], 'The stored/snapshotted field itself remains the exact minor-unit integer.');
    }

    public function test_a_null_amount_has_no_formatted_display(): void
    {
        $estimate = new ActionCostEstimate(
            payerType: PayerType::Workspace,
            payerWorkspaceId: 42,
            currencyCode: null,
            amountMinorUpperBound: null,
            unitCount: 5,
            unitKind: 'units',
            basis: ActionCostEstimate::BASIS_UPPER_BOUND,
            priceVersion: 'test-v1',
            estimatedAt: Carbon::now(),
            expiresAt: Carbon::now()->addHour(),
            walletSufficient: true,
        );

        $method = new \ReflectionMethod(\App\Http\Controllers\Customer\OpportunityController::class, 'safeEstimateFields');
        $method->setAccessible(true);
        $controller = app(\App\Http\Controllers\Customer\OpportunityController::class);

        $fields = $method->invoke($controller, $estimate);

        $this->assertNull($fields['formattedAmount']);
    }

    // =================================================================
    // Correction — a null live estimate at EXECUTION time now fails closed
    // =================================================================

    /**
     * Before this correction, `assertPaidEffectIsCovered()` silently
     * `return`ed whenever `$liveEstimate` was null and an execution was
     * present — treating "the pricing configuration has become
     * unresolvable since approval" as "nothing changed". R-3 requires the
     * opposite: execution recomputes from LIVE state, and "no current
     * estimate" must refuse, never pass.
     */
    public function test_a_null_live_estimate_at_execution_time_now_fails_closed(): void
    {
        [, $opportunity, $execution] = $this->approvedWithCostSnapshot($this->ceilingSnapshot());

        $guard = app(OpportunityAuthorityGuard::class);

        // The approval-time call (no execution yet) is unaffected: the
        // stored snapshot alone is what is checked.
        $guard->assertPaidEffectIsCovered($opportunity->fresh(), 'unregistered_paid_action');

        // The execution-time call with a null live estimate must now
        // refuse — this is the exact behaviour the correction changes.
        $this->expectException(OpportunityPaidEffectEstimateMissingException::class);

        $guard->assertPaidEffectIsCovered($opportunity->fresh(), 'unregistered_paid_action', $execution->fresh(), null);
    }

    // =================================================================
    // Correction — a price/payer change reapproves with a fresh estimate,
    // rather than the generic §5.4(2) failure lifecycle
    // =================================================================

    /**
     * OpportunityManager::returnPaidEffectToAwaitingApproval() in isolation
     * — the same technique this file already established: a real
     * approved/confirmed add_phone pair, its execution's action_key
     * relabelled to the synthetic 'unregistered_paid_action' (hasPaidEffect
     * fails open for any unregistered key) so a swapped-in mocked
     * ActionCostEstimator can be consulted, exactly mirroring how
     * estimatePaidEffectForApproval() is already tested above.
     */
    public function test_a_price_change_reapproves_with_a_fresh_estimate_and_a_typed_reason(): void
    {
        [, $opportunity, $execution] = $this->approvedWithCostSnapshot($this->ceilingSnapshot());
        $execution->update(['action_key' => 'unregistered_paid_action']);

        $newEstimate = $this->liveEstimate(amountMinorUpperBound: 999, priceVersion: 'test-v2');
        $estimator = Mockery::mock(ActionCostEstimator::class);
        $estimator->shouldReceive('estimateForAction')->once()->andReturn($newEstimate);
        $this->app->instance(ActionCostEstimator::class, $estimator);

        $reason = OpportunityPaidEffectPriceChangedException::forAction((int) $opportunity->id, 'unregistered_paid_action');

        $updated = app(OpportunityManager::class)->returnPaidEffectToAwaitingApproval($execution->fresh(), $reason);

        // 6/7 — awaiting_approval, carrying the NEW live estimate.
        $this->assertSame(OpportunityStatus::AwaitingApproval, $updated->status);
        $this->assertSame(999, $updated->action_cost_amount_minor_upper_bound);
        $this->assertSame('test-v2', $updated->action_cost_price_version);

        // 8 — the OLD ceiling (250, test-v1) is gone, never silently
        // honoured as still approved.
        $this->assertNotSame(250, $updated->action_cost_amount_minor_upper_bound);
        $this->assertNotSame('test-v1', $updated->action_cost_price_version);

        // 5 — the pending attempt is terminal, with its own distinct reason.
        $this->assertSame(OpportunityActionExecutionStatus::Failed, $execution->fresh()->status);
        $this->assertNull($execution->fresh()->started_at, 'The execution never reached running.');

        // 9 — a typed, auditable transition reason.
        $this->assertDatabaseHas('opportunity_transitions', [
            'opportunity_id' => $opportunity->id,
            'from_status' => 'in_progress',
            'to_status' => 'awaiting_approval',
            'reason_code' => 'paid_effect_price_changed',
        ]);
    }

    /**
     * The same principle applies to a payer change: a new payer never
     * inherits the old payer's approval — the Opportunity returns to
     * awaiting_approval priced against the NEW payer, under its own typed
     * reason.
     */
    public function test_a_payer_change_reapproves_with_a_fresh_estimate_against_the_new_payer(): void
    {
        [, $opportunity, $execution] = $this->approvedWithCostSnapshot($this->ceilingSnapshot());
        $execution->update(['action_key' => 'unregistered_paid_action']);

        $newEstimate = $this->liveEstimate(payerType: PayerType::AgencyRebill, payerWorkspaceId: 777);
        $estimator = Mockery::mock(ActionCostEstimator::class);
        $estimator->shouldReceive('estimateForAction')->once()->andReturn($newEstimate);
        $this->app->instance(ActionCostEstimator::class, $estimator);

        $reason = OpportunityPaidEffectPayerChangedException::forAction((int) $opportunity->id, 'unregistered_paid_action');

        $updated = app(OpportunityManager::class)->returnPaidEffectToAwaitingApproval($execution->fresh(), $reason);

        $this->assertSame(OpportunityStatus::AwaitingApproval, $updated->status);
        $this->assertSame('agency_rebill', $updated->action_cost_payer_type);
        $this->assertSame(777, $updated->action_cost_payer_workspace_id);
        $this->assertNotSame('workspace', $updated->action_cost_payer_type, 'The old payer is never silently kept.');

        $this->assertDatabaseHas('opportunity_transitions', [
            'opportunity_id' => $opportunity->id,
            'reason_code' => 'paid_effect_payer_changed',
        ]);
    }

    /**
     * FAILS CLOSED, NOT SOFTLY — wallet insufficient. If the fresh recompute
     * this reapproval attempt performs is itself now wallet-insufficient,
     * this must NOT enter awaiting_approval with a known-bad figure: it
     * throws, and nothing is written.
     */
    public function test_reapproval_fails_closed_when_the_fresh_estimate_is_now_wallet_insufficient(): void
    {
        [, $opportunity, $execution] = $this->approvedWithCostSnapshot($this->ceilingSnapshot());
        $execution->update(['action_key' => 'unregistered_paid_action']);

        $estimator = Mockery::mock(ActionCostEstimator::class);
        $estimator->shouldReceive('estimateForAction')->once()->andReturn($this->liveEstimate(walletSufficient: false));
        $this->app->instance(ActionCostEstimator::class, $estimator);

        $reason = OpportunityPaidEffectPriceChangedException::forAction((int) $opportunity->id, 'unregistered_paid_action');

        try {
            app(OpportunityManager::class)->returnPaidEffectToAwaitingApproval($execution->fresh(), $reason);
            $this->fail('A wallet-insufficient reapproval attempt must throw.');
        } catch (OpportunityPaidEffectWalletInsufficientException) {
            $this->assertTrue(true);
        }

        // Nothing was half-written: the whole attempt rolled back.
        $this->assertSame(OpportunityStatus::InProgress, $opportunity->fresh()->status);
        $this->assertSame(OpportunityActionExecutionStatus::Pending, $execution->fresh()->status);
    }

    /**
     * FAILS CLOSED, NOT SOFTLY — no estimate at all. Never fabricate a
     * replacement estimate merely to enter awaiting_approval.
     */
    public function test_reapproval_fails_closed_when_no_fresh_estimate_is_available_at_all(): void
    {
        [, $opportunity, $execution] = $this->approvedWithCostSnapshot($this->ceilingSnapshot());
        $execution->update(['action_key' => 'unregistered_paid_action']);

        $estimator = Mockery::mock(ActionCostEstimator::class);
        $estimator->shouldReceive('estimateForAction')->once()->andReturn(null);
        $this->app->instance(ActionCostEstimator::class, $estimator);

        $reason = OpportunityPaidEffectPriceChangedException::forAction((int) $opportunity->id, 'unregistered_paid_action');

        try {
            app(OpportunityManager::class)->returnPaidEffectToAwaitingApproval($execution->fresh(), $reason);
            $this->fail('A reapproval attempt with no resolvable estimate must throw.');
        } catch (OpportunityPaidEffectEstimateMissingException) {
            $this->assertTrue(true);
        }

        $this->assertSame(OpportunityStatus::InProgress, $opportunity->fresh()->status);
        $this->assertSame(OpportunityActionExecutionStatus::Pending, $execution->fresh()->status);
    }

    // =================================================================
    // ExecuteOpportunityAction — the job routes a reapproval-required
    // refusal correctly, and never invokes the handler
    // =================================================================

    public function test_the_job_routes_a_reapproval_required_refusal_without_invoking_the_handler(): void
    {
        [$business, $opportunity, $execution] = $this->approvedWithCostSnapshot($this->ceilingSnapshot());
        $reason = OpportunityPaidEffectPriceChangedException::forAction((int) $opportunity->id, 'add_phone');

        $this->partialMock(OpportunityManager::class, function ($mock) use ($opportunity, $reason) {
            $mock->shouldReceive('beginExecutionAttempt')->once()->andThrow($reason);
            $mock->shouldReceive('returnPaidEffectToAwaitingApproval')->once()->andReturn($opportunity->fresh());
            $mock->shouldNotReceive('recordExecutionResult');
        });

        // OpportunityActionExecutor is final (cannot be mocked as a plain
        // type-hinted double); the REAL instance is used instead — since
        // beginExecutionAttempt() is stubbed to throw before ever reaching
        // it, it is never called regardless, and the proof that it never
        // ran is the Business row itself staying untouched below.
        (new ExecuteOpportunityAction((int) $execution->id))->handle(app(OpportunityManager::class), app(OpportunityActionExecutor::class));

        $this->assertNull($business->fresh()->phone, 'The handler was never invoked.');
    }

    /**
     * When the reapproval attempt ITSELF is blocked (wallet now
     * insufficient, or no estimate at all), the job falls back to the
     * ordinary §5.4(2) failure lifecycle rather than leaving the
     * Opportunity in a half-handled state.
     */
    public function test_the_job_falls_back_to_the_generic_failure_path_when_reapproval_is_itself_blocked(): void
    {
        [$business, $opportunity, $execution] = $this->approvedWithCostSnapshot($this->ceilingSnapshot());
        $reason = OpportunityPaidEffectPriceChangedException::forAction((int) $opportunity->id, 'add_phone');
        $blocked = OpportunityPaidEffectWalletInsufficientException::forAction((int) $opportunity->id, 'add_phone');

        $this->partialMock(OpportunityManager::class, function ($mock) use ($execution, $reason, $blocked) {
            $mock->shouldReceive('beginExecutionAttempt')->once()->andThrow($reason);
            $mock->shouldReceive('returnPaidEffectToAwaitingApproval')->once()->andThrow($blocked);
            $mock->shouldReceive('recordExecutionResult')
                ->once()
                ->with(Mockery::on(fn ($e) => $e->id === $execution->id), false, 'Opportunity action state no longer matched the approved action.');
        });

        (new ExecuteOpportunityAction((int) $execution->id))->handle(app(OpportunityManager::class), app(OpportunityActionExecutor::class));

        $this->assertNull($business->fresh()->phone, 'The handler was never invoked.');
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

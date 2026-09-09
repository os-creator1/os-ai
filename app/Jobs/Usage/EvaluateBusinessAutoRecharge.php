<?php

namespace App\Jobs\Usage;

use App\Enums\Usage\FundingAttemptPurpose;
use App\Jobs\Base;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Library\Usage\UsageWalletManager;
use App\Repositories\Contracts\BusinessFundingAttemptRepository;
use App\Repositories\Contracts\BusinessUsageWalletRepository;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

/**
 * M3 contract §15 — the RFC's own centralized after-commit auto-recharge
 * trigger, finally activated at M3 (dispatched from UsageWalletManager's
 * reserve()/commit() negative-available-delta sites, item 100). No
 * provider call occurs inside the wallet transaction that dispatched this
 * job — that transaction has already committed by the time this job's own
 * handle() runs. UsageBillingCheckoutManager is resolved lazily via
 * app(), never method-injected — Laravel resolves every type-hinted
 * handle() parameter eagerly before the method body runs, which would
 * otherwise construct the real (fail-closed) StripePaymentProviderGateway
 * on every dispatch, even the overwhelmingly common no-op case where
 * auto_recharge_enabled is false and no provider call is ever needed.
 */
class EvaluateBusinessAutoRecharge extends Base implements ShouldQueueAfterCommit
{
    public function __construct(
        private readonly int $businessId,
    ) {
    }

    public function handle(
        BusinessUsageWalletRepository $walletRepository,
        BusinessFundingAttemptRepository $attemptRepository,
    ): void {
        $wallet = $walletRepository->findByBusinessId($this->businessId);

        if ($wallet === null || ! $wallet->auto_recharge_enabled) {
            return;
        }

        if ($wallet->available_balance_micro >= (int) $wallet->auto_recharge_threshold_micro) {
            return;
        }

        if ($wallet->auto_recharge_threshold_micro === null || $wallet->auto_recharge_amount_micro === null) {
            return;
        }

        $amountMicro = (int) $wallet->auto_recharge_amount_micro;
        $business = $wallet->business;
        $walletManager = app(UsageWalletManager::class);

        // Customer Experience Slice 5, Correction Round 1 §5 — the read-only
        // pre-check of every applicable control (the Business monthly
        // ceiling and its approved hard maximum, the Workspace aggregate
        // ceiling and its hard maximum while the Workspace pays, and the
        // twice-per-rolling-24-hours limit). This is the cheap early exit;
        // the authoritative decision is repeated under the wallet and
        // Workspace row locks inside UsageBillingCheckoutManager::
        // initiateCharge(), before the attempt is created and before any
        // provider call. A refusal is a policy outcome, never a payment
        // failure: it creates no attempt, touches no balance, does not
        // count against consecutive_recharge_failures, and alerts the payer
        // at most once per rolling window.
        $admission = $walletManager->autoRechargeCeilingAdmission($business, $amountMicro);

        if (! $admission->allowed) {
            $walletManager->notifyAutoRechargeRefusal($this->businessId, (string) $admission->denialReason);

            return;
        }

        // Outstanding-attempt idempotency — never a second concurrent
        // auto-recharge attempt for the same Business (M3 contract §15).
        $outstanding = $attemptRepository->findOutstandingForBusiness($this->businessId, FundingAttemptPurpose::AutoRecharge->value);

        if ($outstanding !== null) {
            return;
        }

        $result = app(UsageBillingCheckoutManager::class)->initiateAutoRecharge($business, $amountMicro);

        // The locked re-evaluation refused (a concurrent evaluation or a
        // sibling Business consumed the remaining allowance first): the
        // same policy outcome as above, and never a payment failure.
        if ($result->state === \App\Enums\Usage\FundingAttemptState::Failed
            && in_array($result->denialReason, UsageWalletManager::AUTO_RECHARGE_REFUSAL_REASONS, true)
        ) {
            $walletManager->notifyAutoRechargeRefusal($this->businessId, (string) $result->denialReason);

            return;
        }

        // RFC-005 §19, as made authoritative by the Job/Event Dispatch
        // Completion Correction Contract §5: both a Failed outcome and a
        // RequiresAction outcome count as consecutive auto-recharge
        // failures — no immediate retry loop within this execution either
        // way. recordAutoRechargeFailure() itself owns the 2->3 disable
        // edge and the disabled-notification dispatch decision.
        if ($result->state === \App\Enums\Usage\FundingAttemptState::Failed
            || $result->state === \App\Enums\Usage\FundingAttemptState::RequiresAction
        ) {
            app(\App\Library\Usage\UsageWalletManager::class)->recordAutoRechargeFailure($this->businessId);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Library\Opportunity;

use App\Library\Money\Exceptions\UnsupportedCurrencyException;
use App\Library\Money\MicroAmountConverter;
use App\Library\Usage\EffectivePayerResolver;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Repositories\Contracts\BusinessUsageRateRepository;
use App\Repositories\Contracts\BusinessUsageWalletRepository;
use App\Repositories\Contracts\UsageMeterRepository;
use Illuminate\Support\Carbon;

/**
 * Implementation Contract 19 §5.3, §12 19.E — the ONE deterministic
 * computation of what a `paid_effect` Opportunity action will cost.
 *
 * READ ONLY (§12 19.E's own implementation prompt: "No wallet write belongs
 * in this sub-slice"). This class reserves nothing, commits nothing and
 * never locks the wallet row — it reads the same UsageMeter/BusinessUsageRate
 * seams UsageWalletManager::reserve() reads, and EffectivePayerResolver's
 * plain (non-locking) resolve(), exactly like a Home render reads a cached
 * insight: a preview, safe to compute on every page view. The wallet
 * reservation itself, when this product later executes a genuinely metered
 * provider call, belongs to that call's own UsageWalletManager::reserve()/
 * commit(), never to this class.
 *
 * NEVER INVENTS. estimateForAction() returns null the moment any real
 * pricing fact is missing: the action is not `paid_effect`, the registry
 * names no meter for it, no UsageMeter/active rate exists, or the meter's
 * own business/currency scope does not match. A null estimate is refused by
 * the caller exactly like OpportunityAuthorityGuard already refuses a
 * missing cost snapshot (§5.4(5)) — a `paid_effect` action nobody has
 * configured a real price for is not offered, not approved and never
 * executed (R-4: no fabricated number reaches a customer).
 */
class ActionCostEstimator
{
    public function __construct(
        private readonly EffectivePayerResolver $payers,
        private readonly UsageMeterRepository $meters,
        private readonly BusinessUsageRateRepository $rates,
        private readonly BusinessUsageWalletRepository $wallets,
    ) {
    }

    /**
     * The registry-gated entry point: is this action even a `paid_effect`
     * action, and does the registry name a meter to price it by? Every
     * action in OpportunityActionRegistry today is `paid_effect => false`
     * with `meter_key => null`, so this returns null for all of them —
     * exactly "do not enable a paid action that is not configured".
     */
    public function estimateForAction(Business $business, string $actionKey): ?ActionCostEstimate
    {
        if (! OpportunityActionRegistry::hasPaidEffect($actionKey)) {
            return null;
        }

        $meterKey = OpportunityActionRegistry::meterKeyFor($actionKey);

        if ($meterKey === null) {
            return null;
        }

        return $this->estimate($business, $meterKey, OpportunityActionRegistry::estimatedQuantityFor($actionKey));
    }

    /**
     * The pricing computation itself, over an explicit meter key — the same
     * meter/rate lookup reserve() performs, minus the wallet lock, minus the
     * write. $quantity is the same decimal-safe string convention
     * UsageWalletManager::reserve()'s own $estimatedQuantity uses.
     */
    public function estimate(Business $business, string $meterKey, string $quantity = '1'): ?ActionCostEstimate
    {
        $meter = $this->meters->findByMeterKey($meterKey);

        if ($meter === null || ! $meter->is_metered || $meter->active_rate_id === null) {
            return null;
        }

        if ($meter->business_id !== null && (int) $meter->business_id !== (int) $business->id) {
            return null;
        }

        $rate = $this->rates->findById((int) $meter->active_rate_id);

        if ($rate === null || $rate->meter_key !== $meter->meter_key) {
            return null;
        }

        // RFC-005's own internal unit throughout: micro-units (1 micro =
        // 1/1,000,000 of the currency's major unit). Every wallet/pricing
        // comparison below stays in this unit — it is converted to the
        // currency's real minor units ONLY at the very end, for the one
        // field Contract 19 §5.3 defines as minor units.
        $amountMicro = (int) UsageWalletManager::bcRoundHalfUp(
            bcmul((string) $rate->retail_rate_micro, $quantity, 10),
            '1',
        );

        // §5.3 — the display payer, resolved plainly (never the locking,
        // paid-effect variant: no wallet lock belongs to an estimate). The
        // Business payer case has no Workspace-shaped identity of its own;
        // Contract 13's frozen 1:1 Workspace:Business topology makes the
        // Business's own Workspace the only honest identity to record in a
        // Workspace-shaped column (never a fabricated id, R-19's own
        // discipline applied here).
        $payer = $this->payers->resolve($business);
        $payerWorkspaceId = $payer->providerCustomerWorkspaceId ?? (int) $business->workspace_id;

        // Wallet sufficiency is decided against the EXACT RFC-005 micro
        // balance, never the rounded/ceiled minor-unit display figure —
        // the two are different concepts and must never be conflated.
        $wallet = $this->wallets->findByBusinessId((int) $business->id);
        $walletSufficient = $wallet !== null && (int) $wallet->available_balance_micro >= $amountMicro;

        $rate->loadMissing('currency');
        $currencyCode = $rate->currency?->code;

        // §5.3 — the customer-facing ceiling is currency MINOR units, never
        // this codebase's internal micro convention (R-4: no invented
        // conversion, no float). A currency this codebase cannot express in
        // minor units at all is a pricing fact we do not have, exactly like
        // a missing rate — the whole estimate is refused rather than
        // showing a number with no honest unit (NEVER INVENTS).
        if ($currencyCode === null) {
            return null;
        }

        try {
            $amountMinorUpperBound = MicroAmountConverter::ceilToMinorUnits($amountMicro, $currencyCode);
        } catch (UnsupportedCurrencyException) {
            return null;
        }

        $now = Carbon::now();

        return new ActionCostEstimate(
            payerType: $payer->payerType,
            payerWorkspaceId: $payerWorkspaceId,
            currencyCode: $currencyCode,
            amountMinorUpperBound: $amountMinorUpperBound,
            unitCount: null,
            unitKind: $rate->unit_label,
            basis: ActionCostEstimate::BASIS_UPPER_BOUND,
            priceVersion: (string) $rate->version,
            estimatedAt: $now,
            expiresAt: $now->copy()->addMinutes(max(1, (int) config('opportunity.action_cost_estimate_ttl_minutes'))),
            walletSufficient: $walletSufficient,
        );
    }
}

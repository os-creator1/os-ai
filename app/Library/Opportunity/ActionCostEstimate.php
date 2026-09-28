<?php

declare(strict_types=1);

namespace App\Library\Opportunity;

use App\Enums\Usage\PayerType;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Implementation Contract 19 §5.3, §12 19.E — the customer-facing estimate
 * of what a `paid_effect` action will cost, before any human approves it.
 *
 * Two costs exist in this product and this is never the other one (§5.3):
 * the AI provider cost (`AiUsageCostEstimate`, micro-USD, admin-only, built
 * by the gateway) measures what the PLATFORM pays a model provider. This
 * object measures what the CUSTOMER's payer will be charged for an approved
 * action, computed by deterministic server code from RFC-005's own pricing
 * seams (UsageMeter/BusinessUsageRate) and EffectivePayerResolver — never
 * from model output (R-4), and never constructed by a controller from
 * request input.
 *
 * UNIT NOTE. `amountMinorUpperBound` (and every other RFC-005 monetary
 * figure this object reads: retail_rate_micro, available_balance_micro) is
 * carried in this codebase's one existing monetary unit throughout RFC-005
 * — its own "micro" convention — despite the schema column's
 * `_minor_upper_bound` name (Implementation Contract 19 §8's own naming).
 * RFC-005 defines no separate decimal-currency (cents) conversion utility
 * anywhere in the codebase; inventing one here, for exactly one caller,
 * would itself be the kind of invented rate/conversion this slice is
 * chartered not to add. A future slice that needs true minor-unit display
 * is free to add that conversion as its own reviewed change.
 *
 * `basis` is always `upper_bound`: the quantity priced is the action's own
 * fixed estimated unit count (never a live-metered actual), so the figure
 * shown before approval is a ceiling the execution-time recheck enforces
 * (R-3), never a promise of the exact final charge.
 */
final readonly class ActionCostEstimate
{
    public const BASIS_EXACT = 'exact';

    public const BASIS_UPPER_BOUND = 'upper_bound';

    public function __construct(
        public PayerType $payerType,
        public int $payerWorkspaceId,
        public ?string $currencyCode,
        public ?int $amountMinorUpperBound,
        public ?int $unitCount,
        public ?string $unitKind,
        public string $basis,
        public string $priceVersion,
        public CarbonInterface $estimatedAt,
        public CarbonInterface $expiresAt,
        public bool $walletSufficient,
    ) {
    }

    /**
     * The exact `action_cost_*` column shape OpportunityAuthorityGuard
     * already reads (ACTION_COST_FIELDS) — ready to spread into an
     * Opportunity/OpportunityActionExecution update().
     *
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return [
            'action_cost_payer_type' => $this->payerType->value,
            'action_cost_payer_workspace_id' => $this->payerWorkspaceId,
            'action_cost_currency_code' => $this->currencyCode,
            'action_cost_amount_minor_upper_bound' => $this->amountMinorUpperBound,
            'action_cost_unit_count' => $this->unitCount,
            'action_cost_unit_kind' => $this->unitKind,
            'action_cost_basis' => $this->basis,
            'action_cost_price_version' => $this->priceVersion,
            'action_cost_estimated_at' => $this->estimatedAt,
            'action_cost_expires_at' => $this->expiresAt,
            'action_cost_wallet_sufficient' => $this->walletSufficient,
        ];
    }

    /**
     * The reverse of toSnapshot() — reconstructs the estimate an approval or
     * execution row is carrying, from OpportunityAuthorityGuard::
     * actionCostSnapshot()'s own array shape (ISO-string timestamps). Null
     * when the snapshot is incomplete in any field a real estimate always
     * fills — the same completeness the guard's own $complete check already
     * requires before this is ever called.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function fromSnapshot(array $snapshot): ?self
    {
        $payerType = is_string($snapshot['action_cost_payer_type'] ?? null)
            ? PayerType::tryFrom($snapshot['action_cost_payer_type'])
            : null;

        $payerWorkspaceId = $snapshot['action_cost_payer_workspace_id'] ?? null;
        $basis = $snapshot['action_cost_basis'] ?? null;
        $priceVersion = $snapshot['action_cost_price_version'] ?? null;
        $estimatedAt = $snapshot['action_cost_estimated_at'] ?? null;
        $expiresAt = $snapshot['action_cost_expires_at'] ?? null;
        $walletSufficient = $snapshot['action_cost_wallet_sufficient'] ?? null;

        if ($payerType === null
            || ! is_int($payerWorkspaceId)
            || ! is_string($basis)
            || ! is_string($priceVersion)
            || $estimatedAt === null
            || $expiresAt === null
            || ! is_bool($walletSufficient)
        ) {
            return null;
        }

        return new self(
            payerType: $payerType,
            payerWorkspaceId: $payerWorkspaceId,
            currencyCode: is_string($snapshot['action_cost_currency_code'] ?? null) ? $snapshot['action_cost_currency_code'] : null,
            amountMinorUpperBound: is_int($snapshot['action_cost_amount_minor_upper_bound'] ?? null) ? $snapshot['action_cost_amount_minor_upper_bound'] : null,
            unitCount: is_int($snapshot['action_cost_unit_count'] ?? null) ? $snapshot['action_cost_unit_count'] : null,
            unitKind: is_string($snapshot['action_cost_unit_kind'] ?? null) ? $snapshot['action_cost_unit_kind'] : null,
            basis: $basis,
            priceVersion: $priceVersion,
            estimatedAt: Carbon::parse($estimatedAt),
            expiresAt: Carbon::parse($expiresAt),
            walletSufficient: $walletSufficient,
        );
    }

    /**
     * R-3 — "a payer change refuses". Compared on identity alone (which
     * payer, from which Workspace), never on consent or funding detail: a
     * live re-resolution that names a different funding source than the one
     * the human approved must never be honoured under that approval.
     */
    public function sameFundingAs(self $other): bool
    {
        return $this->payerType === $other->payerType
            && $this->payerWorkspaceId === $other->payerWorkspaceId;
    }

    /**
     * R-3 — "no stale pre-approval decision grants future permission": a
     * ceiling is never silently raised. True when the live recomputation
     * costs strictly more than what the human approved, in whichever of
     * amount/unit_count this action prices by.
     */
    public function exceedsCeiling(self $ceiling): bool
    {
        if ($this->amountMinorUpperBound !== null && $ceiling->amountMinorUpperBound !== null
            && $this->amountMinorUpperBound > $ceiling->amountMinorUpperBound) {
            return true;
        }

        if ($this->unitCount !== null && $ceiling->unitCount !== null
            && $this->unitCount > $ceiling->unitCount) {
            return true;
        }

        return false;
    }
}

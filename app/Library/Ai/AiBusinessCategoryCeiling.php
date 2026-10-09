<?php

namespace App\Library\Ai;

use App\Library\Ai\Enums\AiUsageCategory;
use App\Library\Ai\Enums\AiUsageEntryStatus;
use App\Models\AiUsageLedgerEntry;
use App\Models\Business;

/**
 * Content Autopilot — the per-Business, per-category ceiling on AI spend in one budget period.
 *
 * `config('ai.business_category_ceilings.<category>')` holds two amounts: `target_microusd` (what normal use is
 * designed to stay under — never refuses anything) and `hard_ceiling_microusd` (enforced by AiGateway through
 * allows()). It sits on top of the Workspace cap and the Agency Business sub-cap, never instead of them. The ceiling is
 * a safety limit, not a spending target, and it is configuration only: there is no owner-facing setter.
 *
 * Spend is read from `ai_usage_ledger` for the Business and period: committed calls at their actual cost, failed
 * calls at the cost the provider billed, and live reservations at their estimate. Released and refused entries cost
 * nothing. A single indexed read (business_id, period_key).
 */
final class AiBusinessCategoryCeiling
{
    public function applies(AiUsageCategory $category, ?Business $business): bool
    {
        return $business !== null && $this->hardCeilingMicrousd($category) !== null;
    }

    public function targetMicrousd(AiUsageCategory $category): ?int
    {
        $value = config('ai.business_category_ceilings.' . $category->value . '.target_microusd');

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    public function hardCeilingMicrousd(AiUsageCategory $category): ?int
    {
        $config = config('ai.business_category_ceilings.' . $category->value);

        if (! is_array($config)) {
            return null;
        }

        // A present-but-invalid ceiling fails closed (nothing may be spent), never open.
        $value = $config['hard_ceiling_microusd'] ?? null;

        return is_numeric($value) && (int) $value >= 0 ? (int) $value : 0;
    }

    public function spentMicrousd(Business $business, AiUsageCategory $category, string $periodKey): int
    {
        $rows = AiUsageLedgerEntry::query()
            ->where('business_id', $business->id)
            ->where('category', $category->value)
            ->where('period_key', $periodKey)
            ->whereIn('status', [
                AiUsageEntryStatus::Reserved->value,
                AiUsageEntryStatus::Committed->value,
                AiUsageEntryStatus::Failed->value,
            ])
            ->get(['status', 'estimated_cost_microusd', 'actual_cost_microusd']);

        $spent = 0;

        foreach ($rows as $row) {
            $spent += $row->status === AiUsageEntryStatus::Reserved
                ? (int) $row->estimated_cost_microusd
                : (int) $row->actual_cost_microusd;
        }

        return $spent;
    }

    public function remainingMicrousd(Business $business, AiUsageCategory $category, string $periodKey): int
    {
        $ceiling = $this->hardCeilingMicrousd($category);

        return $ceiling === null ? PHP_INT_MAX : max(0, $ceiling - $this->spentMicrousd($business, $category, $periodKey));
    }

    /** Would a call estimated at $estimateMicrousd still fit under the hard ceiling? */
    public function allows(Business $business, AiUsageCategory $category, string $periodKey, int $estimateMicrousd): bool
    {
        $ceiling = $this->hardCeilingMicrousd($category);

        if ($ceiling === null) {
            return true;
        }

        return $this->spentMicrousd($business, $category, $periodKey) + max(0, $estimateMicrousd) <= $ceiling;
    }

    public function lockKey(Business $business, AiUsageCategory $category): string
    {
        return 'ai-category-ceiling:' . $category->value . ':' . $business->id;
    }
}

<?php

namespace App\Library\Entitlement;

use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\Currency;
use App\Models\Workspace;

/**
 * The customer's Plan & subscription page: the account's (Workspace's) own
 * AI Business OS plan — Core, Growth or Agency — read from the Workspace
 * plan domain (workspace_plan_assignments / _catalog / _features) through
 * EntitlementManager. Never the inherited Ultimate SMS `plans` /
 * `subscriptions` model, which is a separate domain.
 *
 * Everything shown is a persisted fact; nothing is guessed:
 * - included features are the plan's packaging, limited to features that
 *   exist today (Available) and have customer copy — planned modules the
 *   plan will carry later are not listed as if they could be used;
 * - capacity comes from the canonical capacity decisions;
 * - a price is shown only when the catalog has one configured, and a
 *   complimentary plan says so — never a made-up amount or "$0".
 */
final class WorkspacePlanPresenter
{
    public function __construct(private readonly EntitlementManager $entitlementManager)
    {
    }

    /**
     * @return array{
     *     plan: array{name: string, status: string, status_variant: string, complimentary: bool}|null,
     *     included: list<array{name: string, description: string}>,
     *     capacity: list<array{label: string, value: string}>,
     *     billing: list<array{label: string, value: string}>
     * }
     */
    public function present(Workspace $workspace): array
    {
        $summary = $this->entitlementManager->getWorkspaceEntitlementSummary($workspace);

        if (! $summary->isAssigned || $summary->tier === null) {
            return ['plan' => null, 'included' => [], 'capacity' => [], 'billing' => []];
        }

        return [
            'plan' => [
                'name' => (string) $summary->tierDisplayName,
                'status' => ucfirst($summary->status->value),
                'status_variant' => match ($summary->status) {
                    WorkspacePlanAssignmentStatus::Active => 'success',
                    WorkspacePlanAssignmentStatus::Suspended => 'warning',
                    default => 'neutral',
                },
                'complimentary' => (bool) $summary->isComplimentary,
            ],
            'included' => $this->included($summary),
            'capacity' => array_merge($this->businessCapacity($summary), $this->locationCapacity($workspace)),
            'billing' => $this->billing($summary),
        ];
    }

    /**
     * @return list<array{name: string, description: string}>
     */
    private function included(WorkspaceEntitlementSummary $summary): array
    {
        $packaged = array_flip(array_map('strval', $summary->planFeatureKeys));
        $included = [];

        foreach (PlatformFeatureCopy::keys() as $featureKey) {
            if (isset($packaged[$featureKey]) && PlatformFeatureRegistry::isAvailable($featureKey)) {
                $included[] = [
                    'name' => (string) PlatformFeatureCopy::name($featureKey),
                    'description' => (string) PlatformFeatureCopy::description($featureKey),
                ];
            }
        }

        return $included;
    }

    /**
     * Business (Agency: client account) capacity, from the canonical
     * EntitlementManager::decideBusinessSlotCapacity() decision the summary
     * carries — whatever the catalog and allocations say today.
     *
     * @return list<array{label: string, value: string}>
     */
    private function businessCapacity(WorkspaceEntitlementSummary $summary): array
    {
        $capacity = $summary->capacity;
        $label = $summary->tier === WorkspacePlanTier::Agency ? 'Client accounts' : 'Businesses';

        if ($capacity->unlimited) {
            return [['label' => $label, 'value' => "{$capacity->currentBusinessCount} in use · no limit"]];
        }

        if ($capacity->effectiveCapacity === null) {
            return [];
        }

        return [['label' => $label, 'value' => "{$capacity->currentBusinessCount} of {$capacity->effectiveCapacity} in use"]];
    }

    /**
     * Physical-location capacity. It is not canonical on main yet: the
     * Customer Experience Slice 1A capacity correction introduces it, decided
     * per Business. When that lands, its per-Business decision is added here
     * as rows like the Business row above — the page renders whatever rows
     * this returns, so nothing else changes. Until then nothing is shown
     * rather than an old or guessed number.
     *
     * @return list<array{label: string, value: string}>
     */
    private function locationCapacity(Workspace $workspace): array
    {
        return [];
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function billing(WorkspaceEntitlementSummary $summary): array
    {
        if ($summary->isComplimentary) {
            return [['label' => 'Price', 'value' => 'Complimentary']];
        }

        foreach ($this->entitlementManager->listPlanCatalogSummaries() as $catalog) {
            if ($catalog->tier !== $summary->tier) {
                continue;
            }

            $currency = $catalog->currencyId !== null ? Currency::query()->find($catalog->currencyId) : null;

            if ($catalog->price === null || $currency === null) {
                return [];
            }

            $amount = number_format((float) $catalog->price, 2);
            $formatted = str_contains((string) $currency->format, '{PRICE}')
                ? str_replace('{PRICE}', $amount, (string) $currency->format)
                : "{$amount} {$currency->code}";

            return [['label' => 'Price', 'value' => $catalog->billingCycle === 'monthly' ? "{$formatted} per month" : "{$formatted} ({$catalog->billingCycle})"]];
        }

        return [];
    }
}

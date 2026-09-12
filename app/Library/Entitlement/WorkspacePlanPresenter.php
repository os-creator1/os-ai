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
     * Business (Agency: client account) capacity.
     *
     * RFC-004 §33 (v1.4, on main) is the canonical model: Core = 1 Business,
     * Growth = 1 Business, Agency = unlimited Businesses, while the
     * 3-included / 4-and-5-by-allocation / 6+-requires-Agency rule governs
     * PHYSICAL locations, not Businesses.
     *
     * Only "unlimited" is a figure the running catalog states and §33 agrees
     * with, so only that is shown. Core and Growth still carry Milestone 1's
     * superseded Business-slot numbers (business_slot_included = 3,
     * business_slot_max = 5) because §33's additive migration — the one that
     * corrects the data — has not landed, and §33 records that the merged M1
     * seed is historical and is not edited. Printing those would state a
     * limit the product has withdrawn ("1 of 3 Businesses"); printing "1"
     * would make this page a second authority for a rule it cannot read. So
     * no Business figure is shown until the canonical data says it — and this
     * method needs no change when it does.
     *
     * @return list<array{label: string, value: string}>
     */
    private function businessCapacity(WorkspaceEntitlementSummary $summary): array
    {
        $capacity = $summary->capacity;

        if (! $capacity->unlimited) {
            return [];
        }

        $label = $summary->tier === WorkspacePlanTier::Agency ? 'Client accounts' : 'Businesses';

        return [['label' => $label, 'value' => "{$capacity->currentBusinessCount} in use · no limit"]];
    }

    /**
     * Physical-location capacity, per Business.
     *
     * Not readable on main yet: RFC-004 §33 contracts the additive migration
     * that adds workspace_plan_catalog.location_slot_included /
     * location_slot_max / unlimited_location_slots /
     * additional_location_slot_price_ratio plus the per-Business allocation
     * columns, and the decision over them. Until that lands there is nothing
     * canonical to read, and the included / allocation / Agency rules are not
     * restated here — this page never becomes a second authority for
     * capacity, and never invents a location price the catalog does not
     * carry. When it lands, this method asks that decision for each Business
     * in the account and returns one row each; the page renders whatever rows
     * it returns, so nothing else changes.
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

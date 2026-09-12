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
     *     capacity_note: string|null,
     *     billing: list<array{label: string, value: string}>
     * }
     */
    public function present(Workspace $workspace): array
    {
        $summary = $this->entitlementManager->getWorkspaceEntitlementSummary($workspace);

        if (! $summary->isAssigned || $summary->tier === null) {
            return ['plan' => null, 'included' => [], 'capacity' => [], 'capacity_note' => null, 'billing' => []];
        }

        $catalogs = $this->entitlementManager->listPlanCatalogSummaries();

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
            'capacity' => array_merge($this->businessCapacity($summary), $this->locationCapacity($summary, $catalogs)),
            'capacity_note' => $this->capacityNote($summary, $catalogs),
            'billing' => $this->billing($summary, $catalogs),
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
     * Business (Agency: client account) capacity, straight from
     * EntitlementManager::decideBusinessSlotCapacity() — RFC-004 §33's
     * corrected model (Core = 1, Growth = 1, Agency = unlimited) lives in the
     * catalog and that decision, never in this page.
     *
     * A plan whose capacity is genuinely unavailable — no assignment, or a
     * suspended/inactive one, where the decision carries neither `unlimited`
     * nor an effective capacity — states no figure at all.
     *
     * @return list<array{label: string, value: string}>
     */
    private function businessCapacity(WorkspaceEntitlementSummary $summary): array
    {
        $capacity = $summary->capacity;
        $label = $summary->tier === WorkspacePlanTier::Agency ? 'Client accounts' : 'Businesses';

        if ($capacity->unlimited) {
            return [['label' => $label, 'value' => "Unlimited · {$capacity->currentBusinessCount} in use"]];
        }

        if ($capacity->effectiveCapacity === null) {
            return [];
        }

        $allowance = $capacity->effectiveCapacity === 1 ? '1 Business' : "{$capacity->effectiveCapacity} Businesses";

        return [['label' => $label, 'value' => "{$allowance} · {$capacity->currentBusinessCount} in use"]];
    }

    /**
     * Physical-location capacity per Business — the tier's allowance, read
     * from the same workspace_plan_catalog columns
     * EntitlementManager::decideLocationSlotCapacity() reads (RFC-004 §33),
     * carried here by listPlanCatalogSummaries(). How many locations a given
     * Business is actually using belongs to that Business's own Locations
     * page, which asks the decision directly.
     *
     * Nothing is computed here: the included figure, the per-Business ceiling
     * and "unlimited" are printed as the catalog states them, and the plan a
     * customer moves to for unlimited locations is the catalog's own unlimited
     * tier, not a name written into this page. A catalog row without a
     * location allowance states nothing.
     *
     * @param  array<int, WorkspacePlanCatalogSummary>  $catalogs
     * @return list<array{label: string, value: string}>
     */
    private function locationCapacity(WorkspaceEntitlementSummary $summary, array $catalogs): array
    {
        $catalog = $this->catalogFor($summary, $catalogs);

        if ($catalog === null) {
            return [];
        }

        $label = $summary->tier === WorkspacePlanTier::Agency ? 'Locations per client account' : 'Locations';

        if ($catalog->unlimitedLocationSlots) {
            return [['label' => $label, 'value' => 'Unlimited']];
        }

        if ($catalog->locationSlotIncluded < 1) {
            return [];
        }

        $included = $catalog->locationSlotIncluded === 1 ? '1 location included' : "{$catalog->locationSlotIncluded} locations included";

        if ($catalog->locationSlotMax === null || $catalog->locationSlotMax <= $catalog->locationSlotIncluded) {
            return [['label' => $label, 'value' => $included]];
        }

        return [[
            'label' => $label,
            'value' => "{$included} · up to {$catalog->locationSlotMax} with an add-on",
        ]];
    }

    /**
     * The sentence under Capacity: what happens past the included locations,
     * and which plan lifts the ceiling. Both figures and the plan name come
     * from the catalog; no price is stated, because a tier's location add-on
     * is a ratio of a base price the catalog does not carry yet.
     *
     * @param  array<int, WorkspacePlanCatalogSummary>  $catalogs
     */
    private function capacityNote(WorkspaceEntitlementSummary $summary, array $catalogs): ?string
    {
        $catalog = $this->catalogFor($summary, $catalogs);

        if ($catalog === null || $catalog->unlimitedLocationSlots || $catalog->locationSlotIncluded < 1) {
            return null;
        }

        if ($catalog->locationSlotMax === null || $catalog->locationSlotMax <= $catalog->locationSlotIncluded) {
            return null;
        }

        $note = "Locations beyond the first {$catalog->locationSlotIncluded}, up to {$catalog->locationSlotMax} per Business, need an add-on you allocate to that Business.";
        $unlimited = $this->unlimitedLocationCatalog($catalogs);

        if ($unlimited !== null && $unlimited->tier !== $summary->tier) {
            $note .= " To run more than {$catalog->locationSlotMax}, move to the {$unlimited->displayName} plan, which includes unlimited locations.";
        }

        return $note;
    }

    /**
     * @param  array<int, WorkspacePlanCatalogSummary>  $catalogs
     */
    private function catalogFor(WorkspaceEntitlementSummary $summary, array $catalogs): ?WorkspacePlanCatalogSummary
    {
        foreach ($catalogs as $catalog) {
            if ($catalog->tier === $summary->tier) {
                return $catalog;
            }
        }

        return null;
    }

    /**
     * @param  array<int, WorkspacePlanCatalogSummary>  $catalogs
     */
    private function unlimitedLocationCatalog(array $catalogs): ?WorkspacePlanCatalogSummary
    {
        foreach ($catalogs as $catalog) {
            if ($catalog->unlimitedLocationSlots && $catalog->isActive) {
                return $catalog;
            }
        }

        return null;
    }

    /**
     * @param  array<int, WorkspacePlanCatalogSummary>  $catalogs
     * @return list<array{label: string, value: string}>
     */
    private function billing(WorkspaceEntitlementSummary $summary, array $catalogs): array
    {
        if ($summary->isComplimentary) {
            return [['label' => 'Price', 'value' => 'Complimentary']];
        }

        foreach ($catalogs as $catalog) {
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

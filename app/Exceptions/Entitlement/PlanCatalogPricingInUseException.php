<?php

namespace App\Exceptions\Entitlement;

use RuntimeException;

/**
 * Thrown by EntitlementManager::updateCatalogPricing() (RFC-004 §12.5) when
 * clearing a catalog row's price/currency_id is attempted while at least
 * one non-complimentary workspace_plan_assignments row still references
 * that catalog row — clearing would otherwise silently create an
 * undefined-pricing state underneath an already-paid Workspace.
 *
 * Carries only a numeric catalog identifier — never Customer, User or
 * Business names, company, email, phone, or address.
 */
class PlanCatalogPricingInUseException extends RuntimeException
{
    public function __construct(public readonly int $catalogId)
    {
        parent::__construct("Workspace plan catalog [{$catalogId}] pricing cannot be cleared while a non-complimentary assignment references it.");
    }
}

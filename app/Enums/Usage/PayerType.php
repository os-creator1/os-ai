<?php

namespace App\Enums\Usage;

/**
 * Who funds a Business's usage (RFC-005 §16).
 *
 * agency_rebill is activated by V1 Implementation Contract 09: the managing
 * Agency Workspace (through an Active Contract 01 relationship) funds a
 * Client Business under its owner's standing consent. It is still never
 * customer-selectable through the existing Client/legacy payer selector
 * (UpdateBusinessPayerRequest accepts only business/workspace); it is
 * assigned only by BillingProfileManager::assignPayer() for the managing
 * Agency owner.
 *
 * Who actually pays — which provider customer, which instrument — is never
 * derived from this enum alone: EffectivePayerResolver is the one place that
 * answers it. The two methods below are the enum's only semantics, and both
 * are exhaustive on purpose, so a future payer type cannot silently inherit
 * another type's spend-control scope.
 */
enum PayerType: string
{
    case Business = 'business';
    case Workspace = 'workspace';
    case AgencyRebill = 'agency_rebill';

    /**
     * Contract 09 §5.4 — whether the Business's OWN Workspace controls row
     * governs this Business at all: it is locked at spend time and its
     * Workspace-wide paid-activity pause applies.
     *
     * Business and Workspace payers keep today's behavior. An AgencyRebill
     * Business is funded by a different (the managing Agency's) Workspace, so
     * the Client Workspace's own controls are not a payer-side control over it.
     */
    public function isGovernedByOwnWorkspaceControls(): bool
    {
        return match ($this) {
            self::Business, self::Workspace => true,
            self::AgencyRebill => false,
        };
    }

    /**
     * Contract 09 §5.4 — whether this Business's spend and automatic top-ups
     * count toward its own Workspace's aggregate monthly spend cap and
     * aggregate recharge ceiling. Only a Workspace payer does (unchanged).
     * No payer type ever counts toward a cross-Workspace Agency aggregate:
     * none exists in V1.
     */
    public function countsTowardOwnWorkspaceAggregateLimits(): bool
    {
        return match ($this) {
            self::Workspace => true,
            self::Business, self::AgencyRebill => false,
        };
    }
}

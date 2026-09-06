<?php

namespace App\Enums\Entitlement;

/**
 * Correction 1 — a small, code-backed authority distinguishing a
 * PlatformFeature that executes against an explicit Business (the
 * overwhelming majority: Crm, Conversations, Automations, and every
 * currently-Planned feature) from one that executes at the Workspace
 * level only, with no owning Business at all (ProspectOutreach — Agency
 * AI Prospecting). See PlatformFeatureRegistry::isWorkspaceScoped()/
 * isBusinessScoped() — the single place this is consulted, so the
 * distinction can never drift between decide(), decideForWorkspace(),
 * decideAvailableFeaturesForBusiness(), and the Business feature-toggle
 * mutators.
 */
enum PlatformFeatureScope: string
{
    case Workspace = 'workspace';
    case Business = 'business';
}

<?php

namespace App\Exceptions\NicheBlueprint;

/**
 * Contract 20 §6.2 check 4 — the named PlatformFeature is Workspace-scoped.
 *
 * A Blueprint component installs into a BUSINESS, so it can only ever be gated
 * by a Business-scoped feature. A Workspace-scoped key (today only
 * `ProspectOutreach`) would make `EntitlementManager::decide()` answer
 * `wrong_feature_scope` for every installation of that component, forever.
 */
class WrongFeatureScopeException extends BlueprintAuthoringException
{
    public function __construct(public readonly string $componentKey, public readonly string $featureKey)
    {
        parent::__construct(
            "Blueprint component [{$componentKey}] names Workspace-scoped feature [{$featureKey}]; components are Business-scoped."
        );
    }
}

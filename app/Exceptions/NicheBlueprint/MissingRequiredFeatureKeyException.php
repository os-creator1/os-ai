<?php

namespace App\Exceptions\NicheBlueprint;

/**
 * Contract 20 §6.2 check 2 — a component declared no entitlement.
 *
 * Addendum §16's "Each component declares its required entitlement" is
 * universal, not a default. No fallback or implicit feature is EVER
 * substituted: a component that cannot name a PlatformFeature is unpublishable
 * until the relevant product authority defines one.
 */
class MissingRequiredFeatureKeyException extends BlueprintAuthoringException
{
    public function __construct(public readonly string $componentKey)
    {
        parent::__construct(
            "Blueprint component [{$componentKey}] declares no required_feature_key; there is no ungated component."
        );
    }
}

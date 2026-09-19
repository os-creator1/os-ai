<?php

namespace App\Exceptions\NicheBlueprint;

/**
 * Contract 20 §6.2 check 3 — `PlatformFeatureRegistry::isKnown()` is false.
 *
 * Caught at publish so the `platform_feature_unknown` case can never reach an
 * installation, where it would become a per-Business skip record naming a
 * feature that does not exist.
 */
class UnknownPlatformFeatureKeyException extends BlueprintAuthoringException
{
    public function __construct(public readonly string $componentKey, public readonly string $featureKey)
    {
        parent::__construct(
            "Blueprint component [{$componentKey}] names unknown PlatformFeature [{$featureKey}]."
        );
    }
}

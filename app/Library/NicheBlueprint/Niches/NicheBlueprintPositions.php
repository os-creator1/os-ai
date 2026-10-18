<?php

namespace App\Library\NicheBlueprint\Niches;

use App\Library\NicheBlueprint\Adapters\BlueprintComponentAdapterRegistry;
use App\Library\NicheBlueprint\Workspace\BlueprintSurfaces;

/**
 * The install position of a seeded component: the surface's rank (so a pipeline
 * installs before the form that routes into it, and both before the goal that
 * references them) plus the author's ordinal within the surface.
 */
final class NicheBlueprintPositions
{
    public static function for(string $componentType, int $ordinal): int
    {
        $definition = app(BlueprintComponentAdapterRegistry::class)->find($componentType);

        return BlueprintSurfaces::positionFor($definition->surface(), $ordinal);
    }
}

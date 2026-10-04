<?php

namespace App\Library\NicheBlueprint\Safety;

use RuntimeException;

/** Thrown when something tries to reach the outside world from the Blueprint Workspace. */
final class BlueprintModeRefusedException extends RuntimeException
{
    public function __construct(public readonly string $action)
    {
        parent::__construct("Blueprint mode refused the external action [{$action}]: the Blueprint Workspace never sends, charges, connects or publishes anything real.");
    }
}

<?php

namespace App\Exceptions\NicheBlueprint;

use Throwable;

/**
 * Contract 20 §6.2 check 5 — the component's own adapter rejected its payload.
 *
 * This is `PipelineBlueprint`'s "validated when it is built, so a malformed
 * template fails where it is defined rather than half-way through copying"
 * rule, moved to the publish boundary. The installer must NEVER be the first
 * place a malformed payload is discovered: by then it is being copied into a
 * live Business.
 *
 * The adapter's own exception is preserved as `$previous`, so an operator sees
 * the precise reason rather than merely that something was wrong.
 */
class InvalidComponentDescriptorException extends BlueprintAuthoringException
{
    public function __construct(
        public readonly string $componentKey,
        public readonly string $componentType,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            "Blueprint component [{$componentKey}] has a payload its [{$componentType}] adapter rejected"
                . ($previous !== null ? ': ' . $previous->getMessage() : '.'),
            0,
            $previous
        );
    }
}

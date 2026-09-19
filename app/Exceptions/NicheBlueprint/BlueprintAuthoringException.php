<?php

namespace App\Exceptions\NicheBlueprint;

use RuntimeException;

/**
 * Contract 20 §6.2 — base for every refusal the Blueprint authoring/publishing
 * domain raises.
 *
 * Typed rather than bare, for one reason that matters: the publisher is a
 * fail-closed gate, and a caller (later the Sub-slice F platform surface, or
 * an operator tool) must be able to tell an operator EXACTLY which rule
 * refused a publish. A raw RuntimeException would force that caller to parse
 * strings, and a leaked SQL constraint error would expose a database detail as
 * a product message.
 */
abstract class BlueprintAuthoringException extends RuntimeException
{
}

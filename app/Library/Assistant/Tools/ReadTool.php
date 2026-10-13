<?php

namespace App\Library\Assistant\Tools;

use App\Library\Assistant\AssistantContext;
use App\Library\Assistant\ToolResult;

/**
 * A bounded, side-effect-free reader over an existing domain seam. The model may ask for it by key; the server
 * decides whether this actor may have it (`available()`), validates the arguments against `rules()`, and runs it
 * through the module's own reader — which applies the module's own Location ACL.
 */
interface ReadTool
{
    public function key(): string;

    /** One line the model sees, in the tool catalogue. */
    public function description(): string;

    /** Laravel validation rules for the arguments; unknown keys are dropped, never passed on. @return array<string, mixed> */
    public function rules(): array;

    /** Is this tool offered to this actor at all? (permission + entitlement, same rule as the module's page). */
    public function available(AssistantContext $context): bool;

    /** @param array<string, mixed> $args validated @throws \Throwable only for a genuine fault; absence of data is a ToolResult */
    public function read(AssistantContext $context, array $args): ToolResult;
}

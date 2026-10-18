<?php

namespace App\Library\NicheBlueprint\Adapters;

use App\Models\NicheBlueprint;

/**
 * Optional beside BlueprintComponentAdapter: a LIVE component propagates the
 * moment its version is published, by syncing the table the Business-facing
 * reader already consults. Called by NicheBlueprintPublisher inside the
 * publish transaction, so the version flip and the sync commit together.
 */
interface PublishesLiveBlueprintComponent
{
    /**
     * @param  array<string, mixed>  $payload  this version's descriptor
     * @param  ?array<string, mixed>  $previousPayload  the superseded version's descriptor for the same component_key, or null
     */
    public function onPublished(NicheBlueprint $blueprint, array $payload, ?array $previousPayload, int $actorUserId): void;
}

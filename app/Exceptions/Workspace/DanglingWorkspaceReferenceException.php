<?php

namespace App\Exceptions\Workspace;

use RuntimeException;

/**
 * Thrown by the enforce_business_workspace_constraint migration (RFC-003
 * §10.6 step 5, §12.4) when a non-null businesses.workspace_id references a
 * workspaces.id that does not exist — the migration refuses to add the
 * foreign key over data it cannot already satisfy, rather than surfacing an
 * opaque MySQL constraint-addition error.
 *
 * Carries only numeric Workspace identifiers — never Customer, User or
 * Business names, company, email, phone, or address data.
 */
class DanglingWorkspaceReferenceException extends RuntimeException
{
    /**
     * @var array<int, int>
     */
    public readonly array $workspaceIds;

    /**
     * Constructor property promotion is deliberately not used here:
     * workspaceIds must be normalized (cast, deduped, sorted, reindexed)
     * before it is ever exposed, and promoted readonly properties are
     * assigned before the constructor body runs, which would make that
     * normalization impossible to apply afterward.
     *
     * @param  array<int, mixed>  $workspaceIds
     */
    public function __construct(array $workspaceIds)
    {
        $normalizedIds = collect($workspaceIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        parent::__construct(sprintf(
            'Cannot enforce businesses.workspace_id: %d referenced Workspace ID(s) do not exist [%s].',
            count($normalizedIds),
            implode(', ', $normalizedIds)
        ));

        $this->workspaceIds = $normalizedIds;
    }
}

<?php

namespace App\Exceptions\Workspace;

use RuntimeException;

/**
 * Thrown by the enforce_workspace_business_one_to_one_constraint migration
 * (Implementation Contract 13 §5/§8) when a direct, independent query
 * against `businesses` finds one or more Workspace IDs still holding more
 * than one Business — the migration refuses to add the `UNIQUE` constraint
 * over data that would violate it, rather than surfacing an opaque MySQL
 * constraint-addition error. Never trusts Contract 10/12's own earlier
 * success reports; this is always a fresh re-check.
 *
 * Carries only numeric Workspace identifiers — never Customer, User or
 * Business names, company, email, phone, or address data.
 */
class MultipleBusinessesPerWorkspaceException extends RuntimeException
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
            'Cannot enforce businesses.workspace_id UNIQUE: %d Workspace ID(s) still have more than one Business [%s].',
            count($normalizedIds),
            implode(', ', $normalizedIds)
        ));

        $this->workspaceIds = $normalizedIds;
    }
}

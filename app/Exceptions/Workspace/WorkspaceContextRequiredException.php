<?php

namespace App\Exceptions\Workspace;

use App\Enums\Workspace\WorkspaceContextFailureReason;
use RuntimeException;

/**
 * Thrown by the legacy-onboarding Workspace resolver (RFC-003 §13.1) when it
 * cannot safely determine a single Workspace for an owner — either because
 * more than one otherwise-valid candidate exists, or because a Business's
 * workspace_id reference cannot be trusted. Never chooses a candidate
 * arbitrarily; this is always a hard stop, not a fallback path.
 *
 * Carries only numeric identifiers and a closed reason value — never
 * Customer, User or Business names, company, email, phone, or address.
 */
class WorkspaceContextRequiredException extends RuntimeException
{
    public readonly int $ownerUserId;

    /**
     * @var array<int, int>
     */
    public readonly array $candidateWorkspaceIds;

    public readonly WorkspaceContextFailureReason $reason;

    /**
     * Constructor property promotion is deliberately not used here:
     * candidateWorkspaceIds must be normalized (cast, deduped, sorted,
     * reindexed) before it is ever exposed, and promoted readonly
     * properties are assigned before the constructor body runs, which
     * would make that normalization impossible to apply afterward.
     *
     * @param  array<int, mixed>  $candidateWorkspaceIds
     */
    public function __construct(int $ownerUserId, array $candidateWorkspaceIds, WorkspaceContextFailureReason $reason)
    {
        $normalizedIds = collect($candidateWorkspaceIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        parent::__construct(sprintf(
            'Cannot resolve a single Workspace for owner_user_id [%d]: %s [%s].',
            $ownerUserId,
            $reason->value,
            implode(', ', $normalizedIds)
        ));

        $this->ownerUserId = $ownerUserId;
        $this->candidateWorkspaceIds = $normalizedIds;
        $this->reason = $reason;
    }
}

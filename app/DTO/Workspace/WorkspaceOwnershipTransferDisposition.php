<?php

declare(strict_types=1);

namespace App\DTO\Workspace;

use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceOwnershipTransferMode;
use App\Exceptions\Workspace\InvalidBusinessAccessScopeAssignmentException;

/**
 * The previous owner's disposition for WorkspaceManager::transferOwnership()
 * (RFC-003 Milestone 2 domain contract, corrected report §10) — the caller
 * must explicitly choose between deactivating the previous owner entirely
 * or converting them to an active admin membership; neither is ever
 * defaulted.
 *
 * The private constructor and the two named factories make invalid states
 * unconstructible: deactivate() cannot accept a scope or Business IDs at
 * all, and convertToAdmin() validates its scope/ID combination (`all` +
 * non-empty IDs is invalid, `selected` + an empty ID set is valid) before
 * an instance can exist.
 */
final class WorkspaceOwnershipTransferDisposition
{
    /**
     * Constructor property promotion is deliberately not used for
     * $businessIds: it must be normalized (cast, deduped, sorted,
     * reindexed) before it is ever exposed, and promoted readonly
     * properties are assigned before the constructor body runs, which
     * would make that normalization impossible to apply afterward.
     *
     * @param  array<int, mixed>  $businessIds
     */
    private function __construct(
        public readonly WorkspaceOwnershipTransferMode $mode,
        public readonly ?WorkspaceBusinessAccessScope $scope,
        array $businessIds,
    ) {
        $this->businessIds = collect($businessIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @var array<int, int>
     */
    public readonly array $businessIds;

    public static function deactivate(): self
    {
        return new self(WorkspaceOwnershipTransferMode::Deactivate, null, []);
    }

    /**
     * @param  array<int, mixed>  $businessIds
     */
    public static function convertToAdmin(WorkspaceBusinessAccessScope $scope, array $businessIds = []): self
    {
        if ($scope === WorkspaceBusinessAccessScope::All && $businessIds !== []) {
            throw new InvalidBusinessAccessScopeAssignmentException($scope, count($businessIds));
        }

        return new self(WorkspaceOwnershipTransferMode::ConvertToAdmin, $scope, $businessIds);
    }
}

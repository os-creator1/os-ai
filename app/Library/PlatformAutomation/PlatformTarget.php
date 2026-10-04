<?php

namespace App\Library\PlatformAutomation;

use App\Enums\PlatformAutomation\PlatformTargetType;
use App\Models\Business;
use App\Models\Workspace;

/**
 * The explicit subject of a Platform run. It is built from ids the triggering
 * event itself carried and is stored on the run; nothing downstream ever asks for
 * "the current Business" or takes a global first() row.
 */
final class PlatformTarget
{
    private function __construct(
        public readonly PlatformTargetType $type,
        public readonly int $id,
        public readonly ?int $workspaceId,
        public readonly ?int $businessId,
        public readonly ?int $userId,
    ) {
    }

    public static function user(int $userId): self
    {
        return new self(PlatformTargetType::User, $userId, null, null, $userId);
    }

    public static function workspace(int $workspaceId, ?int $subscriptionId = null): self
    {
        return new self($subscriptionId !== null ? PlatformTargetType::Subscription : PlatformTargetType::Workspace,
            $subscriptionId ?? $workspaceId, $workspaceId, null, null);
    }

    /** Resolves the owning Workspace from the Business row itself, never from request state. */
    public static function business(int $businessId, PlatformTargetType $as = PlatformTargetType::Business): ?self
    {
        $business = Business::query()->find($businessId);

        return $business === null ? null : new self($as, $businessId, $business->workspace_id ? (int) $business->workspace_id : null, $businessId, null);
    }

    public static function platform(): self
    {
        return new self(PlatformTargetType::Platform, 0, null, null, null);
    }
}

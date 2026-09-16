<?php

namespace App\Library\ViewAs;

use Carbon\CarbonImmutable;

/**
 * An active View-as-client session as the shell and the resolver see it
 * (contract §5.5). The authenticated user never changes — this object is a
 * narrowing layer over the real actor, never a substitute identity.
 *
 * V1 Contract 04 §5: workspaceId/workspaceUid always name the Workspace being
 * VIEWED. viewingAgencyWorkspaceId/viewingAgencyWorkspaceUid name the Agency
 * Workspace a cross-Workspace session is authorized through, and are null for
 * the same-Workspace path. Deliberately nothing here carries payer, wallet,
 * funding or AgencyRebill consent authority: viewing a client never confers
 * financial authority over it (Contract 04 §6/§11).
 */
final class ViewAsContext
{
    public function __construct(
        public readonly int $sessionId,
        public readonly string $uid,
        public readonly int $actorUserId,
        public readonly string $actorDisplayName,
        public readonly int $workspaceId,
        public readonly string $workspaceUid,
        public readonly int $businessId,
        public readonly string $businessUid,
        public readonly string $businessName,
        public readonly CarbonImmutable $startedAt,
        public readonly CarbonImmutable $expiresAt,
        public readonly ?int $viewingAgencyWorkspaceId = null,
        public readonly ?string $viewingAgencyWorkspaceUid = null,
    ) {
    }

    public function minutesRemaining(CarbonImmutable $now): int
    {
        return max(0, (int) ceil($now->diffInSeconds($this->expiresAt, false) / 60));
    }
}

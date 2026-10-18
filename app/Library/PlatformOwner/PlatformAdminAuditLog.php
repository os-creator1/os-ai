<?php

namespace App\Library\PlatformOwner;

use App\Models\PlatformAdminAction;

/**
 * The single writer of platform_admin_actions. Callers pass an actor id they
 * have already authority-checked; payloads must never carry secrets, tokens,
 * reset links or passwords — only identifiers and before/after values.
 */
class PlatformAdminAuditLog
{
    public function record(int $actorUserId, string $action, string $subjectType, ?string $subjectRef, string $summary, ?string $reason = null, array $payload = []): PlatformAdminAction
    {
        return PlatformAdminAction::create([
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_ref' => $subjectRef,
            'summary' => mb_substr($summary, 0, 255),
            'reason' => $reason,
            'payload' => $payload === [] ? null : $payload,
        ]);
    }
}

<?php

namespace App\Library\Ai;

use App\Models\User;

/**
 * Contract §5.7a C, §6.7 — Rule R-28: "attribution, not authentication".
 *
 * A Platform `AiRequest` carries only an integer `actorUserId`, supplied by
 * trusted server code. Re-reading the named `User` row here can prove THAT
 * user is an admin; it can never prove WHO supplied the integer — that is
 * boundary 1 (authentication at the edge, `$request->user()`, never a
 * client-selectable parameter), which this class is not and does not
 * replace.
 *
 * This is boundary 2: a *second*, independent recheck, run fresh
 * immediately before every reservation (`AiGateway::complete()`), so a
 * miswired route, an authenticated non-admin, or an admin demoted or
 * deleted between enqueue and execution is refused before any provider
 * call. It never trusts a passed-in flag, role snapshot or permission
 * claim — only a fresh read of `users.is_admin`, the same account-type
 * flag `EnsureUserIsAdministrator` uses and for the same reason its own
 * docblock gives: the permission-string path treats `users.id === 1` as an
 * unconditional super-admin bypass regardless of account type.
 */
final class PlatformAiAuthority
{
    public function authorize(int $actorUserId): bool
    {
        $user = User::query()->find($actorUserId);

        return $user !== null && (bool) $user->is_admin;
    }
}

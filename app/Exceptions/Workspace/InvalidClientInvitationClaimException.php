<?php

namespace App\Exceptions\Workspace;

use RuntimeException;

/**
 * Implementation Contract 07 §5/§6 — thrown for every reason a client
 * invitation claim may fail: not found, wrong token, expired, revoked,
 * already accepted, or the authenticated actor's email does not match the
 * invitation's. Deliberately ONE exception type for all of these, never a
 * distinct one per reason — the whole point of this exception is that the
 * caller renders exactly one generic "this invitation is no longer valid"
 * response regardless of which internal reason applied (matching this
 * codebase's own existence-disclosure discipline, e.g. PR #302's
 * indistinguishable-404 precedent).
 *
 * $reason is internal-only (safe for a log line, never for an HTTP
 * response): a short fixed keyword such as "not_found", "invalid_token",
 * "expired", "revoked", "already_accepted", or "email_mismatch" — never a
 * plaintext token, email, or Business/Agency name.
 */
class InvalidClientInvitationClaimException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
    ) {
        parent::__construct("Client invitation claim refused: {$reason}.");
    }
}

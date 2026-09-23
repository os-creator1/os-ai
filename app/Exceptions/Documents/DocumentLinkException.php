<?php

namespace App\Exceptions\Documents;

use RuntimeException;

/**
 * Implementation Contract 17 §6.3 — ONE exception type for every reason a
 * secure document link may be refused, mirroring
 * InvalidClientInvitationClaimException's discipline exactly.
 *
 * The `reason` is for OUR logs and tests only. The public surface renders
 * ONE generic "this link is no longer valid" response whichever reason
 * applied — never disclosing which — because distinguishing "unknown
 * document" from "wrong token" from "voided" from "account suspended" is
 * precisely the existence disclosure the precedent exists to prevent.
 *
 * Nothing here ever carries the plaintext token, the recipient's address or
 * any Business data: an exception message is a log line waiting to happen.
 */
final class DocumentLinkException extends RuntimeException
{
    /** The uid located no document at all. */
    public const NOT_FOUND = 'not_found';

    /** The document has no live link (never sent, or the hash was cleared by void). */
    public const NO_ACTIVE_LINK = 'no_active_link';

    /** access_token_expires_at has passed. */
    public const EXPIRED = 'expired';

    /** Hash::check() refused — a wrong, rotated or cross-document token. */
    public const INVALID_TOKEN = 'invalid_token';

    /** The owning Business/Workspace is gone, inactive or not Active. */
    public const ACCOUNT_UNAVAILABLE = 'account_unavailable';

    /** The canonical account-access authority says the account is Locked. */
    public const ACCOUNT_LOCKED = 'account_locked';

    /** EntitlementManager currently denies Payments & Contracts. */
    public const NOT_ENTITLED = 'not_entitled';

    /** The document's Location is archived or otherwise unusable. */
    public const LOCATION_UNUSABLE = 'location_unusable';

    /** The document's own lifecycle forbids being viewed at all (void/expired). */
    public const DOCUMENT_NOT_VIEWABLE = 'document_not_viewable';

    /** There is no issued version to show. */
    public const NO_ISSUED_VERSION = 'no_issued_version';

    /** The document's lifecycle forbids the requested operation (e.g. signing). */
    public const OPERATION_NOT_PERMITTED = 'operation_not_permitted';

    /** A signature already exists (unique(business_document_id) also enforces this). */
    public const ALREADY_SIGNED = 'already_signed';

    /** The signer's submitted evidence was malformed. */
    public const INVALID_SIGNATURE_INPUT = 'invalid_signature_input';

    private function __construct(public readonly string $reason)
    {
        // Deliberately reason-only: no interpolated uid, token, email or
        // Business name may ever reach a log through this message.
        parent::__construct('The document link is not valid.');
    }

    public static function because(string $reason): self
    {
        return new self($reason);
    }
}

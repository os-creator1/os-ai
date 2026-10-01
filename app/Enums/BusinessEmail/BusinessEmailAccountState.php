<?php

namespace App\Enums\BusinessEmail;

/**
 * pending      — OAuth started; no usable credential yet.
 * active       — consent completed; a refresh token is held.
 * revoked      — the PROVIDER invalidated the grant (invalid_grant / 401).
 * disconnected — the Business (or an operator) ended it on purpose.
 *
 * Only `active` may send. revoked/disconnected/pending hold no credential.
 */
enum BusinessEmailAccountState: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Revoked = 'revoked';
    case Disconnected = 'disconnected';
}

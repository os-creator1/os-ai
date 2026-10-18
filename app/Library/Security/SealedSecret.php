<?php

namespace App\Library\Security;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Versioned, application-key encryption for a stored provider credential.
 *
 * A sealed value is `enc:v1:` + Crypt::encryptString(). Anything without that
 * prefix is a legacy plaintext value written before sealing existed (or by an
 * admin/Growth code path that still stores plaintext): open() returns it
 * unchanged, so old rows keep working and are upgraded only when explicitly
 * re-sealed. open() never throws and never logs the value.
 */
final class SealedSecret
{
    public const PREFIX = 'enc:v1:';

    public static function isSealed(?string $stored): bool
    {
        return is_string($stored) && str_starts_with($stored, self::PREFIX);
    }

    /** Idempotent: an already-sealed value is returned unchanged. */
    public static function seal(?string $plain): ?string
    {
        if ($plain === null || $plain === '' || self::isSealed($plain)) {
            return $plain;
        }

        return self::PREFIX . Crypt::encryptString($plain);
    }

    /** Null when a sealed value cannot be decrypted (e.g. rotated key). */
    public static function open(?string $stored): ?string
    {
        if (! self::isSealed($stored)) {
            return $stored;
        }

        try {
            return Crypt::decryptString(substr((string) $stored, strlen(self::PREFIX)));
        } catch (DecryptException) {
            return null;
        }
    }
}

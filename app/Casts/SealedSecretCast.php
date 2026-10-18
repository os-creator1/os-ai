<?php

namespace App\Casts;

use App\Library\Security\SealedSecret;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;

/**
 * Reads transparently open a sealed value or pass legacy plaintext through.
 * Writes are stored exactly as given, so existing Growth/admin code that
 * assigns plaintext is unchanged; callers that own an agency credential seal
 * it first with SealedSecret::seal().
 */
class SealedSecretCast implements CastsAttributes, SerializesCastableAttributes
{
    public function get($model, string $key, $value, array $attributes)
    {
        return is_string($value) ? SealedSecret::open($value) : $value;
    }

    public function set($model, string $key, $value, array $attributes)
    {
        return $value;
    }

    /**
     * A sealed credential is never part of toArray()/toJson(), so it cannot leak through
     * a serialized model or a JSON response. (A legacy plaintext value serializes as it
     * always did until it is sealed.)
     */
    public function serialize($model, string $key, $value, array $attributes)
    {
        return SealedSecret::isSealed($attributes[$key] ?? null) ? null : $value;
    }
}

<?php

namespace App\Library\NicheBlueprint\Workspace;

/**
 * Object-key-order-insensitive sha256 of a JSON-able value. MySQL JSON columns
 * reorder object keys, so a checksum over raw json_encode would flap.
 */
final class BlueprintChecksum
{
    public static function of(mixed $value): string
    {
        return hash('sha256', (string) json_encode(self::normalise($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function normalise(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $mapped = array_map([self::class, 'normalise'], $value);

        if (! array_is_list($mapped)) {
            ksort($mapped);
        }

        return $mapped;
    }
}

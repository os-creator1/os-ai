<?php

namespace App\Library\Appearance;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The signed-in person's own Light / Dark / System appearance preference.
 *
 * The preference is stored per user (users.appearance), never per Business or
 * Agency workspace, so it follows the person across devices and is untouched by
 * workspace switching. A user with no saved value keeps the existing light
 * interface. Only the three supported values are ever stored or rendered; any
 * other string is treated as "not set".
 */
final class Appearance
{
    public const LIGHT = 'light';
    public const DARK = 'dark';
    public const SYSTEM = 'system';

    public const DEFAULT = self::LIGHT;

    /** @return array<int, string> */
    public static function values(): array
    {
        return [self::LIGHT, self::DARK, self::SYSTEM];
    }

    public static function isValid(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::values(), true);
    }

    /** Unsupported, empty or missing input falls back to the light default. */
    public static function normalize(mixed $value): string
    {
        return self::isValid($value) ? $value : self::DEFAULT;
    }

    /** The preference to render for a user; guests and unset users get light. */
    public static function forUser(?Authenticatable $user): string
    {
        if ($user === null) {
            return self::DEFAULT;
        }

        return self::normalize($user->appearance ?? null);
    }

    /**
     * Extra class for the <html> element. Dark reuses the template's existing
     * `dark-layout` class, so Bootstrap/vendor components and the semantic
     * tokens switch together. "system" is resolved client-side before first
     * paint (panels/appearance-boot.blade.php), because only the browser knows
     * the operating-system preference.
     */
    public static function htmlClass(?Authenticatable $user): string
    {
        return self::forUser($user) === self::DARK ? 'dark-layout' : '';
    }
}

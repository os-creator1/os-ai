<?php

namespace App\Enums\Dashboard;

/**
 * Customer Experience Slice 4 §5.1 — how urgent an attention item is.
 *
 * Always rendered as its WORD (word()), never as colour alone; the badge
 * colour only reinforces it.
 */
enum AttentionSeverity: string
{
    case Blocking = 'blocking';
    case Warning = 'warning';
    case Informational = 'informational';

    public function word(): string
    {
        return match ($this) {
            self::Blocking => 'Blocking',
            self::Warning => 'Warning',
            self::Informational => 'Informational',
        };
    }

    /** Lower sorts first: blocking, then warning, then informational. */
    public function rank(): int
    {
        return match ($this) {
            self::Blocking => 0,
            self::Warning => 1,
            self::Informational => 2,
        };
    }

    /** The x-badge variant that reinforces — never replaces — the word. */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Blocking => 'danger',
            self::Warning => 'warning',
            self::Informational => 'accent',
        };
    }
}

<?php

namespace App\Enums\Dashboard;

/**
 * Customer Experience Slice 4 §4.4 — the direction of a headline's change,
 * derived from its absolute delta (never from its percentage).
 */
enum HeadlineTrend: string
{
    case Up = 'up';
    case Down = 'down';
    case Unchanged = 'unchanged';

    public static function fromDelta(int|float $delta): self
    {
        return match (true) {
            $delta > 0 => self::Up,
            $delta < 0 => self::Down,
            default => self::Unchanged,
        };
    }
}

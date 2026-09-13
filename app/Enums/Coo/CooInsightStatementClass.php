<?php

namespace App\Enums\Coo;

/**
 * Contract §8.3/§8.4 — how sure one statement of an insight may be.
 */
enum CooInsightStatementClass: string
{
    case Known = 'known';
    case Likely = 'likely';
    case Unknown = 'unknown';

    /** The plain word shown to the customer — never a colour alone (§8.4). */
    public function label(): string
    {
        return match ($this) {
            self::Known => 'Known',
            self::Likely => 'Likely',
            self::Unknown => 'Unknown',
        };
    }
}

<?php

namespace App\Enums\Crm;

/**
 * Where a CRM deal stands overall. Independent of its stage: winning or losing a
 * deal closes it where it is, and reopening puts it back on the board there.
 */
enum CrmOpportunityStatus: string
{
    case Open = 'open';
    case Won = 'won';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Won => 'Won',
            self::Lost => 'Lost',
        };
    }
}

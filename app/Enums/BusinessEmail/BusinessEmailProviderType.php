<?php

namespace App\Enums\BusinessEmail;

/**
 * The two mail providers a Business can connect, and only those two.
 *
 * Deliberately NOT App\Enums\Calendar\ExternalCalendarProvider: that enum
 * names Calendar's "outlook" and belongs to a different consent. Email's
 * Microsoft provider is the Microsoft identity platform + Graph Mail API.
 */
enum BusinessEmailProviderType: string
{
    case Google = 'google';
    case Microsoft = 'microsoft';

    public function label(): string
    {
        return match ($this) {
            self::Google => 'Google (Gmail / Google Workspace)',
            self::Microsoft => 'Microsoft (Outlook / Microsoft 365)',
        };
    }
}

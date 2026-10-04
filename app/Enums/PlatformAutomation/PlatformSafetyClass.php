<?php

namespace App\Enums\PlatformAutomation;

/**
 * What a step can do to the world. The class, not the step type, decides how
 * much authority a run needs: ACCOUNT_STATE, BILLING and ENTITLEMENT steps never
 * execute on their own — the run stops at `awaiting_approval` until a Platform
 * Owner confirms, and the canonical service then runs with THAT person as actor.
 */
enum PlatformSafetyClass: string
{
    case ReadOnly = 'read_only';
    case Notification = 'notification';
    case ExternalMessage = 'external_message';
    case AccountState = 'account_state';
    case Billing = 'billing';
    case Entitlement = 'entitlement';

    public function requiresApproval(): bool
    {
        return match ($this) {
            self::AccountState, self::Billing, self::Entitlement => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ReadOnly => 'Read only',
            self::Notification => 'Notification',
            self::ExternalMessage => 'External message',
            self::AccountState => 'Account state (needs approval)',
            self::Billing => 'Billing (needs approval)',
            self::Entitlement => 'Entitlement (needs approval)',
        };
    }
}

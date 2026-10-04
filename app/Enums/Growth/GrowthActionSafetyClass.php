<?php

namespace App\Enums\Growth;

/**
 * What an action can do to the world (Growth Center §67). The class — not the
 * UI — decides the confirmation policy, so a new action can never quietly
 * skip confirmation by forgetting to ask for it.
 *
 * V1 ships only READ_ONLY navigation handoffs and SAFE_LOCAL_WRITE, because
 * every write goes through the owning module's own screen and service (the
 * Growth Center never duplicates a module's write). The other classes exist
 * so a future action must declare which it is.
 */
enum GrowthActionSafetyClass: string
{
    case ReadOnly = 'read_only';
    case SafeLocalWrite = 'safe_local_write';
    case ExternalMessage = 'external_message';
    case ExternalProviderMutation = 'external_provider_mutation';
    case Financial = 'financial';
    case Publication = 'publication';

    /** Must the owner explicitly confirm before this runs? */
    public function requiresConfirmation(): bool
    {
        return match ($this) {
            self::ReadOnly => false,
            self::SafeLocalWrite => true,
            self::ExternalMessage, self::ExternalProviderMutation, self::Financial, self::Publication => true,
        };
    }

    /** Classes that may NEVER be pre-approved, batched or run by AI. */
    public function alwaysExplicit(): bool
    {
        return in_array($this, [self::Financial, self::Publication, self::ExternalProviderMutation, self::ExternalMessage], true);
    }
}

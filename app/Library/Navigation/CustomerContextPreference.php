<?php

namespace App\Library\Navigation;

/**
 * The remembered "last selected Business" — a NAVIGATION PREFERENCE only.
 *
 * It is never authorization (Slice 1B brief §3 rule 10): the resolver
 * re-authorizes whatever it remembers through
 * WorkspaceManager::userCanAccessBusiness() on every request that relies
 * on it, and clears it the moment it no longer names an accessible, active
 * Business inside a Workspace the actor can see.
 */
final class CustomerContextPreference
{
    public const SESSION_KEY = 'customer_context';

    /**
     * @return array{workspace: ?string, business: ?string, accountFrame: bool}
     */
    public function get(): array
    {
        $stored = session(self::SESSION_KEY);

        if (! is_array($stored)) {
            return ['workspace' => null, 'business' => null, 'accountFrame' => false];
        }

        return [
            'workspace' => isset($stored['workspace']) && is_string($stored['workspace']) ? $stored['workspace'] : null,
            'business' => isset($stored['business']) && is_string($stored['business']) ? $stored['business'] : null,
            'accountFrame' => ($stored['accountFrame'] ?? false) === true,
        ];
    }

    /**
     * Remembering a Business is always a Business-frame intent, so it clears
     * any standing "stay at the account level" choice.
     */
    public function remember(string $workspaceUid, ?string $businessUid): void
    {
        session([self::SESSION_KEY => ['workspace' => $workspaceUid, 'business' => $businessUid, 'accountFrame' => false]]);
    }

    /**
     * The actor DELIBERATELY chose this account's own frame (the context
     * switcher's account option).
     *
     * Without this intent the resolver would helpfully re-enter the only
     * Business it can see and the choice would be undone on the next request —
     * which is right when nothing was chosen, and wrong the moment the actor
     * asked for the account. It is a NAVIGATION PREFERENCE exactly like the
     * remembered Business: the resolver still re-authorizes the account on
     * every request and clears it the moment it stops being reachable, and it
     * grants nothing on its own.
     */
    public function rememberAccount(string $workspaceUid): void
    {
        session([self::SESSION_KEY => ['workspace' => $workspaceUid, 'business' => null, 'accountFrame' => true]]);
    }

    /**
     * Drop a Business that turned out to be unusable. This is the resolver's
     * own housekeeping, never an account-frame choice by the actor, so the
     * intent flag stays false and the sole-Business rules still apply.
     */
    public function forgetBusiness(): void
    {
        $current = $this->get();

        if ($current['workspace'] === null) {
            $this->forget();

            return;
        }

        session([self::SESSION_KEY => ['workspace' => $current['workspace'], 'business' => null, 'accountFrame' => false]]);
    }

    public function forget(): void
    {
        session()->forget(self::SESSION_KEY);
    }
}

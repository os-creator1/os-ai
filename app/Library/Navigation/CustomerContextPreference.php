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
     * @return array{workspace: ?string, business: ?string}
     */
    public function get(): array
    {
        $stored = session(self::SESSION_KEY);

        if (! is_array($stored)) {
            return ['workspace' => null, 'business' => null];
        }

        return [
            'workspace' => isset($stored['workspace']) && is_string($stored['workspace']) ? $stored['workspace'] : null,
            'business' => isset($stored['business']) && is_string($stored['business']) ? $stored['business'] : null,
        ];
    }

    public function remember(string $workspaceUid, ?string $businessUid): void
    {
        session([self::SESSION_KEY => ['workspace' => $workspaceUid, 'business' => $businessUid]]);
    }

    public function forgetBusiness(): void
    {
        $current = $this->get();

        if ($current['workspace'] === null) {
            $this->forget();

            return;
        }

        session([self::SESSION_KEY => ['workspace' => $current['workspace'], 'business' => null]]);
    }

    public function forget(): void
    {
        session()->forget(self::SESSION_KEY);
    }
}

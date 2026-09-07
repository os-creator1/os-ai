<?php

namespace App\DTO\GoogleBusinessProfile;

use App\Library\GoogleBusinessProfile\GoogleProviderValueNormalizer as N;

/**
 * GBP Slice A contract §20.3 — one Google account the authorized user can
 * reach, from Account Management v1 `accounts.list`.
 *
 * A DTO never holds a raw provider array (contract §20.3). An account
 * whose resource name does not match `accounts/{id}` is DISCARDED by the
 * caller, not repaired.
 */
final readonly class GoogleAccountSummary
{
    /**
     * Contract §7.1 — the documented AccountType values. An unrecognised
     * future value degrades to null rather than being displayed verbatim.
     */
    private const TYPES = [
        'ACCOUNT_TYPE_UNSPECIFIED',
        'PERSONAL',
        'LOCATION_GROUP',
        'USER_GROUP',
        'ORGANIZATION',
    ];

    /**
     * Contract §7.1 — the documented AccountRole values.
     */
    private const ROLES = [
        'ACCOUNT_ROLE_UNSPECIFIED',
        'PRIMARY_OWNER',
        'OWNER',
        'MANAGER',
        'SITE_MANAGER',
    ];

    private const VERIFICATION_STATES = [
        'VERIFICATION_STATE_UNSPECIFIED',
        'VERIFIED',
        'UNVERIFIED',
        'VERIFICATION_REQUESTED',
    ];

    public function __construct(
        public string $resourceName,
        public ?string $accountName,
        public ?string $type,
        public ?string $role,
        public ?string $verificationState,
    ) {
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromProviderArray(array $raw): ?self
    {
        $resourceName = N::accountResourceName($raw['name'] ?? null);

        if ($resourceName === null) {
            return null;
        }

        return new self(
            resourceName: $resourceName,
            accountName: N::string($raw['accountName'] ?? null, 191),
            type: N::enumValue($raw['type'] ?? null, self::TYPES),
            role: N::enumValue($raw['role'] ?? null, self::ROLES),
            verificationState: N::enumValue($raw['verificationState'] ?? null, self::VERIFICATION_STATES),
        );
    }

    public function displayName(): string
    {
        return $this->accountName ?? $this->resourceName;
    }

    public function roleLabel(): string
    {
        return match ($this->role) {
            'PRIMARY_OWNER' => 'Primary owner',
            'OWNER' => 'Owner',
            'MANAGER' => 'Manager',
            'SITE_MANAGER' => 'Site manager',
            default => 'Unknown role',
        };
    }
}

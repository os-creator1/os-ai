<?php

namespace App\Library\Domains;

use RuntimeException;

/** A hostname cannot be claimed. The message is safe to show. */
final class DomainUnavailableException extends RuntimeException
{
    public const TAKEN = 'domain_taken';

    public const PLATFORM = 'domain_reserved';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function taken(): self
    {
        return new self(self::TAKEN, 'That domain is already in use.');
    }

    public static function platform(): self
    {
        return new self(self::PLATFORM, 'That domain belongs to the platform and cannot be used.');
    }
}

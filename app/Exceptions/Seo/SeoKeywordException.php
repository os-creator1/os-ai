<?php

namespace App\Exceptions\Seo;

use RuntimeException;

/**
 * Contract 18 §8.4 — every refusal SeoKeywordManager can make, as one closed
 * vocabulary. `reason` is machine-readable; `customerMessage()` is calm,
 * fixed copy that never echoes customer input or reveals another tenant's or
 * Location's data.
 */
final class SeoKeywordException extends RuntimeException
{
    public const ACCESS_DENIED = 'access_denied';
    public const INVALID_PHRASE = 'invalid_phrase';
    public const DUPLICATE = 'duplicate';
    public const LIMIT_REACHED = 'limit_reached';
    public const LOCATION_NOT_ACTIVE = 'location_not_active';
    public const NOT_ACTIVE = 'not_active';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    /** The actor may not reach this Business, Location or keyword. Callers answer 404. */
    public static function accessDenied(): self
    {
        return new self(self::ACCESS_DENIED, 'The actor may not manage this keyword.');
    }

    public static function invalidPhrase(): self
    {
        return new self(self::INVALID_PHRASE, 'The keyword is empty, too long or contains unsupported characters.');
    }

    public static function duplicate(): self
    {
        return new self(self::DUPLICATE, 'This keyword already exists for this Business and Location.');
    }

    public static function limitReached(int $limit): self
    {
        return new self(self::LIMIT_REACHED, "The Business already has {$limit} active keywords.");
    }

    public static function locationNotActive(): self
    {
        return new self(self::LOCATION_NOT_ACTIVE, 'The Location is not active.');
    }

    public static function notActive(): self
    {
        return new self(self::NOT_ACTIVE, 'Archived keywords cannot be edited; reactivate it first.');
    }

    public function customerMessage(): string
    {
        return match ($this->reason) {
            self::INVALID_PHRASE => 'Enter a keyword of up to 120 characters, without line breaks or special control characters.',
            self::DUPLICATE => 'You already have this keyword for that location. It may be archived; if so, reactivate it instead.',
            self::LIMIT_REACHED => 'You have reached the limit of active keywords. Archive one to add another.',
            self::LOCATION_NOT_ACTIVE => 'That location is archived, so its keywords cannot be changed.',
            self::NOT_ACTIVE => 'This keyword is archived. Reactivate it before editing it.',
            default => 'That keyword could not be found.',
        };
    }
}

<?php

namespace App\Exceptions\Seo;

use RuntimeException;

/**
 * Contract 18 §8.6 — every refusal the review managers can make, as one
 * closed vocabulary. `reason` is machine-readable; `customerMessage()` is
 * calm, fixed copy that never echoes customer input or reveals another
 * tenant's, Location's or Contact's data. An unknown, foreign and
 * inaccessible Business/Location/request are ALL `access_denied` (callers
 * answer 404); an unknown and a foreign Contact are the same
 * `contact_invalid`.
 */
final class SeoReviewException extends RuntimeException
{
    public const ACCESS_DENIED = 'access_denied';
    public const LOCATION_NOT_ACTIVE = 'location_not_active';
    public const INVALID_URL = 'invalid_url';
    public const INVALID_CHANNEL = 'invalid_channel';
    public const CONTACT_FORBIDDEN = 'contact_forbidden';
    public const CONTACT_INVALID = 'contact_invalid';
    public const CONTACT_LOCATION_MISMATCH = 'contact_location_mismatch';
    public const OPPORTUNITY_INVALID = 'opportunity_invalid';
    public const COOLDOWN = 'cooldown';
    public const INVALID_OUTCOME = 'invalid_outcome';
    public const ALREADY_RESOLVED = 'already_resolved';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function accessDenied(): self
    {
        return new self(self::ACCESS_DENIED, 'The actor may not manage this review record.');
    }

    public static function locationNotActive(): self
    {
        return new self(self::LOCATION_NOT_ACTIVE, 'The location is not active.');
    }

    public static function invalidUrl(): self
    {
        return new self(self::INVALID_URL, 'The review link is not a valid https link.');
    }

    public static function invalidChannel(): self
    {
        return new self(self::INVALID_CHANNEL, 'The channel is not one of the recorded channels.');
    }

    public static function contactForbidden(): self
    {
        return new self(self::CONTACT_FORBIDDEN, 'The actor may not use Contacts.');
    }

    public static function contactInvalid(): self
    {
        return new self(self::CONTACT_INVALID, 'The contact is unknown or belongs to another business.');
    }

    public static function contactLocationMismatch(): self
    {
        return new self(self::CONTACT_LOCATION_MISMATCH, 'The contact does not belong to this location.');
    }

    public static function opportunityInvalid(): self
    {
        return new self(self::OPPORTUNITY_INVALID, 'The opportunity does not match this business, contact and location.');
    }

    public static function cooldown(): self
    {
        return new self(self::COOLDOWN, 'This contact was already asked at this location inside the cooldown window.');
    }

    public static function invalidOutcome(): self
    {
        return new self(self::INVALID_OUTCOME, 'A request can only be marked reviewed or declined.');
    }

    public static function alreadyResolved(): self
    {
        return new self(self::ALREADY_RESOLVED, 'This request already has an outcome.');
    }

    /** Calm, fixed, tenant-safe copy for the UI. */
    public function customerMessage(): string
    {
        return match ($this->reason) {
            self::LOCATION_NOT_ACTIVE => 'This location is archived, so its review records can no longer be changed.',
            self::INVALID_URL => 'Enter a full link that starts with https://.',
            self::INVALID_CHANNEL => 'Choose how the person was asked.',
            self::CONTACT_FORBIDDEN => 'You do not have permission to choose a contact.',
            self::CONTACT_INVALID, self::CONTACT_LOCATION_MISMATCH => 'That contact cannot be used for this location.',
            self::OPPORTUNITY_INVALID => 'That opportunity cannot be linked to this request.',
            self::COOLDOWN => 'This person was already asked recently at this location. Try again after the cooldown window, or mark the earlier request as declined.',
            self::INVALID_OUTCOME => 'Choose reviewed or declined.',
            self::ALREADY_RESOLVED => 'This request already has an outcome.',
            default => 'That could not be saved.',
        };
    }
}

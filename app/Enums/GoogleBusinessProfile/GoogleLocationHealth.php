<?php

namespace App\Enums\GoogleBusinessProfile;

/**
 * GBP Slice A contract §21.3 — a small PLATFORM vocabulary derived from
 * signals Google spreads across two products (VoiceOfMerchantState in
 * Verifications v1; Location.metadata / Location.openInfo in Business
 * Information v1).
 *
 * This is deliberately NOT "one unified Google enum": the underlying
 * Google signals stay individually visible on the binding row, and no
 * state is ever auto-remediated (Slice A initiates no verification and
 * resolves no duplicate).
 */
enum GoogleLocationHealth: string
{
    case OwnershipConflict = 'ownership_conflict';
    case Suspended = 'suspended';
    case Disabled = 'disabled';
    case Duplicate = 'duplicate';
    case VerificationPending = 'verification_pending';
    case AwaitingReview = 'awaiting_review';
    case Verified = 'verified';
    case Unverified = 'unverified';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::OwnershipConflict => 'Ownership conflict',
            self::Suspended => 'Suspended',
            self::Disabled => 'Disabled',
            self::Duplicate => 'Duplicate listing',
            self::VerificationPending => 'Verification pending',
            self::AwaitingReview => 'Awaiting Google review',
            self::Verified => 'Verified',
            self::Unverified => 'Not verified',
            self::Unknown => 'Unknown',
        };
    }
}

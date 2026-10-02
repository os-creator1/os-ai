<?php

namespace App\Exceptions\Branding;

use RuntimeException;

/**
 * Agency V1 completion — a refusal from AgencyWhiteLabelManager, as a closed
 * reason vocabulary (the same shape as AgencyBillingException). The message is
 * always safe to show: it never carries a path, a stack detail or another
 * Workspace's data.
 */
final class AgencyWhiteLabelException extends RuntimeException
{
    /** Only the Agency Workspace OWNER may change the Agency's branding. */
    public const NOT_OWNER = 'not_owner';

    /** The Workspace is not currently an eligible Agency (tier, or a usable account). */
    public const NOT_ELIGIBLE = 'not_eligible';

    /** The Agency's plan does not include white label. */
    public const NOT_ENTITLED = 'not_entitled';

    public const INVALID_NAME = 'invalid_name';

    public const INVALID_TAGLINE = 'invalid_tagline';

    public const INVALID_ACCENT = 'invalid_accent';

    public const INVALID_SUPPORT_EMAIL = 'invalid_support_email';

    public const INVALID_LOGO = 'invalid_logo';

    public const LOGO_STORAGE_FAILED = 'logo_storage_failed';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function because(string $reason, ?string $detail = null): self
    {
        return new self($reason, $detail ?? match ($reason) {
            self::NOT_OWNER => 'Only the agency owner can change white label settings.',
            self::NOT_ELIGIBLE => 'White label is only available to an agency account that is currently in good standing.',
            self::NOT_ENTITLED => 'Your plan does not include white label.',
            self::INVALID_NAME => 'Enter a name between 1 and 80 characters, without HTML.',
            self::INVALID_TAGLINE => 'The tagline can be at most 160 characters, without HTML.',
            self::INVALID_ACCENT => 'Enter the accent colour as a 6-digit hex value such as #1a73e8.',
            self::INVALID_SUPPORT_EMAIL => 'Enter a valid support email address.',
            self::INVALID_LOGO => 'That logo could not be used.',
            self::LOGO_STORAGE_FAILED => 'The logo could not be saved. Please try again.',
            default => 'That change could not be made.',
        });
    }
}

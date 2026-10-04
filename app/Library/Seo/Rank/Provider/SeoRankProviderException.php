<?php

namespace App\Library\Seo\Rank\Provider;

use RuntimeException;

/**
 * A bounded, sanitized provider failure. The message is ALWAYS fixed copy and
 * `code` a closed vocabulary: provider bodies, URLs and credentials never
 * reach logs, the database or a customer.
 */
final class SeoRankProviderException extends RuntimeException
{
    /** The provider definitively did not create a task: no charge, safe to retry. */
    public const REJECTED = 'rejected';
    /** Unknown whether a task/charge exists: never resubmit automatically. */
    public const AMBIGUOUS = 'ambiguous';

    public const CODE_NOT_CONFIGURED = 'not_configured';
    public const CODE_AUTH = 'provider_auth';
    public const CODE_PAYMENT = 'provider_payment';
    public const CODE_RATE_LIMIT = 'provider_rate_limit';
    public const CODE_INVALID_REQUEST = 'provider_invalid_request';
    public const CODE_TASK_FAILED = 'provider_task_failed';
    public const CODE_UNAVAILABLE = 'provider_unavailable';
    public const CODE_BAD_RESPONSE = 'provider_bad_response';

    public function __construct(
        public readonly string $outcome,
        public readonly string $errorCode,
    ) {
        parent::__construct('Rank provider call failed (' . $errorCode . ').');
    }

    public static function rejected(string $errorCode): self
    {
        return new self(self::REJECTED, $errorCode);
    }

    public static function ambiguous(string $errorCode): self
    {
        return new self(self::AMBIGUOUS, $errorCode);
    }

    public function isAmbiguous(): bool
    {
        return $this->outcome === self::AMBIGUOUS;
    }
}

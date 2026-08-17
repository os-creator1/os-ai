<?php

namespace App\Enums\Usage;

/**
 * business_funding_attempts.state (RFC-005 §17.C, M3 contract §21.3).
 * Refunded/Disputed exist at the schema/enum level only — no M3 code path
 * ever writes them (M3 contract §7, §11 item 12).
 */
enum FundingAttemptState: string
{
    case Created = 'created';
    case ProviderPending = 'provider_pending';
    case RequiresAction = 'requires_action';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Canceled = 'canceled';
    case Refunded = 'refunded';
    case Disputed = 'disputed';
}

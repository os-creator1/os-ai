<?php

namespace App\Library\Usage;

/**
 * RFC-005 Remediation #6 §19 — a small, stateless, pure policy class; the
 * single source of truth for the normalized retry-attempt ceiling. Every
 * consumer of usage_billing.webhook_event.max_attempts semantics
 * (ProcessPaymentProviderEvent's claim() eligibility,
 * PaymentProviderEventController's exhausted()/disposition eligibility,
 * RetryStuckPaymentProviderEvents, and EloquentPaymentProviderEventRepository::retryable(),
 * which additionally re-clamps defensively) calls this same method — none
 * re-derives or independently casts the raw config value.
 *
 * MAX_ATTEMPTS_CEILING is intentionally a plain class constant, never a
 * config key: an operator can still tune how many attempts an event gets
 * below the ceiling, but cannot raise the ceiling itself, which is what
 * keeps the query-count bound mechanical rather than operator-defeatable.
 */
final class PaymentProviderEventRetryPolicy
{
    public const MAX_ATTEMPTS_CEILING = 20;

    public static function normalizedMaxAttempts(): int
    {
        return max(0, min((int) config('usage_billing.webhook_event.max_attempts'), self::MAX_ATTEMPTS_CEILING));
    }
}

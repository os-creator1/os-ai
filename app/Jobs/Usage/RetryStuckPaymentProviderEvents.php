<?php

namespace App\Jobs\Usage;

use App\Jobs\Base;
use App\Library\Usage\PaymentProviderEventRetryPolicy;
use App\Repositories\Contracts\PaymentProviderEventRepository;

/**
 * RFC-005 Remediation #6 §19 — the scanner half of the retry/reclaim
 * design. Reads $maxAttempts from the identical, centrally shared
 * PaymentProviderEventRetryPolicy::normalizedMaxAttempts() every other
 * consumer uses, and the received-row grace interval from the existing
 * usage_billing.webhook_event.lease_minutes config value (not a new
 * config key). BATCH_LIMIT is a plain class constant, always >=
 * PaymentProviderEventRetryPolicy::MAX_ATTEMPTS_CEILING, so retryable()'s
 * own fairness-floor validation never throws in production.
 *
 * Performs zero accounting mutation itself — every candidate is simply
 * re-dispatched through ProcessPaymentProviderEvent's own existing,
 * atomic claim/process/terminal-state machinery.
 */
class RetryStuckPaymentProviderEvents extends Base
{
    public const BATCH_LIMIT = 200;

    public function handle(PaymentProviderEventRepository $eventRepository): void
    {
        $maxAttempts = PaymentProviderEventRetryPolicy::normalizedMaxAttempts();
        $receivedGraceMinutes = (int) config('usage_billing.webhook_event.lease_minutes');

        $candidates = $eventRepository->retryable($maxAttempts, $receivedGraceMinutes, self::BATCH_LIMIT);

        foreach ($candidates as $candidate) {
            ProcessPaymentProviderEvent::dispatch((int) $candidate->id);
        }
    }
}

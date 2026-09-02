<?php

namespace App\Jobs\Usage;

use App\Jobs\Base;
use App\Repositories\Contracts\PaymentProviderEventRepository;

/**
 * M3 contract §14 — nulls payload_encrypted for every terminal
 * (processed/ignored/disposed) event past the configured retention
 * window. A merely-exhausted-but-not-yet-disposed failed/stale-processing
 * row is never purged (M3 contract §14).
 */
class PurgeExpiredWebhookPayloads extends Base
{
    public function handle(PaymentProviderEventRepository $eventRepository): void
    {
        $retentionDays = $this->resolvedRetentionDays();

        if ($retentionDays === null) {
            return;
        }

        foreach ($eventRepository->purgeable($retentionDays) as $event) {
            $eventRepository->purgePayload($event->id);
        }
    }

    /**
     * Mirrors Kernel::opportunitySnoozeSweepCronMinutes()'s own validation
     * idiom (RFC-002 §14/§33): a raw, possibly-string, env-sourced config
     * value must be an int, or a digit-only string representing one,
     * before it is trusted as a retention window. Absent, blank,
     * non-digit, zero, or negative all resolve to null — purging
     * disabled for this run — never a silently cast "purge everything
     * already completed" default (config/usage_billing.php's own
     * docblock: retention must fail closed, never purge immediately).
     */
    private function resolvedRetentionDays(): ?int
    {
        $configured = config('usage_billing.webhook_event.retention_days');

        if (! is_int($configured) && ! (is_string($configured) && ctype_digit($configured))) {
            return null;
        }

        $days = (int) $configured;

        return $days > 0 ? $days : null;
    }
}

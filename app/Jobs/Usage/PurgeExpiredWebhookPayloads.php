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
        $retentionDays = (int) config('usage_billing.webhook_event.retention_days');

        foreach ($eventRepository->purgeable($retentionDays) as $event) {
            $eventRepository->purgePayload($event->id);
        }
    }
}

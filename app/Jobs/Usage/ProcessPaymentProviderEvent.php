<?php

namespace App\Jobs\Usage;

use App\Jobs\Base;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Repositories\Contracts\BusinessFundingAttemptRepository;
use App\Repositories\Contracts\PaymentProviderEventRepository;
use Illuminate\Support\Facades\Log;

/**
 * M3 contract §13/§14 — claims one payment_provider_events row via the
 * atomic, bounded claim algorithm, then processes it: the event's own
 * metadata hint (never event_type) selects exactly one local table+row,
 * which is then validated in full against the verified event before any
 * mutation. Missing/malformed/unknown/ambiguous/mismatched metadata or a
 * validation failure causes zero mutation and marks the event failed,
 * routing it to reconciliation (§14's exhausted-event queue).
 */
class ProcessPaymentProviderEvent extends Base
{
    public function __construct(
        private readonly int $eventId,
    ) {
    }

    public function handle(
        PaymentProviderEventRepository $eventRepository,
        BusinessFundingAttemptRepository $attemptRepository,
        UsageBillingCheckoutManager $checkoutManager,
    ): void {
        $leaseMinutes = (int) config('usage_billing.webhook_event.lease_minutes');
        $maxAttempts = (int) config('usage_billing.webhook_event.max_attempts');

        $claimed = $eventRepository->claim($this->eventId, $leaseMinutes, $maxAttempts);

        if ($claimed === 0) {
            return;
        }

        $event = $eventRepository->findById($this->eventId);

        if ($event === null) {
            return;
        }

        try {
            $this->process($event, $attemptRepository, $checkoutManager, $eventRepository);
        } catch (\Throwable $e) {
            Log::warning('Payment provider event processing failed.', [
                'event_id' => $event->id,
                'provider_event_id' => $event->provider_event_id,
                'classification' => $e::class,
            ]);
            $eventRepository->markFailed($event->id, $e::class);
        }
    }

    private function process($event, BusinessFundingAttemptRepository $attemptRepository, UsageBillingCheckoutManager $checkoutManager, PaymentProviderEventRepository $eventRepository): void
    {
        $metadata = null;

        if ($event->payload_encrypted !== null) {
            $decoded = json_decode($event->payload_encrypted, true);
            $metadata = $decoded['data']['object']['metadata'] ?? null;
        }

        if (! is_array($metadata) || ($metadata['app_subject_kind'] ?? null) !== 'funding_attempt' || empty($metadata['app_subject_id'])) {
            $eventRepository->markFailed($event->id, 'missing_or_unrecognized_metadata');

            return;
        }

        $attempt = $attemptRepository->findById((int) $metadata['app_subject_id']);

        if ($attempt === null) {
            $eventRepository->markFailed($event->id, 'no_matching_local_record');

            return;
        }

        // Validate every applicable persisted expectation before any
        // mutation (M3 contract §13 step 10) — provider object id and
        // operation identifier against this exact attempt's own frozen
        // values.
        if ($attempt->provider_session_or_intent_reference !== $event->provider_object_id) {
            $eventRepository->markFailed($event->id, 'provider_object_id_mismatch');

            return;
        }

        if (($metadata['app_operation_id'] ?? null) !== $attempt->local_idempotency_key) {
            $eventRepository->markFailed($event->id, 'operation_id_mismatch');

            return;
        }

        $object = $decoded['data']['object'] ?? [];

        if (array_key_exists('amount', $object) && (int) $object['amount'] !== $checkoutManager->expectedMinorUnitsFor($attempt)) {
            $eventRepository->markFailed($event->id, 'amount_mismatch');

            return;
        }

        if (array_key_exists('currency', $object) && strtoupper((string) $object['currency']) !== $checkoutManager->expectedCurrencyCodeFor($attempt)) {
            $eventRepository->markFailed($event->id, 'currency_mismatch');

            return;
        }

        if (array_key_exists('customer', $object) && (string) $object['customer'] !== $attempt->provider_customer_external_id_snapshot) {
            $eventRepository->markFailed($event->id, 'customer_mismatch');

            return;
        }

        if (str_ends_with((string) $event->event_type, '.succeeded')) {
            $checkoutManager->confirmAttemptFromWebhook($attempt, $event);
            $eventRepository->markProcessed($event->id);

            return;
        }

        if (str_ends_with((string) $event->event_type, '.payment_failed') || str_contains((string) $event->event_type, 'canceled')) {
            $checkoutManager->markAttemptFailedFromWebhook($attempt, 'provider_reported_failure', $event);
            $eventRepository->markProcessed($event->id);

            return;
        }

        // Any other event_type (e.g. .requires_action, .created) carries
        // only provider-object lifecycle information this job does not
        // need to act on — intentionally ignored, never a mutation.
        $eventRepository->markIgnored($event->id);
    }
}

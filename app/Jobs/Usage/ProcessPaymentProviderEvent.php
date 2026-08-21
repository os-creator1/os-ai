<?php

namespace App\Jobs\Usage;

use App\Jobs\Base;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Repositories\Contracts\AdditionalBusinessSlotAgreementRepository;
use App\Repositories\Contracts\AdditionalBusinessSlotRenewalChargeRepository;
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
 *
 * M4 contract §15 (Correction Round 1 §A) — widened from a single
 * hardcoded 'funding_attempt' subject-kind check to a small, explicit
 * dispatch over exactly three recognized kinds. This job never mutates a
 * renewal-charge or agreement row itself — every mutation routes through
 * UsageBillingCheckoutManager's own named methods.
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
        AdditionalBusinessSlotAgreementRepository $agreementRepository,
        AdditionalBusinessSlotRenewalChargeRepository $renewalChargeRepository,
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
            $metadata = null;
            $decoded = null;

            if ($event->payload_encrypted !== null) {
                $decoded = json_decode($event->payload_encrypted, true);
                $metadata = $decoded['data']['object']['metadata'] ?? null;
            }

            $subjectKind = is_array($metadata) ? ($metadata['app_subject_kind'] ?? null) : null;

            match ($subjectKind) {
                'funding_attempt' => $this->processFundingAttempt($event, $metadata, $decoded, $attemptRepository, $checkoutManager, $eventRepository),
                'slot_renewal_charge' => $this->processSlotRenewalCharge($event, $metadata, $decoded, $renewalChargeRepository, $checkoutManager, $eventRepository),
                'slot_agreement' => $this->processSlotAgreementInitialCheckout($event, $metadata, $decoded, $agreementRepository, $checkoutManager, $eventRepository),
                default => $eventRepository->markFailed($event->id, 'missing_or_unrecognized_metadata'),
            };
        } catch (\Throwable $e) {
            Log::warning('Payment provider event processing failed.', [
                'event_id' => $event->id,
                'provider_event_id' => $event->provider_event_id,
                'classification' => $e::class,
            ]);
            $eventRepository->markFailed($event->id, $e::class);
        }
    }

    /**
     * M3 contract §13 — unchanged behavior. The purpose-aware dispatch
     * (ManualTopUp/AutoRecharge/AddonPurchase) lives entirely inside
     * UsageBillingCheckoutManager's own confirmAttemptFromWebhook()/
     * confirmSucceeded() (M4 contract §21) — this branch never inspects
     * purpose itself.
     */
    private function processFundingAttempt($event, array $metadata, ?array $decoded, BusinessFundingAttemptRepository $attemptRepository, UsageBillingCheckoutManager $checkoutManager, PaymentProviderEventRepository $eventRepository): void
    {
        if (empty($metadata['app_subject_id'])) {
            $eventRepository->markFailed($event->id, 'missing_or_unrecognized_metadata');

            return;
        }

        $attempt = $attemptRepository->findById((int) $metadata['app_subject_id']);

        if ($attempt === null) {
            $eventRepository->markFailed($event->id, 'no_matching_local_record');

            return;
        }

        if ($attempt->provider_session_or_intent_reference !== $event->provider_object_id) {
            $eventRepository->markFailed($event->id, 'provider_object_id_mismatch');

            return;
        }

        if (($metadata['app_operation_id'] ?? null) !== $attempt->local_idempotency_key) {
            $eventRepository->markFailed($event->id, 'operation_id_mismatch');

            return;
        }

        $object = $decoded['data']['object'] ?? [];

        // M4 contract §15 (Correction Round 2 §E.6) — a payment_intent
        // event always carries amount/currency/customer; a genuinely
        // absent field is missing required evidence, not "check skipped".
        if (! array_key_exists('amount', $object)) {
            $eventRepository->markFailed($event->id, 'missing_required_evidence');

            return;
        }

        if ((int) $object['amount'] !== $checkoutManager->expectedMinorUnitsFor($attempt)) {
            $eventRepository->markFailed($event->id, 'amount_mismatch');

            return;
        }

        if (! array_key_exists('currency', $object)) {
            $eventRepository->markFailed($event->id, 'missing_required_evidence');

            return;
        }

        if (strtoupper((string) $object['currency']) !== $checkoutManager->expectedCurrencyCodeFor($attempt)) {
            $eventRepository->markFailed($event->id, 'currency_mismatch');

            return;
        }

        if (! array_key_exists('customer', $object)) {
            $eventRepository->markFailed($event->id, 'missing_required_evidence');

            return;
        }

        if ((string) $object['customer'] !== $attempt->provider_customer_external_id_snapshot) {
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

    /**
     * M4 contract §15 (Correction Round 1 §A) — every renewal/mid-period-
     * increase charge's own off-session PaymentIntent webhook
     * confirmation. Never mutates the charge itself — dispatches to
     * confirmSlotRenewalChargeFromWebhook()/markSlotRenewalChargeFailedFromWebhook()
     * once the identical pre-mutation validation sequence has passed.
     */
    private function processSlotRenewalCharge($event, array $metadata, ?array $decoded, AdditionalBusinessSlotRenewalChargeRepository $renewalChargeRepository, UsageBillingCheckoutManager $checkoutManager, PaymentProviderEventRepository $eventRepository): void
    {
        if (empty($metadata['app_subject_id'])) {
            $eventRepository->markFailed($event->id, 'missing_or_unrecognized_metadata');

            return;
        }

        $charge = $renewalChargeRepository->findById((int) $metadata['app_subject_id']);

        if ($charge === null) {
            $eventRepository->markFailed($event->id, 'no_matching_local_record');

            return;
        }

        if ($charge->provider_session_or_intent_reference !== $event->provider_object_id) {
            $eventRepository->markFailed($event->id, 'provider_object_id_mismatch');

            return;
        }

        if (($metadata['app_operation_id'] ?? null) !== $charge->local_idempotency_key) {
            $eventRepository->markFailed($event->id, 'operation_id_mismatch');

            return;
        }

        $object = $decoded['data']['object'] ?? [];

        // M4 contract §15 (Correction Round 2 §E.6) — a payment_intent
        // event always carries amount/currency/customer; a genuinely
        // absent field is missing required evidence, not "check skipped".
        if (! array_key_exists('amount', $object)) {
            $eventRepository->markFailed($event->id, 'missing_required_evidence');

            return;
        }

        if ((int) $object['amount'] !== $checkoutManager->expectedMinorUnitsForRenewalCharge($charge)) {
            $eventRepository->markFailed($event->id, 'amount_mismatch');

            return;
        }

        if (! array_key_exists('currency', $object)) {
            $eventRepository->markFailed($event->id, 'missing_required_evidence');

            return;
        }

        if (strtoupper((string) $object['currency']) !== $checkoutManager->expectedCurrencyCodeForRenewalCharge($charge)) {
            $eventRepository->markFailed($event->id, 'currency_mismatch');

            return;
        }

        if (! array_key_exists('customer', $object)) {
            $eventRepository->markFailed($event->id, 'missing_required_evidence');

            return;
        }

        if ((string) $object['customer'] !== $charge->provider_customer_external_id_snapshot) {
            $eventRepository->markFailed($event->id, 'customer_mismatch');

            return;
        }

        if (str_ends_with((string) $event->event_type, '.succeeded')) {
            $checkoutManager->confirmSlotRenewalChargeFromWebhook($charge, $event);
            $eventRepository->markProcessed($event->id);

            return;
        }

        if (str_ends_with((string) $event->event_type, '.payment_failed') || str_contains((string) $event->event_type, 'canceled')) {
            $checkoutManager->markSlotRenewalChargeFailedFromWebhook($charge, 'provider_reported_failure', $event);
            $eventRepository->markProcessed($event->id);

            return;
        }

        $eventRepository->markIgnored($event->id);
    }

    /**
     * M4 contract §15/§15a — the initial Checkout Session's own webhook
     * confirmation. Never a PaymentIntent event for this step. Amount
     * comparison falls back to amount_total (M4 contract §15b) since a
     * Checkout Session's own payload carries no top-level 'amount' field.
     */
    private function processSlotAgreementInitialCheckout($event, array $metadata, ?array $decoded, AdditionalBusinessSlotAgreementRepository $agreementRepository, UsageBillingCheckoutManager $checkoutManager, PaymentProviderEventRepository $eventRepository): void
    {
        if (empty($metadata['app_subject_id'])) {
            $eventRepository->markFailed($event->id, 'missing_or_unrecognized_metadata');

            return;
        }

        $agreement = $agreementRepository->findById((int) $metadata['app_subject_id']);

        if ($agreement === null) {
            $eventRepository->markFailed($event->id, 'no_matching_local_record');

            return;
        }

        if ($agreement->provider_session_or_intent_reference !== $event->provider_object_id) {
            $eventRepository->markFailed($event->id, 'provider_object_id_mismatch');

            return;
        }

        if (($metadata['app_operation_id'] ?? null) !== $agreement->local_idempotency_key) {
            $eventRepository->markFailed($event->id, 'operation_id_mismatch');

            return;
        }

        $object = $decoded['data']['object'] ?? [];

        // M4 contract §15/§15b (Correction Round 2 §E.6) — a Checkout
        // Session event always carries at least one of amount/amount_total,
        // and always carries currency/customer; genuinely absent required
        // evidence fails closed rather than being treated as "check
        // skipped, therefore valid".
        if (! array_key_exists('amount', $object) && ! array_key_exists('amount_total', $object)) {
            $eventRepository->markFailed($event->id, 'missing_required_evidence');

            return;
        }

        $amount = $object['amount'] ?? $object['amount_total'];

        if ((int) $amount !== $checkoutManager->expectedMinorUnitsForAgreement($agreement)) {
            $eventRepository->markFailed($event->id, 'amount_mismatch');

            return;
        }

        if (! array_key_exists('currency', $object)) {
            $eventRepository->markFailed($event->id, 'missing_required_evidence');

            return;
        }

        if (strtoupper((string) $object['currency']) !== $checkoutManager->expectedCurrencyCodeForAgreement($agreement)) {
            $eventRepository->markFailed($event->id, 'currency_mismatch');

            return;
        }

        if (! array_key_exists('customer', $object)) {
            $eventRepository->markFailed($event->id, 'missing_required_evidence');

            return;
        }

        $agreement->loadMissing('providerCustomer');

        if ($agreement->providerCustomer === null || (string) $object['customer'] !== $agreement->providerCustomer->provider_customer_id) {
            $eventRepository->markFailed($event->id, 'customer_mismatch');

            return;
        }

        if (str_ends_with((string) $event->event_type, '.completed') || str_ends_with((string) $event->event_type, '.async_payment_succeeded')) {
            $checkoutManager->confirmSlotAgreementFromWebhook($agreement, $event);
            $eventRepository->markProcessed($event->id);

            return;
        }

        // Session expiry/async-payment-failure carries no authorized
        // recovery path (M4 contract §21 names no
        // markSlotAgreementFailedFromWebhook()-shaped method) —
        // intentionally ignored, never a mutation.
        $eventRepository->markIgnored($event->id);
    }
}

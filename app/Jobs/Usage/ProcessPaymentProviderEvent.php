<?php

namespace App\Jobs\Usage;

use App\Jobs\Base;
use App\Library\Usage\PaymentProviderEventRetryPolicy;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Models\BusinessFundingAttempt;
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
 *
 * RFC-005 Remediation #6 §2 — a refund/dispute/refund-object event never
 * carries this app's own app_subject_kind metadata (Charge/Dispute/Refund
 * metadata is independent, never inherited from the originating
 * PaymentIntent), so these ten event types are routed by event_type
 * directly, before the metadata-based dispatch below is ever reached.
 */
class ProcessPaymentProviderEvent extends Base
{
    private const REFUND_DISPUTE_EVENT_TYPES = [
        'charge.refunded',
        'charge.dispute.funds_withdrawn',
        'charge.dispute.funds_reinstated',
        'charge.dispute.created',
        'charge.dispute.updated',
        'charge.dispute.closed',
        'refund.created',
        'refund.updated',
        'refund.failed',
        'charge.refund.updated',
    ];

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
        $maxAttempts = PaymentProviderEventRetryPolicy::normalizedMaxAttempts();

        $claimed = $eventRepository->claim($this->eventId, $leaseMinutes, $maxAttempts);

        if ($claimed === 0) {
            return;
        }

        $event = $eventRepository->findById($this->eventId);

        if ($event === null) {
            return;
        }

        try {
            $decoded = null;

            if ($event->payload_encrypted !== null) {
                $decoded = json_decode($event->payload_encrypted, true);
            }

            if (in_array($event->event_type, self::REFUND_DISPUTE_EVENT_TYPES, true)) {
                $this->processRefundDisputeOutcome($event, is_array($decoded) ? $decoded : [], $attemptRepository, $checkoutManager, $eventRepository);

                return;
            }

            $metadata = is_array($decoded) ? ($decoded['data']['object']['metadata'] ?? null) : null;
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
     * M3 contract §13 — unchanged behavior for AutoRecharge.
     *
     * RFC-005 Funding Provider-Flow Correction Contract §10/§11 — becomes
     * purpose-aware, branching on the already-loaded $attempt->purpose
     * (never event_type) to select the expected provider-object family:
     * AutoRecharge remains PaymentIntent-shaped (amount/.succeeded/
     * .payment_failed, unchanged); ManualTopUp/AddonPurchase are
     * Checkout-Session-shaped (amount_total/.completed+.async_payment_succeeded/
     * .expired), mirroring processSlotAgreementInitialCheckout()'s own
     * already-correct pattern. The purpose-based branch runs before any
     * event-type comparison, so an event of the wrong family fails the
     * amount-field-presence check first — a PaymentIntent event can never
     * confirm a Checkout-backed attempt and vice versa.
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
        $isCheckoutBacked = $attempt->purpose === \App\Enums\Usage\FundingAttemptPurpose::ManualTopUp
            || $attempt->purpose === \App\Enums\Usage\FundingAttemptPurpose::AddonPurchase;

        // A payment_intent event always carries amount; a Checkout Session
        // event always carries amount_total — never both, never the other
        // for a given purpose. A genuinely absent field for the expected
        // family is missing required evidence, not "check skipped".
        $amountField = $isCheckoutBacked ? 'amount_total' : 'amount';

        if (! array_key_exists($amountField, $object)) {
            $eventRepository->markFailed($event->id, 'missing_required_evidence');

            return;
        }

        if ((int) $object[$amountField] !== $checkoutManager->expectedMinorUnitsFor($attempt)) {
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

        $successSuffixes = $isCheckoutBacked ? ['.completed', '.async_payment_succeeded'] : ['.succeeded'];
        $failureSuffixes = $isCheckoutBacked ? ['.expired'] : ['.payment_failed'];

        foreach ($successSuffixes as $suffix) {
            if (str_ends_with((string) $event->event_type, $suffix)) {
                $checkoutManager->confirmAttemptFromWebhook($attempt, $event);
                $eventRepository->markProcessed($event->id);

                return;
            }
        }

        foreach ($failureSuffixes as $suffix) {
            if (str_ends_with((string) $event->event_type, $suffix)) {
                $checkoutManager->markAttemptFailedFromWebhook($attempt, 'provider_reported_failure', $event);
                $eventRepository->markProcessed($event->id);

                return;
            }
        }

        if (! $isCheckoutBacked && str_contains((string) $event->event_type, 'canceled')) {
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

    /**
     * RFC-005 Remediation #6 §2 — the top-level dispatch across the ten
     * refund/dispute/refund-object event types, each routed by event_type
     * directly (never metadata).
     */
    private function processRefundDisputeOutcome($event, array $decoded, BusinessFundingAttemptRepository $attemptRepository, UsageBillingCheckoutManager $checkoutManager, PaymentProviderEventRepository $eventRepository): void
    {
        match ($event->event_type) {
            'charge.refunded' => $this->processRefundOutcome($event, $decoded, $attemptRepository, $checkoutManager, $eventRepository),
            'charge.dispute.funds_withdrawn' => $this->processDisputeWithdrawal($event, $decoded, $attemptRepository, $checkoutManager, $eventRepository),
            'charge.dispute.funds_reinstated' => $this->processDisputeReinstatement($event, $decoded, $attemptRepository, $checkoutManager, $eventRepository),
            'charge.dispute.created', 'charge.dispute.updated', 'charge.dispute.closed' => $this->processDisputeLifecycleAuditOnly($event, $decoded, $attemptRepository, $checkoutManager, $eventRepository),
            default => $this->processRefundObjectAuditOnly($event, $decoded, $attemptRepository, $checkoutManager, $eventRepository),
        };
    }

    /**
     * RFC-005 Remediation #6 §3 — resolves both payment_intent and
     * Charge.id/Dispute.charge/Refund.charge independently. Both resolve
     * to the same attempt -> that attempt. Both resolve to different
     * attempts -> ambiguous. Exactly one resolves -> that one. Neither ->
     * null, not ambiguous.
     *
     * @return array{0: ?BusinessFundingAttempt, 1: bool} attempt, ambiguous
     */
    private function resolveFundingAttemptByDualReference(?string $paymentIntentReference, ?string $chargeReference, BusinessFundingAttemptRepository $attemptRepository): array
    {
        $byPaymentIntent = (! blank($paymentIntentReference))
            ? $attemptRepository->findByProviderPaymentIntentReference($paymentIntentReference)
            : null;

        $byCharge = (! blank($chargeReference))
            ? $attemptRepository->findByProviderChargeReference($chargeReference)
            : null;

        if ($byPaymentIntent !== null && $byCharge !== null) {
            if ((int) $byPaymentIntent->id !== (int) $byCharge->id) {
                return [null, true];
            }

            return [$byPaymentIntent, false];
        }

        return [$byPaymentIntent ?? $byCharge, false];
    }

    /**
     * RFC-005 Remediation #6 §5 — locked to zero/one/two entries, exactly
     * one negative and one positive when two are present, no duplicate
     * ids, every entry's own currency matching expected. Does not itself
     * enforce which specific sign(s) a given event type requires — that
     * is event-context-specific and checked by each caller.
     *
     * @return string 'ok'|'malformed'|'currency_mismatch'
     */
    private function validateBalanceTransactions(array $balanceTransactions, string $expectedCurrencyCode): string
    {
        if (count($balanceTransactions) > 2) {
            return 'malformed';
        }

        $seenIds = [];
        $signs = [];

        foreach ($balanceTransactions as $balanceTransaction) {
            if (! is_array($balanceTransaction)
                || ! array_key_exists('id', $balanceTransaction)
                || ! array_key_exists('amount', $balanceTransaction)
                || ! array_key_exists('currency', $balanceTransaction)
            ) {
                return 'malformed';
            }

            $id = (string) $balanceTransaction['id'];

            if (in_array($id, $seenIds, true)) {
                return 'malformed';
            }

            $seenIds[] = $id;
            $signs[] = (int) $balanceTransaction['amount'] <=> 0;

            if (strtoupper((string) $balanceTransaction['currency']) !== $expectedCurrencyCode) {
                return 'currency_mismatch';
            }
        }

        if (count($balanceTransactions) === 2 && (! in_array(-1, $signs, true) || ! in_array(1, $signs, true))) {
            return 'malformed';
        }

        return 'ok';
    }

    /**
     * RFC-005 Remediation #6 §6/§18 — charge.refunded.
     */
    private function processRefundOutcome($event, array $decoded, BusinessFundingAttemptRepository $attemptRepository, UsageBillingCheckoutManager $checkoutManager, PaymentProviderEventRepository $eventRepository): void
    {
        $object = $decoded['data']['object'] ?? [];

        [$attempt, $ambiguous] = $this->resolveFundingAttemptByDualReference($object['payment_intent'] ?? null, $object['id'] ?? null, $attemptRepository);

        if ($ambiguous) {
            $eventRepository->markFailed($event->id, 'cross_reference_ambiguity');

            return;
        }

        if ($attempt === null) {
            $eventRepository->markFailed($event->id, 'no_matching_local_record');

            return;
        }

        if (! array_key_exists('amount_refunded', $object) || ! array_key_exists('currency', $object)) {
            $eventRepository->markFailed($event->id, 'missing_required_evidence');

            return;
        }

        if (strtoupper((string) $object['currency']) !== $checkoutManager->expectedCurrencyCodeFor($attempt)) {
            $eventRepository->markFailed($event->id, 'currency_mismatch');

            return;
        }

        $result = $checkoutManager->applyRefundOutcome($attempt, (int) $object['amount_refunded'], (string) ($object['id'] ?? ''));

        $this->markProcessedWithAttribution($eventRepository, $event, $attempt->business_id, $attempt->id, $result, $checkoutManager->expectedCurrencyCodeFor($attempt));
    }

    /**
     * RFC-005 Remediation #6 §8/§18 — charge.dispute.funds_withdrawn.
     */
    private function processDisputeWithdrawal($event, array $decoded, BusinessFundingAttemptRepository $attemptRepository, UsageBillingCheckoutManager $checkoutManager, PaymentProviderEventRepository $eventRepository): void
    {
        $object = $decoded['data']['object'] ?? [];

        [$attempt, $ambiguous] = $this->resolveFundingAttemptByDualReference($object['payment_intent'] ?? null, $object['charge'] ?? null, $attemptRepository);

        if ($ambiguous) {
            $eventRepository->markFailed($event->id, 'cross_reference_ambiguity');

            return;
        }

        if ($attempt === null) {
            $eventRepository->markFailed($event->id, 'no_matching_local_record');

            return;
        }

        $balanceTransactions = $object['balance_transactions'] ?? [];

        if (! is_array($balanceTransactions)) {
            $eventRepository->markFailed($event->id, 'malformed_balance_transaction_array');

            return;
        }

        if (count($balanceTransactions) === 0) {
            $this->markIgnoredDisputeAuditOnly($eventRepository, $event, $attempt, $checkoutManager, $object);

            return;
        }

        $validation = $this->validateBalanceTransactions($balanceTransactions, $checkoutManager->expectedCurrencyCodeFor($attempt));

        if ($validation === 'currency_mismatch') {
            $eventRepository->markFailed($event->id, 'currency_mismatch');

            return;
        }

        if ($validation === 'malformed') {
            $eventRepository->markFailed($event->id, 'malformed_balance_transaction_array');

            return;
        }

        $negativeEntry = null;

        foreach ($balanceTransactions as $balanceTransaction) {
            if ((int) ($balanceTransaction['amount'] ?? 0) < 0) {
                $negativeEntry = $balanceTransaction;

                break;
            }
        }

        if ($negativeEntry === null) {
            $eventRepository->markFailed($event->id, 'malformed_balance_transaction_array');

            return;
        }

        $result = $checkoutManager->applyDisputeChargebackOutcome(
            $attempt,
            abs((int) $negativeEntry['amount']),
            (string) ($object['id'] ?? ''),
            (string) ($negativeEntry['id'] ?? ''),
        );

        $this->markProcessedWithAttribution($eventRepository, $event, $attempt->business_id, $attempt->id, $result, $checkoutManager->expectedCurrencyCodeFor($attempt));

        // RFC-005 Remediation #6 §11/§26 — at most one dispatch decision
        // per genuinely new DisputeChargeback outcome, never for a
        // replay, never for a refund.
        if ($result->normalizedOutcome === 'dispute_chargeback_applied' && $result->ledgerEntryId !== null) {
            SendChargebackDisputeNotification::dispatch($result->ledgerEntryId)->afterCommit();
        }
    }

    /**
     * RFC-005 Remediation #6 §9/§18 — charge.dispute.funds_reinstated.
     */
    private function processDisputeReinstatement($event, array $decoded, BusinessFundingAttemptRepository $attemptRepository, UsageBillingCheckoutManager $checkoutManager, PaymentProviderEventRepository $eventRepository): void
    {
        $object = $decoded['data']['object'] ?? [];

        [$attempt, $ambiguous] = $this->resolveFundingAttemptByDualReference($object['payment_intent'] ?? null, $object['charge'] ?? null, $attemptRepository);

        if ($ambiguous) {
            $eventRepository->markFailed($event->id, 'cross_reference_ambiguity');

            return;
        }

        if ($attempt === null) {
            $eventRepository->markFailed($event->id, 'no_matching_local_record');

            return;
        }

        $balanceTransactions = $object['balance_transactions'] ?? [];

        if (! is_array($balanceTransactions)) {
            $eventRepository->markFailed($event->id, 'malformed_balance_transaction_array');

            return;
        }

        if (count($balanceTransactions) === 0) {
            $this->markIgnoredDisputeAuditOnly($eventRepository, $event, $attempt, $checkoutManager, $object);

            return;
        }

        $validation = $this->validateBalanceTransactions($balanceTransactions, $checkoutManager->expectedCurrencyCodeFor($attempt));

        if ($validation === 'currency_mismatch') {
            $eventRepository->markFailed($event->id, 'currency_mismatch');

            return;
        }

        if ($validation === 'malformed') {
            $eventRepository->markFailed($event->id, 'malformed_balance_transaction_array');

            return;
        }

        $positiveEntry = null;
        $negativeEntry = null;

        foreach ($balanceTransactions as $balanceTransaction) {
            $amount = (int) ($balanceTransaction['amount'] ?? 0);

            if ($amount > 0) {
                $positiveEntry = $balanceTransaction;
            } elseif ($amount < 0) {
                $negativeEntry = $balanceTransaction;
            }
        }

        if ($positiveEntry === null) {
            $eventRepository->markFailed($event->id, 'malformed_balance_transaction_array');

            return;
        }

        if ($negativeEntry === null) {
            // RFC-005 Remediation #6 §5/§9 — a positive entry with no
            // accompanying negative entry fails closed: there is no
            // withdrawal balance-transaction id to resolve lineage from.
            $eventRepository->markFailed($event->id, 'missing_original_chargeback_reference');

            return;
        }

        $result = $checkoutManager->applyDisputeReinstatementOutcome(
            $attempt,
            abs((int) $positiveEntry['amount']),
            (string) ($object['id'] ?? ''),
            (string) ($positiveEntry['id'] ?? ''),
            (string) ($negativeEntry['id'] ?? ''),
        );

        if ($result === null) {
            $eventRepository->markFailed($event->id, 'missing_original_chargeback_reference');

            return;
        }

        $this->markProcessedWithAttribution($eventRepository, $event, $attempt->business_id, $attempt->id, $result, $checkoutManager->expectedCurrencyCodeFor($attempt));
    }

    /**
     * RFC-005 Remediation #6 §3/§16/§18, corrected by the third exceptional
     * post-merge implementation correction (§18 of the correction record)
     * — charge.dispute.created/.updated/.closed, durably recorded and
     * marked ignored, zero mutation, but only for a uniquely resolved
     * attempt. §3's resolution rule now applies uniformly: conflicting
     * references fail closed with cross_reference_ambiguity; neither
     * reference resolving fails closed with no_matching_local_record —
     * both administrator-visible via the existing failed-event
     * exhausted/dispose queue, never silently swallowed into an
     * unattributed, permanently ignored audit row.
     */
    private function processDisputeLifecycleAuditOnly($event, array $decoded, BusinessFundingAttemptRepository $attemptRepository, UsageBillingCheckoutManager $checkoutManager, PaymentProviderEventRepository $eventRepository): void
    {
        $object = $decoded['data']['object'] ?? [];

        [$attempt, $ambiguous] = $this->resolveFundingAttemptByDualReference($object['payment_intent'] ?? null, $object['charge'] ?? null, $attemptRepository);

        if ($ambiguous) {
            $eventRepository->markFailed($event->id, 'cross_reference_ambiguity');

            return;
        }

        if ($attempt === null) {
            $eventRepository->markFailed($event->id, 'no_matching_local_record');

            return;
        }

        $this->markIgnoredDisputeAuditOnly($eventRepository, $event, $attempt, $checkoutManager, $object);
    }

    /**
     * RFC-005 Remediation #6 §16/§18 — shared by charge.dispute.created/
     * .updated/.closed and by a funds_withdrawn/funds_reinstated event
     * whose own balance_transactions[] is empty. normalized_outcome =
     * dispute_audit_only; zero mutation.
     */
    private function markIgnoredDisputeAuditOnly(PaymentProviderEventRepository $eventRepository, $event, ?BusinessFundingAttempt $attempt, UsageBillingCheckoutManager $checkoutManager, array $object): void
    {
        $reportedAmountMicro = 0;

        if ($attempt !== null && array_key_exists('amount', $object) && is_numeric($object['amount'])) {
            $reportedAmountMicro = $checkoutManager->expectedMicroForMinorUnits((int) $object['amount'], $attempt);
        }

        $eventRepository->markIgnored($event->id, [
            'business_id' => $attempt?->business_id,
            'funding_attempt_id' => $attempt?->id,
            'normalized_outcome' => 'dispute_audit_only',
            'normalized_status' => $attempt?->state?->value,
            'normalized_reported_amount_micro' => $reportedAmountMicro,
            'normalized_outcome_delta_micro' => 0,
            'normalized_wallet_delta_micro' => 0,
            'normalized_policy_excess_micro' => 0,
            'normalized_currency_code' => $attempt !== null ? $checkoutManager->expectedCurrencyCodeFor($attempt) : null,
            'normalized_reason' => substr((string) $event->event_type, 0, 64),
            'normalized_recorded_at' => now(),
        ]);
    }

    /**
     * RFC-005 Remediation #6 §3/§17/§18, corrected by the third exceptional
     * post-merge implementation correction (§18 of the correction record)
     * — refund.created/refund.updated/refund.failed/charge.refund.updated,
     * durably recorded and marked ignored, zero mutation, but only for a
     * uniquely resolved attempt. These event types never drive mutation
     * regardless of resolution, but §3's own resolution rule still applies
     * uniformly for administrator-visibility reasons: conflicting
     * references fail closed with cross_reference_ambiguity; neither
     * reference resolving fails closed with no_matching_local_record —
     * both administrator-visible via the existing failed-event
     * exhausted/dispose queue, never silently swallowed into an
     * unattributed, permanently ignored audit row.
     */
    private function processRefundObjectAuditOnly($event, array $decoded, BusinessFundingAttemptRepository $attemptRepository, UsageBillingCheckoutManager $checkoutManager, PaymentProviderEventRepository $eventRepository): void
    {
        $object = $decoded['data']['object'] ?? [];

        [$attempt, $ambiguous] = $this->resolveFundingAttemptByDualReference($object['payment_intent'] ?? null, $object['charge'] ?? null, $attemptRepository);

        if ($ambiguous) {
            $eventRepository->markFailed($event->id, 'cross_reference_ambiguity');

            return;
        }

        if ($attempt === null) {
            $eventRepository->markFailed($event->id, 'no_matching_local_record');

            return;
        }

        $reportedAmountMicro = 0;

        if ($attempt !== null
            && array_key_exists('amount', $object)
            && is_numeric($object['amount'])
            && array_key_exists('currency', $object)
            && strtoupper((string) $object['currency']) === $checkoutManager->expectedCurrencyCodeFor($attempt)
        ) {
            $reportedAmountMicro = $checkoutManager->expectedMicroForMinorUnits((int) $object['amount'], $attempt);
        }

        $eventRepository->markIgnored($event->id, [
            'business_id' => $attempt?->business_id,
            'funding_attempt_id' => $attempt?->id,
            'normalized_outcome' => 'refund_object_audit_only',
            'normalized_status' => $attempt?->state?->value,
            'normalized_reported_amount_micro' => $reportedAmountMicro,
            'normalized_outcome_delta_micro' => 0,
            'normalized_wallet_delta_micro' => 0,
            'normalized_policy_excess_micro' => 0,
            'normalized_currency_code' => $attempt !== null ? $checkoutManager->expectedCurrencyCodeFor($attempt) : null,
            'normalized_reason' => substr((string) $event->event_type, 0, 64),
            'normalized_recorded_at' => now(),
        ]);
    }

    /**
     * RFC-005 Remediation #6 §18 — builds $attribution directly from a
     * ProviderOutcomeResult's own five fields; never a recomputation.
     */
    private function markProcessedWithAttribution(PaymentProviderEventRepository $eventRepository, $event, int $businessId, int $fundingAttemptId, \App\Library\Usage\ProviderOutcomeResult $result, string $currencyCode): void
    {
        $eventRepository->markProcessed($event->id, [
            'business_id' => $businessId,
            'funding_attempt_id' => $fundingAttemptId,
            'normalized_outcome' => $result->normalizedOutcome,
            'normalized_status' => $result->resultingState->value,
            'normalized_reported_amount_micro' => $result->reportedAmountMicro,
            'normalized_outcome_delta_micro' => $result->outcomeDeltaMicro,
            'normalized_wallet_delta_micro' => $result->walletDeltaMicro,
            'normalized_policy_excess_micro' => $result->policyExcessMicro,
            'normalized_currency_code' => $currencyCode,
            'normalized_reason' => substr((string) $event->event_type, 0, 64),
            'normalized_recorded_at' => now(),
        ]);
    }
}

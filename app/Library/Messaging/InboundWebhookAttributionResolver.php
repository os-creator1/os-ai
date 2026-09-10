<?php

namespace App\Library\Messaging;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Messaging\InboundWebhookEventKind;
use App\Enums\Messaging\MessagingOperationStatus;
use App\Enums\Messaging\MessagingProvider;
use App\Enums\Messaging\MessagingTransportMode;
use App\Enums\Messaging\WebhookRejectionReason;
use App\Library\Messaging\Contracts\MessagingProviderAdapter;
use App\Library\Messaging\DTO\InboundWebhookEvent;
use App\Library\Messaging\Exceptions\MessagingProviderNotConfiguredException;
use App\Library\SMSCounter;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Models\BusinessMessagingIdentity;
use App\Models\Reports;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Slice 3 §4.6 — fail-closed attribution for managed Telnyx inbound traffic.
 *
 * Two independent signals must agree before anything is attributed: the
 * Messaging Profile ID and the destination number. Neither is ever, on its
 * own, sufficient — that single-signal shortcut is the exact defect this
 * resolver exists to prevent. There is no global-first-match, no optional
 * Business fallback, no user-provided Business id, and no URL path segment
 * involved in the decision.
 *
 * Every refusal writes exactly one idempotent rejection row, updates no
 * conversation or message row, debits no wallet, triggers no automation, and
 * attributes to no Business.
 *
 * Response codes are chosen for semantic honesty only (§4.6.4); correctness
 * against duplicate delivery rests entirely on the idempotency guards, never
 * on a status code.
 */
class InboundWebhookAttributionResolver
{
    /**
     * How many times an unattributable DELIVERY callback is asked for
     * redelivery before this platform accepts that it will never be
     * attributable.
     *
     * Sized for the early-DLR race it exists to survive — a callback that
     * beat its own operation's finalization is resolvable on the very next
     * attempt — while staying small enough that a genuinely foreign
     * provider_message_id cannot make a provider retry indefinitely.
     */
    public const EARLY_DLR_RETRY_BUDGET = 3;

    public function __construct(
        private readonly BusinessMessagingIdentityResolver $resolver,
        private readonly MessagingWebhookRejectionRecorder $rejections,
        private readonly UsageWalletManager $walletManager,
    ) {
    }

    public function handle(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();

        try {
            $adapter = app(MessagingProviderAdapter::class);
        } catch (MessagingProviderNotConfiguredException) {
            // Fail closed when managed messaging is not active: nothing is
            // attributed, and no rejection row is written for traffic we are
            // not configured to receive at all.
            return response()->json(['status' => 'ignored'], 200);
        }

        // 1. Signature verification, first, always.
        if (! $adapter->verifyInboundSignature($rawBody, self::headers($request))) {
            $this->rejections->record(
                WebhookRejectionReason::InvalidSignature,
                MessagingProvider::Telnyx,
                $rawBody,
            );

            return response()->json(['status' => 'rejected'], 403);
        }

        // 2. Parse. A verified body that is not the expected shape is a 400.
        try {
            $event = $adapter->parseInboundWebhook($rawBody);
        } catch (\Throwable) {
            $this->rejections->record(
                WebhookRejectionReason::MalformedPayload,
                MessagingProvider::Telnyx,
                $rawBody,
            );

            return response()->json(['status' => 'rejected'], 400);
        }

        // 3. Branch by kind — the two shapes have opposite expected states
        // for (provider, provider_message_id).
        return $event->kind === InboundWebhookEventKind::DeliveryStatus
            ? $this->handleDeliveryStatus($event, $rawBody)
            : $this->handleMessageReceived($event, $rawBody);
    }

    private function handleMessageReceived(InboundWebhookEvent $event, string $rawBody): JsonResponse
    {
        // A row already existing for this exact pair means this inbound
        // message was already processed — a true replay.
        if ($event->providerMessageId !== null && $event->providerMessageId !== '') {
            $alreadyProcessed = DB::table(ManagedMessageDispatcher::TABLE)
                ->where('provider', MessagingProvider::Telnyx->value)
                ->where('provider_message_id', $event->providerMessageId)
                ->exists();

            if ($alreadyProcessed) {
                $this->rejections->record(
                    WebhookRejectionReason::Duplicate,
                    MessagingProvider::Telnyx,
                    $rawBody,
                    $event->messagingProfileId,
                    $event->destinationNumber,
                );

                return response()->json(['status' => 'duplicate'], 200);
            }
        }

        // 4. Both signals must be present and normalizable before either is
        // resolved; a missing one is a malformed payload, not an
        // unattributable one.
        $profileId = $event->messagingProfileId !== null ? trim($event->messagingProfileId) : '';
        $normalizedDestination = E164Normalizer::normalize($event->destinationNumber);

        if ($profileId === '' || $normalizedDestination === null) {
            $this->rejections->record(
                WebhookRejectionReason::MalformedPayload,
                MessagingProvider::Telnyx,
                $rawBody,
                $event->messagingProfileId,
                $event->destinationNumber,
            );

            return response()->json(['status' => 'rejected'], 400);
        }

        $identityByProfile = $this->resolver->resolveByMessagingProfileId($profileId);
        $identityByNumber = $this->resolver->resolveByPhoneNumber($normalizedDestination);

        if ($identityByProfile === null || $identityByNumber === null) {
            $this->rejections->record(
                WebhookRejectionReason::UnknownMapping,
                MessagingProvider::Telnyx,
                $rawBody,
                $profileId,
                $normalizedDestination,
                $identityByProfile?->id,
                $identityByNumber?->id,
            );

            return response()->json(['status' => 'unattributed'], 200);
        }

        if ((int) $identityByProfile->id !== (int) $identityByNumber->id) {
            $this->rejections->record(
                WebhookRejectionReason::ConflictingMapping,
                MessagingProvider::Telnyx,
                $rawBody,
                $profileId,
                $normalizedDestination,
                (int) $identityByProfile->id,
                (int) $identityByNumber->id,
            );

            return response()->json(['status' => 'unattributed'], 200);
        }

        // 5. Only on full agreement. A delivery that loses the unique-key
        //    race reports the duplicate it is, rather than claiming an
        //    effect another copy actually produced.
        if (! $this->persistInbound($identityByProfile, $event, $normalizedDestination)) {
            return response()->json(['status' => 'duplicate'], 200);
        }

        return response()->json(['status' => 'accepted'], 200);
    }

    private function handleDeliveryStatus(InboundWebhookEvent $event, string $rawBody): JsonResponse
    {
        // 1. Locate, never create.
        $operation = $event->providerMessageId === null || $event->providerMessageId === ''
            ? null
            : DB::table(ManagedMessageDispatcher::TABLE)
                ->where('provider', MessagingProvider::Telnyx->value)
                ->where('provider_message_id', $event->providerMessageId)
                ->first();

        if ($operation === null) {
            // THE EARLY-DLR RACE (audit P10).
            //
            // A delivery callback can legitimately arrive between the
            // provider accepting a message and this platform durably
            // attaching the returned provider_message_id to its operation
            // row. Answering 200 told the provider "understood, do not send
            // that again" — and the callback was then lost for good, leaving
            // the operation stuck at accepted forever.
            //
            // A 503 asks for redelivery, by which time finalization has
            // completed. The bound is deliberate and operational: the
            // recorder returns the occurrence count for this exact
            // fingerprint, and after EARLY_DLR_RETRY_BUDGET redeliveries
            // this stops asking and accepts the callback as genuinely
            // unattributable — so a provider_message_id that never belonged
            // here cannot make a provider retry forever.
            $rejection = $this->rejections->record(
                WebhookRejectionReason::UnknownMapping,
                MessagingProvider::Telnyx,
                $rawBody,
                $event->messagingProfileId,
                $event->destinationNumber,
            );

            if ((int) $rejection->occurrence_count <= self::EARLY_DLR_RETRY_BUDGET) {
                return response()->json(['status' => 'retry'], 503);
            }

            return response()->json(['status' => 'unattributed'], 200);
        }

        // 2. Business-identity validation against any evidence the payload
        // also carries — a mismatch is a conflict, never a state change.
        if (! $this->deliveryEvidenceAgrees($event, $operation)) {
            $this->rejections->record(
                WebhookRejectionReason::ConflictingMapping,
                MessagingProvider::Telnyx,
                $rawBody,
                $event->messagingProfileId,
                $event->destinationNumber,
                null,
                null,
            );

            return response()->json(['status' => 'rejected'], 200);
        }

        // 3. Status-transition guard. Replay for a delivery-status event is a
        // function of the row's CURRENT status, never of the row existing.
        $current = MessagingOperationStatus::tryFrom((string) $operation->status);
        $target = self::targetStatus($event->deliveryStatus);

        if ($current === null || $target === null) {
            $this->rejections->record(
                WebhookRejectionReason::MalformedPayload,
                MessagingProvider::Telnyx,
                $rawBody,
                $event->messagingProfileId,
                $event->destinationNumber,
            );

            return response()->json(['status' => 'rejected'], 400);
        }

        if ($current === $target) {
            // Exact replay — a no-op, recognized only AFTER the first valid
            // transition into that status has actually been applied.
            $this->rejections->record(
                WebhookRejectionReason::Duplicate,
                MessagingProvider::Telnyx,
                $rawBody,
                $event->messagingProfileId,
                $event->destinationNumber,
            );

            return response()->json(['status' => 'duplicate'], 200);
        }

        if (! $current->allowsDeliveryTransitionTo($target)) {
            // Regressive or otherwise invalid — the operation can never move
            // backward through a delivery-status callback.
            $this->rejections->record(
                WebhookRejectionReason::RegressiveTransition,
                MessagingProvider::Telnyx,
                $rawBody,
                $event->messagingProfileId,
                $event->destinationNumber,
            );

            return response()->json(['status' => 'rejected'], 200);
        }

        // The operation row and the customer-visible Report move together,
        // in one transaction. A window in which they disagree is a window in
        // which support tells a customer something the platform does not
        // believe — and, before this, a failed DLR updated only the
        // operation, leaving the Report permanently saying Delivered.
        DB::transaction(function () use ($operation, $target, $event): void {
            DB::table(ManagedMessageDispatcher::TABLE)
                ->where('id', $operation->id)
                ->update([
                    'status' => $target->value,
                    // The callback's own timestamp, never the original send's.
                    'occurred_at' => $event->occurredAt,
                    'updated_at' => Carbon::now(),
                ]);

            $this->syncCorrelatedReport($operation, $target);
        });

        return response()->json(['status' => 'accepted'], 200);
    }

    /**
     * ATOMIC, and idempotent by the DATABASE rather than by a prior read.
     *
     * What was here before: an `exists()` check upstream, then an unguarded
     * insert, then the measurement OUTSIDE any transaction. Two concurrent
     * copies of the same webhook both passed the check; one hit the unique
     * index and surfaced a 500. Worse, a crash between the insert and the
     * measurement left an operation row whose measurement could never be
     * written, because every later replay would see that row and take the
     * duplicate branch — the usage was lost permanently.
     *
     * The insert and its measurement are now one transaction, committing
     * together or not at all, and losing the unique key is treated as what
     * it is: a replay another copy already handled.
     *
     * @return bool false when this delivery lost the race, so the caller can
     *              report a duplicate instead of claiming an effect it did
     *              not produce
     */
    private function persistInbound(
        BusinessMessagingIdentity $identity,
        InboundWebhookEvent $event,
        string $destinationNumber,
    ): bool {
        $now = Carbon::now();
        $businessId = (int) $identity->business_id;
        $messageType = $event->mediaUrls === [] ? 'sms' : 'mms';

        try {
            DB::transaction(function () use ($identity, $event, $businessId, $messageType, $now): void {
                DB::table(ManagedMessageDispatcher::TABLE)->insert([
                    'business_id' => $businessId,
                    'business_messaging_identity_id' => (int) $identity->id,
                    'transport_mode' => MessagingTransportMode::Managed->value,
                    'provider' => MessagingProvider::Telnyx->value,
                    'direction' => 'inbound',
                    'message_type' => $messageType,
                    'operation_key' => null,
                    'provider_message_id' => $event->providerMessageId,
                    'status' => MessagingOperationStatus::Delivered->value,
                    'occurred_at' => $event->occurredAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $business = Business::query()->find($businessId);

                if ($business instanceof Business && $event->providerMessageId !== null) {
                    $this->walletManager->recordMeasurement(
                        $business,
                        PlatformFeature::MessagingTransport,
                        self::segmentQuantityFor($event->body),
                        'segment',
                        'inbound:' . MessagingProvider::Telnyx->value . ':' . $event->providerMessageId,
                        MessagingTransportMode::Managed->value,
                    );
                }
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /**
     * The real segment count, from the repository's OWN counting authority.
     *
     * This used to be the literal `'1'`, so a long inbound message was
     * measured as one segment however many it actually occupied — the meter
     * under-reported exactly the traffic that costs most. `SMSCounter` is
     * what the rest of this codebase counts with; a second algorithm here
     * would be guaranteed to disagree with it eventually.
     */
    private static function segmentQuantityFor(?string $body): string
    {
        if ($body === null || $body === '') {
            return '1';
        }

        return (string) max(1, (int) (new SMSCounter())->count($body)->messages);
    }

    /**
     * Slice 3 §4.9 / audit P7 — the operation row and the customer-visible
     * Report are two representations of one fact, so a delivery callback
     * moves both.
     *
     * The correlation is the operation's own `report_id` foreign key, added
     * to this slice's still-unmerged migration. The alternative — matching
     * on (Business, phone number, roughly when) — is ambiguous the moment a
     * Business messages the same recipient twice, and guessing which of two
     * sends a callback belongs to is not something a billing record should
     * ever do.
     */
    private function syncCorrelatedReport(object $operation, MessagingOperationStatus $target): void
    {
        if ($operation->report_id === null) {
            return;
        }

        $status = match ($target) {
            MessagingOperationStatus::Delivered => 'Delivered',
            MessagingOperationStatus::Failed => 'Failed',
            default => null,
        };

        if ($status === null) {
            return;
        }

        Reports::query()
            ->whereKey((int) $operation->report_id)
            ->update([
                'status' => $status,
                'customer_status' => $status,
                'updated_at' => Carbon::now(),
            ]);
    }

    /**
     * A delivery-status payload that also carries Profile/number evidence is
     * cross-checked against the stored operation's own identity; evidence
     * resolving to a different Business is a conflict.
     */
    private function deliveryEvidenceAgrees(InboundWebhookEvent $event, object $operation): bool
    {
        $storedIdentityId = $operation->business_messaging_identity_id !== null
            ? (int) $operation->business_messaging_identity_id
            : null;

        if ($storedIdentityId === null) {
            return true;
        }

        foreach ([
            $this->resolver->resolveByMessagingProfileId($event->messagingProfileId),
            $this->resolver->resolveByPhoneNumber($event->destinationNumber),
        ] as $resolved) {
            if ($resolved instanceof BusinessMessagingIdentity && (int) $resolved->id !== $storedIdentityId) {
                return false;
            }
        }

        return true;
    }

    private static function targetStatus(?string $deliveryStatus): ?MessagingOperationStatus
    {
        if ($deliveryStatus === null) {
            return null;
        }

        return match (strtolower(trim($deliveryStatus))) {
            'delivered' => MessagingOperationStatus::Delivered,
            'failed', 'delivery_failed', 'undelivered' => MessagingOperationStatus::Failed,
            'sent', 'accepted' => MessagingOperationStatus::Accepted,
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function headers(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $key => $value) {
            $headers[$key] = is_array($value) ? ($value[0] ?? null) : $value;
        }

        return $headers;
    }
}

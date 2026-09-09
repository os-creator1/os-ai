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
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Models\BusinessMessagingIdentity;
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

        // 5. Only on full agreement.
        $this->persistInbound($identityByProfile, $event, $normalizedDestination);

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
            $this->rejections->record(
                WebhookRejectionReason::UnknownMapping,
                MessagingProvider::Telnyx,
                $rawBody,
                $event->messagingProfileId,
                $event->destinationNumber,
            );

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

        DB::table(ManagedMessageDispatcher::TABLE)
            ->where('id', $operation->id)
            ->update([
                'status' => $target->value,
                // The callback's own timestamp, never the original send's.
                'occurred_at' => $event->occurredAt,
                'updated_at' => Carbon::now(),
            ]);

        return response()->json(['status' => 'accepted'], 200);
    }

    private function persistInbound(
        BusinessMessagingIdentity $identity,
        InboundWebhookEvent $event,
        string $destinationNumber,
    ): void {
        $now = Carbon::now();
        $businessId = (int) $identity->business_id;
        $messageType = $event->mediaUrls === [] ? 'sms' : 'mms';

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
                '1',
                'segment',
                'inbound:' . MessagingProvider::Telnyx->value . ':' . $event->providerMessageId,
                MessagingTransportMode::Managed->value,
            );
        }
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

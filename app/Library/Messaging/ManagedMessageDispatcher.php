<?php

namespace App\Library\Messaging;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Messaging\MessageDispatchStatus;
use App\Enums\Messaging\MessagingOperationStatus;
use App\Enums\Messaging\MessagingProvider;
use App\Enums\Messaging\MessagingTransportMode;
use App\Enums\Messaging\ProviderErrorCategory;
use App\Library\Messaging\Contracts\MessagingProviderAdapter;
use App\Library\Messaging\DTO\OutboundMessageRequest;
use App\Library\Messaging\DTO\OutboundMessageResult;
use App\Library\Messaging\Exceptions\MessagingIdentityConflictException;
use App\Library\Messaging\Exceptions\MessagingProviderNotConfiguredException;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Slice 3 §4.5 — the single outbound orchestration point for managed
 * messaging.
 *
 * Isolation is structural, not advisory: the identity and the number are
 * always re-resolved from the tenancy-verified Business model, so no caller
 * can supply an identity or number id and no request input can reach that
 * decision (T-MSG-11, T-MSG-12).
 *
 * Ordering, exactly as §4.5 step 6 requires: the operation row and the usage
 * measurement are both written BEFORE the adapter call, and both are
 * idempotent on the same operationKey. The provider call is made outside any
 * open transaction (§4.9).
 *
 * business_messaging_operations is accessed through the query builder rather
 * than an Eloquent model because §4.11's allowlist defines no model path for
 * that table, while naming this class as one of its two permitted writers.
 *
 * No RFC-005 reserve()/commit()/release() call is made for managed telecom
 * transport in Slice 3, and no retail rate is ever activated.
 */
class ManagedMessageDispatcher
{
    public const TABLE = 'business_messaging_operations';

    public function __construct(
        private readonly BusinessMessagingIdentityResolver $resolver,
        private readonly UsageWalletManager $walletManager,
    ) {
    }

    /**
     * @param list<string> $mediaUrls
     *
     * @return OutboundMessageResult never Accepted unless the provider itself
     *                               confirmed acceptance
     *
     * @throws MessagingIdentityConflictException when the Business has no
     *                                            single active identity, no
     *                                            single active primary
     *                                            number, or an unusable
     *                                            destination — in every case
     *                                            with zero provider calls
     */
    public function dispatch(
        Business $business,
        string $toNumber,
        string $body,
        string $operationKey,
        array $mediaUrls = [],
        string $quantity = '1',
    ): OutboundMessageResult {
        // §4.9 — a confirmed acceptance is never re-sent to the provider
        // under the same operation key; the recorded result is returned.
        $existing = DB::table(self::TABLE)->where('operation_key', $operationKey)->first();

        if ($existing !== null) {
            return self::resultFromRecordedOperation($existing);
        }

        $identity = $this->resolver->resolveForBusiness($business);

        if ($identity === null) {
            throw new MessagingIdentityConflictException(sprintf(
                'Business [%d] has no single active managed messaging identity.',
                (int) $business->id,
            ));
        }

        // Fails closed on zero or several active primary numbers — there is
        // deliberately no "first number" fallback.
        $number = $this->resolver->resolvePrimaryNumber($identity);

        $normalizedTo = E164Normalizer::normalize($toNumber);

        if ($normalizedTo === null) {
            throw new MessagingIdentityConflictException(
                'A managed message destination must be an explicit, valid international number.',
            );
        }

        $messageType = $mediaUrls === [] ? 'sms' : 'mms';

        $operationId = $this->recordAttempt($business, (int) $identity->id, $operationKey, $messageType);

        // The RFC-005-owned measurement, before the provider call and
        // idempotent on the same key. Quantity only — never a price.
        $this->walletManager->recordMeasurement(
            $business,
            PlatformFeature::MessagingTransport,
            $quantity,
            'segment',
            $operationKey,
            MessagingTransportMode::Managed->value,
        );

        try {
            $adapter = app(MessagingProviderAdapter::class);
        } catch (MessagingProviderNotConfiguredException) {
            // §4.4 — the kill switch is off, or credentials are absent. A
            // clearly reported failure, never a false "sent".
            $this->finalize($operationId, MessagingOperationStatus::Rejected, null, ProviderErrorCategory::Configuration);

            return OutboundMessageResult::rejected(ProviderErrorCategory::Configuration);
        }

        $result = $adapter->send(new OutboundMessageRequest(
            businessMessagingIdentityId: (int) $identity->id,
            businessMessagingNumberId: (int) $number->id,
            messagingProfileId: (string) $identity->messaging_profile_id,
            fromNumber: (string) $number->phone_number,
            toNumber: $normalizedTo,
            body: $body,
            mediaUrls: $mediaUrls,
            operationKey: $operationKey,
        ));

        // Only the provider's own confirmed acceptance counts as success.
        $this->finalize(
            $operationId,
            $result->accepted ? MessagingOperationStatus::Accepted : MessagingOperationStatus::Rejected,
            $result->providerMessageId,
            $result->errorCategory,
        );

        return $result;
    }

    private function recordAttempt(
        Business $business,
        int $identityId,
        string $operationKey,
        string $messageType,
    ): int {
        // A short, separate transaction that commits before the adapter call
        // — no provider request is ever made inside an open transaction.
        return DB::transaction(function () use ($business, $identityId, $operationKey, $messageType): int {
            $now = Carbon::now();

            return (int) DB::table(self::TABLE)->insertGetId([
                'business_id' => (int) $business->id,
                'business_messaging_identity_id' => $identityId,
                'transport_mode' => MessagingTransportMode::Managed->value,
                'provider' => MessagingProvider::Telnyx->value,
                'direction' => 'outbound',
                'message_type' => $messageType,
                'operation_key' => $operationKey,
                'status' => MessagingOperationStatus::Attempted->value,
                'occurred_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    private function finalize(
        int $operationId,
        MessagingOperationStatus $status,
        ?string $providerMessageId,
        ?ProviderErrorCategory $errorCategory,
    ): void {
        DB::table(self::TABLE)->where('id', $operationId)->update([
            'status' => $status->value,
            'provider_message_id' => $providerMessageId,
            'error_category' => $errorCategory?->value,
            'updated_at' => Carbon::now(),
        ]);
    }

    private static function resultFromRecordedOperation(object $operation): OutboundMessageResult
    {
        $status = MessagingOperationStatus::tryFrom((string) $operation->status);
        $accepted = in_array($status, [
            MessagingOperationStatus::Accepted,
            MessagingOperationStatus::Delivered,
        ], true);

        $errorCategory = $operation->error_category !== null
            ? ProviderErrorCategory::tryFrom((string) $operation->error_category)
            : null;

        return new OutboundMessageResult(
            accepted: $accepted,
            providerMessageId: $operation->provider_message_id !== null ? (string) $operation->provider_message_id : null,
            status: $accepted ? MessageDispatchStatus::Accepted : MessageDispatchStatus::Rejected,
            errorCategory: $accepted ? null : ($errorCategory ?? ProviderErrorCategory::Unknown),
        );
    }
}

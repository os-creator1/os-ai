<?php

namespace App\Library\Messaging;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Messaging\MessageDispatchStatus;
use App\Enums\Messaging\MessagingOperationStatus;
use App\Enums\Messaging\MessagingProvider;
use App\Enums\Messaging\MessagingTransportMode;
use App\Enums\Messaging\ProviderErrorCategory;
use App\Library\AgencyOutreach\AgencyByoSender;
use App\Library\Messaging\DTO\OutboundMessageRequest;
use App\Library\Messaging\DTO\OutboundMessageResult;
use App\Library\Messaging\Exceptions\ByoSenderNotReadyException;
use App\Library\Messaging\Exceptions\MessagingIdentityConflictException;
use App\Library\Usage\UsageWalletManager;
use App\Models\AgencyProspectingChannel;
use App\Models\Business;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The agency's own Telnyx account behind the SAME dispatch seam as managed
 * messaging (ManagedDispatchDelegate::attempt), not a second engine: the same
 * operation table, idempotency key, measurement and conversation history.
 *
 * What differs from ManagedMessageDispatcher, deliberately:
 *   - the provider call uses THIS agency's key and Messaging profile
 *     (TelnyxMessagingAdapter::sendWithKey) — config('services.telnyx.*') is
 *     never read, so there is no path to the platform's credentials;
 *   - no ManagedTransportBilling reserve/commit: Telnyx bills the agency
 *     directly, so the MotionGrove wallet is never touched. The send is still
 *     measured, marked `byo`, like every other BYO send;
 *   - the managed kill switch does not apply (it governs platform-paid
 *     sending) — an unusable channel refuses the send instead.
 */
class ByoMessageDispatcher
{
    public function __construct(private readonly UsageWalletManager $walletManager)
    {
    }

    /**
     * @param list<string> $mediaUrls
     *
     * @throws ByoSenderNotReadyException when the chosen channel is not usable —
     *                                    zero provider calls, zero rows
     */
    public function dispatch(
        Business $business,
        AgencyProspectingChannel $channel,
        string $toNumber,
        string $body,
        string $operationKey,
        array $mediaUrls = [],
        string $quantity = '1',
    ): OutboundMessageResult {
        \App\Library\NicheBlueprint\Safety\BlueprintSafetyGuard::check('sms_send');

        // Ownership, re-proven here and not trusted from the caller: the channel
        // must be this Business's own agency's, and the Business that agency's
        // own sole Business.
        if ((int) $channel->workspace_id !== (int) $business->workspace_id
            || ! AgencyByoSender::isOwnBusiness($business)) {
            throw new ByoSenderNotReadyException('not_owned', 'That sender does not belong to this agency.');
        }

        $existing = DB::table(ManagedMessageDispatcher::TABLE)
            ->where('business_id', (int) $business->id)
            ->where('operation_key', $operationKey)
            ->first();

        if ($existing !== null) {
            return self::resultFromRecordedOperation($existing);
        }

        // A stored authentication flag is re-verified (read-only, rate-limited, de-duplicated) instead of
        // blocking forever. This resends nothing: the idempotency check above already returned any earlier
        // attempt unchanged, and only this NEW dispatch proceeds.
        $readiness = AgencyByoSender::sendReadinessRecovering($channel);

        if (! $readiness['ok']) {
            throw new ByoSenderNotReadyException((string) $readiness['code'], (string) $readiness['message']);
        }

        $credentials = AgencyByoSender::credentials($channel);
        $normalizedTo = E164Normalizer::normalize($toNumber);
        $from = E164Normalizer::normalize((string) $channel->sender_number);

        if ($credentials === null || $from === null) {
            throw new ByoSenderNotReadyException('missing_credentials');
        }

        if ($normalizedTo === null) {
            throw new MessagingIdentityConflictException('A message destination must be an explicit, valid international number.');
        }

        // The ONE bounded retry after a restriction cool-down is claimed here, atomically, only after every local
        // check (ownership, readiness, credentials, destination) has passed and immediately before the operation row
        // and provider request: a local validation failure above never consumes it.
        if (! AgencyByoSender::claimRestrictionRetry($channel)) {
            throw new ByoSenderNotReadyException('sender_restricted', 'Telnyx refused to send from this number recently. Resolve the restriction in Telnyx; sending is retried once after a cool-down.');
        }

        $messageType = $mediaUrls === [] ? 'sms' : 'mms';

        try {
            $operationId = DB::transaction(function () use ($business, $channel, $from, $operationKey, $messageType): int {
                $now = Carbon::now();

                return (int) DB::table(ManagedMessageDispatcher::TABLE)->insertGetId([
                    'business_id' => (int) $business->id,
                    'business_messaging_identity_id' => null,
                    'transport_mode' => MessagingTransportMode::Byo->value,
                    'provider' => MessagingProvider::Telnyx->value,
                    'direction' => 'outbound',
                    'message_type' => $messageType,
                    'operation_key' => $operationKey,
                    'sender_number' => $from,
                    'agency_prospecting_channel_id' => (int) $channel->id,
                    'status' => MessagingOperationStatus::Attempted->value,
                    'occurred_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent copy of the same send won the key: report its record.
            return self::resultFromRecordedOperation(
                DB::table(ManagedMessageDispatcher::TABLE)
                    ->where('business_id', (int) $business->id)
                    ->where('operation_key', $operationKey)
                    ->first(),
            );
        }

        // Measured as BYO (quantity only), before the provider call, idempotent on the key.
        $this->walletManager->recordMeasurement(
            $business,
            PlatformFeature::MessagingTransport,
            $quantity,
            'segment',
            $operationKey,
            MessagingTransportMode::Byo->value,
        );

        $result = TelnyxMessagingAdapter::sendWithKey($credentials['api_key'], new OutboundMessageRequest(
            businessMessagingIdentityId: 0,
            businessMessagingNumberId: 0,
            messagingProfileId: $credentials['profile_id'],
            fromNumber: $from,
            toNumber: $normalizedTo,
            body: $body,
            mediaUrls: $mediaUrls,
            operationKey: $operationKey,
        ));

        // Only a CONFIRMED authentication rejection (401, or 403 with Telnyx's authentication-failed code)
        // is remembered as a bad key. A different 403 is a provider restriction on an accepted key: it is
        // remembered as such and blocks further attempts for a cool-down. Timeouts, 429 and 5xx remember nothing.
        if (! $result->accepted && $result->errorCategory === ProviderErrorCategory::Configuration) {
            $channel->forceFill(['last_error_code' => 'invalid_api_key', 'last_error_at' => Carbon::now()])->save();
        } elseif (! $result->accepted && $result->errorCategory === ProviderErrorCategory::Restricted) {
            $channel->forceFill(['last_error_code' => 'sender_restricted', 'last_error_at' => Carbon::now()])->save();
        } elseif ($result->accepted && $channel->last_error_code === 'sender_restricted') {
            // The one permitted retry after the cool-down went through: the restriction is lifted.
            $channel->forceFill(['last_error_code' => null, 'last_error_at' => null])->save();
        }

        DB::table(ManagedMessageDispatcher::TABLE)->where('id', $operationId)->update([
            'status' => $result->accepted ? MessagingOperationStatus::Accepted->value : MessagingOperationStatus::Rejected->value,
            'provider_message_id' => $result->providerMessageId,
            'error_category' => $result->errorCategory?->value,
            'updated_at' => Carbon::now(),
        ]);

        return $result;
    }

    private static function resultFromRecordedOperation(object $operation): OutboundMessageResult
    {
        $status = MessagingOperationStatus::tryFrom((string) $operation->status);
        $accepted = in_array($status, [MessagingOperationStatus::Accepted, MessagingOperationStatus::Delivered], true);

        return new OutboundMessageResult(
            accepted: $accepted,
            providerMessageId: $operation->provider_message_id !== null ? (string) $operation->provider_message_id : null,
            status: $accepted ? MessageDispatchStatus::Accepted : MessageDispatchStatus::Rejected,
            errorCategory: $accepted ? null : ($operation->error_category !== null
                ? (ProviderErrorCategory::tryFrom((string) $operation->error_category) ?? ProviderErrorCategory::Unknown)
                : ProviderErrorCategory::Unknown),
        );
    }
}

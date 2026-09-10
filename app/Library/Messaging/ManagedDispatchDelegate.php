<?php

namespace App\Library\Messaging;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Messaging\MessagingTransportMode;
use App\Library\Messaging\DTO\OutboundMessageResult;
use App\Library\Messaging\Exceptions\MessagingIdentityConflictException;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Models\Campaigns;
use App\Models\CustomerBasedSendingServer;
use App\Models\Reports;
use Illuminate\Support\Str;

/**
 * Slice 3 §4.5 — the narrow pre-dispatch seam the two production
 * convergence points share.
 *
 * Both legacy entry points (EloquentCampaignRepository::quickSend() and
 * Campaigns::sendSMS(), the point campaignBuilder()'s async chain converges
 * on) ask this one question before touching a provider: does this Business
 * have managed messaging, and if so, has the managed path already handled
 * the send?
 *
 * It returns null when the send is NOT managed, so the caller falls through
 * to its existing legacy/BYO behaviour completely unchanged — Slice 3 adds a
 * branch in front of the legacy path, it does not rewrite it.
 *
 * A Business whose managed identity exists but cannot be used (no single
 * active primary number, an unusable destination) fails closed here with
 * zero provider calls, rather than silently falling back to the legacy
 * provider — falling back would defeat the isolation this slice exists to
 * establish.
 */
class ManagedDispatchDelegate
{
    /**
     * §4.8's unit for telecom transport, shared by the managed and BYO
     * measurement paths so the two are directly comparable in one table.
     */
    public const MEASUREMENT_UNIT = 'segment';

    /**
     * @param list<string> $mediaUrls
     *
     * @return OutboundMessageResult|null null when this Business has no
     *                                    managed identity and the caller
     *                                    should proceed with its legacy path
     */
    public static function attempt(
        ?int $businessId,
        ?string $toNumber,
        ?string $body,
        ?string $operationKey = null,
        array $mediaUrls = [],
        string $quantity = '1',
    ): ?OutboundMessageResult {
        if ($businessId === null || $toNumber === null || $toNumber === '') {
            return null;
        }

        $business = Business::query()->find($businessId);

        if (! $business instanceof Business) {
            return null;
        }

        // Resolved from the Business alone — never from caller input.
        $identity = app(BusinessMessagingIdentityResolver::class)->resolveForBusiness($business);

        if ($identity === null) {
            return null;
        }

        return app(ManagedMessageDispatcher::class)->dispatch(
            $business,
            $toNumber,
            (string) $body,
            $operationKey ?? ('managed:' . $businessId . ':' . Str::uuid()),
            $mediaUrls,
            $quantity,
        );
    }

    /**
     * §4.5 step 9 — "the calling flow's existing conversation/message
     * persistence proceeds using the confirmed providerMessageId".
     *
     * `Campaigns::send()` hands whatever `sendSMS()` returns straight to
     * `track_message()`, which reads `->id`, `->status`, `->sms_count` and
     * `->cost` AND ALSO indexes it as an array (`$response['status']`,
     * `$response['cost']`, Campaigns.php:741-743). The legacy provider path
     * satisfies both because it returns a `Reports` Eloquent model, which is
     * ArrayAccess. A plain stdClass is not, so returning one threw
     * "Cannot use object of type stdClass as array" — and because the sync
     * queue runs `SendMessage` inside `Batch::add()`'s own bookkeeping
     * transaction, that throw rolled back the batch transaction and took the
     * operation and measurement rows with it. The rows were never the
     * problem; the return shape was.
     *
     * So a managed send returns the same kind of value a legacy send does.
     * The tracking log, the delivered/failed counters and the sms_unit
     * accounting all keep working, unchanged, on managed traffic.
     *
     * `sending_server_id` is deliberately null: managed transport has no
     * legacy SendingServer, the column is nullable, and inventing one would
     * be a lie about which server carried the message.
     */
    public static function recordLegacyReport(
        Campaigns $campaign,
        array $preparedData,
        OutboundMessageResult $result,
    ): Reports {
        $attributes = [
            'user_id' => $campaign->user_id,
            'business_id' => $campaign->business_id,
            'to' => str_replace(['(', ')', '+', '-', ' '], '', (string) ($preparedData['phone'] ?? '')),
            'message' => $preparedData['message'] ?? null,
            'sms_type' => $preparedData['sms_type'] ?? $campaign->sms_type,
            'status' => $result->accepted ? 'Delivered' : 'Failed',
            'customer_status' => $result->accepted ? 'Delivered' : 'Failed',
            'direction' => Reports::DIRECTION_OUTGOING,
            'cost' => $preparedData['cost'] ?? 0,
            'sms_count' => $preparedData['sms_count'] ?? 1,
            'sending_server_id' => null,
        ];

        if (isset($preparedData['sender_id'])) {
            $attributes['from'] = $preparedData['sender_id'];
        }

        if (isset($preparedData['campaign_id'])) {
            $attributes['campaign_id'] = $preparedData['campaign_id'];
        }

        if (isset($preparedData['media_url'])) {
            $attributes['media_url'] = $preparedData['media_url'];
        }

        return Reports::create($attributes);
    }

    /**
     * True when this Business sends through managed messaging, without
     * dispatching anything — for callers that only need to branch.
     */
    public static function isManaged(?int $businessId): bool
    {
        if ($businessId === null) {
            return false;
        }

        $business = Business::query()->find($businessId);

        if (! $business instanceof Business) {
            return false;
        }

        return app(BusinessMessagingIdentityResolver::class)->resolveForBusiness($business) !== null;
    }

    /**
     * Slice 3 §4.7/§4.8 — T-MSG-35, T-BYO-1, T-BYO-2.
     *
     * "BYO transport gets zero platform transport rate or wallet debit;
     * §4.8's recordMeasurement() call with transport_marker: 'byo' is how a
     * BYO send is still measured."
     *
     * WHERE THIS IS CALLED, AND WHY THERE.
     *
     * The authoritative successful-dispatch boundary is the point where the
     * legacy provider layer has returned a persisted `Reports` row whose
     * status says Delivered. Before that point the send may still fail;
     * after it, the transport genuinely happened. Both of Slice 3's two
     * contracted convergence points reach it, so this is called from both
     * and from nowhere else.
     *
     * WHAT MAKES A SEND "BYO", AUTHORITATIVELY.
     *
     * Not the request. Not `$input['business_id']`, not `$input['user_id']`,
     * neither of which is trustworthy (see the implementation document's
     * §7(g)). The Business is resolved from persisted state only: the
     * `customer_based_sending_servers` row that assigns the SendingServer
     * this send actually used to a Business. A gateway with no such
     * assignment is an admin/legacy shared server, not a customer's own BYO
     * connection, and is deliberately not measured here.
     *
     * WHY IT IS SAFE TO RUN ON EVERY LEGACY SEND.
     *
     * A managed Business never reaches the legacy switch at all — `attempt()`
     * intercepts it upstream. The managed-identity check below is therefore
     * belt-and-braces, and exists so that a future path which somehow sent a
     * managed Business's traffic through a legacy gateway would be refused a
     * `byo` marker rather than silently mislabelled.
     *
     * IDEMPOTENCY. The key is derived from the `Reports` row's own uid — the
     * authoritative persisted identity of this one dispatch, minted once by
     * `HasUid`. Re-processing the same dispatch record cannot produce a
     * second measurement, because `recordOnce()` is idempotent on the key
     * and the key is a pure function of the row. It is deliberately not
     * random per call, which would defeat the whole mechanism.
     *
     * WHAT THIS DOES NOT DO. No reservation. No wallet debit. No spending-cap
     * consumption. No rate, no activation, no ledger entry. No
     * `business_messaging_operations` row — a BYO send is not a managed
     * operation and must never masquerade as one. And nothing about the
     * provider's credentials is carried into the measurement: the row holds
     * a business id, a feature key, a quantity, a unit and a marker.
     *
     * @param object|string|null $dispatchResult whatever the legacy provider
     *                                           layer returned — a `Reports`
     *                                           model on success, or an error
     *                                           string
     */
    public static function recordByoMeasurement(
        ?int $sendingServerId,
        mixed $dispatchResult,
        string $quantity = '1',
    ): bool {
        if ($sendingServerId === null || ! is_object($dispatchResult)) {
            return false;
        }

        // A failed send is never recorded as transport that happened.
        $status = $dispatchResult->status ?? null;

        if (! is_string($status) || substr_count($status, 'Delivered') !== 1) {
            return false;
        }

        $sendIdentity = $dispatchResult->uid ?? null;

        if (! is_string($sendIdentity) || $sendIdentity === '') {
            return false;
        }

        // Authoritative ownership: the persisted assignment row, never the
        // request. Read-only access to CustomerBasedSendingServer, which
        // §4.11 permits and this slice adds no column or method to.
        $assignment = CustomerBasedSendingServer::query()
            ->where('sending_server', $sendingServerId)
            ->where('status', 1)
            ->first();

        if ($assignment === null || $assignment->business_id === null) {
            return false;
        }

        $business = Business::query()->find((int) $assignment->business_id);

        if (! $business instanceof Business) {
            return false;
        }

        // A managed Business's traffic is never labelled BYO.
        if (app(BusinessMessagingIdentityResolver::class)->resolveForBusiness($business) !== null) {
            return false;
        }

        app(UsageWalletManager::class)->recordMeasurement(
            $business,
            PlatformFeature::MessagingTransport,
            $quantity,
            self::MEASUREMENT_UNIT,
            'byo:' . $sendIdentity,
            MessagingTransportMode::Byo->value,
        );

        return true;
    }

    /**
     * Whether a failure from attempt() is a managed-path conflict (which
     * must fail closed) rather than an ordinary legacy outcome.
     */
    public static function isManagedConflict(\Throwable $e): bool
    {
        return $e instanceof MessagingIdentityConflictException;
    }
}

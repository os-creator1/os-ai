<?php

namespace App\Library\Messaging;

use App\Library\Messaging\DTO\OutboundMessageResult;
use App\Library\Messaging\Exceptions\MessagingIdentityConflictException;
use App\Models\Business;
use App\Models\Campaigns;
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
     * Whether a failure from attempt() is a managed-path conflict (which
     * must fail closed) rather than an ordinary legacy outcome.
     */
    public static function isManagedConflict(\Throwable $e): bool
    {
        return $e instanceof MessagingIdentityConflictException;
    }
}

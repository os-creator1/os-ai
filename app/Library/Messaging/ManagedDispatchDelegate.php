<?php

namespace App\Library\Messaging;

use App\Library\Messaging\DTO\OutboundMessageResult;
use App\Library\Messaging\Exceptions\MessagingIdentityConflictException;
use App\Models\Business;
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

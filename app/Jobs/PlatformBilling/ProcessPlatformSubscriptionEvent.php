<?php

namespace App\Jobs\PlatformBilling;

use App\Enums\PlatformBilling\PlatformSubscriptionEventState;
use App\Jobs\Base;
use App\Library\PlatformBilling\PlatformProviderStatusMap;
use App\Library\PlatformBilling\PlatformSubscriptionFinalizer;
use App\Library\PlatformBilling\PlatformSubscriptionManager;
use App\Models\PlatformSubscription;
use App\Models\PlatformSubscriptionEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Implementation Contract 21 §12 — the claim/lease consumer for lane-A events.
 *
 * THE CLAIM IS ONE ATOMIC CONDITIONAL UPDATE whose WHERE admits only a
 * `received` row, a retryable `failed` one, or a `processing` one whose lease
 * has expired. Zero rows updated means another worker owns it and this job
 * returns immediately — that is what makes concurrent delivery of one event
 * harmless.
 *
 * TERMINAL WRITES ARE GUARDED `WHERE id = ? AND state = 'processing'`, so a
 * worker that lost its lease mid-flight cannot overwrite the winner's result.
 *
 * FAIL CLOSED ON IDENTITY (§12.4). An event whose customer or subscription
 * does not resolve to a LANE-A record is recorded `failed` with a reason code.
 * It is never guessed at, and it is never allowed to reach lane B's documents
 * or lane D's wallets — which is precisely the cross-lane accident §2 exists
 * to prevent.
 *
 * `last_error` stores an exception CLASS or a reason CODE, never a message: a
 * provider message can carry customer identifiers and request detail.
 *
 * Mutation happens only through the shared finalizer, which re-reads provider
 * truth and drives EntitlementManager's existing lifecycle writers. This job
 * decides nothing about money or access on its own.
 */
class ProcessPlatformSubscriptionEvent extends Base implements ShouldQueue
{
    /** How long a claim is held before another worker may take it over. */
    private const LEASE_SECONDS = 120;

    public function __construct(private readonly int $eventId)
    {
    }

    public function handle(PlatformSubscriptionManager $manager): void
    {
        if ($this->claim() === 0) {
            return;
        }

        $event = PlatformSubscriptionEvent::query()->find($this->eventId);

        if ($event === null) {
            return;
        }

        try {
            [$state, $reason, $subscriptionId] = $this->process($event, $manager);
        } catch (Throwable $e) {
            $this->finish(PlatformSubscriptionEventState::Failed, class_basename($e), null);

            throw $e;
        }

        $this->finish($state, $reason, $subscriptionId);
    }

    /**
     * @return array{0: PlatformSubscriptionEventState, 1: ?string, 2: ?int}
     */
    private function process(PlatformSubscriptionEvent $event, PlatformSubscriptionManager $manager): array
    {
        $eventType = (string) $event->event_type;

        // §12.5 — outside the closed set is acknowledged and ignored, never
        // guessed at.
        if (! PlatformProviderStatusMap::handlesEventType($eventType)) {
            return [PlatformSubscriptionEventState::Ignored, 'unhandled_event_type', null];
        }

        $payload = json_decode((string) $event->payload_encrypted, true);
        $object = $payload['data']['object'] ?? null;

        if (! is_array($object)) {
            return [PlatformSubscriptionEventState::Ignored, 'unusable_payload', null];
        }

        $subscription = $this->resolve($event, $object);

        if (is_string($subscription)) {
            // A reason code rather than a row: fail closed.
            return [PlatformSubscriptionEventState::Failed, $subscription, null];
        }

        $providerSubscriptionId = $this->providerSubscriptionId($eventType, $object)
            ?? (string) $subscription->provider_subscription_id;

        if ($providerSubscriptionId === '') {
            return [PlatformSubscriptionEventState::Failed, 'no_provider_subscription', (int) $subscription->id];
        }

        // Provider truth is RE-READ rather than trusted from the payload: the
        // event body is a point-in-time copy, and §8.5's rule that local state
        // changes only on verified provider truth is best served by asking.
        $disposition = $manager->applyProviderSubscription(
            $subscription,
            $providerSubscriptionId,
            (string) $event->provider_event_id,
            $event->provider_created_at,
        );

        return match ($disposition) {
            PlatformSubscriptionFinalizer::APPLIED => [PlatformSubscriptionEventState::Processed, null, (int) $subscription->id],
            PlatformSubscriptionFinalizer::IGNORED_OUT_OF_ORDER,
            PlatformSubscriptionFinalizer::IGNORED_NO_CHANGE,
            PlatformSubscriptionFinalizer::IGNORED_COMPLIMENTARY => [PlatformSubscriptionEventState::Ignored, $disposition, (int) $subscription->id],
            // Every cross-check mismatch is fail-closed and kept as a code.
            default => [PlatformSubscriptionEventState::Failed, $disposition, (int) $subscription->id],
        };
    }

    /**
     * §12.4 — resolution by PROVIDER REFERENCE, in this order and no other:
     *   1. `checkout.session.completed` carries OUR OWN `client_reference_id`,
     *      which is the durable local subscription UID — the strongest link
     *      there is, because we put it there;
     *   2. the provider subscription id against `provider_subscription_id`;
     *   3. the provider customer id against `provider_customer_id`, which is
     *      how the very first `customer.subscription.created` for a brand-new
     *      checkout finds its row before the subscription id is recorded.
     *
     * Anything unresolved is a reason code, never a guess.
     *
     * @param  array<string, mixed>  $object
     * @return PlatformSubscription|string the row, or a fail-closed reason code
     */
    private function resolve(PlatformSubscriptionEvent $event, array $object): PlatformSubscription|string
    {
        if ((string) $event->event_type === 'checkout.session.completed') {
            $reference = $object['client_reference_id'] ?? null;

            if (! is_string($reference) || $reference === '') {
                // A checkout that is not ours — another product on the same
                // Stripe account, for instance. Fail closed rather than
                // adopting it.
                return 'foreign_checkout_session';
            }

            $subscription = PlatformSubscription::query()->where('uid', $reference)->first();

            return $subscription ?? 'no_matching_local_record';
        }

        if ($event->provider_subscription_id !== null) {
            $bySubscription = PlatformSubscription::query()
                ->where('provider_subscription_id', $event->provider_subscription_id)
                ->first();

            if ($bySubscription !== null) {
                return $bySubscription;
            }
        }

        if ($event->provider_customer_id !== null) {
            $byCustomer = PlatformSubscription::query()
                ->where('provider_customer_id', $event->provider_customer_id)
                ->orderBy('id')
                ->get();

            if ($byCustomer->count() === 1) {
                return $byCustomer->first();
            }

            if ($byCustomer->count() > 1) {
                // Two local rows share a provider customer: nothing in the
                // event says which one this is about.
                return 'cross_reference_ambiguity';
            }
        }

        return 'no_matching_local_record';
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function providerSubscriptionId(string $eventType, array $object): ?string
    {
        $value = str_starts_with($eventType, 'customer.subscription.')
            ? ($object['id'] ?? null)
            : ($object['subscription'] ?? null);

        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_array($value) && is_string($value['id'] ?? null)) {
            return $value['id'];
        }

        return null;
    }

    /**
     * §12 — the atomic conditional claim.
     */
    private function claim(): int
    {
        $now = now();

        return DB::table('platform_subscription_events')
            ->where('id', $this->eventId)
            ->where(function ($query) use ($now) {
                $query->where('state', PlatformSubscriptionEventState::Received->value)
                    ->orWhere('state', PlatformSubscriptionEventState::Failed->value)
                    ->orWhere(function ($stale) use ($now) {
                        $stale->where('state', PlatformSubscriptionEventState::Processing->value)
                            ->whereNotNull('lease_expires_at')
                            ->where('lease_expires_at', '<', $now);
                    });
            })
            ->update([
                'state' => PlatformSubscriptionEventState::Processing->value,
                'attempts' => DB::raw('attempts + 1'),
                'processing_started_at' => $now,
                'last_attempt_at' => $now,
                'lease_expires_at' => $now->copy()->addSeconds(self::LEASE_SECONDS),
            ]);
    }

    /**
     * §12 — terminal writes are guarded on still owning the claim.
     */
    private function finish(PlatformSubscriptionEventState $state, ?string $reason, ?int $subscriptionId): void
    {
        $attributes = [
            'state' => $state->value,
            'completed_at' => now(),
            'lease_expires_at' => null,
            'last_error' => $reason === null ? null : mb_substr($reason, 0, 120),
        ];

        if ($subscriptionId !== null) {
            $attributes['platform_subscription_id'] = $subscriptionId;
        }

        DB::table('platform_subscription_events')
            ->where('id', $this->eventId)
            ->where('state', PlatformSubscriptionEventState::Processing->value)
            ->update($attributes);
    }
}

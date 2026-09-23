<?php

namespace App\Jobs\PlatformBilling;

use App\Enums\PlatformBilling\PlatformSubscriptionEventState;
use App\Jobs\Base;
use App\Library\PlatformBilling\PlatformProviderStatusMap;
use App\Library\PlatformBilling\PlatformSubscriptionFinalizer;
use App\Library\PlatformBilling\PlatformSubscriptionManager;
use App\Library\PlatformBilling\V1SignupManager;
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

    /**
     * §12 — a BOUNDED retry policy, overriding Base's default of one attempt.
     *
     * Money events must not be one-shot. If the finalizer succeeds and then
     * activation fails on a transient database error, a single attempt would
     * leave a PAID Workspace unassigned with nothing to fix it. Three attempts
     * with backoff is the smallest policy that survives a blip without
     * hammering the provider or the queue.
     *
     * The claim's WHERE already admits a `failed` row, so a retry reclaims the
     * same event safely rather than racing a second worker.
     */
    public int $tries = 3;

    public int $maxExceptions = 3;

    /** Seconds between attempts: quick, then patient. */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function __construct(private readonly int $eventId)
    {
    }

    public function handle(PlatformSubscriptionManager $manager, V1SignupManager $signup): void
    {
        if ($this->claim() === 0) {
            return;
        }

        $event = PlatformSubscriptionEvent::query()->find($this->eventId);

        if ($event === null) {
            return;
        }

        try {
            [$state, $reason, $subscriptionId] = $this->process($event, $manager, $signup);
        } catch (Throwable $e) {
            $this->finish(PlatformSubscriptionEventState::Failed, class_basename($e), null);

            throw $e;
        }

        $this->finish($state, $reason, $subscriptionId);
    }

    /**
     * @return array{0: PlatformSubscriptionEventState, 1: ?string, 2: ?int}
     */
    private function process(PlatformSubscriptionEvent $event, PlatformSubscriptionManager $manager, V1SignupManager $signup): array
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

        // THE WEBHOOK MUST BE SUFFICIENT TO FINISH THE ACCOUNT. A customer who
        // pays and then closes the tab never reaches the Checkout success
        // endpoint, so activation cannot live only there: it would leave a
        // charged customer with an unassigned Workspace. This is the SAME
        // idempotent seam the success endpoint calls — not a second copy of
        // the plan-assignment logic — and it trusts no session or browser
        // state, only the durable subscription row.
        //
        // ACTIVATION IS GATED ON THE ROW'S STATE, NOT ON THIS ATTEMPT HAVING
        // CHANGED IT. Gating on APPLIED alone left a durability hole: if the
        // finalizer succeeded and activation then failed, the retry's
        // finalizer would report no change and activation would be skipped
        // forever, stranding a paid Workspace. So every disposition that is
        // not an IDENTITY FAILURE activates, and the seam's own
        // provider-confirmed gate plus its idempotency decide whether anything
        // actually happens.
        //
        // A mismatch never activates: an event we could not prove belongs to
        // this subscription must not be able to hand it a plan.
        if (! in_array($disposition, [
            PlatformSubscriptionFinalizer::SUBSCRIPTION_MISMATCH,
            PlatformSubscriptionFinalizer::CUSTOMER_MISMATCH,
            PlatformSubscriptionFinalizer::OPERATION_ID_MISMATCH,
        ], true)) {
            $signup->activateFromConfirmedSubscription($subscription->refresh());
        }

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

        // OUR OWN DURABLE IDENTITY, echoed back by the provider. Checkout sets
        // `subscription_data.metadata.app_operation_id` to the local
        // subscription uid, so a `customer.subscription.*` event carries it on
        // the subscription object — the same strength of link as
        // `client_reference_id`, and the one that resolves the very first
        // subscription event for a brand-new checkout, before any provider
        // subscription id has been recorded locally.
        $operationId = $object['metadata']['app_operation_id'] ?? null;

        if (is_string($operationId) && $operationId !== '') {
            $byOperation = PlatformSubscription::query()->where('uid', $operationId)->first();

            if ($byOperation !== null) {
                return $byOperation;
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

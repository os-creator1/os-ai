<?php

namespace App\Jobs\AgencyBilling;

use App\Enums\AgencyBilling\AgencySubscriptionEventState;
use App\Jobs\Base;
use App\Library\AgencyBilling\AgencyClientSubscriptionFinalizer;
use App\Library\AgencyBilling\AgencyClientSubscriptionManager;
use App\Library\AgencyBilling\AgencyProviderStatusMap;
use App\Library\AgencyBilling\AgencyStripeConnectManager;
use App\Models\AgencyClientSubscription;
use App\Models\AgencyClientSubscriptionEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Lane C §C4.1 — the claim/lease consumer for lane-C Connect events.
 *
 * THE CLAIM IS ONE ATOMIC CONDITIONAL UPDATE whose WHERE admits only a
 * `received` row, a retryable `failed` one, or a `processing` one whose lease
 * has expired. Zero rows updated means another worker owns it and this job
 * returns immediately — which is what makes concurrent delivery harmless.
 *
 * FAIL CLOSED ON IDENTITY, AND THE ACCOUNT COMES FIRST. Before anything is
 * resolved, the event's own `account` must name a known, non-disconnected
 * Agency connection; then the subscription it resolves to must be bound to that
 * same account. An event for Agency X can never move Agency Y's client, and a
 * lane-B document-payment event arriving on this endpoint — which can happen
 * when an Agency uses one Stripe account for both purposes — cannot resolve at
 * all, because its type is outside lane C's closed set and its objects are not
 * lane-C subscriptions.
 *
 * `last_error` stores an exception CLASS or a reason CODE, never a message: a
 * provider message can carry customer identifiers and request detail.
 *
 * Mutation happens only through the shared finalizer. This job decides nothing
 * about money or access on its own.
 */
class ProcessAgencyClientSubscriptionEvent extends Base implements ShouldQueue
{
    /** How long a claim is held before another worker may take it over. */
    private const LEASE_SECONDS = 120;

    /**
     * A BOUNDED retry policy, overriding Base's default of one attempt. Money
     * events must not be one-shot: if the finalizer succeeds and a later step
     * fails on a transient database error, a single attempt would strand a
     * paying client. Three attempts with backoff is the smallest policy that
     * survives a blip without hammering the provider or the queue.
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

    public function handle(
        AgencyClientSubscriptionManager $manager,
        AgencyStripeConnectManager $connections,
    ): void {
        if ($this->claim() === 0) {
            return;
        }

        $event = AgencyClientSubscriptionEvent::query()->find($this->eventId);

        if ($event === null) {
            return;
        }

        try {
            [$state, $reason, $subscriptionId] = $this->process($event, $manager, $connections);
        } catch (Throwable $e) {
            $this->finish(AgencySubscriptionEventState::Failed, class_basename($e), null);

            throw $e;
        }

        $this->finish($state, $reason, $subscriptionId);
    }

    /**
     * @return array{0: AgencySubscriptionEventState, 1: ?string, 2: ?int}
     */
    private function process(
        AgencyClientSubscriptionEvent $event,
        AgencyClientSubscriptionManager $manager,
        AgencyStripeConnectManager $connections,
    ): array {
        $eventType = (string) $event->event_type;

        // Outside lane C's closed set is acknowledged and ignored, never
        // guessed at. This is also what makes a stray lane-B Connect event on
        // this endpoint harmless.
        if (! AgencyProviderStatusMap::handlesEventType($eventType)) {
            return [AgencySubscriptionEventState::Ignored, 'unhandled_event_type', null];
        }

        // ---- THE ACCOUNT, BEFORE ANYTHING ELSE --------------------------
        $connectedAccountId = (string) $event->connected_account_id;

        if ($connectedAccountId === '') {
            // A Connect event with no account is not one we can place safely.
            return [AgencySubscriptionEventState::Failed, 'no_connected_account', null];
        }

        $connection = $connections->findByConnectedAccountId($connectedAccountId);

        if ($connection === null) {
            // Not an account any Agency here currently has connected.
            return [AgencySubscriptionEventState::Failed, 'unknown_connected_account', null];
        }

        $payload = json_decode((string) $event->payload_encrypted, true);
        $object = $payload['data']['object'] ?? null;

        if (! is_array($object)) {
            return [AgencySubscriptionEventState::Ignored, 'unusable_payload', null];
        }

        $subscription = $this->resolve($event, $object, $connectedAccountId);

        if (is_string($subscription)) {
            return [AgencySubscriptionEventState::Failed, $subscription, null];
        }

        // The resolved row must belong to the SAME account the event came from
        // AND to the Agency that owns that connection. Two separate facts, both
        // checked, because a shared customer id is not proof of either.
        if ((string) $subscription->connected_account_id !== $connectedAccountId
            || (int) $subscription->agency_workspace_id !== (int) $connection->agency_workspace_id) {
            return [AgencySubscriptionEventState::Failed, AgencyClientSubscriptionFinalizer::ACCOUNT_MISMATCH, (int) $subscription->id];
        }

        $providerSubscriptionId = $this->providerSubscriptionId($eventType, $object)
            ?? (string) $subscription->provider_subscription_id;

        if ($providerSubscriptionId === '') {
            return [AgencySubscriptionEventState::Failed, 'no_provider_subscription', (int) $subscription->id];
        }

        $this->attachEvent($event, $subscription);

        // Provider truth is RE-READ rather than trusted from the payload: the
        // event body is a point-in-time copy, and lane C's rule that local
        // state changes only on verified provider truth is best served by
        // asking, on the Agency's own account.
        $disposition = $manager->applyProviderSubscription(
            $subscription,
            $providerSubscriptionId,
            $connectedAccountId,
            (string) $event->provider_event_id,
            $event->provider_created_at,
        );

        return match ($disposition) {
            AgencyClientSubscriptionFinalizer::APPLIED
                => [AgencySubscriptionEventState::Processed, null, (int) $subscription->id],
            AgencyClientSubscriptionFinalizer::IGNORED_OUT_OF_ORDER,
            AgencyClientSubscriptionFinalizer::IGNORED_COMPLIMENTARY
                => [AgencySubscriptionEventState::Ignored, $disposition, (int) $subscription->id],
            // Every cross-check mismatch is fail-closed and kept as a code.
            default => [AgencySubscriptionEventState::Failed, $disposition, (int) $subscription->id],
        };
    }

    /**
     * §C4.1 — resolution by PROVIDER REFERENCE, in this order and no other,
     * and always scoped to the account the event actually came from:
     *
     *   1. `checkout.session.completed` carries OUR OWN `client_reference_id`,
     *      which is the durable lane-C subscription UID — the strongest link
     *      there is, because we put it there;
     *   2. the provider subscription id;
     *   3. our own `app_operation_id` metadata, echoed back on the
     *      subscription object — the link that resolves the very first
     *      subscription event for a brand-new checkout;
     *   4. the provider customer id, with an ambiguity guard.
     *
     * Anything unresolved is a reason code, never a guess.
     *
     * @param  array<string, mixed>  $object
     * @return AgencyClientSubscription|string the row, or a fail-closed reason code
     */
    private function resolve(
        AgencyClientSubscriptionEvent $event,
        array $object,
        string $connectedAccountId,
    ): AgencyClientSubscription|string {
        if ((string) $event->event_type === 'checkout.session.completed') {
            $reference = $object['client_reference_id'] ?? null;

            if (! is_string($reference) || $reference === '') {
                // A checkout that is not ours — another product on the same
                // connected account, for instance. Fail closed rather than
                // adopting it.
                return 'foreign_checkout_session';
            }

            $subscription = AgencyClientSubscription::query()->where('uid', $reference)->first();

            return $subscription ?? 'no_matching_local_record';
        }

        if ($event->provider_subscription_id !== null) {
            $bySubscription = AgencyClientSubscription::query()
                ->where('provider_subscription_id', $event->provider_subscription_id)
                ->where('connected_account_id', $connectedAccountId)
                ->first();

            if ($bySubscription !== null) {
                return $bySubscription;
            }
        }

        $operationId = $object['metadata']['app_operation_id'] ?? null;

        if (is_string($operationId) && $operationId !== '') {
            $byOperation = AgencyClientSubscription::query()->where('uid', $operationId)->first();

            if ($byOperation !== null) {
                return $byOperation;
            }
        }

        if ($event->provider_customer_id !== null) {
            // Scoped to the account: two different Agencies can legitimately
            // have unrelated customers carrying the same id string.
            $byCustomer = AgencyClientSubscription::query()
                ->where('provider_customer_id', $event->provider_customer_id)
                ->where('connected_account_id', $connectedAccountId)
                ->orderBy('id')
                ->get();

            if ($byCustomer->count() === 1) {
                return $byCustomer->first();
            }

            if ($byCustomer->count() > 1) {
                // Two local rows share one provider customer on one account:
                // nothing in the event says which one this is about.
                return 'cross_reference_ambiguity';
            }
        }

        return 'no_matching_local_record';
    }

    /** Bind the event row to what it turned out to be about, for audit. */
    private function attachEvent(AgencyClientSubscriptionEvent $event, AgencyClientSubscription $subscription): void
    {
        DB::table('agency_client_subscription_events')
            ->where('id', $event->id)
            ->update([
                'agency_client_subscription_id' => $subscription->id,
                'agency_workspace_id' => $subscription->agency_workspace_id,
            ]);
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
     * The atomic conditional claim.
     */
    private function claim(): int
    {
        $now = now();

        return DB::table('agency_client_subscription_events')
            ->where('id', $this->eventId)
            ->where(function ($query) use ($now) {
                $query->where('state', AgencySubscriptionEventState::Received->value)
                    ->orWhere('state', AgencySubscriptionEventState::Failed->value)
                    ->orWhere(function ($stale) use ($now) {
                        $stale->where('state', AgencySubscriptionEventState::Processing->value)
                            ->whereNotNull('lease_expires_at')
                            ->where('lease_expires_at', '<', $now);
                    });
            })
            ->update([
                'state' => AgencySubscriptionEventState::Processing->value,
                'attempts' => DB::raw('attempts + 1'),
                'processing_started_at' => $now,
                'last_attempt_at' => $now,
                'lease_expires_at' => $now->copy()->addSeconds(self::LEASE_SECONDS),
            ]);
    }

    /**
     * Terminal writes are guarded on still owning the claim, so a worker that
     * lost its lease mid-flight cannot overwrite the winner's result.
     */
    private function finish(AgencySubscriptionEventState $state, ?string $reason, ?int $subscriptionId): void
    {
        $attributes = [
            'state' => $state->value,
            'completed_at' => now(),
            'lease_expires_at' => null,
            'last_error' => $reason === null ? null : mb_substr($reason, 0, 120),
        ];

        if ($subscriptionId !== null) {
            $attributes['agency_client_subscription_id'] = $subscriptionId;
        }

        DB::table('agency_client_subscription_events')
            ->where('id', $this->eventId)
            ->where('state', AgencySubscriptionEventState::Processing->value)
            ->update($attributes);
    }
}

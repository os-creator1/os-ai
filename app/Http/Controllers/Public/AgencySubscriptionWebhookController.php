<?php

namespace App\Http\Controllers\Public;

use App\Enums\AgencyBilling\AgencySubscriptionEventState;
use App\Exceptions\AgencyBilling\AgencyBillingException;
use App\Http\Controllers\Controller;
use App\Jobs\AgencyBilling\ProcessAgencyClientSubscriptionEvent;
use App\Library\AgencyBilling\AgencyStripeGateway;
use App\Models\AgencyClientSubscriptionEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Lane C §C4.1 — lane C's OWN webhook endpoint.
 *
 * FOUR LANES, FOUR ENDPOINTS, FOUR SECRETS. An Agency SaaS subscription event
 * must never be verifiable by, or resolvable against, lane A's platform
 * subscriptions, lane B's document payments or lane D's wallets. Sharing one
 * endpoint would make that a matter of careful routing; separate endpoints make
 * it a matter of cryptography.
 *
 * SIGNATURE FIRST, OVER THE RAW BODY, before anything is stored. A body we
 * cannot verify never reaches the database.
 *
 * THESE ARE CONNECT EVENTS, so each one names the connected `account` it
 * belongs to. That account is captured at INTAKE, as durable evidence, and the
 * job then proves it belongs to a known Agency connection AND matches the
 * subscription the event claims to be about. Intake is deliberately permissive
 * and processing deliberately strict: an event we cannot place is still
 * recorded so it can be investigated, then failed closed with a reason code
 * rather than guessed at.
 *
 * INTAKE MUTATES NOTHING COMMERCIAL. It records and dispatches; every decision
 * about money or access belongs to the job and the shared finalizer.
 */
class AgencySubscriptionWebhookController extends Controller
{
    public function __construct(private readonly AgencyStripeGateway $gateway)
    {
    }

    public function handle(Request $request): Response
    {
        // 1 — signature first, over the raw body.
        try {
            $event = $this->gateway->verifyWebhookPayload(
                $request->getContent(),
                (string) $request->header('Stripe-Signature', ''),
            );
        } catch (AgencyBillingException) {
            // No reason code and no provider detail in the body: an attacker
            // probing signatures learns nothing beyond "not accepted".
            return response('', 400);
        }

        $providerEventId = (string) ($event['id'] ?? '');
        $eventType = (string) ($event['type'] ?? '');

        if ($providerEventId === '' || $eventType === '') {
            // A verified body that is not an event is acknowledged and
            // dropped: retrying it would never succeed.
            return response('', 200);
        }

        $object = $event['data']['object'] ?? [];
        $payload = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        try {
            $row = AgencyClientSubscriptionEvent::create([
                // The Connect account the provider says this belongs to.
                // Captured now so the job's check is against durable evidence.
                'connected_account_id' => self::stringField($event, 'account'),
                'provider_event_id' => $providerEventId,
                'event_type' => $eventType,
                'provider_customer_id' => self::stringField($object, 'customer'),
                'provider_subscription_id' => self::subscriptionReference($eventType, $object),
                'payload_encrypted' => $payload,
                'payload_hash' => hash('sha256', (string) $payload),
                'provider_created_at' => isset($event['created']) && is_int($event['created'])
                    ? CarbonImmutable::createFromTimestampUTC($event['created'])
                    : null,
            ]);
        } catch (QueryException $e) {
            // 2 — duplicate delivery. 200, and nothing re-processed. The
            // unique index on provider_event_id is what makes this structural.
            if ($e->getCode() === '23000') {
                $this->redispatchIfExhausted($providerEventId);

                return response('', 200);
            }

            throw $e;
        }

        // 3 — the job claims the row.
        ProcessAgencyClientSubscriptionEvent::dispatch((int) $row->id);

        return response('', 200);
    }

    /**
     * A retry of an event whose previous attempts all failed deserves another
     * run; anything else is already handled or in flight.
     */
    private function redispatchIfExhausted(string $providerEventId): void
    {
        $row = AgencyClientSubscriptionEvent::query()
            ->where('provider_event_id', $providerEventId)
            ->first();

        if ($row !== null && $row->state === AgencySubscriptionEventState::Failed) {
            ProcessAgencyClientSubscriptionEvent::dispatch((int) $row->id);
        }
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private static function stringField(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        if (is_string($value) && $value !== '') {
            return Str::limit($value, 180, '');
        }

        if (is_array($value) && is_string($value['id'] ?? null)) {
            return Str::limit($value['id'], 180, '');
        }

        return null;
    }

    /**
     * A subscription event IS the subscription; an invoice event points at one.
     *
     * @param  array<string, mixed>  $object
     */
    private static function subscriptionReference(string $eventType, array $object): ?string
    {
        return str_starts_with($eventType, 'customer.subscription.')
            ? self::stringField($object, 'id')
            : self::stringField($object, 'subscription');
    }
}

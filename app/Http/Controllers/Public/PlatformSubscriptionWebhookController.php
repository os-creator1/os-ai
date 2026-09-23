<?php

namespace App\Http\Controllers\Public;

use App\Exceptions\PlatformBilling\PlatformBillingException;
use App\Http\Controllers\Controller;
use App\Jobs\PlatformBilling\ProcessPlatformSubscriptionEvent;
use App\Library\PlatformBilling\PlatformStripeGateway;
use App\Models\PlatformSubscriptionEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Implementation Contract 21 §12 — the LANE-A webhook boundary, at its own
 * path (`stripe/webhook/platform-subscriptions`) with its own signing secret.
 *
 * Three endpoints, three secrets, three lanes (§2): lane B's Connect endpoint
 * and lane D's usage-billing endpoint are deliberately separate, so an event
 * about a Workspace's platform subscription can never be verified by, or
 * resolved against, another lane's records.
 *
 * NO `account` FIELD HERE, and that is the point. Lane B routes by the event's
 * connected-account id because one endpoint serves every connected account.
 * Lane A has no connected account at all — every event belongs to the Platform
 * Owner's own Stripe account — so `provider_event_id` alone is the identity,
 * and the unique key on it is what makes duplicate delivery harmless.
 *
 * §12 IN ORDER, AND THE ORDER IS THE SECURITY:
 *   1. verify the signature over the EXACT RAW BODY before a single row is
 *      inserted; failure is 400 with zero side effects;
 *   2. insert identity + encrypted payload + hash. A duplicate delivery
 *      violates unique(provider_event_id), is caught on SQLSTATE 23000, and
 *      returns 200 with zero re-processing;
 *   3. dispatch the job, which claims the row atomically.
 *
 * INTAKE MUTATES NO SUBSCRIPTION AND NO WORKSPACE. It records and hands off,
 * so a slow or failing consumer can never make Stripe retry the verification
 * step. An event we cannot place is still recorded and then fails closed in
 * the job (§12.4), rather than being lost or crashing intake.
 */
class PlatformSubscriptionWebhookController extends Controller
{
    public function __construct(private readonly PlatformStripeGateway $gateway)
    {
    }

    public function handle(Request $request): Response
    {
        // 1 — signature first, over the raw body, before anything is stored.
        try {
            $event = $this->gateway->verifyWebhookPayload(
                $request->getContent(),
                (string) $request->header('Stripe-Signature', ''),
            );
        } catch (PlatformBillingException) {
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
            $row = PlatformSubscriptionEvent::create([
                'provider_event_id' => $providerEventId,
                'event_type' => $eventType,
                // Recorded at intake purely so the job can resolve the row
                // without re-parsing; resolution itself, and its fail-closed
                // behaviour, belong to the job.
                'provider_customer_id' => self::stringField($object, 'customer'),
                'provider_subscription_id' => self::subscriptionReference($eventType, $object),
                'payload_encrypted' => $payload,
                'payload_hash' => hash('sha256', (string) $payload),
                'provider_created_at' => isset($event['created']) && is_int($event['created'])
                    ? CarbonImmutable::createFromTimestampUTC($event['created'])
                    : null,
            ]);
        } catch (QueryException $e) {
            // 2 — duplicate delivery. 200, and nothing re-processed.
            if ($e->getCode() === '23000') {
                return response('', 200);
            }

            throw $e;
        }

        // 3 — the job claims the row; intake itself mutates nothing.
        ProcessPlatformSubscriptionEvent::dispatch((int) $row->id);

        return response('', 200);
    }

    /**
     * Where the subscription reference lives depends on the object the event
     * carries: a subscription event IS the subscription, while an invoice
     * event points at one. Both shapes are read explicitly rather than
     * guessed at.
     *
     * @param  array<string, mixed>  $object
     */
    private static function subscriptionReference(string $eventType, array $object): ?string
    {
        if (str_starts_with($eventType, 'customer.subscription.')) {
            return self::stringField($object, 'id');
        }

        return self::stringField($object, 'subscription');
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private static function stringField(array $object, string $key): ?string
    {
        $value = $object[$key] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        // Expanded objects arrive as a map with their own id.
        if (is_array($value) && is_string($value['id'] ?? null)) {
            return $value['id'];
        }

        return null;
    }
}

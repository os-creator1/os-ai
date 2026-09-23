<?php

namespace App\Http\Controllers\Public;

use App\Exceptions\Payments\StripeConnectException;
use App\Http\Controllers\Controller;
use App\Jobs\BusinessPayments\ProcessBusinessPaymentEvent;
use App\Library\Payments\StripeConnectGateway;
use App\Models\BusinessPaymentEvent;
use App\Models\BusinessStripeConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Database\QueryException;

/**
 * Implementation Contract 17 §5.8 / §8.2 — the lane-B Connect webhook
 * boundary, at its OWN path with its OWN secret, deliberately separate from
 * lane D's `stripe/webhook/usage-billing`.
 *
 * For Stripe Connect one platform-level endpoint receives events for every
 * connected account, and each event carries its own `account` field — that
 * field, never a per-Business secret, is what routes an event to a Business
 * (§5.8).
 *
 * §8.2 IN ORDER, and the order is the security:
 *   1. verify the signature over the EXACT RAW BODY before a single row is
 *      inserted; failure is 400 with zero side effects;
 *   2. insert identity + encrypted payload + hash. A duplicate delivery
 *      violates unique(stripe_account_id, provider_event_id), is caught on
 *      SQLSTATE 23000, and returns 200 with zero re-processing;
 *   3. dispatch the job, which claims the row atomically.
 *
 * Intake NEVER mutates a payment or a document. It records and hands off, so
 * a slow or failing consumer can never make Stripe retry the verification
 * step. An event for an unrecognized account is still recorded (with a null
 * connection) and ignored by the job, rather than lost or crashing intake.
 */
class BusinessPaymentWebhookController extends Controller
{
    public function __construct(private readonly StripeConnectGateway $gateway)
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
        } catch (StripeConnectException) {
            // No reason code and no provider detail in the body: an attacker
            // probing signatures learns nothing beyond "not accepted".
            return response('', 400);
        }

        $providerEventId = (string) ($event['id'] ?? '');
        $eventType = (string) ($event['type'] ?? '');
        $accountId = (string) ($event['account'] ?? '');

        if ($providerEventId === '' || $eventType === '' || $accountId === '') {
            // A verified body that is not a Connect event is acknowledged and
            // dropped: retrying it would never succeed.
            return response('', 200);
        }

        $payload = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        try {
            $row = BusinessPaymentEvent::create([
                // Nullable by design (§5.8): an unknown account is still
                // recorded, then ignored by the job.
                'business_stripe_connection_id' => BusinessStripeConnection::query()
                    ->where('stripe_account_id', $accountId)
                    ->orderByDesc('id')
                    ->value('id'),
                'stripe_account_id' => $accountId,
                'provider_event_id' => $providerEventId,
                'event_type' => $eventType,
                'payload_encrypted' => $payload,
                'payload_hash' => hash('sha256', (string) $payload),
            ]);
        } catch (QueryException $e) {
            // 2 — duplicate delivery. 200, and nothing re-processed.
            if ($e->getCode() === '23000') {
                return response('', 200);
            }

            throw $e;
        }

        // 3 — the job claims the row; intake itself mutates nothing.
        ProcessBusinessPaymentEvent::dispatch((int) $row->id);

        return response('', 200);
    }
}

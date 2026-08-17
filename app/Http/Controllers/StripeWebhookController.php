<?php

namespace App\Http\Controllers;

use App\Enums\Usage\PaymentProvider;
use App\Enums\Usage\ProviderEventState;
use App\Exceptions\Usage\WebhookSignatureVerificationException;
use App\Jobs\Usage\ProcessPaymentProviderEvent;
use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Repositories\Contracts\PaymentProviderEventRepository;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * RFC-005 M3 contract §13 — unauthenticated, signature-verified webhook
 * intake. No session, no CSRF (routes/public.php, item 108), no
 * authenticated-user assumption. Verifies the Stripe signature over the
 * exact raw body before any row is inserted or any mutation occurs; a
 * verification failure returns 400 with zero side effects. Persists
 * identity/payload/hash only, then dispatches ProcessPaymentProviderEvent
 * — this controller performs no accounting mutation itself.
 */
class StripeWebhookController extends Controller
{
    public function __construct(
        private readonly PaymentProviderGateway $gateway,
        private readonly PaymentProviderEventRepository $eventRepository,
    ) {
    }

    public function handle(Request $request): Response
    {
        $rawBody = $request->getContent();
        $signatureHeader = (string) $request->header('Stripe-Signature');

        try {
            $result = $this->gateway->verifyWebhookSignature(
                $rawBody,
                $signatureHeader,
                (string) config('services.stripe.webhook.secret'),
            );
        } catch (WebhookSignatureVerificationException) {
            return response('', 400);
        }

        try {
            $event = $this->eventRepository->create([
                'provider' => PaymentProvider::Stripe,
                'provider_event_id' => $result->providerEventId,
                'event_type' => $result->eventType,
                'provider_object_id' => $result->providerObjectId,
                'payload_encrypted' => $rawBody,
                'payload_hash' => hash('sha256', $rawBody),
                'state' => ProviderEventState::Received,
                'attempts' => 0,
                'received_at' => now(),
            ]);
        } catch (QueryException $e) {
            if ((string) $e->getCode() === '23000') {
                return response('', 200);
            }

            throw $e;
        }

        ProcessPaymentProviderEvent::dispatch($event->id);

        return response('', 200);
    }
}

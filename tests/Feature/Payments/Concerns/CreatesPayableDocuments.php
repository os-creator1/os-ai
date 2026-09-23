<?php

namespace Tests\Feature\Payments\Concerns;

use App\Enums\Documents\StripeConnectionStatus;
use App\Library\Documents\DocumentManager;
use App\Library\Documents\PublicDocumentAccess;
use App\Library\Documents\PublicDocumentGuard;
use App\Library\Entitlement\CustomerAccountAccessGuard;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Payments\StripeConnectGateway;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Models\BusinessStripeConnection;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Documents\Concerns\SendsDocuments;
use Tests\Support\Payments\FakeStripeConnectGateway;

/**
 * Sub-slice E fixtures — a real sent/signed document with a real payment
 * schedule and a charge-ready connected account, all built through the
 * production managers.
 */
trait CreatesPayableDocuments
{
    use SendsDocuments;

    protected FakeStripeConnectGateway $gateway;

    protected int $payableAccountSequence = 0;

    protected function bindFakeGateway(): FakeStripeConnectGateway
    {
        $this->gateway = new FakeStripeConnectGateway();
        $this->gateway->baselineTransactionLevel = DB::transactionLevel();
        $this->app->instance(StripeConnectGateway::class, $this->gateway);

        return $this->gateway;
    }

    /** A live, active, charge-ready connection for the Business. */
    protected function chargeReadyConnection(Business $business, string $accountId = 'acct_ready001'): BusinessStripeConnection
    {
        $connection = new BusinessStripeConnection([
            'business_id' => $business->id,
            'stripe_account_id' => $accountId,
        ]);

        $connection->forceFill([
            'status' => StripeConnectionStatus::Active->value,
            'charges_enabled' => true,
            'payouts_enabled' => true,
            'details_submitted' => true,
            'connected_at' => now(),
        ])->save();

        return $connection;
    }

    /**
     * A document that is signed, payable, and backed by a ready connection.
     *
     * @param  array<int, array<string, mixed>>|null  $schedule
     * @return array{tenant: array, document: BusinessDocument, token: string, connection: BusinessStripeConnection}
     */
    protected function payableDocument(?array $schedule = null, bool $sign = true, ?string $accountId = null): array
    {
        $tenant = $this->sendableTenant();
        // `stripe_account_id` is globally unique, so a test that builds more
        // than one payable fixture needs a distinct account each time. The
        // first one is still acct_ready001.
        $connection = $this->chargeReadyConnection(
            $tenant['business'],
            $accountId ?? 'acct_ready' . str_pad((string) (++$this->payableAccountSequence), 3, '0', STR_PAD_LEFT),
        );

        $document = $this->draftDocument($tenant);

        if ($schedule !== null) {
            app(DocumentManager::class)->setSchedule($document, $schedule);
        }

        [$document, $token] = $this->sendAndCaptureToken($document->refresh());

        if ($sign) {
            app(DocumentManager::class)->sign($document, [
                'signer_name' => 'Pat Rivera',
                'signer_email' => 'pat@example.test',
                'typed_name' => 'Pat Rivera',
                'ip_address' => '127.0.0.1',
                'user_agent' => null,
            ]);
        }

        return [
            'tenant' => $tenant,
            'document' => $document->refresh(),
            'token' => $token,
            'connection' => $connection,
        ];
    }

    /** A deposit + balance schedule totalling the fixture's 50000 minor units. */
    protected function depositAndBalance(): array
    {
        return [
            ['kind' => 'deposit', 'amount_minor' => 20000, 'currency_code' => 'USD'],
            ['kind' => 'balance', 'amount_minor' => 30000, 'currency_code' => 'USD'],
        ];
    }

    protected function accessFor(BusinessDocument $document, string $token): PublicDocumentAccess
    {
        return app(PublicDocumentGuard::class)->resolve((string) $document->uid, $token);
    }

    protected function payUrl(BusinessDocument $document, string $token): string
    {
        return route('public.documents.pay', ['uid' => $document->uid, 'token' => $token]);
    }

    /**
     * Payments & Contracts is Planned until Sub-slice G, so the public guard
     * would refuse everything. This replaces EXACTLY that one step.
     */
    protected function allowPaymentEntitlement(): void
    {
        $this->app->bind(PublicDocumentGuard::class, fn ($app) => new class(
            $app->make(CustomerAccountAccessGuard::class),
            $app->make(EntitlementManager::class),
        ) extends PublicDocumentGuard {
            protected function entitlementAllows(Workspace $workspace, Business $business): bool
            {
                return true;
            }
        });
    }

    /**
     * A signed Connect webhook body for one PaymentIntent.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    protected function webhookPayload(
        string $eventType,
        string $intentId,
        string $accountId,
        int $amountMinor,
        string $currency,
        ?string $operationId,
        ?string $eventId = null,
    ): array {
        $body = json_encode([
            'id' => $eventId ?? ('evt_' . Str::random(16)),
            'type' => $eventType,
            'account' => $accountId,
            'data' => ['object' => [
                'id' => $intentId,
                'object' => 'payment_intent',
                'amount' => $amountMinor,
                'currency' => strtolower($currency),
                'metadata' => $operationId === null ? [] : ['app_operation_id' => $operationId],
            ]],
        ]);

        return [$body, ['Stripe-Signature' => $this->gateway->validSignature]];
    }

    protected function postWebhook(string $body, array $headers)
    {
        return $this->call('POST', '/stripe/webhook/business-payments', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $headers['Stripe-Signature'] ?? '',
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }
}

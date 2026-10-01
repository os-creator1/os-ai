<?php

namespace Tests\Feature\Documents;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Documents\DocumentManager;
use App\Library\Payments\PaymentManager;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentPayment;
use App\Models\BusinessDocumentRefund;
use App\Models\BusinessLocation;
use App\Models\BusinessStripeConnection;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Notifications\Documents\DocumentIssuedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Payments\Concerns\CreatesPayableDocuments;
use Tests\TestCase;

/**
 * Payments & Invoices V1 — Agency View As follows the CURRENT payment
 * authority rules; this class pins them, it does not change them.
 *
 * The standing product decision (ConversationsViewAsSendTest, PR #301) is that
 * View As is true impersonation: an authorized Agency actor may do whatever
 * the viewed customer could, bounded by everything that bounds the customer —
 * Business tenancy, the `payments_contracts` capability, the entitlement and
 * Location authority — and by the closed §5.5 prohibited list
 * (ViewAsProhibitedActions), none of whose families includes the documents
 * routes. Connecting or disconnecting the Business's Stripe account is a
 * provider-credential action and stays owner-only (Contract 17 §6.2), so an
 * Agency actor — who is not the owner — never reaches it.
 *
 * What View As can NEVER do is leave the viewed Business: a sibling client's
 * invoices are as invisible as they are to anyone else.
 */
class DocumentViewAsAuthorityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesPayableDocuments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeGateway();
        Notification::fake();
    }

    /** @return array{pair: array, tenant: array, document: BusinessDocument} */
    private function clientWithSentInvoice(array $pair, string $account): array
    {
        $business = $pair['clientBusiness'];
        $business->status = BusinessStatus::Active;
        $business->currency_code = 'USD';
        $business->save();
        $location = BusinessLocation::create(['business_id' => $business->id, 'name' => 'Main', 'service_mode' => 'storefront', 'country_code' => 'US']);
        $group = ContactGroups::create(['customer_id' => $business->customer_id, 'business_id' => $business->id, 'name' => 'Clients ' . uniqid(), 'status' => true]);
        $contact = Contacts::create(['customer_id' => $business->customer_id, 'business_id' => $business->id, 'group_id' => $group->id, 'phone' => '1415555' . random_int(1000, 9999), 'status' => Contacts::STATUS_SUBSCRIBE]);
        DB::table('contacts')->where('id', $contact->id)->update(['location_id' => $location->id]);
        $this->chargeReadyConnection($business, $account);

        $tenant = ['customer' => $pair['clientOwner'], 'business' => $business->fresh(), 'workspace' => $pair['clientWorkspace'], 'location' => $location, 'contact' => $contact->fresh()];
        $document = app(DocumentManager::class)->send($this->draftDocument($tenant, ['kind' => 'invoice']))->refresh();

        return ['pair' => $pair, 'tenant' => $tenant, 'document' => $document];
    }

    private function viewing(array $pair): void
    {
        $this->authenticateAs($pair['agencyOwner']);
        $this->post(route('customer.workspaces.clients.view-as', [$pair['agencyWorkspace']->uid, $pair['clientWorkspace']->uid]))
            ->assertRedirect(route('user.home'));
    }

    private function url(array $s, string $name, array $extra = []): string
    {
        return route('customer.workspaces.businesses.documents.' . $name, [
            $s['pair']['clientWorkspace']->uid, $s['tenant']['business']->uid, ...$extra,
        ]);
    }

    private function agencyWithClient(): array
    {
        $pair = $this->createAgencyManagedClient(clientBusinessName: 'Harbor Lane Studios', agencyBusinessName: 'Primary', agencyWorkspaceName: 'Northwind Agency');
        $this->assignTier($pair['clientWorkspace'], WorkspacePlanTier::Growth);

        return $pair;
    }

    /** A fresh known token: a resend rotates the link and hands back its plaintext. */
    private function latestToken(BusinessDocument $document): string
    {
        $token = null;
        Notification::fake();
        app(DocumentManager::class)->resendLink($document->refresh());
        Notification::assertSentOnDemand(DocumentIssuedNotification::class, function ($notification) use (&$token) {
            $token = (new \ReflectionProperty($notification, 'plaintextToken'))->getValue($notification);

            return true;
        });

        return (string) $token;
    }

    public function test_the_agency_actor_has_no_access_to_a_client_business_until_it_starts_view_as(): void
    {
        $s = $this->clientWithSentInvoice($this->agencyWithClient(), 'acct_va001');
        $this->authenticateAs($s['pair']['agencyOwner']);

        $this->get($this->url($s, 'index'))->assertNotFound();
        $this->get($this->url($s, 'show', [$s['document']->uid]))->assertNotFound();
    }

    public function test_view_as_can_read_resend_and_void_exactly_as_the_client_could(): void
    {
        $s = $this->clientWithSentInvoice($this->agencyWithClient(), 'acct_va002');
        $this->viewing($s['pair']);

        $this->get($this->url($s, 'index'))->assertOk();
        $this->get($this->url($s, 'show', [$s['document']->uid]))->assertOk()->assertSee('Re-send payment link', false);

        $hash = $s['document']->access_token_hash;
        $this->post($this->url($s, 'resend', [$s['document']->uid]))->assertRedirect();
        $this->assertNotSame($hash, $s['document']->fresh()->access_token_hash);

        $this->post($this->url($s, 'void', [$s['document']->uid]), ['reason' => 'Agency voided'])->assertRedirect();
        $this->assertSame('void', $s['document']->fresh()->status->value);
    }

    public function test_view_as_refund_follows_the_same_gate_as_the_client(): void
    {
        $s = $this->clientWithSentInvoice($this->agencyWithClient(), 'acct_va003');
        $document = $s['document'];
        $token = $this->latestToken($document);
        app(PaymentManager::class)->start($this->accessFor($document->refresh(), $token));
        $payment = BusinessDocumentPayment::query()->firstOrFail();
        $connection = BusinessStripeConnection::query()->find($payment->business_stripe_connection_id);
        [$body, $headers] = $this->webhookPayload('payment_intent.succeeded', (string) $payment->provider_payment_intent_id,
            (string) $connection->stripe_account_id, (int) $payment->amount_minor, (string) $payment->currency_code, (string) $payment->local_idempotency_key);
        $this->postWebhook($body, $headers)->assertOk();

        $this->viewing($s['pair']);

        // Without the explicit confirmation the refund is refused, View As or not.
        $this->post($this->url($s, 'payments.refund', [$document->uid, $payment->uid]), ['amount_minor' => 100])->assertSessionHasErrors('confirm');
        $this->assertSame(0, BusinessDocumentRefund::query()->count());

        $this->post($this->url($s, 'payments.refund', [$document->uid, $payment->uid]), ['confirm' => '1', 'amount_minor' => 100])->assertRedirect();
        $this->assertSame(1, BusinessDocumentRefund::query()->count());
    }

    public function test_view_as_never_reaches_stripe_connect_changes_or_a_sibling_clients_invoices(): void
    {
        $pair = $this->agencyWithClient();
        $s = $this->clientWithSentInvoice($pair, 'acct_va004');

        // A second managed client of the SAME agency.
        $otherPair = $this->createAgencyManagedClient($pair['agencyWorkspace'], 'Other Client', 'Other Client Workspace');
        $this->assignTier($otherPair['clientWorkspace'], WorkspacePlanTier::Growth);
        $other = $this->clientWithSentInvoice($otherPair, 'acct_va005');

        $this->viewing($pair);

        // Owner-only provider-credential action: the Agency actor is not the owner.
        $disconnect = route('customer.workspaces.businesses.payments.connect.disconnect', [$pair['clientWorkspace']->uid, $s['tenant']['business']->uid]);
        $this->post($disconnect)->assertNotFound();
        $this->assertNull(BusinessStripeConnection::query()->where('business_id', $s['tenant']['business']->id)->firstOrFail()->disconnected_at);

        // The OTHER client's Business is not the viewed one.
        $foreign = route('customer.workspaces.businesses.documents.show', [$otherPair['clientWorkspace']->uid, $other['tenant']['business']->uid, $other['document']->uid]);
        $this->get($foreign)->assertNotFound();
        $this->post(route('customer.workspaces.businesses.documents.void', [$otherPair['clientWorkspace']->uid, $other['tenant']['business']->uid, $other['document']->uid]), ['reason' => 'x'])->assertNotFound();
        $this->assertSame('sent', $other['document']->fresh()->status->value);

        // And the viewed client's own document through the OTHER client's frame.
        $mixed = route('customer.workspaces.businesses.documents.show', [$otherPair['clientWorkspace']->uid, $other['tenant']['business']->uid, $s['document']->uid]);
        $this->get($mixed)->assertNotFound();
    }
}

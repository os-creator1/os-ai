<?php

namespace Tests\Feature\Documents;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Documents\DocumentManager;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentSignature;
use App\Models\Contacts;
use App\Notifications\Documents\DocumentIssuedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Catalog\Concerns\CreatesCatalogHttpFixtures;
use Tests\Feature\Documents\Concerns\CreatesDocumentsTestData;
use Tests\Feature\Documents\Concerns\SendsDocuments;
use Tests\Support\Documents\ShownVersion;
use Tests\TestCase;

/**
 * The customer-facing journey over REAL HTTP, and the tenancy walls around it:
 * draft -> add lines -> send -> recipient views -> signs -> read-only signed
 * record for the Business, plus foreign-Business, Location-limited and
 * Agency View-As boundaries.
 */
class DocumentJourneyAndTenancyHttpTest extends TestCase
{
    use RefreshDatabase;
    use SendsDocuments;
    use CreatesDocumentsTestData;
    use CreatesCatalogHttpFixtures;

    private function docRoute(string $name, $workspace, Business $business, array $parameters = []): string
    {
        return route('customer.workspaces.businesses.documents.' . $name, [$workspace->uid, $business->uid, ...$parameters]);
    }

    // -----------------------------------------------------------------
    // The whole journey
    // -----------------------------------------------------------------

    public function test_the_owner_takes_a_proposal_from_draft_to_a_read_only_signed_record(): void
    {
        $tenant = $this->sendableTenant('Harbor Lane Studios');
        $this->allowPublicEntitlement();
        $ws = $tenant['workspace'];
        $biz = $tenant['business'];
        $this->authenticateAs($tenant['customer']);
        $item = $this->catalogItem($biz, 'Garden Package', ['price_minor' => 50000]);

        // ---- create: ownership is re-derived, never taken from the form -------
        $this->post($this->docRoute('store', $ws, $biz), [
            'kind' => 'proposal', 'title' => 'Journey proposal',
            'location_uid' => $tenant['location']->uid, 'contact_uid' => $tenant['contact']->uid,
            // Caller-supplied model fields are not part of the contract and are ignored.
            'business_id' => 999999, 'business_location_id' => 999999, 'contact_id' => 999999,
            'status' => 'signed', 'currency_code' => 'EUR', 'requires_signature' => 0,
        ])->assertRedirect();

        $document = BusinessDocument::query()->sole();
        $this->assertSame((int) $biz->id, (int) $document->business_id);
        $this->assertSame((int) $tenant['location']->id, (int) $document->business_location_id);
        $this->assertSame((int) $tenant['contact']->id, (int) $document->contact_id);
        $this->assertSame('draft', $document->status->value);
        $this->assertSame('USD', $document->currency_code);
        $this->assertTrue((bool) $document->requires_signature);

        // ---- author ----------------------------------------------------------
        $this->patch($this->docRoute('update', $ws, $biz, [$document->uid]), [
            'title' => 'Journey proposal v2',
            'content' => ['body' => "Scope of work.\nNet 30."],
            'recipient_name_snapshot' => 'Pat Rivera',
            'recipient_email_snapshot' => 'pat@example.test',
        ])->assertRedirect();
        $this->post($this->docRoute('catalog-lines.store', $ws, $biz, [$document->uid]), ['catalog_item_uid' => $item->uid, 'quantity' => 1])->assertRedirect();
        $this->post($this->docRoute('custom-lines.store', $ws, $biz, [$document->uid]), ['name' => 'Site visit', 'quantity' => 1, 'unit_price_minor' => 25000])->assertRedirect();
        $this->put($this->docRoute('schedule.update', $ws, $biz, [$document->uid]), [
            'terms' => [
                ['kind' => 'deposit', 'amount_minor' => 25000, 'currency_code' => 'USD'],
                ['kind' => 'balance', 'amount_minor' => 50000, 'currency_code' => 'USD'],
            ],
        ])->assertRedirect();

        $this->get($this->docRoute('show', $ws, $biz, [$document->uid]))
            ->assertOk()->assertSee('Journey proposal v2')->assertSee('Garden Package')->assertSee('Site visit')->assertSee('Send document');

        // ---- send ------------------------------------------------------------
        Notification::fake();
        $this->post($this->docRoute('send', $ws, $biz, [$document->uid]))->assertRedirect();
        $token = null;
        Notification::assertSentOnDemand(DocumentIssuedNotification::class, function ($notification) use (&$token) {
            $token = (new \ReflectionProperty($notification, 'plaintextToken'))->getValue($notification);

            return true;
        });
        $document = $document->fresh();
        $this->assertSame('sent', $document->status->value);

        // The Business now sees the SENT version read-only, its history and delivery state.
        $this->get($this->docRoute('show', $ws, $biz, [$document->uid]))
            ->assertOk()
            ->assertSee('data-role="issued-version"', false)
            ->assertSee('Sent version')
            ->assertSee('Garden Package')
            ->assertSee('750.00')
            ->assertSee('Re-send payment link')
            ->assertSee('handed to the mail provider')
            ->assertSee('pat@example.test')
            ->assertDontSee('Save recipient')
            ->assertDontSee('Add custom line');

        // The sent version cannot be edited from the UI either.
        $this->patch($this->docRoute('update', $ws, $biz, [$document->uid]), ['title' => 'Sneaky retitle'])->assertSessionHasErrors();
        $this->post($this->docRoute('custom-lines.store', $ws, $biz, [$document->uid]), ['name' => 'Late', 'quantity' => 1, 'unit_price_minor' => 1])->assertSessionHasErrors();
        $this->assertSame('Journey proposal v2', $document->fresh()->title);

        // A double-clicked Send is a replay: no error, no second email.
        Notification::fake();
        $this->post($this->docRoute('send', $ws, $biz, [$document->uid]))->assertRedirect()->assertSessionHasNoErrors();
        Notification::assertNothingSent();

        // ---- the recipient views it and signs --------------------------------
        $this->get($this->publicUrl($document, (string) $token))
            ->assertOk()->assertSee('Journey proposal v2')->assertSee('Net 30.')->assertSee('Garden Package');

        $this->post($this->signUrl($document, (string) $token), [
            'displayed_version_uid' => ShownVersion::uid($document),
            'signer_name' => 'Pat Rivera', 'signer_email' => 'pat@example.test', 'typed_name' => 'Pat Rivera',
        ])->assertOk()->assertSee('Signature recorded');

        // ---- the Business sees the signed, read-only record -------------------
        $this->authenticateAs($tenant['customer']);
        $this->get($this->docRoute('show', $ws, $biz, [$document->uid]))
            ->assertOk()
            ->assertSee('Sent version')
            ->assertSee('data-role="signature"', false)
            ->assertSee('Typed by Pat Rivera')
            // A signed proposal is payable, so its link can still be re-sent (Payments lane).
            ->assertSee('Re-send payment link');
        $this->get($this->docRoute('index', $ws, $biz))->assertOk()->assertSee('Journey proposal v2')->assertSee('signed');

        // ... and its content and lifecycle can no longer be changed (re-delivering
        // the link, asserted above, is not a content change).
        $this->patch($this->docRoute('update', $ws, $biz, [$document->uid]), ['content' => ['body' => 'Rewritten']])->assertSessionHasErrors();
        $this->post($this->docRoute('revise', $ws, $biz, [$document->uid]))->assertSessionHasErrors();
        $this->assertSame(1, BusinessDocumentSignature::query()->count());
        $this->assertSame('signed', $document->fresh()->status->value);
    }

    public function test_a_sent_document_is_revised_and_re_sent_through_the_ui_without_a_duplicate_event(): void
    {
        $tenant = $this->sendableTenant();
        $this->authenticateAs($tenant['customer']);
        $ws = $tenant['workspace'];
        $biz = $tenant['business'];
        [$document] = $this->sendAndCaptureToken($this->draftDocument($tenant));
        $v1 = \Tests\Support\Documents\ShownVersion::uid($document);

        $this->post($this->docRoute('revise', $ws, $biz, [$document->uid]))->assertRedirect()->assertSessionHasNoErrors();

        // Both the still-live sent version and the new revision draft are on the page.
        $this->get($this->docRoute('show', $ws, $biz, [$document->uid]))
            ->assertOk()->assertSee('Sent version')->assertSee('Draft details')->assertDontSee('Revise (new version)');

        $this->patch($this->docRoute('update', $ws, $biz, [$document->uid]), ['content' => ['body' => 'Revised terms']])->assertRedirect()->assertSessionHasNoErrors();

        \Illuminate\Support\Facades\Event::fake([\App\Events\DocumentSent::class]);
        $this->post($this->docRoute('send', $ws, $biz, [$document->uid]))->assertRedirect()->assertSessionHasNoErrors();
        $this->post($this->docRoute('send', $ws, $biz, [$document->uid]))->assertRedirect()->assertSessionHasNoErrors();

        // Version 2 is now the issued one; sending it twice announced it once.
        $this->assertNotSame($v1, \Tests\Support\Documents\ShownVersion::uid($document));
        \Illuminate\Support\Facades\Event::assertDispatchedTimes(\App\Events\DocumentSent::class, 1);
        $this->assertSame(2, $document->versions()->count());
    }

    // -----------------------------------------------------------------
    // Foreign Business identity at create
    // -----------------------------------------------------------------

    public function test_a_contact_or_location_from_another_business_is_a_404_and_writes_nothing(): void
    {
        $tenant = $this->sendableTenant('Harbor Lane Studios');
        $other = $this->sendableTenant('Other Co');
        $this->authenticateAs($tenant['customer']);

        foreach ([
            ['location_uid' => $tenant['location']->uid, 'contact_uid' => $other['contact']->uid],
            ['location_uid' => $other['location']->uid, 'contact_uid' => $tenant['contact']->uid],
            ['location_uid' => $tenant['location']->uid, 'contact_uid' => 'not-a-real-contact'],
        ] as $identity) {
            $this->post($this->docRoute('store', $tenant['workspace'], $tenant['business']), array_merge(['kind' => 'proposal', 'title' => 'Probe'], $identity))->assertNotFound();
        }

        $this->assertSame(0, BusinessDocument::query()->count());
    }

    // -----------------------------------------------------------------
    // Location-limited staff
    // -----------------------------------------------------------------

    public function test_location_limited_staff_cannot_enumerate_or_mutate_another_locations_documents(): void
    {
        $tenant = $this->sendableTenant('Harbor Lane Studios');
        $ws = $tenant['workspace'];
        $biz = $tenant['business'];
        $locA = $tenant['location'];
        $locB = $this->documentsLocation($biz, ['name' => 'Second Branch']);
        $tenantB = array_merge($tenant, ['location' => $locB, 'contact' => Contacts::findOrFail($this->documentsContact($biz, $locB))]);

        $docA = $this->draftDocument($tenant, ['title' => 'Alpha Branch Proposal']);
        $docB = $this->draftDocument($tenantB, ['title' => 'Bravo Branch Proposal']);
        $lineB = $docB->versions()->firstOrFail()->lineItems()->firstOrFail();
        $item = $this->catalogItem($biz, 'Garden Package');

        $staff = $this->staffGrantedOnly($ws, $locA);
        $this->authenticateAs($staff);

        // Enumeration: the list shows only the granted Location's documents.
        $this->get($this->docRoute('index', $ws, $biz))
            ->assertOk()->assertSee('Alpha Branch Proposal')->assertDontSee('Bravo Branch Proposal')->assertDontSee('Second Branch');

        // Positive control: the granted Location's document is fully reachable.
        $this->get($this->docRoute('show', $ws, $biz, [$docA->uid]))->assertOk();

        // Every read and mutation against the other Location's document is a 404 ...
        $before = $this->fingerprint(['business_documents', 'business_document_versions', 'business_document_line_items', 'business_document_payment_schedule_items']);
        $this->assertEveryRouteRefuses($ws, $biz, $docB, $lineB->uid, $item->uid);
        // ... and writes nothing.
        $this->assertSame($before, $this->fingerprint(['business_documents', 'business_document_versions', 'business_document_line_items', 'business_document_payment_schedule_items']));

        // Creating a document at the other Location is refused as well.
        $this->post($this->docRoute('store', $ws, $biz), [
            'kind' => 'proposal', 'title' => 'Smuggled', 'location_uid' => $locB->uid, 'contact_uid' => $tenantB['contact']->uid,
        ])->assertNotFound();
        $this->assertSame(0, BusinessDocument::query()->where('title', 'Smuggled')->count());
    }

    public function test_a_foreign_business_document_is_unreachable_through_every_route(): void
    {
        $tenant = $this->sendableTenant('Harbor Lane Studios');
        $other = $this->sendableTenant('Other Co');
        $foreign = $this->draftDocument($other, ['title' => 'Foreign Proposal']);
        $line = $foreign->versions()->firstOrFail()->lineItems()->firstOrFail();
        $item = $this->catalogItem($tenant['business'], 'Garden Package');
        $this->authenticateAs($tenant['customer']);

        $this->get($this->docRoute('index', $tenant['workspace'], $tenant['business']))->assertOk()->assertDontSee('Foreign Proposal');

        $before = $this->fingerprint(['business_documents', 'business_document_versions', 'business_document_line_items']);
        // The foreign document addressed through the caller's OWN workspace + business.
        $this->assertEveryRouteRefuses($tenant['workspace'], $tenant['business'], $foreign, $line->uid, $item->uid);
        $this->assertSame($before, $this->fingerprint(['business_documents', 'business_document_versions', 'business_document_line_items']));
    }

    /** Every document route, addressed at a document the actor must not reach. */
    /**
     * @param  array<string, int>  $expected  label => status, where the contract is not a plain 404
     */
    private function assertEveryRouteRefuses($workspace, Business $business, BusinessDocument $document, string $lineUid, string $itemUid, array $expected = []): void
    {
        $uid = $document->uid;
        $requests = [
            'show' => ['GET', $this->docRoute('show', $workspace, $business, [$uid]), []],
            'update' => ['PATCH', $this->docRoute('update', $workspace, $business, [$uid]), ['title' => 'Hijacked']],
            'send' => ['POST', $this->docRoute('send', $workspace, $business, [$uid]), []],
            'resend' => ['POST', $this->docRoute('resend', $workspace, $business, [$uid]), []],
            'revise' => ['POST', $this->docRoute('revise', $workspace, $business, [$uid]), []],
            'void' => ['POST', $this->docRoute('void', $workspace, $business, [$uid]), ['reason' => 'Hijack']],
            'catalog-lines' => ['POST', $this->docRoute('catalog-lines.store', $workspace, $business, [$uid]), ['catalog_item_uid' => $itemUid, 'quantity' => 1]],
            'custom-lines' => ['POST', $this->docRoute('custom-lines.store', $workspace, $business, [$uid]), ['name' => 'X', 'quantity' => 1, 'unit_price_minor' => 1]],
            'remove-line' => ['DELETE', $this->docRoute('lines.destroy', $workspace, $business, [$uid, $lineUid]), []],
            'reorder' => ['PUT', $this->docRoute('lines.order', $workspace, $business, [$uid]), ['line_uids' => [$lineUid]]],
            'schedule' => ['PUT', $this->docRoute('schedule.update', $workspace, $business, [$uid]), ['terms' => [['kind' => 'full', 'amount_minor' => 1, 'currency_code' => 'USD']]]],
        ];

        foreach ($requests as $label => [$method, $url, $payload]) {
            $response = $this->call($method, $url, $payload);
            $status = $expected[$label] ?? 404;
            $this->assertSame($status, $response->getStatusCode(), "{$label} must be a {$status} for a document the actor cannot reach (got {$response->getStatusCode()} -> " . ($response->headers->get('Location') ?? '-') . ').');
        }
    }

    // -----------------------------------------------------------------
    // Agency View As
    // -----------------------------------------------------------------

    public function test_agency_view_as_reaches_only_the_viewed_businesss_documents(): void
    {
        [$agency, $viewed, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Viewed Client', 'Northwind Agency');
        $managed = $this->createAgencyManagedClient($workspace, 'Sibling Client', 'Sibling Client Workspace');
        $sibling = $managed['clientBusiness'];

        $viewedDoc = $this->documentFor($viewed, $agency->user, 'Viewed Client Proposal');
        $siblingDoc = $this->documentFor($sibling, $managed['clientOwner']->user, 'Sibling Client Proposal');
        $siblingLine = $siblingDoc->versions()->firstOrFail()->lineItems()->firstOrFail();
        $siblingItem = $this->catalogItem($sibling, 'Sibling Package');

        $this->authenticateAs($agency);
        $this->startViewAs($workspace, $viewed)->assertRedirect(route('user.home'));

        // The viewed Business's documents are reachable; the sibling's are not.
        $this->get($this->docRoute('index', $workspace, $viewed))
            ->assertOk()->assertSee('Viewed Client Proposal')->assertDontSee('Sibling Client Proposal');
        $this->get($this->docRoute('show', $workspace, $viewed, [$viewedDoc->uid]))->assertOk();

        $before = $this->fingerprint(['business_documents', 'business_document_versions', 'business_document_line_items']);
        // A sibling document's raw uid does not open through the viewed Business ...
        // (While viewing, any `.destroy` route is a prohibited action — View As
        // never deletes data — so it is refused before routing, with a redirect.)
        $this->assertEveryRouteRefuses($workspace, $viewed, $siblingDoc, $siblingLine->uid, $siblingItem->uid, ['remove-line' => 302]);
        // ... and the sibling's own routes are not reachable while viewing.
        // The existing View-As rule also covers the viewed Business's OWN document:
        // removing a line is a prohibited (deleting) action while viewing.
        $viewedLine = $viewedDoc->versions()->firstOrFail()->lineItems()->firstOrFail();
        $this->delete($this->docRoute('lines.destroy', $workspace, $viewed, [$viewedDoc->uid, $viewedLine->uid]))->assertRedirect();
        $this->assertSame(1, $viewedDoc->versions()->firstOrFail()->lineItems()->count());

        $this->get($this->docRoute('index', $managed['clientWorkspace'], $sibling))->assertNotFound();
        $this->get($this->docRoute('show', $managed['clientWorkspace'], $sibling, [$siblingDoc->uid]))->assertNotFound();
        $this->post($this->docRoute('void', $managed['clientWorkspace'], $sibling, [$siblingDoc->uid]), ['reason' => 'x'])->assertNotFound();
        $this->assertSame($before, $this->fingerprint(['business_documents', 'business_document_versions', 'business_document_line_items']));
    }

    private function documentFor(Business $business, $actor, string $title): BusinessDocument
    {
        DB::table('businesses')->where('id', $business->id)->update(['status' => BusinessStatus::Active->value, 'currency_code' => 'USD']);
        $business = $business->fresh();
        $location = $this->documentsLocation($business, ['name' => 'Main']);
        $contact = Contacts::findOrFail($this->documentsContact($business, $location));
        $manager = app(DocumentManager::class);
        $document = $manager->create($business, $location, $contact, null, 'proposal', $title, $actor);
        $manager->addCustomLine($document, 'Design work', null, 1, 10000);

        return $document->refresh();
    }
}

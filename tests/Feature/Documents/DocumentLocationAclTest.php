<?php

namespace Tests\Feature\Documents;

use App\Enums\Business\BusinessStatus;
use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Http\Controllers\Customer\Business\DocumentsController;
use App\Library\Documents\DocumentManager;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Payments\PaymentManager;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\BusinessDocument;
use App\Models\Customer;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Documents\Concerns\CreatesDocumentsTestData;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Payments & Invoices V1 — Location authority over EVERY document action
 * (Contract 17 §6.1 gate 4, Addendum §4: "knowing or binding a record ID MUST
 * NEVER bypass Location authorization").
 *
 * A staff member granted Location A only must neither see nor act on a
 * Location B invoice: not list it, open it, edit it, send it, re-send it,
 * revise it, void it, or refund its payment — and a refused request changes
 * nothing. Each refusal is the same 404 a nonexistent document gets, so the
 * existence of a sibling Location's invoice is not even disclosed.
 */
class DocumentLocationAclTest extends TestCase
{
    use RefreshDatabase, CreatesDocumentsTestData, CreatesCustomerContextFixtures;

    /**
     * @return array{bundle: array, locationA: \App\Models\BusinessLocation, locationB: \App\Models\BusinessLocation, staff: Customer, ownerCustomer: Customer, docA: BusinessDocument, docB: BusinessDocument, paymentBUid: string}
     */
    private function scenario(): array
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();
        $bundle = $this->documentsBundle();
        $bundle['business']->status = BusinessStatus::Active;
        $bundle['business']->save();

        $ownerCustomer = Customer::where('user_id', $bundle['business']->workspace->owner_user_id)->firstOrFail();
        $permissions = $ownerCustomer->permissions;
        $permissions = is_string($permissions) ? json_decode($permissions, true) : $permissions;
        $ownerCustomer->permissions = json_encode(array_values(array_unique([...($permissions ?: []), 'payments_contracts'])));
        $ownerCustomer->save();

        $locationA = $bundle['location'];
        $locationB = $this->documentsLocation($bundle['business'], ['name' => 'Branch B']);
        $contactB = $this->documentsContact($bundle['business'], $locationB);

        $staff = $this->createCustomer();
        $permissions = json_encode(['payments_contracts']);
        $staff->permissions = $permissions;
        $staff->save();
        $membership = $this->createMembership($bundle['business']->workspace, $staff->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'location_access_scope' => LocationAccessScope::Selected,
        ]);
        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $locationA);

        $docA = BusinessDocument::findOrFail($this->insertDocument($bundle, ['status' => 'sent', 'kind' => 'invoice', 'requires_signature' => false, 'title' => 'Invoice at A']));
        $docB = BusinessDocument::findOrFail($this->insertDocument($bundle, [
            'business_location_id' => $locationB->id, 'contact_id' => $contactB,
            'status' => 'sent', 'kind' => 'invoice', 'requires_signature' => false, 'title' => 'Invoice at B',
        ]));

        // A real succeeded payment on B's invoice, so the refund route has
        // something genuine to refuse.
        $versionId = $this->insertVersion($docB->id, ['state' => 'issued']);
        DB::table('business_documents')->where('id', $docB->id)->update(['current_version_id' => $versionId]);
        $itemId = $this->insertScheduleItem($versionId, ['status' => 'paid']);
        $connectionId = $this->insertStripeConnection($bundle['business']->id);
        $paymentId = $this->insertPayment($bundle['business']->id, $docB->id, $itemId, $connectionId, ['status' => 'succeeded']);
        $paymentBUid = (string) DB::table('business_document_payments')->where('id', $paymentId)->value('uid');

        $this->app->bind(DocumentsController::class, fn ($app) => new class($app->make(DocumentManager::class), $app->make(EntitlementManager::class), $app->make(LocationAccessGuard::class), $app->make(PaymentManager::class)) extends DocumentsController {
            protected function entitlementAllows(\App\Models\Workspace $workspace, \App\Models\Business $business): bool
            {
                return true;
            }
        });

        return compact('bundle', 'locationA', 'locationB', 'staff', 'ownerCustomer', 'docA', 'docB', 'paymentBUid');
    }

    private function base(array $s): string
    {
        return route('customer.workspaces.businesses.documents.index', [$s['bundle']['business']->workspace->uid, $s['bundle']['business']->uid]);
    }

    public function test_staff_restricted_to_location_a_only_lists_location_a_invoices(): void
    {
        $s = $this->scenario();
        $this->authenticateAs($s['staff']);

        $this->get($this->base($s))->assertOk()->assertSee('Invoice at A')->assertDontSee('Invoice at B');
        $this->get($this->base($s) . '/' . $s['docA']->uid)->assertOk();
    }

    public function test_staff_restricted_to_location_a_cannot_reach_any_action_on_a_location_b_invoice(): void
    {
        $s = $this->scenario();
        $this->authenticateAs($s['staff']);
        $doc = $this->base($s) . '/' . $s['docB']->uid;
        $before = DB::table('business_documents')->orderBy('id')->get()->toJson() . DB::table('business_document_payments')->orderBy('id')->get()->toJson();

        $routes = [
            ['GET', $doc, []],
            ['PATCH', $doc, ['title' => 'Hijacked']],
            ['POST', $doc . '/catalog-lines', ['catalog_item_uid' => (string) Str::uuid(), 'quantity' => 1]],
            ['POST', $doc . '/custom-lines', ['name' => 'x', 'quantity' => 1, 'unit_price_minor' => 1]],
            ['PUT', $doc . '/schedule', ['terms' => [['kind' => 'full', 'amount_minor' => 1, 'currency_code' => 'USD']]]],
            ['POST', $doc . '/send', []],
            ['POST', $doc . '/resend', []],
            ['POST', $doc . '/revise', []],
            ['POST', $doc . '/void', ['reason' => 'Hijack']],
            ['POST', $doc . '/payments/' . $s['paymentBUid'] . '/refund', ['confirm' => '1', 'amount_minor' => 100]],
        ];

        foreach ($routes as [$method, $url, $payload]) {
            $this->call($method, $url, $payload)->assertNotFound();
        }

        $this->assertSame($before, DB::table('business_documents')->orderBy('id')->get()->toJson() . DB::table('business_document_payments')->orderBy('id')->get()->toJson(),
            'A refused request changes nothing.');
        $this->assertSame(0, DB::table('business_document_refunds')->count());
        $this->assertSame('sent', $s['docB']->fresh()->status->value);
    }

    public function test_staff_restricted_to_location_a_cannot_create_a_draft_at_location_b_or_with_its_contact(): void
    {
        $s = $this->scenario();
        $this->authenticateAs($s['staff']);
        $contactAUid = DB::table('contacts')->where('id', $s['bundle']['contactId'])->value('uid');
        $contactBUid = DB::table('contacts')->where('location_id', $s['locationB']->id)->value('uid');

        $this->post($this->base($s), ['kind' => 'invoice', 'title' => 'At B', 'location_uid' => $s['locationB']->uid, 'contact_uid' => $contactBUid])->assertNotFound();

        // Location A, but a Contact that belongs to Location B: the identity
        // check refuses it (nothing is created).
        $count = BusinessDocument::count();
        $this->post($this->base($s), ['kind' => 'invoice', 'title' => 'Mixed', 'location_uid' => $s['locationA']->uid, 'contact_uid' => $contactBUid])
            ->assertSessionHasErrors();
        $this->assertSame($count, BusinessDocument::count());

        // Positive control: the legitimate pairing works.
        $this->post($this->base($s), ['kind' => 'invoice', 'title' => 'At A', 'location_uid' => $s['locationA']->uid, 'contact_uid' => $contactAUid])->assertRedirect();
        $this->assertSame($count + 1, BusinessDocument::count());
    }

    public function test_an_owner_with_every_location_sees_both(): void
    {
        $s = $this->scenario();
        $this->authenticateAs($s['ownerCustomer']);

        $this->get($this->base($s))->assertOk()->assertSee('Invoice at A')->assertSee('Invoice at B');
        $this->get($this->base($s) . '/' . $s['docB']->uid)->assertOk();
    }
}

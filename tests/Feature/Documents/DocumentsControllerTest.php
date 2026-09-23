<?php

namespace Tests\Feature\Documents;

use App\Http\Controllers\Customer\Business\DocumentsController;
use App\Library\Documents\DocumentManager;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\BusinessDocument;
use App\Models\Customer;
use App\Enums\Business\BusinessStatus;
use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Documents\Concerns\CreatesDocumentsTestData;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

class DocumentsControllerTest extends TestCase
{
    use RefreshDatabase, CreatesDocumentsTestData, CreatesCustomerContextFixtures;

    private function activeBundle(): array
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();
        $bundle = $this->documentsBundle();
        $bundle['business']->status = BusinessStatus::Active;
        $bundle['business']->save();
        $owner = Customer::where('user_id', $bundle['business']->workspace->owner_user_id)->firstOrFail();
        $permissions = $owner->permissions;
        if (is_string($permissions)) {
            $permissions = json_decode($permissions, true);
        }
        $owner->permissions = json_encode(array_values(array_unique([...($permissions ?: []), 'payments_contracts'])));
        $owner->save();
        return $bundle;
    }

    private function url(array $bundle, string $tail = ''): string
    {
        return route('customer.workspaces.businesses.documents.index', [$bundle['business']->workspace->uid, $bundle['business']->uid]).$tail;
    }

    private function allowEntitlement(): void
    {
        $this->app->bind(DocumentsController::class, fn ($app) => new class($app->make(DocumentManager::class), $app->make(EntitlementManager::class), $app->make(LocationAccessGuard::class), $app->make(\App\Library\Payments\PaymentManager::class)) extends DocumentsController {
            protected function entitlementAllows(\App\Models\Workspace $workspace, \App\Models\Business $business): bool { return true; }
        });
    }

    public function test_planned_feature_refuses_authoring_route(): void
    {
        $bundle = $this->activeBundle();
        $this->authenticateAs(Customer::where('user_id', $bundle['business']->workspace->owner_user_id)->firstOrFail());
        $this->get($this->url($bundle))->assertNotFound();
    }

    public function test_list_and_create_draft_for_accessible_location(): void
    {
        $bundle = $this->activeBundle();
        $this->allowEntitlement();
        $this->authenticateAs(Customer::where('user_id', $bundle['business']->workspace->owner_user_id)->firstOrFail());
        $this->get($this->url($bundle))->assertOk();
        $this->post($this->url($bundle), [
            'kind' => 'proposal', 'title' => 'New proposal',
            'location_uid' => $bundle['location']->uid,
            'contact_uid' => DB::table('contacts')->where('id', $bundle['contactId'])->value('uid'),
        ])->assertRedirect();
        $this->assertDatabaseHas('business_documents', ['business_id' => $bundle['business']->id, 'title' => 'New proposal', 'status' => 'draft']);
        $document = BusinessDocument::where('business_id', $bundle['business']->id)->firstOrFail();
        $this->get($this->url($bundle, '/'.$document->uid))
            ->assertOk()->assertSee('Draft details')->assertSee('Add catalog package')->assertSee('Payment terms');
        $this->post($this->url($bundle, '/'.$document->uid.'/custom-lines'), [
            'name' => 'Setup', 'quantity' => 2, 'unit_price_minor' => 500,
        ])->assertRedirect();
        $this->assertSame(1000, (int) $document->versions()->firstOrFail()->fresh()->total_minor);
    }

    public function test_foreign_document_and_foreign_location_are_refused(): void
    {
        $own = $this->activeBundle();
        $foreign = $this->activeBundle();
        $this->allowEntitlement();
        $documentId = $this->insertDocument($foreign);
        $uid = BusinessDocument::findOrFail($documentId)->uid;
        $this->authenticateAs(Customer::where('user_id', $own['business']->workspace->owner_user_id)->firstOrFail());
        $this->get($this->url($own, '/'.$uid))->assertNotFound();
        $this->post($this->url($own), [
            'kind' => 'proposal', 'title' => 'Bad', 'location_uid' => $foreign['location']->uid,
            'contact_uid' => DB::table('contacts')->where('id', $own['contactId'])->value('uid'),
        ])->assertNotFound();
    }

    public function test_capability_without_tenancy_is_refused(): void
    {
        $own = $this->activeBundle();
        $foreign = $this->activeBundle();
        $this->allowEntitlement();
        $this->authenticateAs(Customer::where('user_id', $own['business']->workspace->owner_user_id)->firstOrFail());
        $this->get($this->url($foreign))->assertNotFound();
    }

    public function test_tenancy_without_capability_is_refused(): void
    {
        $bundle = $this->activeBundle();
        $this->allowEntitlement();
        Customer::where('user_id', $bundle['business']->workspace->owner_user_id)->update(['permissions' => json_encode([])]);
        $this->authenticateAs(Customer::where('user_id', $bundle['business']->workspace->owner_user_id)->firstOrFail(), []);
        $this->get($this->url($bundle))->assertStatus(401);
    }

    public function test_planned_entitlement_refuses_every_authoring_route_without_writing(): void
    {
        $bundle = $this->activeBundle();
        $documentId = $this->insertDocument($bundle);
        $document = BusinessDocument::findOrFail($documentId);
        $versionId = $this->insertVersion($documentId);
        $lineId = $this->insertLineItem($versionId);
        $lineUid = DB::table('business_document_line_items')->where('id', $lineId)->value('uid');
        $this->authenticateAs(Customer::where('user_id', $bundle['business']->workspace->owner_user_id)->firstOrFail());
        $base = $this->url($bundle);
        $routes = [
            ['GET', $base, []],
            ['POST', $base, []],
            ['GET', $base.'/'.$document->uid, []],
            ['PATCH', $base.'/'.$document->uid, []],
            ['POST', $base.'/'.$document->uid.'/catalog-lines', []],
            ['POST', $base.'/'.$document->uid.'/custom-lines', []],
            ['DELETE', $base.'/'.$document->uid.'/lines/'.$lineUid, []],
            ['PUT', $base.'/'.$document->uid.'/lines/order', []],
            ['PUT', $base.'/'.$document->uid.'/schedule', []],
            ['POST', $base.'/'.$document->uid.'/void', []],
            // Sub-slice F — the refund action is behind the SAME chain, so it
            // fails closed while PaymentsContracts is Planned (§6.1).
            ['POST', $base.'/'.$document->uid.'/payments/00000000-0000-0000-0000-000000000000/refund',
                ['confirm' => '1', 'amount_minor' => 100]],
        ];
        foreach ($routes as [$method, $url, $payload]) {
            $this->call($method, $url, $payload)->assertNotFound();
        }
        $this->assertSame(1, BusinessDocument::count());
        $this->assertSame('draft', $document->fresh()->status->value);
    }

    /**
     * Sub-slice F §6.1 — refunds take the SAME single `payments_contracts`
     * capability as the rest of this controller PLUS an explicit confirmation.
     * There is no new permission key and no owner-only rule.
     */
    public function test_a_refund_without_explicit_confirmation_is_refused(): void
    {
        $bundle = $this->activeBundle();
        $document = BusinessDocument::findOrFail($this->insertDocument($bundle));
        $this->allowEntitlement();
        $this->authenticateAs(Customer::where('user_id', $bundle['business']->workspace->owner_user_id)->firstOrFail());

        $this->post($this->url($bundle, '/'.$document->uid.'/payments/'.$document->uid.'/refund'), [
            'amount_minor' => 5000,
        ])->assertSessionHasErrors('confirm');

        $this->assertSame(0, DB::table('business_document_refunds')->count());
    }

    public function test_a_refund_naming_a_payment_outside_the_document_is_a_404(): void
    {
        $bundle = $this->activeBundle();
        $document = BusinessDocument::findOrFail($this->insertDocument($bundle));
        $this->allowEntitlement();
        $this->authenticateAs(Customer::where('user_id', $bundle['business']->workspace->owner_user_id)->firstOrFail());

        $this->post($this->url($bundle, '/'.$document->uid.'/payments/00000000-0000-0000-0000-000000000000/refund'), [
            'confirm' => '1', 'amount_minor' => 5000,
        ])->assertNotFound();

        $this->assertSame(0, DB::table('business_document_refunds')->count());
    }

    public function test_the_refund_route_needs_the_same_capability_as_every_other_action(): void
    {
        $bundle = $this->activeBundle();
        $document = BusinessDocument::findOrFail($this->insertDocument($bundle));
        $this->allowEntitlement();
        Customer::where('user_id', $bundle['business']->workspace->owner_user_id)->update(['permissions' => json_encode([])]);
        $this->authenticateAs(Customer::where('user_id', $bundle['business']->workspace->owner_user_id)->firstOrFail(), []);

        $this->post($this->url($bundle, '/'.$document->uid.'/payments/'.$document->uid.'/refund'), [
            'confirm' => '1', 'amount_minor' => 5000,
        ])->assertStatus(401);
    }

    public function test_staff_without_exact_location_grant_cannot_view_document(): void
    {
        $bundle = $this->activeBundle();
        $granted = $this->documentsLocation($bundle['business']);
        $membershipUser = $this->createCustomer();
        $membership = $this->createMembership($bundle['business']->workspace, $membershipUser->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'location_access_scope' => LocationAccessScope::Selected,
        ]);
        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $granted);
        $document = BusinessDocument::findOrFail($this->insertDocument($bundle));
        $this->allowEntitlement();
        $this->authenticateAs($membershipUser);
        $this->get($this->url($bundle, '/'.$document->uid))->assertNotFound();
    }
}

<?php

namespace Tests\Feature\Documents;

use App\Http\Controllers\Customer\Business\DocumentsController;
use App\Library\Documents\DocumentManager;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\BusinessDocument;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Documents\Concerns\CreatesDocumentsTestData;
use Tests\TestCase;

class DocumentsControllerTest extends TestCase
{
    use RefreshDatabase, CreatesDocumentsTestData;

    private function url(array $bundle, string $tail = ''): string
    {
        return route('customer.workspaces.businesses.documents.index', [$bundle['business']->workspace->uid, $bundle['business']->uid]).$tail;
    }

    private function allowEntitlement(): void
    {
        $this->app->bind(DocumentsController::class, fn ($app) => new class($app->make(DocumentManager::class), $app->make(EntitlementManager::class), $app->make(LocationAccessGuard::class)) extends DocumentsController {
            protected function entitlementAllows(\App\Models\Workspace $workspace, \App\Models\Business $business): bool { return true; }
        });
    }

    public function test_planned_feature_refuses_authoring_route(): void
    {
        $bundle = $this->documentsBundle();
        $this->withoutMiddleware();
        $this->actingAs($bundle['business']->workspace->owner)
            ->get($this->url($bundle))->assertNotFound();
    }

    public function test_list_and_create_draft_for_accessible_location(): void
    {
        $bundle = $this->documentsBundle();
        $this->withoutMiddleware();
        $this->allowEntitlement();
        $this->actingAs($bundle['business']->workspace->owner)
            ->get($this->url($bundle))->assertOk();
        $this->post($this->url($bundle), [
            'kind' => 'proposal', 'title' => 'New proposal',
            'location_uid' => $bundle['location']->uid,
            'contact_uid' => DB::table('contacts')->where('id', $bundle['contactId'])->value('uid'),
        ])->assertRedirect();
        $this->assertDatabaseHas('business_documents', ['business_id' => $bundle['business']->id, 'title' => 'New proposal', 'status' => 'draft']);
    }

    public function test_foreign_document_and_foreign_location_are_refused(): void
    {
        $own = $this->documentsBundle();
        $this->withoutMiddleware();
        $foreign = $this->documentsBundle();
        $this->allowEntitlement();
        $documentId = $this->insertDocument($foreign);
        $uid = BusinessDocument::findOrFail($documentId)->uid;
        $this->actingAs($own['business']->workspace->owner)
            ->get($this->url($own, '/'.$uid))->assertNotFound();
        $this->post($this->url($own), [
            'kind' => 'proposal', 'title' => 'Bad', 'location_uid' => $foreign['location']->uid,
            'contact_uid' => DB::table('contacts')->where('id', $own['contactId'])->value('uid'),
        ])->assertNotFound();
    }

    public function test_capability_without_tenancy_is_refused(): void
    {
        $own = $this->documentsBundle();
        $this->withoutMiddleware();
        $foreign = $this->documentsBundle();
        $this->allowEntitlement();
        $this->actingAs($own['business']->workspace->owner)
            ->get($this->url($foreign))->assertNotFound();
    }

    public function test_tenancy_without_capability_is_refused(): void
    {
        $bundle = $this->documentsBundle();
        $this->withoutMiddleware();
        $this->allowEntitlement();
        Customer::where('user_id', $bundle['business']->workspace->owner_user_id)->update(['permissions' => json_encode([])]);
        $this->actingAs($bundle['business']->workspace->owner)
            ->get($this->url($bundle))->assertForbidden();
    }
}

<?php

namespace Tests\Feature\Documents;

use App\Library\Catalog\CatalogItemLocationOverrideManager;
use App\Library\Catalog\CatalogItemManager;
use App\Library\Documents\DocumentManager;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentVersion;
use App\Models\Contacts;
use App\Models\CrmOpportunity;
use Illuminate\Support\Str;
use App\Models\PackageSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Documents\Concerns\CreatesDocumentsTestData;
use Tests\TestCase;

class DocumentManagerTest extends TestCase
{
    use RefreshDatabase, CreatesDocumentsTestData;

    private function manager(): DocumentManager { return app(DocumentManager::class); }

    private function draft(): array
    {
        $bundle = $this->documentsBundle();
        $actor = $bundle['business']->workspace->owner;
        $document = $this->manager()->create($bundle['business'], $bundle['location'], Contacts::findOrFail($bundle['contactId']), null, 'proposal', 'Proposal', $actor);
        return [$bundle, $document, $actor];
    }

    public function test_create_edit_and_one_draft(): void
    {
        [, $document] = $this->draft();
        $this->assertSame(1, $document->versions()->where('state', 'draft')->count());
        $this->manager()->edit($document, ['title' => 'Updated', 'content' => ['body' => 'Terms']]);
        $this->assertSame('Updated', $document->fresh()->title);
        $this->assertSame(['body' => 'Terms'], $document->versions()->first()->content);
        $this->assertSame(1, $document->versions()->where('state', 'draft')->count());
    }

    public function test_invoice_draft_has_no_signature_requirement_and_only_one_open_version(): void
    {
        $bundle = $this->documentsBundle();
        $document = $this->manager()->create($bundle['business'], $bundle['location'], Contacts::findOrFail($bundle['contactId']), null, 'invoice', 'Invoice', $bundle['business']->workspace->owner);
        $this->assertSame('invoice', $document->kind->value);
        $this->assertFalse($document->requires_signature);
        $this->assertSame(1, $document->versions()->where('state', 'draft')->count());
        $this->assertSame($bundle['business']->currency_code, $document->currency_code);
    }

    public function test_catalog_snapshot_and_later_price_edit(): void
    {
        [$bundle, $document, $actor] = $this->draft();
        $item = app(CatalogItemManager::class)->create($bundle['business'], ['type' => 'package', 'name' => 'Package', 'price_minor' => 50000, 'currency_code' => 'USD']);
        $line = $this->manager()->addCatalogLine($document, $item, 2, $actor);
        $snapshot = PackageSnapshot::where('uid', $line->package_snapshot_uid)->firstOrFail();
        $this->assertSame(1, PackageSnapshot::count());
        $this->assertSame((int) $item->id, (int) $snapshot->catalog_item_id);
        $this->assertSame((int) $document->business_id, (int) $snapshot->business_id);
        $this->assertSame(50000, (int) $snapshot->price_minor_at_snapshot);
        $this->assertSame((int) $bundle['location']->id, (int) $snapshot->business_location_id);
        $this->assertSame((int) $actor->id, (int) $snapshot->created_by_user_id);
        $this->assertSame(100000, (int) $document->versions()->first()->total_minor);
        app(CatalogItemManager::class)->update($bundle['business'], $item, ['price_minor' => 65000, 'currency_code' => 'USD']);
        $this->assertSame(50000, (int) $snapshot->fresh()->price_minor_at_snapshot);
        $this->assertSame(50000, (int) $line->fresh()->unit_price_minor);
    }

    public function test_location_override_and_quote_only_explicit_price(): void
    {
        [$bundle, $document, $actor] = $this->draft();
        $item = app(CatalogItemManager::class)->create($bundle['business'], ['type' => 'package', 'name' => 'Fixed', 'price_minor' => 50000, 'currency_code' => 'USD']);
        app(CatalogItemLocationOverrideManager::class)->setPriceOverride($bundle['business'], $item, $bundle['location'], 65000);
        $line = $this->manager()->addCatalogLine($document, $item, 1, $actor);
        $this->assertSame(65000, (int) $line->unit_price_minor);
        app(CatalogItemLocationOverrideManager::class)->setPriceOverride($bundle['business'], $item, $bundle['location'], 70000);
        $this->assertSame(65000, (int) $line->fresh()->unit_price_minor);
        $this->assertSame(65000, (int) PackageSnapshot::where('uid', $line->package_snapshot_uid)->firstOrFail()->price_minor_at_snapshot);
        app(CatalogItemManager::class)->archive($bundle['business'], $item);
        $this->assertSame(65000, (int) $line->fresh()->unit_price_minor);
        $quote = app(CatalogItemManager::class)->create($bundle['business'], ['type' => 'package', 'name' => 'Quote']);
        $quoted = $this->manager()->addCatalogLine($document, $quote, 1, $actor, 23000);
        $this->assertSame(23000, (int) $quoted->unit_price_minor);
        $this->expectException(\App\Library\Catalog\Exceptions\CatalogRuleException::class);
        $this->manager()->addCatalogLine($document, $quote, 1, $actor);
    }

    public function test_explicit_price_on_fixed_item_is_refused(): void
    {
        [$bundle, $document, $actor] = $this->draft();
        $item = app(CatalogItemManager::class)->create($bundle['business'], ['type' => 'package', 'name' => 'Fixed', 'price_minor' => 50000, 'currency_code' => 'USD']);
        $this->expectException(\App\Library\Catalog\Exceptions\CatalogRuleException::class);
        $this->manager()->addCatalogLine($document, $item, 1, $actor, 1);
    }

    public function test_custom_line_totals_schedule_and_remove(): void
    {
        [, $document] = $this->draft();
        $first = $this->manager()->addCustomLine($document, 'Service', null, 2, 7500);
        $second = $this->manager()->addCustomLine($document, 'Setup', null, 1, 5000);
        $this->assertNull($first->package_snapshot_uid);
        $this->assertSame(0, PackageSnapshot::count());
        $this->assertSame(20000, (int) $document->versions()->first()->total_minor);
        $this->manager()->reorderLines($document, [$second->id, $first->id]);
        $this->assertSame(0, $second->fresh()->position);
        $this->manager()->setSchedule($document, [
            ['kind' => 'deposit', 'amount_minor' => 5000, 'currency_code' => 'USD'],
            ['kind' => 'balance', 'amount_minor' => 15000, 'currency_code' => 'USD'],
        ]);
        $this->assertSame(2, $document->versions()->first()->paymentScheduleItems()->count());
        $this->manager()->removeLine($document, $first);
        $this->assertSame(5000, (int) $document->versions()->first()->total_minor);
        $this->assertSame(0, $document->versions()->first()->paymentScheduleItems()->count());
    }

    public function test_invalid_schedule_currency_and_amount_refused(): void
    {
        [, $document] = $this->draft();
        $this->manager()->addCustomLine($document, 'Line', null, 1, 10000);
        foreach ([
            [['kind' => 'full', 'amount_minor' => 9999, 'currency_code' => 'USD']],
            [['kind' => 'full', 'amount_minor' => 10000, 'currency_code' => 'EUR']],
            [['kind' => 'deposit', 'amount_minor' => 10000, 'currency_code' => 'USD']],
            [['kind' => 'balance', 'amount_minor' => 10000, 'currency_code' => 'USD']],
            [['kind' => 'deposit', 'amount_minor' => 3000, 'currency_code' => 'USD'], ['kind' => 'balance', 'amount_minor' => 6000, 'currency_code' => 'USD']],
        ] as $terms) {
            try { $this->manager()->setSchedule($document, $terms); $this->fail('Invalid schedule accepted.'); }
            catch (ValidationException) { $this->assertSame(0, $document->versions()->first()->paymentScheduleItems()->count()); }
        }
    }

    public function test_foreign_contact_and_location_refused(): void
    {
        [$bundle] = $this->draft();
        $foreign = $this->documentsBundle();
        foreach ([[$bundle['business'], $bundle['location'], Contacts::findOrFail($foreign['contactId'])],
                  [$bundle['business'], $foreign['location'], Contacts::findOrFail($bundle['contactId'])]] as [$business, $location, $contact]) {
            try { $this->manager()->create($business, $location, $contact, null, 'invoice', 'Bad', $business->workspace->owner); $this->fail('Cross-tenant identity accepted.'); }
            catch (ValidationException) { $this->assertTrue(true); }
        }
    }

    public function test_sibling_and_null_location_contacts_are_refused(): void
    {
        $bundle = $this->documentsBundle();
        $contact = Contacts::findOrFail($bundle['contactId']);
        $sibling = $this->documentsLocation($bundle['business']);
        foreach ([$sibling->id, null] as $locationId) {
            $contact->location_id = $locationId;
            $contact->save();
            try {
                $this->manager()->create($bundle['business'], $bundle['location'], $contact, null, 'proposal', 'Invalid', $bundle['business']->workspace->owner);
                $this->fail('Mismatched Contact Location accepted.');
            } catch (ValidationException) {
                $this->assertSame(0, BusinessDocument::count());
            }
        }
    }

    public function test_foreign_catalog_item_cannot_be_attached(): void
    {
        [, $document, $actor] = $this->draft();
        $foreign = $this->documentsBundle();
        $item = app(CatalogItemManager::class)->create($foreign['business'], ['type' => 'package', 'name' => 'Foreign', 'price_minor' => 1000, 'currency_code' => 'USD']);
        $this->expectException(ValidationException::class);
        $this->manager()->addCatalogLine($document, $item, 1, $actor);
    }

    public function test_every_opportunity_identity_mismatch_is_refused(): void
    {
        $bundle = $this->documentsBundle();
        $foreign = $this->documentsBundle();
        $sibling = $this->documentsLocation($bundle['business']);
        $siblingContact = $this->documentsContact($bundle['business'], $sibling);
        $pipelineId = DB::table('crm_pipelines')->insertGetId([
            'uid' => (string) Str::uuid(), 'business_id' => $bundle['business']->id,
            'name' => 'Sales', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $stageId = DB::table('crm_pipeline_stages')->insertGetId([
            'uid' => (string) Str::uuid(), 'business_id' => $bundle['business']->id,
            'pipeline_id' => $pipelineId, 'name' => 'New', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $contact = Contacts::findOrFail($bundle['contactId']);
        foreach ([
            ['business_id' => $foreign['business']->id, 'location_id' => $bundle['location']->id, 'contact_id' => $contact->id],
            ['business_id' => $bundle['business']->id, 'location_id' => $sibling->id, 'contact_id' => $contact->id],
            ['business_id' => $bundle['business']->id, 'location_id' => null, 'contact_id' => $contact->id],
            ['business_id' => $bundle['business']->id, 'location_id' => $bundle['location']->id, 'contact_id' => $siblingContact],
        ] as $identity) {
            $id = DB::table('crm_opportunities')->insertGetId([
                ...$identity, 'uid' => (string) Str::uuid(), 'pipeline_id' => $pipelineId,
                'stage_id' => $stageId, 'title' => 'Candidate', 'created_at' => now(), 'updated_at' => now(),
            ]);
            try {
                $this->manager()->create($bundle['business'], $bundle['location'], $contact, CrmOpportunity::findOrFail($id), 'proposal', 'Bad', $bundle['business']->workspace->owner);
                $this->fail('Incompatible Opportunity accepted.');
            } catch (ValidationException) {
                $this->assertSame(0, BusinessDocument::count());
            }
        }
    }

    public function test_sent_document_can_edit_only_its_open_draft_version(): void
    {
        [, $document] = $this->draft();
        $document->status = \App\Enums\Documents\DocumentStatus::Sent;
        $document->save();
        $this->manager()->edit($document, ['content' => ['body' => 'New draft terms']]);
        $this->assertSame('New draft terms', $document->versions()->first()->content['body']);
        try {
            $this->manager()->edit($document, ['title' => 'Frozen']);
            $this->fail('Sent identity changed.');
        } catch (ValidationException) {
            $this->assertSame('Proposal', $document->fresh()->title);
        }
        $version = $document->versions()->first();
        $version->state = \App\Enums\Documents\DocumentVersionState::Issued;
        $version->save();
        $this->expectException(ValidationException::class);
        $this->manager()->addCustomLine($document, 'Forbidden', null, 1, 100);
    }

    public function test_void_transition_and_issued_content_cannot_be_edited(): void
    {
        [, $document] = $this->draft();
        $this->manager()->addCustomLine($document, 'Line', null, 1, 10000);
        $this->manager()->setSchedule($document, [['kind' => 'full', 'amount_minor' => 10000, 'currency_code' => 'USD']]);
        $this->manager()->void($document, 'Canceled');
        $this->assertSame('void', $document->fresh()->status->value);
        $this->assertSame('void', $document->versions()->first()->paymentScheduleItems()->first()->status->value);
        $this->expectException(ValidationException::class);
        $this->manager()->edit($document, ['title' => 'Forbidden']);
    }

    public function test_sent_and_signed_void_clear_link_and_current_schedule(): void
    {
        foreach ([\App\Enums\Documents\DocumentStatus::Sent, \App\Enums\Documents\DocumentStatus::Signed] as $status) {
            [, $document] = $this->draft();
            $this->manager()->addCustomLine($document, 'Line', null, 1, 1000);
            $this->manager()->setSchedule($document, [['kind' => 'full', 'amount_minor' => 1000, 'currency_code' => 'USD']]);
            $version = $document->versions()->firstOrFail();
            $version->state = \App\Enums\Documents\DocumentVersionState::Issued;
            $version->save();
            $document->status = $status;
            $document->current_version_id = $version->id;
            $document->access_token_hash = 'test-token-hash';
            $document->save();
            $this->manager()->void($document, 'Customer canceled');
            $this->assertSame('void', $document->fresh()->status->value);
            $this->assertNull($document->fresh()->access_token_hash);
            $this->assertSame('void', $version->paymentScheduleItems()->firstOrFail()->status->value);
            $this->assertSame(1, $document->versions()->count());
        }
    }

    public function test_terminal_document_cannot_be_voided(): void
    {
        foreach ([\App\Enums\Documents\DocumentStatus::Paid, \App\Enums\Documents\DocumentStatus::Expired, \App\Enums\Documents\DocumentStatus::Void] as $status) {
            [, $document] = $this->draft();
            $document->status = $status;
            $document->save();
            try {
                $this->manager()->void($document, 'Invalid');
                $this->fail('Terminal document was voided.');
            } catch (ValidationException) {
                $this->assertSame($status, $document->fresh()->status);
            }
        }
    }

    public function test_void_refuses_unreimbursed_succeeded_payment_until_fully_refunded(): void
    {
        [$bundle, $document] = $this->draft();
        $this->manager()->addCustomLine($document, 'Service', null, 1, 10000);
        $this->manager()->setSchedule($document, [['kind' => 'full', 'amount_minor' => 10000, 'currency_code' => 'USD']]);
        $version = $document->versions()->firstOrFail();
        $version->state = \App\Enums\Documents\DocumentVersionState::Issued;
        $version->save();
        $document->status = \App\Enums\Documents\DocumentStatus::Signed;
        $document->current_version_id = $version->id;
        $document->save();
        $connectionId = $this->insertStripeConnection($bundle['business']->id);
        $paymentId = $this->insertPayment($bundle['business']->id, $document->id, $version->paymentScheduleItems()->firstOrFail()->id, $connectionId, [
            'status' => 'succeeded', 'amount_minor' => 10000,
        ]);
        foreach ([0, 4000] as $refunded) {
            if ($refunded > 0) {
                $this->insertRefund($bundle['business']->id, $paymentId, ['status' => 'succeeded', 'amount_minor' => $refunded]);
            }
            try {
                $this->manager()->void($document, 'Cancel');
                $this->fail('Captured balance was abandoned.');
            } catch (ValidationException) {
                $this->assertSame('signed', $document->fresh()->status->value);
            }
        }
        $this->insertRefund($bundle['business']->id, $paymentId, ['status' => 'succeeded', 'amount_minor' => 6000]);
        $this->manager()->void($document, 'Fully refunded');
        $this->assertSame('void', $document->fresh()->status->value);
    }
}

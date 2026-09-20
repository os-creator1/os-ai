<?php

namespace Tests\Feature\Documents;

use App\Library\Catalog\CatalogItemLocationOverrideManager;
use App\Library\Catalog\CatalogItemManager;
use App\Library\Documents\DocumentManager;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentVersion;
use App\Models\Contacts;
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

    public function test_catalog_snapshot_and_later_price_edit(): void
    {
        [$bundle, $document, $actor] = $this->draft();
        $item = app(CatalogItemManager::class)->create($bundle['business'], ['type' => 'package', 'name' => 'Package', 'price_minor' => 50000, 'currency_code' => 'USD']);
        $line = $this->manager()->addCatalogLine($document, $item, 2, $actor);
        $snapshot = PackageSnapshot::where('uid', $line->package_snapshot_uid)->firstOrFail();
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
}

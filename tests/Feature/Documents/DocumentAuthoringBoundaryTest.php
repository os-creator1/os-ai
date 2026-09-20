<?php

namespace Tests\Feature\Documents;

use App\Http\Controllers\Customer\Business\DocumentsController;
use App\Library\Documents\DocumentManager;
use Tests\TestCase;

final class DocumentAuthoringBoundaryTest extends TestCase
{
    public function test_customer_controller_delegates_writes_to_manager(): void
    {
        $source = file_get_contents((new \ReflectionClass(DocumentsController::class))->getFileName());
        $this->assertIsString($source);
        $this->assertStringContainsString('DocumentManager $manager', $source);
        foreach (['BusinessDocument::create(', 'BusinessDocumentVersion::create(', 'BusinessDocumentLineItem::create(', 'BusinessDocumentPaymentScheduleItem::create(', 'DB::table(', '->save(', '->delete(', '->update('] as $write) {
            $this->assertStringNotContainsString($write, $source, $write);
        }
    }

    public function test_catalog_line_uses_canonical_snapshot_service(): void
    {
        $source = file_get_contents((new \ReflectionClass(DocumentManager::class))->getFileName());
        $this->assertIsString($source);
        $this->assertStringContainsString('$this->snapshots->snapshot($item, $location, $actor, $explicitPriceMinor)', $source);
        $this->assertStringNotContainsString("DB::table('package_snapshots')", $source);
        $this->assertStringNotContainsString('CatalogItemPricingResolver', $source);
        $this->assertStringContainsString('DB::afterCommit(fn () => DocumentVoided::dispatch($result->id))', $source);
    }
}

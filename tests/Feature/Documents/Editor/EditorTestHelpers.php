<?php

namespace Tests\Feature\Documents\Editor;

use App\Library\Documents\DocumentManager;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentVersion;

/**
 * Fixtures for the Contract 17B editor API tests. The using test class also
 * uses SendsDocuments and CreatesCatalogHttpFixtures.
 */
trait EditorTestHelpers
{
    /** @return array<string, mixed> a tenant, signed in as its owner */
    protected function editorTenant(string $name = 'Harbor Lane Studios'): array
    {
        $tenant = $this->sendableTenant($name);
        $this->authenticateAs($tenant['customer']);

        return $tenant;
    }

    protected function blankDocument(array $tenant, string $title = 'Editor proposal'): BusinessDocument
    {
        $manager = app(DocumentManager::class);
        $document = $manager->create($tenant['business'], $tenant['location'], $tenant['contact'], null, 'proposal', $title, $tenant['customer']->user);
        $manager->edit($document, ['recipient_email_snapshot' => 'client@example.test', 'recipient_name_snapshot' => 'Pat Rivera']);

        return $document->refresh();
    }

    protected function ed(string $name, array $tenant, ?BusinessDocument $document = null, array $extra = []): string
    {
        $parameters = [$tenant['workspace']->uid, $tenant['business']->uid];
        if ($document !== null) {
            $parameters[] = $document->uid;
        }

        return route('customer.workspaces.businesses.documents.editor.' . $name, [...$parameters, ...$extra]);
    }

    protected function lock(BusinessDocument $document): int
    {
        return (int) BusinessDocumentVersion::where('business_document_id', $document->id)->where('state', 'draft')->value('lock_version');
    }

    protected function draftVersion(BusinessDocument $document): BusinessDocumentVersion
    {
        return BusinessDocumentVersion::where('business_document_id', $document->id)->where('state', 'draft')->firstOrFail();
    }

    /** @return array<int, array<string, mixed>> */
    protected function standardBlocks(bool $signature = true): array
    {
        return array_values(array_filter([
            ['id' => 'b-title', 'type' => 'heading', 'data' => ['level' => 1, 'runs' => [['t' => 'Proposal for '], ['merge' => 'contact.first_name']]]],
            ['id' => 'b-intro', 'type' => 'text', 'data' => ['runs' => [['t' => 'Thank you.']]]],
            ['id' => 'b-products', 'type' => 'product_list', 'data' => []],
            ['id' => 'b-payment', 'type' => 'payment_terms', 'data' => []],
            $signature ? ['id' => 'b-sign', 'type' => 'signature', 'data' => ['label' => 'Client signature']] : null,
        ]));
    }

    /** A draft with blocks, one custom line (price in minor units) and a recipient. */
    protected function documentWithLine(array $tenant, int $unitMinor = 100000, bool $signature = true): BusinessDocument
    {
        $manager = app(DocumentManager::class);
        $document = $this->blankDocument($tenant);
        $manager->saveBlocks($document, $this->standardBlocks($signature));
        $manager->addCustomLine($document, 'Design work', null, 1, $unitMinor);

        return $document->refresh();
    }
}

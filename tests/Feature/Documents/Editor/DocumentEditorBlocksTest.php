<?php

namespace Tests\Feature\Documents\Editor;

use App\Exceptions\Documents\DocumentDraftConflictException;
use App\Library\Documents\DocumentManager;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentVersion;
use App\Models\CatalogItemImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Catalog\Concerns\CreatesCatalogHttpFixtures;
use Tests\Feature\Documents\Concerns\CreatesDocumentsTestData;
use Tests\Feature\Documents\Concerns\SendsDocuments;
use Tests\TestCase;

/**
 * Contract 17B §2/§5/§8 — saving blocks, optimistic concurrency, immutability
 * and tenancy of the editor's JSON API.
 */
class DocumentEditorBlocksTest extends TestCase
{
    use RefreshDatabase;
    use SendsDocuments;
    use CreatesDocumentsTestData;
    use CreatesCatalogHttpFixtures;
    use EditorTestHelpers;

    public function test_a_blank_block_draft_saves_and_reloads_with_its_blocks(): void
    {
        $tenant = $this->editorTenant();
        $document = $this->blankDocument($tenant);
        $this->assertSame(2, $this->lock($document), 'the recipient edit already moved the version on');

        $this->get($this->ed('edit', $tenant, $document))->assertOk()->assertSee('document-editor-bootstrap', false);

        $response = $this->putJson($this->ed('blocks', $tenant, $document), [
            'blocks' => $this->standardBlocks(), 'title' => 'Renamed in the editor', 'expected_lock_version' => 2,
        ])->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('lock_version', 3)->assertJsonPath('title', 'Renamed in the editor');
        $this->assertCount(5, $response->json('blocks'));

        $version = $this->draftVersion($document);
        $this->assertSame(2, $version->content['schema_version']);
        $this->assertSame(['heading', 'text', 'product_list', 'payment_terms', 'signature'], array_column($version->content['blocks'], 'type'));
        $this->assertSame('Renamed in the editor', $document->fresh()->title);
        $this->assertSame(3, (int) $version->lock_version);

        // Reload: the page bootstrap carries the persisted blocks and the version.
        $page = $this->get($this->ed('edit', $tenant, $document))->assertOk()->getContent();
        $this->assertStringContainsString('Client signature', $page);
        $this->assertStringContainsString('"lock_version":3', $page);
    }

    public function test_block_order_can_be_changed_and_persists(): void
    {
        $tenant = $this->editorTenant();
        $document = $this->blankDocument($tenant);
        $blocks = $this->standardBlocks();
        $this->putJson($this->ed('blocks', $tenant, $document), ['blocks' => $blocks, 'expected_lock_version' => $this->lock($document)])->assertOk();

        $this->putJson($this->ed('blocks', $tenant, $document), ['blocks' => array_reverse($blocks), 'expected_lock_version' => $this->lock($document)])->assertOk();

        $this->assertSame(['b-sign', 'b-payment', 'b-products', 'b-intro', 'b-title'], array_column($this->draftVersion($document)->content['blocks'], 'id'));
    }

    public function test_invalid_blocks_are_a_422_with_field_errors_and_nothing_is_saved(): void
    {
        $tenant = $this->editorTenant();
        $document = $this->blankDocument($tenant);
        $before = $this->lock($document);

        $this->putJson($this->ed('blocks', $tenant, $document), [
            'blocks' => [['type' => 'script', 'data' => []], ['type' => 'text', 'data' => ['runs' => [['t' => 'x', 'href' => 'javascript:alert(1)']]]]],
            'expected_lock_version' => $before,
        ])->assertStatus(422)->assertJsonPath('status', 'invalid')->assertJsonStructure(['errors' => ['blocks.0.type', 'blocks.1.data.runs.0.href']]);

        $this->putJson($this->ed('blocks', $tenant, $document), ['blocks' => []])->assertStatus(422)->assertJsonStructure(['errors' => ['expected_lock_version']]);
        $this->assertSame($before, $this->lock($document));
        $this->assertArrayNotHasKey('blocks', $this->draftVersion($document)->content ?? []);
    }

    public function test_only_one_product_block_is_accepted(): void
    {
        $tenant = $this->editorTenant();
        $document = $this->blankDocument($tenant);

        $this->putJson($this->ed('blocks', $tenant, $document), [
            'blocks' => [['type' => 'product_list', 'data' => []], ['type' => 'product_list', 'data' => []]],
            'expected_lock_version' => $this->lock($document),
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['blocks.1.type']]);
    }

    public function test_a_stale_second_tab_gets_a_409_on_every_draft_endpoint_and_changes_nothing(): void
    {
        $tenant = $this->editorTenant();
        $document = $this->documentWithLine($tenant);
        $line = $this->draftVersion($document)->lineItems()->firstOrFail();
        $tabB = $this->lock($document);

        // Tab A saves first.
        $this->putJson($this->ed('blocks', $tenant, $document), ['blocks' => $this->standardBlocks(), 'expected_lock_version' => $tabB])->assertOk();
        $current = $this->lock($document);
        $this->assertGreaterThan($tabB, $current);
        $contentBefore = $this->draftVersion($document)->content;
        $item = $this->catalogItem($tenant['business'], 'Garden Package');

        $stale = ['expected_lock_version' => $tabB];
        $attempts = [
            ['putJson', $this->ed('blocks', $tenant, $document), ['blocks' => []] + $stale],
            ['putJson', $this->ed('plan', $tenant, $document), ['structure' => 'full'] + $stale],
            ['postJson', $this->ed('lines.catalog', $tenant, $document), ['catalog_item_uid' => $item->uid] + $stale],
            ['postJson', $this->ed('lines.custom', $tenant, $document), ['name' => 'Extra', 'price' => '5.00'] + $stale],
            ['patchJson', $this->ed('lines.quantity', $tenant, $document, [$line->uid]), ['quantity' => 3] + $stale],
            ['deleteJson', $this->ed('lines.destroy', $tenant, $document, [$line->uid]), $stale],
            ['putJson', $this->ed('lines.order', $tenant, $document), ['line_uids' => [$line->uid]] + $stale],
            ['postJson', $this->ed('upgrade', $tenant, $document), $stale],
        ];

        foreach ($attempts as [$verb, $url, $payload]) {
            $this->{$verb}($url, $payload)
                ->assertStatus(409)->assertJsonPath('status', 'conflict')->assertJsonPath('lock_version', $current);
        }

        $this->assertSame($current, $this->lock($document));
        $this->assertSame($contentBefore, $this->draftVersion($document)->content);
        $this->assertSame(1, $this->draftVersion($document)->lineItems()->count());
        $this->assertSame(1, (int) $this->draftVersion($document)->lineItems()->value('quantity'));
    }

    public function test_the_manager_bumps_the_version_on_every_mutation_and_null_means_no_precondition(): void
    {
        $tenant = $this->editorTenant();
        $manager = app(DocumentManager::class);
        $document = $manager->create($tenant['business'], $tenant['location'], $tenant['contact'], null, 'proposal', 'Versioned', $tenant['customer']->user);
        $this->assertSame(1, $this->lock($document));

        $manager->edit($document, ['title' => 'One']);
        $this->assertSame(2, $this->lock($document));
        $line = $manager->addCustomLine($document, 'Work', null, 1, 10000);
        $this->assertSame(3, $this->lock($document));
        $manager->updateLineQuantity($document, $line, 2);
        $this->assertSame(4, $this->lock($document));
        $manager->setSchedule($document, [['kind' => 'full', 'amount_minor' => 20000, 'currency_code' => 'USD']]);
        $this->assertSame(5, $this->lock($document));
        $manager->reorderLines($document, [(int) $line->id]);
        $this->assertSame(6, $this->lock($document));
        $this->assertSame(6, $manager->lastLockVersion());
        $manager->removeLine($document, $line);
        $this->assertSame(7, $this->lock($document));

        try {
            $manager->edit($document, ['title' => 'Stale'], 3);
            $this->fail('A stale expected version must be refused.');
        } catch (DocumentDraftConflictException $e) {
            $this->assertSame(7, $e->currentLockVersion);
        }
        $this->assertSame('One', $document->fresh()->title);

        $manager->edit($document, ['title' => 'Right'], 7);
        $this->assertSame(8, $this->lock($document));
    }

    public function test_autosave_is_refused_for_sent_signed_and_void_documents_but_allowed_on_a_revised_draft(): void
    {
        $tenant = $this->editorTenant();
        $manager = app(DocumentManager::class);

        // Sent.
        $sent = $this->documentWithLine($tenant);
        $manager->setPaymentPlan($sent, ['structure' => 'full'], null);
        [$sent] = $this->sendAndCaptureToken($sent);
        $this->putJson($this->ed('blocks', $tenant, $sent), ['blocks' => $this->standardBlocks(), 'expected_lock_version' => 1])
            ->assertStatus(422)->assertJsonPath('status', 'invalid');

        // Revised draft of that sent document: editable again.
        $manager->revise($sent, $tenant['customer']->user);
        $revised = $this->draftVersion($sent);
        $this->putJson($this->ed('blocks', $tenant, $sent), ['blocks' => $this->standardBlocks(), 'expected_lock_version' => (int) $revised->lock_version])
            ->assertOk()->assertJsonPath('lock_version', (int) $revised->lock_version + 1);

        // Signed.
        $signed = $this->documentWithLine($tenant);
        $manager->setPaymentPlan($signed, ['structure' => 'full'], null);
        [$signed] = $this->sendAndCaptureToken($signed);
        $manager->sign($signed, $this->evidence($signed));
        $this->putJson($this->ed('blocks', $tenant, $signed), ['blocks' => $this->standardBlocks(), 'expected_lock_version' => 1])->assertStatus(422);

        // Void.
        $void = $this->documentWithLine($tenant);
        $manager->setPaymentPlan($void, ['structure' => 'full'], null);
        [$void] = $this->sendAndCaptureToken($void);
        $manager->void($void, 'Withdrawn');
        $this->putJson($this->ed('blocks', $tenant, $void), ['blocks' => $this->standardBlocks(), 'expected_lock_version' => 1])->assertStatus(422);
        $this->patchJson($this->ed('lines.quantity', $tenant, $void, [BusinessDocument::find($void->id)->versions()->first()->lineItems()->first()->uid]), ['quantity' => 5, 'expected_lock_version' => 1])->assertStatus(422);
    }

    public function test_a_foreign_document_catalog_item_line_and_image_fail_closed(): void
    {
        $tenant = $this->editorTenant('Mine');
        $document = $this->documentWithLine($tenant);
        $other = $this->sendableTenant('Theirs');
        $this->authenticateAs($tenant['customer']);
        $foreignDocument = $this->documentWithLine($other);
        $foreignItem = $this->catalogItem($other['business'], 'Foreign Package');
        $foreignLine = $this->draftVersion($foreignDocument)->lineItems()->firstOrFail();
        $foreignImage = CatalogItemImage::create(['catalog_item_id' => $foreignItem->id, 'disk' => 'public', 'path' => 'x/y.jpg', 'mime_type' => 'image/jpeg', 'size' => 10, 'position' => 0]);
        $ownItem = $this->catalogItem($tenant['business'], 'Own Package');
        $ownImage = CatalogItemImage::create(['catalog_item_id' => $ownItem->id, 'disk' => 'public', 'path' => 'x/own.jpg', 'mime_type' => 'image/jpeg', 'size' => 10, 'position' => 0]);
        $lock = $this->lock($document);

        // A foreign document, addressed through MY Business route.
        $this->putJson($this->ed('blocks', $tenant, $foreignDocument), ['blocks' => [], 'expected_lock_version' => 1])->assertNotFound();
        $this->getJson($this->ed('edit', $tenant, $foreignDocument))->assertNotFound();
        $this->getJson($this->ed('contact.dates', $tenant, $foreignDocument))->assertNotFound();
        // A foreign catalog item and a foreign line uid.
        $this->postJson($this->ed('lines.catalog', $tenant, $document), ['catalog_item_uid' => $foreignItem->uid, 'expected_lock_version' => $lock])->assertNotFound();
        $this->patchJson($this->ed('lines.quantity', $tenant, $document, [$foreignLine->uid]), ['quantity' => 9, 'expected_lock_version' => $lock])->assertNotFound();
        $this->deleteJson($this->ed('lines.destroy', $tenant, $document, [$foreignLine->uid]), ['expected_lock_version' => $lock])->assertNotFound();
        // A foreign Business's catalog image cannot be placed in a block.
        $this->putJson($this->ed('blocks', $tenant, $document), [
            'blocks' => [['type' => 'image', 'data' => ['catalog_image_uid' => $foreignImage->uid, 'alt' => 'x']]], 'expected_lock_version' => $lock,
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['blocks.0.data.catalog_image_uid']]);
        // ...but its own image can.
        $this->putJson($this->ed('blocks', $tenant, $document), [
            'blocks' => [['type' => 'image', 'data' => ['catalog_image_uid' => $ownImage->uid, 'alt' => 'x']]], 'expected_lock_version' => $lock,
        ])->assertOk();

        // The foreign document was never touched.
        $this->assertSame(1, $this->draftVersion($foreignDocument)->lineItems()->count());
        $this->assertSame($lock + 1, $this->lock($document));
    }

    public function test_a_legacy_draft_converts_deterministically_once_and_only_while_a_draft(): void
    {
        $tenant = $this->editorTenant();
        $manager = app(DocumentManager::class);
        $body = "Scope of work.\n\nNet 30 days,\nno exceptions.\n\nThank you.";

        $a = $this->blankDocument($tenant, 'Legacy A');
        $b = $this->blankDocument($tenant, 'Legacy B');
        $manager->edit($a, ['content' => ['body' => $body]]);
        $manager->edit($b, ['content' => ['body' => $body]]);

        // The layout cannot be edited around unconverted prose.
        $this->putJson($this->ed('blocks', $tenant, $a), ['blocks' => [], 'expected_lock_version' => $this->lock($a)])->assertStatus(422);

        $this->postJson($this->ed('upgrade', $tenant, $a), ['expected_lock_version' => $this->lock($a)])
            ->assertOk()->assertJsonPath('is_block_document', true)->assertJsonPath('is_legacy', false);
        $this->postJson($this->ed('upgrade', $tenant, $b), ['expected_lock_version' => $this->lock($b)])->assertOk();

        $blocksA = $this->draftVersion($a)->content['blocks'];
        $this->assertSame(['text', 'text', 'text', 'product_list', 'payment_terms', 'signature'], array_column($blocksA, 'type'));
        $this->assertSame("Net 30 days,\nno exceptions.", $blocksA[1]['data']['runs'][0]['t']);
        $this->assertSame($blocksA, $this->draftVersion($b)->content['blocks'], 'the same body always converts to the same blocks');
        $this->assertSame($body, $this->draftVersion($a)->content['body'], 'the original text is kept');

        // Second call: refused, nothing changes.
        $lock = $this->lock($a);
        $this->postJson($this->ed('upgrade', $tenant, $a), ['expected_lock_version' => $lock])->assertStatus(422);
        $this->assertSame($lock, $this->lock($a));
        $this->assertSame($blocksA, $this->draftVersion($a)->content['blocks']);

        // Not a draft any more: refused and never rewritten.
        $c = $this->blankDocument($tenant, 'Legacy C');
        $manager->edit($c, ['content' => ['body' => 'Legacy body.']]);
        $manager->addCustomLine($c, 'Work', null, 1, 10000);
        $manager->setSchedule($c, [['kind' => 'full', 'amount_minor' => 10000, 'currency_code' => 'USD']]);
        [$c] = $this->sendAndCaptureToken($c);
        $this->postJson($this->ed('upgrade', $tenant, $c), ['expected_lock_version' => 1])->assertStatus(422);
        $this->assertSame(['body' => 'Legacy body.'], collect($c->versions()->first()->content)->only('body')->all());
        $this->assertArrayNotHasKey('blocks', $c->versions()->first()->content);
    }

    public function test_send_refuses_a_block_document_without_its_signature_block(): void
    {
        $tenant = $this->editorTenant();
        $manager = app(DocumentManager::class);
        $document = $this->documentWithLine($tenant, 100000, false);
        $manager->setPaymentPlan($document, ['structure' => 'full'], null);

        try {
            $manager->send($document);
            $this->fail('Sending without a signature block must be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('signature', strtolower($e->errors()['document'][0]));
        }
        $this->assertSame('draft', $document->fresh()->status->value);

        $manager->saveBlocks($document, $this->standardBlocks(true));
        $sent = $manager->send($document->refresh());
        $this->assertSame('sent', $sent->status->value);
    }

    private function evidence(BusinessDocument $document): array
    {
        return [
            'displayed_version_uid' => (string) BusinessDocumentVersion::findOrFail($document->fresh()->current_version_id)->uid,
            'signer_name' => 'Pat Rivera', 'signer_email' => 'client@example.test', 'typed_name' => 'Pat Rivera',
            'ip_address' => '203.0.113.9', 'user_agent' => 'phpunit',
        ];
    }
}

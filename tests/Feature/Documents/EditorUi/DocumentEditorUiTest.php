<?php

namespace Tests\Feature\Documents\EditorUi;

use App\Library\Documents\DocumentManager;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentVersion;
use App\Models\CatalogItemImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Catalog\Concerns\CreatesCatalogHttpFixtures;
use Tests\Feature\Documents\Concerns\CreatesDocumentsTestData;
use Tests\Feature\Documents\Concerns\SendsDocuments;
use Tests\Feature\Documents\Editor\EditorTestHelpers;
use Tests\TestCase;

/**
 * Contract 17B stage 4 — the visual editor's page, preview, New proposal flow
 * and the Documents list. (The editor's JSON API has its own tests in
 * tests/Feature/Documents/Editor.)
 */
class DocumentEditorUiTest extends TestCase
{
    use RefreshDatabase;
    use SendsDocuments;
    use CreatesDocumentsTestData;
    use CreatesCatalogHttpFixtures;
    use EditorTestHelpers;

    /** @return array<string, mixed> the bootstrap JSON embedded in the editor page */
    private function bootstrap(string $html): array
    {
        $this->assertSame(1, preg_match('#<script type="application/json" id="document-editor-bootstrap">(.*?)</script>#s', $html, $match), 'bootstrap script missing');

        return json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
    }

    private function docsUrl(array $tenant, string $tail = ''): string
    {
        return route('customer.workspaces.businesses.documents.index', [$tenant['workspace']->uid, $tenant['business']->uid]) . $tail;
    }

    private function legacyDraft(array $tenant, string $title = 'Legacy proposal'): BusinessDocument
    {
        $manager = app(DocumentManager::class);
        $document = $manager->create($tenant['business'], $tenant['location'], $tenant['contact'], null, 'proposal', $title, $tenant['customer']->user);
        $manager->edit($document, ['content' => ['body' => 'Old classic terms and conditions.'], 'recipient_email_snapshot' => 'client@example.test']);

        return $document->refresh();
    }

    // ---- the editor page --------------------------------------------------------

    public function test_the_editor_renders_for_a_draft_with_its_bootstrap_json(): void
    {
        $tenant = $this->editorTenant();
        $document = $this->documentWithLine($tenant);

        $response = $this->get($this->ed('edit', $tenant, $document))->assertOk();
        $html = $response->getContent();
        $boot = $this->bootstrap($html);

        $this->assertSame($document->uid, $boot['document']['uid']);
        $this->assertTrue($boot['editable']);
        $this->assertTrue($boot['is_block_document']);
        $this->assertFalse($boot['is_legacy']);
        $this->assertCount(5, $boot['blocks']);
        $this->assertSame($this->lock($document), $boot['lock_version']);
        $this->assertSame('Design work', $boot['lines'][0]['name']);
        $this->assertSame('USD', $boot['currency_code']);
        $this->assertArrayHasKey('delivery', $boot);
        $this->assertSame($this->ed('blocks', $tenant, $document), $boot['urls']['blocks']);
        $this->assertSame($this->ed('preview', $tenant, $document), $boot['urls']['preview']);

        // The shell: header, title, status chip, save indicator, the three columns' roots.
        foreach (['editor-header', 'editor-title', 'editor-status', 'save-indicator', 'action-preview', 'action-save', 'action-send', 'action-more', 'toolbox', 'editor-canvas', 'inspector'] as $role) {
            $this->assertStringContainsString('data-role="' . $role . '"', $html, $role);
        }
        $this->assertStringContainsString('>Draft<', $html);
        $this->assertStringContainsString('css/base/pages/documents-editor.css', $html);
        $this->assertStringContainsString('js/documents/editor.js', $html);
        $this->assertStringNotContainsString('data-role="legacy-upgrade"', $html);
    }

    public function test_save_as_template_is_rendered_but_disabled_until_templates_exist(): void
    {
        $tenant = $this->editorTenant();
        $html = $this->get($this->ed('edit', $tenant, $this->blankDocument($tenant)))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<button[^>]*data-role="action-save-template"[^>]*disabled[^>]*>#', $html);
        $this->assertStringContainsString('title="Coming with templates"', $html);
    }

    public function test_the_toolbox_offers_exactly_the_allowed_blocks_and_no_deferred_fields(): void
    {
        $tenant = $this->editorTenant();
        $html = $this->get($this->ed('edit', $tenant, $this->blankDocument($tenant)))->assertOk()->getContent();
        $boot = $this->bootstrap($html);

        $groups = [];
        $ids = [];
        foreach ($boot['toolbox']['categories'] as $category) {
            $groups[$category['label']] = array_column($category['items'], 'id');
            $ids = array_merge($ids, array_column($category['items'], 'id'));
        }

        $this->assertSame([
            'Content' => ['text', 'heading', 'image', 'divider', 'spacer'],
            'Commerce' => ['product', 'custom_line', 'payment_terms'],
            'Fields' => ['signature'],
            'Structure' => ['section', 'page_break'],
            'Business' => ['business_details', 'contact_name', 'contact_email'],
        ], $groups);

        foreach (['text_field', 'date_field', 'checkbox', 'columns', 'logo'] as $deferred) {
            $this->assertNotContains($deferred, $ids);
            $this->assertStringNotContainsString('data-tool="' . $deferred . '"', $html);
        }
        foreach ($ids as $id) {
            $this->assertStringContainsString('data-tool="' . $id . '"', $html, "toolbox button for {$id}");
        }

        // Only schema block types are ever inserted by the toolbox.
        foreach ($boot['toolbox']['categories'] as $category) {
            foreach ($category['items'] as $item) {
                $this->assertContains($item['type'], $boot['toolbox']['block_types']);
            }
        }
        $this->assertContains('contact.first_name', array_column($boot['toolbox']['merge_fields'], 'token'));
    }

    public function test_no_minor_units_wording_reaches_the_editor_page_or_its_scripts(): void
    {
        $tenant = $this->editorTenant();
        $html = $this->get($this->ed('edit', $tenant, $this->documentWithLine($tenant)))->assertOk()->getContent();
        $this->assertStringNotContainsStringIgnoringCase('minor unit', $html);

        $sources = array_merge(
            glob(base_path('resources/js/documents/editor/*.js')) ?: [],
            glob(base_path('resources/views/customer/business/documents/editor*.blade.php')) ?: [],
            [base_path('resources/views/customer/business/documents/index.blade.php')],
        );
        $this->assertNotEmpty($sources);
        foreach ($sources as $source) {
            $this->assertStringNotContainsStringIgnoringCase('minor unit', (string) file_get_contents($source), basename($source));
        }
    }

    public function test_the_bootstrap_lists_only_this_businesss_catalog_images(): void
    {
        $tenant = $this->editorTenant('Mine');
        $document = $this->blankDocument($tenant);
        $ownItem = $this->catalogItem($tenant['business'], 'Own Package');
        $own = CatalogItemImage::create(['catalog_item_id' => $ownItem->id, 'disk' => 'public', 'path' => 'x/own.jpg', 'mime_type' => 'image/jpeg', 'size' => 10, 'position' => 0]);
        $other = $this->sendableTenant('Theirs');
        $this->authenticateAs($tenant['customer']);
        $foreign = CatalogItemImage::create(['catalog_item_id' => $this->catalogItem($other['business'], 'Foreign')->id, 'disk' => 'public', 'path' => 'x/foreign.jpg', 'mime_type' => 'image/jpeg', 'size' => 10, 'position' => 0]);

        $boot = $this->bootstrap($this->get($this->ed('edit', $tenant, $document))->assertOk()->getContent());

        $this->assertSame([$own->uid], array_column($boot['images'], 'uid'));
        $this->assertNotContains($foreign->uid, array_column($boot['images'], 'uid'));
    }

    public function test_sent_and_signed_documents_open_read_only(): void
    {
        $tenant = $this->editorTenant();
        $document = $this->draftDocument($tenant, ['title' => 'Locked proposal']);
        [$sent] = $this->sendAndCaptureToken($document);
        $this->authenticateAs($tenant['customer']);

        $html = $this->get($this->ed('edit', $tenant, $sent))->assertOk()->getContent();
        $boot = $this->bootstrap($html);
        $this->assertFalse($boot['editable']);
        $this->assertStringContainsString('data-editable="0"', $html);
        $this->assertStringContainsString('data-role="readonly-banner"', $html);
        $this->assertStringContainsString('>Sent<', $html);
        $this->assertStringNotContainsString('data-role="action-send"', $html, 'a sent document has no Send action');
        $this->assertStringNotContainsString('data-role="action-save"', $html);
        $this->assertMatchesRegularExpression('#<button[^>]*data-tool="text"[^>]*disabled#', $html, 'the toolbox is disabled');
        $this->assertMatchesRegularExpression('#<input[^>]*data-role="editor-title"[^>]*disabled#', $html);

        DB::table('business_documents')->where('id', $sent->id)->update(['status' => 'signed']);
        $signed = $this->get($this->ed('edit', $tenant, $sent))->assertOk()->getContent();
        $this->assertStringContainsString('>Signed<', $signed);
        $this->assertStringContainsString('has been signed and is locked', $signed);
        $this->assertFalse($this->bootstrap($signed)['editable']);
    }

    public function test_a_foreign_documents_editor_and_preview_are_404(): void
    {
        $tenant = $this->editorTenant('Mine');
        $other = $this->sendableTenant('Theirs');
        $this->authenticateAs($tenant['customer']);
        $foreign = $this->documentWithLine($other);

        $this->get($this->ed('edit', $tenant, $foreign))->assertNotFound();
        $this->get($this->ed('preview', $tenant, $foreign))->assertNotFound();
    }

    // ---- legacy ------------------------------------------------------------------------

    public function test_a_legacy_draft_shows_the_upgrade_prompt_instead_of_the_canvas(): void
    {
        $tenant = $this->editorTenant();
        $legacy = $this->legacyDraft($tenant);

        $html = $this->get($this->ed('edit', $tenant, $legacy))->assertOk()->getContent();
        $boot = $this->bootstrap($html);

        $this->assertTrue($boot['is_legacy']);
        $this->assertTrue($boot['can_upgrade']);
        $this->assertStringContainsString('data-role="legacy-upgrade"', $html);
        $this->assertStringContainsString('Upgrade to the visual editor', $html);
        $this->assertStringContainsString('data-role="legacy-upgrade-button"', $html);
        $this->assertStringContainsString($boot['urls']['show'], $html, 'a link to the classic page');
        $this->assertStringNotContainsString('data-role="editor-canvas"', $html);
        $this->assertStringNotContainsString('data-role="toolbox"', $html);
    }

    public function test_a_sent_legacy_document_links_to_the_classic_page_only(): void
    {
        $tenant = $this->editorTenant();
        $legacy = $this->legacyDraft($tenant, 'Old and sent');
        $manager = app(DocumentManager::class);
        $manager->addCustomLine($legacy, 'Work', null, 1, 10000);
        $manager->setSchedule($legacy, [['kind' => 'full', 'amount_minor' => 10000, 'currency_code' => 'USD']]);
        [$sent] = $this->sendAndCaptureToken($legacy->refresh());
        $this->authenticateAs($tenant['customer']);

        $html = $this->get($this->ed('edit', $tenant, $sent))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-role="legacy-upgrade-button"', $html);
        $this->assertStringContainsString('data-role="legacy-classic-link"', $html);
        $this->assertStringContainsString('can no longer be changed', $html);
    }

    // ---- preview ---------------------------------------------------------------------------

    public function test_preview_renders_the_saved_draft_through_the_one_renderer_with_live_merge_values(): void
    {
        $tenant = $this->editorTenant();
        $document = $this->documentWithLine($tenant, 150000);

        $html = $this->get($this->ed('preview', $tenant, $document))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="document-blocks"', $html);
        $this->assertStringContainsString('data-mode="preview"', $html);
        // merge field resolved from the document's recipient (Pat Rivera), not left as a token
        $this->assertStringContainsString('Proposal for Pat', $html);
        $this->assertStringNotContainsString('data-token="contact.first_name"', $html);
        // canonical lines and totals
        $this->assertStringContainsString('Design work', $html);
        $this->assertStringContainsString('USD 1,500.00', $html);
        // the signature placeholder and the page chrome
        $this->assertStringContainsString('data-role="signature-placeholder"', $html);
        $this->assertStringContainsString('Client signature', $html);
        $this->assertStringContainsString('data-role="preview-bar"', $html);
    }

    public function test_preview_shows_the_canonical_deposit_schedule(): void
    {
        $tenant = $this->editorTenant();
        $document = $this->documentWithLine($tenant, 100000);
        $this->putJson($this->ed('plan', $tenant, $document), ['structure' => 'deposit', 'deposit' => '250.00', 'expected_lock_version' => $this->lock($document)])->assertOk();

        $html = $this->get($this->ed('preview', $tenant, $document))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="schedule-row"', $html);
        $this->assertStringContainsString('USD 250.00', $html);
        $this->assertStringContainsString('USD 750.00', $html);
        $this->assertStringContainsString('Due after the deposit is paid', $html);
    }

    public function test_preview_escapes_user_content(): void
    {
        $tenant = $this->editorTenant();
        $document = $this->documentWithLine($tenant);
        $manager = app(DocumentManager::class);
        $manager->saveBlocks($document, array_merge($this->standardBlocks(), [
            ['id' => 'b-xss', 'type' => 'text', 'data' => ['runs' => [['t' => '<script>alert("xss")</script><img src=x onerror=alert(1)>']]]],
            ['id' => 'b-sec', 'type' => 'section', 'data' => ['title' => '"><svg onload=alert(2)>']],
        ]));
        $manager->addCustomLine($document, '<b onmouseover=alert(3)>Evil line</b>', '<script>alert(4)</script>', 1, 5000);

        $html = $this->get($this->ed('preview', $tenant, $document))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<svg onload', $html);
        $this->assertStringNotContainsString('<b onmouseover', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $html);
    }

    public function test_preview_of_a_legacy_document_goes_to_the_classic_page(): void
    {
        $tenant = $this->editorTenant();
        $legacy = $this->legacyDraft($tenant);

        $this->get($this->ed('preview', $tenant, $legacy))
            ->assertRedirect(route('customer.workspaces.businesses.documents.show', [$tenant['workspace']->uid, $tenant['business']->uid, $legacy->uid]));
    }

    public function test_preview_of_a_sent_document_uses_the_frozen_version_not_live_values(): void
    {
        $tenant = $this->editorTenant();
        $document = $this->draftDocument($tenant);
        app(DocumentManager::class)->saveBlocks($document, $this->standardBlocks());
        [$sent] = $this->sendAndCaptureToken($document->refresh());
        $this->authenticateAs($tenant['customer']);
        DB::table('businesses')->where('id', $tenant['business']->id)->update(['name' => 'Renamed After Send']);

        $html = $this->get($this->ed('preview', $tenant, $sent))->assertOk()->getContent();

        $this->assertStringContainsString('Proposal for Pat', $html);
        $this->assertStringContainsString('This is the version that was sent', $html);
    }

    // ---- new proposal flow -----------------------------------------------------------------------

    public function test_the_documents_page_offers_the_new_proposal_flow_and_keeps_the_invoice_form(): void
    {
        $tenant = $this->editorTenant();
        $html = $this->get($this->docsUrl($tenant))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="new-proposal-open"', $html);
        $this->assertStringContainsString('data-role="new-proposal-form"', $html);
        $this->assertStringContainsString('name="via" value="editor"', $html);
        $this->assertStringContainsString('Untitled proposal', $html);
        $this->assertStringContainsString('data-role="template-slot"', $html, 'the templates slot stage 5 fills');
        $this->assertStringContainsString(route('customer.workspaces.businesses.documents.editor.contacts.search', [$tenant['workspace']->uid, $tenant['business']->uid]), $html);
        // invoices: the existing form, unchanged in behaviour
        $this->assertStringContainsString('data-role="new-invoice"', $html);
        $this->assertStringContainsString('name="kind" value="invoice"', $html);
        $this->assertStringContainsString('name="location_uid"', $html);
        $this->assertStringNotContainsString('<option value="proposal">', $html, 'the classic proposal creation path is replaced');
    }

    public function test_choosing_a_contact_creates_a_block_ready_draft_and_opens_the_editor(): void
    {
        $tenant = $this->editorTenant();

        $response = $this->post($this->docsUrl($tenant), [
            'kind' => 'proposal', 'via' => 'editor', 'title' => 'Untitled proposal', 'contact_uid' => $tenant['contact']->uid,
        ]);

        $document = BusinessDocument::query()->where('business_id', $tenant['business']->id)->latest('id')->firstOrFail();
        $response->assertRedirect(route('customer.workspaces.businesses.documents.editor.edit', [$tenant['workspace']->uid, $tenant['business']->uid, $document->uid]));

        $this->assertSame('proposal', $document->kind->value);
        $this->assertSame('Untitled proposal', $document->title);
        $this->assertSame((int) $tenant['contact']->id, (int) $document->contact_id);
        $this->assertSame((int) $tenant['location']->id, (int) $document->business_location_id, 'the Location is derived from the contact');
        $version = BusinessDocumentVersion::where('business_document_id', $document->id)->where('state', 'draft')->firstOrFail();
        $this->assertSame([], $version->content['blocks'], 'a block-ready (empty block) draft');
        $this->assertSame(2, $version->content['schema_version']);

        // ...and the editor opens on it as a block document.
        $boot = $this->bootstrap($this->get($this->ed('edit', $tenant, $document))->assertOk()->getContent());
        $this->assertTrue($boot['is_block_document']);
        $this->assertTrue($boot['editable']);
    }

    public function test_the_new_proposal_flow_fails_closed_for_a_foreign_or_unknown_contact(): void
    {
        $tenant = $this->editorTenant('Mine');
        $other = $this->sendableTenant('Theirs');
        $this->authenticateAs($tenant['customer']);
        $before = BusinessDocument::count();

        $this->post($this->docsUrl($tenant), ['kind' => 'proposal', 'via' => 'editor', 'title' => 'X', 'contact_uid' => $other['contact']->uid])->assertNotFound();
        $this->post($this->docsUrl($tenant), ['kind' => 'proposal', 'via' => 'editor', 'title' => 'X', 'contact_uid' => '00000000-0000-0000-0000-000000000000'])->assertNotFound();
        $this->post($this->docsUrl($tenant), ['kind' => 'proposal', 'via' => 'editor', 'title' => 'X'])->assertSessionHasErrors('contact_uid');

        $this->assertSame($before, BusinessDocument::count());
    }

    public function test_the_classic_create_path_and_invoices_behave_as_before(): void
    {
        $tenant = $this->editorTenant();

        // Invoice: Location still required and still redirects to the classic page.
        $this->post($this->docsUrl($tenant), ['kind' => 'invoice', 'title' => 'Inv', 'contact_uid' => $tenant['contact']->uid])->assertSessionHasErrors('location_uid');
        $this->post($this->docsUrl($tenant), ['kind' => 'invoice', 'title' => 'Inv', 'contact_uid' => $tenant['contact']->uid, 'location_uid' => $tenant['location']->uid])->assertRedirect();
        $invoice = BusinessDocument::query()->latest('id')->firstOrFail();
        $this->assertSame('invoice', $invoice->kind->value);
        $this->assertSame([], BusinessDocumentVersion::where('business_document_id', $invoice->id)->firstOrFail()->content, 'an invoice is not turned into a block document');

        // A proposal posted the classic way (no via=editor) keeps its classic show page and plain content.
        $this->post($this->docsUrl($tenant), ['kind' => 'proposal', 'title' => 'Classic', 'contact_uid' => $tenant['contact']->uid, 'location_uid' => $tenant['location']->uid])
            ->assertRedirect(route('customer.workspaces.businesses.documents.show', [$tenant['workspace']->uid, $tenant['business']->uid, BusinessDocument::query()->latest('id')->value('uid')]));
    }

    public function test_the_contact_picker_endpoint_serves_the_new_flow(): void
    {
        $tenant = $this->editorTenant();

        $results = $this->getJson($this->ed('contacts.search', $tenant) . '?q=')->assertOk()->json('results');

        $this->assertContains($tenant['contact']->uid, array_column($results, 'uid'));
    }

    // ---- the list ----------------------------------------------------------------------------------

    public function test_list_rows_link_block_drafts_to_the_editor_and_everything_else_to_the_classic_page(): void
    {
        $tenant = $this->editorTenant();
        $block = $this->blankDocument($tenant, 'Block draft');
        app(DocumentManager::class)->saveBlocks($block, $this->standardBlocks());
        $legacy = $this->legacyDraft($tenant, 'Legacy draft');
        $sentSource = $this->draftDocument($tenant, ['title' => 'Already sent']);
        [$sent] = $this->sendAndCaptureToken($sentSource);
        $this->authenticateAs($tenant['customer']);

        $html = $this->get($this->docsUrl($tenant))->assertOk()->getContent();
        $editorLink = fn ($d) => route('customer.workspaces.businesses.documents.editor.edit', [$tenant['workspace']->uid, $tenant['business']->uid, $d->uid]);
        $showLink = fn ($d) => route('customer.workspaces.businesses.documents.show', [$tenant['workspace']->uid, $tenant['business']->uid, $d->uid]);

        $this->assertStringContainsString('href="' . $editorLink($block) . '"', $html);
        $this->assertStringContainsString('href="' . $showLink($legacy) . '"', $html);
        $this->assertStringNotContainsString('href="' . $editorLink($legacy) . '"', $html);
        $this->assertStringContainsString('href="' . $showLink($sent) . '"', $html);
        $this->assertStringNotContainsString('href="' . $editorLink($sent) . '"', $html);
    }

    public function test_the_classic_show_page_links_a_draft_proposal_to_the_editor(): void
    {
        $tenant = $this->editorTenant();
        $document = $this->blankDocument($tenant);

        $this->get(route('customer.workspaces.businesses.documents.show', [$tenant['workspace']->uid, $tenant['business']->uid, $document->uid]))
            ->assertOk()->assertSee('data-role="open-editor"', false);
    }
}

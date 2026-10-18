<?php

namespace Tests\Feature\Documents\Templates;

use App\Library\Documents\DocumentManager;
use App\Library\Documents\Templates\DocumentTemplateService;
use App\Models\BusinessDocument;
use App\Models\DocumentTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Catalog\Concerns\CreatesCatalogHttpFixtures;
use Tests\Feature\Documents\Concerns\CreatesDocumentsTestData;
use Tests\Feature\Documents\Concerns\SendsDocuments;
use Tests\TestCase;

/**
 * Contract 17B §6 — the Business-side HTTP surface of templates: the library,
 * the template-mode editor page and its JSON endpoint, "Save as template",
 * "Use template" through documents.store, and the 404 / fail-closed rules.
 */
class DocumentTemplateHttpTest extends TestCase
{
    use RefreshDatabase;
    use SendsDocuments;
    use CreatesDocumentsTestData;
    use CreatesCatalogHttpFixtures;
    use TemplateTestHelpers;

    private function service(): DocumentTemplateService
    {
        return app(DocumentTemplateService::class);
    }

    private function docsUrl(array $tenant): string
    {
        return route('customer.workspaces.businesses.documents.index', [$tenant['workspace']->uid, $tenant['business']->uid]);
    }

    private function myTemplate(array $tenant, string $name = 'Wedding layout'): DocumentTemplate
    {
        return $this->service()->saveFromDocument($this->richDocument($tenant, null, 'Source ' . $name), $name, 'proposal', 'Standard wedding terms', $tenant['customer']->user);
    }

    // ---- library ----------------------------------------------------------------------------

    public function test_the_library_shows_empty_states_for_a_business_with_no_templates(): void
    {
        $tenant = $this->editorTenant();

        $html = $this->get($this->tpl('index', $tenant))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="template-library"', $html);
        $this->assertStringContainsString('data-role="my-templates-empty"', $html);
        $this->assertStringContainsString('data-role="recommended-empty"', $html);
        $this->assertStringContainsString('data-role="template-create-form"', $html);
        $this->assertStringNotContainsString('data-role="template-card"', $html);
        $this->assertStringContainsString('saves the layout, not the product or contact', $html);
    }

    public function test_the_library_lists_own_templates_as_cards_and_hides_archived_until_toggled(): void
    {
        $tenant = $this->editorTenant();
        $active = $this->myTemplate($tenant, 'Wedding layout');
        $contract = $this->service()->createBlank($tenant['business'], 'Service contract', 'contract', $tenant['customer']->user);
        $archived = $this->service()->createBlank($tenant['business'], 'Retired layout', 'proposal', $tenant['customer']->user);
        $this->service()->archive($archived, $tenant['business']);

        $html = $this->get($this->tpl('index', $tenant))->assertOk()->getContent();

        $this->assertStringContainsString('Wedding layout', $html);
        $this->assertStringContainsString('Standard wedding terms', $html);
        $this->assertStringContainsString('Service contract', $html);
        $this->assertStringContainsString('Proposal for Contact first name', $html, 'the snippet comes from the first heading / text blocks (token shown by label)');
        $this->assertStringNotContainsString('Retired layout', $html, 'archived templates are hidden by default');
        $this->assertSame(2, substr_count($html, 'data-role="template-card"'));
        foreach (['template-use', 'template-edit', 'template-duplicate', 'template-archive', 'template-preview'] as $role) {
            $this->assertStringContainsString('data-role="' . $role . '"', $html, $role);
        }
        $this->assertStringContainsString('>Contract<', $html);
        $this->assertStringContainsString('>Proposal<', $html);
        $this->assertStringContainsString('data-role="toggle-archived"', $html);
        $this->assertStringContainsString($this->tpl('edit', $tenant, $active), $html);

        $withArchived = $this->get($this->tpl('index', $tenant, null, ['archived' => 1]))->assertOk()->getContent();
        $this->assertStringContainsString('Retired layout', $withArchived);
        $this->assertStringContainsString('data-role="template-restore"', $withArchived);
        $this->assertStringContainsString('data-role="template-archived"', $withArchived);
    }

    public function test_the_library_never_lists_another_businesss_or_an_unrecommended_platform_template(): void
    {
        $a = $this->editorTenant('Alpha Co');
        $b = $this->sendableTenant('Beta Co');
        $this->authenticateAs($a['customer']);
        $this->service()->createBlank($b['business'], 'Beta private layout', 'proposal', $b['customer']->user);
        $this->platformTemplate([], 'Unrecommended platform layout');

        $html = $this->get($this->tpl('index', $a))->assertOk()->getContent();

        $this->assertStringNotContainsString('Beta private layout', $html);
        $this->assertStringNotContainsString('Unrecommended platform layout', $html);
        $this->assertStringContainsString('data-role="my-templates-empty"', $html);
    }

    public function test_recommended_templates_come_only_through_the_recommendation_seam(): void
    {
        $tenant = $this->editorTenant();
        $platform = $this->platformTemplate([['id' => 'h', 'type' => 'heading', 'data' => ['level' => 1, 'runs' => [['t' => 'Recommended layout heading']]]]], 'Photographer starter');
        $this->recommend($platform);

        $html = $this->get($this->tpl('index', $tenant))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="recommended-card"', $html);
        $this->assertStringContainsString('Photographer starter', $html);
        $this->assertStringNotContainsString('data-role="recommended-empty"', $html);
        // A recommended platform template is usable and previewable, but never editable here.
        $this->get($this->tpl('preview', $tenant, $platform))->assertOk()->assertSee('Recommended layout heading');
        $this->get($this->tpl('edit', $tenant, $platform))->assertNotFound();
        $this->putJson($this->tpl('blocks', $tenant, $platform), ['blocks' => [], 'expected_lock_version' => 1])->assertNotFound();
        $this->post($this->tpl('duplicate', $tenant, $platform))->assertNotFound();
        $this->post($this->tpl('archive', $tenant, $platform))->assertNotFound();
    }

    public function test_create_blank_redirects_into_the_template_editor(): void
    {
        $tenant = $this->editorTenant();

        $response = $this->post($this->tpl('create', $tenant), ['name' => 'Fresh contract', 'template_type' => 'contract']);

        $template = DocumentTemplate::where('business_id', $tenant['business']->id)->firstOrFail();
        $response->assertRedirect($this->tpl('edit', $tenant, $template));
        $this->assertSame('Fresh contract', $template->name);
        $this->assertSame('contract', $template->template_type->value);
        $this->assertSame([], $template->blocks);

        $this->post($this->tpl('create', $tenant), [])->assertRedirect();
        $this->assertSame('Untitled template', DocumentTemplate::where('business_id', $tenant['business']->id)->orderByDesc('id')->value('name'));
        $this->post($this->tpl('create', $tenant), ['template_type' => 'invoice'])->assertSessionHasErrors('template_type');
    }

    public function test_duplicate_archive_and_restore_over_http(): void
    {
        $tenant = $this->editorTenant();
        $template = $this->myTemplate($tenant);

        $this->post($this->tpl('duplicate', $tenant, $template))->assertRedirect($this->tpl('index', $tenant));
        $copy = DocumentTemplate::where('name', 'Copy of Wedding layout')->firstOrFail();
        $this->assertSame($template->blocks, $copy->blocks);

        $this->post($this->tpl('archive', $tenant, $template))->assertRedirect($this->tpl('index', $tenant));
        $this->assertSame('archived', $template->fresh()->status->value);
        $this->post($this->tpl('restore', $tenant, $template))->assertRedirect($this->tpl('index', $tenant));
        $this->assertSame('active', $template->fresh()->status->value);
    }

    public function test_the_documents_page_links_to_the_library(): void
    {
        $tenant = $this->editorTenant();

        $html = $this->get($this->docsUrl($tenant))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="templates-link"', $html);
        $this->assertStringContainsString($this->tpl('index', $tenant), $html);
    }

    // ---- template-mode editor ---------------------------------------------------------------

    public function test_the_template_editor_renders_in_template_mode_with_no_document_or_contact_data(): void
    {
        $tenant = $this->editorTenant();
        $image = $this->ownImage($tenant['business']);
        $template = $this->service()->saveFromDocument($this->richDocument($tenant, $image->uid), 'Layout', 'contract', null, $tenant['customer']->user);

        $html = $this->get($this->tpl('edit', $tenant, $template))->assertOk()->getContent();
        $boot = $this->pageBootstrap($html);

        $this->assertSame('template', $boot['mode']);
        $this->assertSame($template->uid, $boot['template']['uid']);
        $this->assertSame('contract', $boot['template']['type']);
        $this->assertSame('active', $boot['template']['status']);
        $this->assertTrue($boot['editable']);
        $this->assertSame($template->lock_version, $boot['lock_version']);
        $this->assertSame($template->blocks, $boot['blocks']);
        $this->assertSame([$image->uid], array_column($boot['images'], 'uid'));
        $this->assertSame($this->tpl('blocks', $tenant, $template), $boot['urls']['blocks']);
        $this->assertSame($this->tpl('preview', $tenant, $template), $boot['urls']['preview']);
        $this->assertSame($this->tpl('index', $tenant), $boot['urls']['library']);

        // Nothing of a document's commerce, recipient or send flow is in the page.
        $this->assertSame([], $boot['lines']);
        $this->assertSame([], $boot['schedule']);
        $this->assertNull($boot['plan']);
        $this->assertSame([], $boot['delivery']);
        $this->assertSame(['name' => '', 'email' => ''], $boot['contact']);
        foreach (['lines_catalog', 'lines_custom', 'catalog_search', 'catalog_store', 'contact_dates', 'send', 'plan', 'upgrade', 'save_template'] as $documentOnly) {
            $this->assertArrayNotHasKey($documentOnly, $boot['urls'], $documentOnly);
        }
        $this->assertArrayHasKey('contact.first_name', $boot['merge_samples']);
        $this->assertStringNotContainsString('Pat Rivera', $html);
        $this->assertStringNotContainsString('client@example.test', $html);

        // Header: back, name, type, status chip, indicator, Preview, Save. No Send, no Save as template, no contact chip.
        foreach (['editor-header', 'editor-back', 'editor-title', 'template-type', 'editor-status', 'save-indicator', 'action-preview', 'action-save', 'toolbox', 'editor-canvas'] as $role) {
            $this->assertStringContainsString('data-role="' . $role . '"', $html, $role);
        }
        foreach (['action-send', 'action-save-template', 'editor-contact'] as $absent) {
            $this->assertStringNotContainsString('data-role="' . $absent . '"', $html, $absent);
        }
        $this->assertStringContainsString('data-mode="template"', $html);
        $this->assertStringContainsString('>Template<', $html);
        $this->assertStringContainsString('aria-label="Template name"', $html);
        $this->assertStringContainsString('Back to template library', $html);
        // The toolbox is the documents toolbox.
        $this->assertStringContainsString('data-tool="product"', $html);
        $this->assertStringContainsString('data-tool="signature"', $html);
    }

    public function test_an_archived_template_opens_read_only(): void
    {
        $tenant = $this->editorTenant();
        $template = $this->myTemplate($tenant);
        $this->service()->archive($template, $tenant['business']);

        $html = $this->get($this->tpl('edit', $tenant, $template))->assertOk()->getContent();

        $this->assertFalse($this->pageBootstrap($html)['editable']);
        $this->assertStringContainsString('data-role="readonly-banner"', $html);
        $this->assertStringContainsString('>Archived<', $html);
        $this->assertStringNotContainsString('data-role="action-save"', $html);
    }

    public function test_the_document_editor_page_has_an_enabled_save_as_template_button_and_its_urls(): void
    {
        $tenant = $this->editorTenant();
        $document = $this->richDocument($tenant);

        $html = $this->get($this->ed('edit', $tenant, $document))->assertOk()->getContent();
        $boot = $this->pageBootstrap($html);

        $this->assertSame('document', $boot['mode']);
        $this->assertSame($this->ed('save-template', $tenant, $document), $boot['urls']['save_template']);
        $this->assertSame($this->tpl('index', $tenant), $boot['urls']['template_library']);
        $this->assertDoesNotMatchRegularExpression('#<button[^>]*data-role="action-save-template"[^>]*disabled#', $html);
        $this->assertStringContainsString('data-role="action-send"', $html);
    }

    public function test_a_sent_document_still_offers_save_as_template(): void
    {
        $tenant = $this->editorTenant();
        [$sent] = $this->sendAndCaptureToken($this->richDocument($tenant));
        $this->authenticateAs($tenant['customer']);

        $html = $this->get($this->ed('edit', $tenant, $sent))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="action-save-template"', $html);
        $this->assertStringNotContainsString('data-role="action-send"', $html);
    }

    // ---- template blocks endpoint -----------------------------------------------------------

    public function test_the_blocks_endpoint_saves_name_type_description_and_blocks_and_bumps_the_version(): void
    {
        $tenant = $this->editorTenant();
        $template = $this->service()->createBlank($tenant['business'], 'T', 'proposal', $tenant['customer']->user);

        $this->putJson($this->tpl('blocks', $tenant, $template), [
            'blocks' => $this->layoutBlocks(), 'name' => 'Renamed', 'template_type' => 'contract', 'description' => 'Hello', 'expected_lock_version' => 1,
        ])->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('lock_version', 2)->assertJsonPath('name', 'Renamed')->assertJsonPath('template_type', 'contract');

        $fresh = $template->fresh();
        $this->assertSame('Renamed', $fresh->name);
        $this->assertSame('contract', $fresh->template_type->value);
        $this->assertCount(6, $fresh->blocks);

        // Only the changed fields move: blocks alone leaves the name alone.
        $this->putJson($this->tpl('blocks', $tenant, $template), ['blocks' => [], 'expected_lock_version' => 2])->assertOk()->assertJsonPath('lock_version', 3);
        $this->assertSame('Renamed', $template->fresh()->name);
        $this->assertSame([], $template->fresh()->blocks);
    }

    public function test_the_spaces_around_a_merge_field_survive_the_json_save_for_templates_and_documents(): void
    {
        $tenant = $this->editorTenant();
        $runs = [['t' => 'Proposal for '], ['merge' => 'contact.first_name'], ['t' => ' - thank you ']];
        $blocks = [['id' => 'h', 'type' => 'heading', 'data' => ['level' => 1, 'runs' => $runs]]];

        // The global TrimStrings middleware must not glue the chip to the word ("forAlex").
        $template = $this->service()->createBlank($tenant['business'], 'T', 'proposal', $tenant['customer']->user);
        $this->putJson($this->tpl('blocks', $tenant, $template), ['blocks' => $blocks, 'expected_lock_version' => 1])->assertOk();
        $this->assertSame('Proposal for ', $template->fresh()->blocks[0]['data']['runs'][0]['t']);
        $this->assertSame(' - thank you ', $template->fresh()->blocks[0]['data']['runs'][2]['t']);

        $document = $this->blankDocument($tenant);
        $this->putJson($this->ed('blocks', $tenant, $document), ['blocks' => $blocks, 'expected_lock_version' => $this->lock($document)])->assertOk();
        $this->assertSame('Proposal for ', $this->draftVersion($document)->content['blocks'][0]['data']['runs'][0]['t']);
    }
    public function test_the_blocks_endpoint_answers_409_422_and_404_explicitly(): void
    {
        $tenant = $this->editorTenant();
        $template = $this->service()->createBlank($tenant['business'], 'T', 'proposal', $tenant['customer']->user);
        $url = $this->tpl('blocks', $tenant, $template);

        $this->putJson($url, ['blocks' => [], 'expected_lock_version' => 9])->assertStatus(409)->assertJsonPath('status', 'conflict')->assertJsonPath('lock_version', 1);
        $this->putJson($url, ['blocks' => [['id' => 'x', 'type' => 'script', 'data' => []]], 'expected_lock_version' => 1])
            ->assertStatus(422)->assertJsonPath('status', 'invalid')->assertJsonStructure(['errors']);
        $this->putJson($url, ['blocks' => [], 'name' => '   ', 'expected_lock_version' => 1])->assertStatus(422)->assertJsonPath('status', 'invalid');
        $this->putJson($url, ['blocks' => []])->assertStatus(422)->assertJsonPath('status', 'invalid'); // no expected_lock_version
        $this->putJson($this->tpl('blocks', $tenant, $template, []) . '', ['blocks' => [], 'expected_lock_version' => 1])->assertOk();

        $forged = str_replace($template->uid, '11111111-1111-4111-8111-111111111111', $url);
        $this->putJson($forged, ['blocks' => [], 'expected_lock_version' => 1])->assertNotFound()->assertJsonPath('status', 'error');
        $this->assertSame(2, $template->fresh()->lock_version, 'only the one valid save moved the version');
    }

    public function test_the_blocks_endpoint_refuses_an_archived_template_and_a_foreign_image(): void
    {
        $tenant = $this->editorTenant('Mine');
        $other = $this->sendableTenant('Theirs');
        $this->authenticateAs($tenant['customer']);
        $foreign = $this->ownImage($other['business'], 'Foreign');
        $template = $this->service()->createBlank($tenant['business'], 'T', 'proposal', $tenant['customer']->user);

        $this->putJson($this->tpl('blocks', $tenant, $template), ['blocks' => [['id' => 'f', 'type' => 'image', 'data' => ['catalog_image_uid' => $foreign->uid, 'alt' => '', 'width_pct' => 100]]], 'expected_lock_version' => 1])
            ->assertStatus(422)->assertJsonPath('status', 'invalid');
        $this->assertSame([], $template->fresh()->blocks);

        $this->service()->archive($template, $tenant['business']);
        $this->putJson($this->tpl('blocks', $tenant, $template), ['blocks' => [], 'expected_lock_version' => 1])->assertStatus(422)->assertJsonPath('status', 'invalid');
    }

    public function test_the_template_preview_renders_sample_data_and_generic_placeholders(): void
    {
        $tenant = $this->editorTenant();
        $template = $this->myTemplate($tenant);

        $html = $this->get($this->tpl('preview', $tenant, $template))->assertOk()->getContent();

        $this->assertStringContainsString('data-mode="template_preview"', $html);
        $this->assertStringContainsString('data-role="product-placeholder"', $html);
        $this->assertStringNotContainsString('Pat Rivera', $html);
        $this->assertStringNotContainsString('data-role="lines"', $html);
        $this->assertStringNotContainsString('contact.first_name', $html, 'tokens are resolved from sample data, never printed raw');
    }

    // ---- foreign / forged ids ---------------------------------------------------------------

    public function test_business_a_gets_404_on_every_template_route_for_business_bs_template(): void
    {
        $a = $this->editorTenant('Alpha Co');
        $b = $this->sendableTenant('Beta Co');
        $this->authenticateAs($a['customer']);
        $theirs = $this->service()->createBlank($b['business'], 'Beta secret', 'proposal', $b['customer']->user);

        $this->get($this->tpl('edit', $a, $theirs))->assertNotFound();
        $this->get($this->tpl('preview', $a, $theirs))->assertNotFound();
        $this->putJson($this->tpl('blocks', $a, $theirs), ['blocks' => [], 'name' => 'Hijack', 'expected_lock_version' => 1])->assertNotFound();
        $this->post($this->tpl('duplicate', $a, $theirs))->assertNotFound();
        $this->post($this->tpl('archive', $a, $theirs))->assertNotFound();
        $this->post($this->tpl('restore', $a, $theirs))->assertNotFound();

        $this->assertSame('Beta secret', $theirs->fresh()->name);
        $this->assertSame('active', $theirs->fresh()->status->value);
        $this->assertSame(1, DocumentTemplate::count());
        // Business B's own routes (with A's workspace in the URL) are not reachable either.
        $this->get(route('customer.workspaces.businesses.document-templates.edit', [$a['workspace']->uid, $b['business']->uid, $theirs->uid]))->assertStatus(404);
    }

    // ---- editor.save-template (document side) -----------------------------------------------

    public function test_save_template_endpoint_creates_an_active_business_template_and_returns_the_library_link(): void
    {
        $tenant = $this->editorTenant();
        $document = $this->richDocument($tenant);
        $before = $this->fingerprint(['business_documents', 'business_document_versions', 'business_document_line_items']);

        $response = $this->postJson($this->ed('save-template', $tenant, $document), ['name' => 'Saved layout', 'template_type' => 'contract', 'description' => 'For weddings'])
            ->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('template.name', 'Saved layout')->assertJsonPath('template.type', 'contract');

        $template = DocumentTemplate::where('uid', $response->json('template.uid'))->firstOrFail();
        $this->assertSame($tenant['business']->id, $template->business_id);
        $this->assertSame('active', $template->status->value);
        $this->assertSame($this->tpl('index', $tenant), $response->json('library_url'));
        $this->assertSame($this->tpl('edit', $tenant, $template), $response->json('edit_url'));
        $this->assertSame($before, $this->fingerprint(['business_documents', 'business_document_versions', 'business_document_line_items']), 'saving a template never changes the document');
        $this->assertStringNotContainsString('Design work', json_encode($template->getAttributes()));
    }

    public function test_save_template_endpoint_validates_and_refuses_legacy_documents(): void
    {
        $tenant = $this->editorTenant();
        $document = $this->richDocument($tenant);

        $this->postJson($this->ed('save-template', $tenant, $document), ['template_type' => 'proposal'])->assertStatus(422)->assertJsonPath('status', 'invalid');
        $this->postJson($this->ed('save-template', $tenant, $document), ['name' => 'X', 'template_type' => 'invoice'])->assertStatus(422);

        $manager = app(DocumentManager::class);
        $legacy = $manager->create($tenant['business'], $tenant['location'], $tenant['contact'], null, 'proposal', 'Legacy', $tenant['customer']->user);
        $manager->edit($legacy, ['content' => ['body' => 'Old classic terms.']]);
        $this->postJson($this->ed('save-template', $tenant, $legacy), ['name' => 'X', 'template_type' => 'proposal'])
            ->assertStatus(422)->assertJsonPath('status', 'invalid')->assertSee('classic text editor');
        $this->assertSame(0, DocumentTemplate::count());
    }

    public function test_save_template_endpoint_works_for_a_sent_document_and_404s_for_a_foreign_one(): void
    {
        $tenant = $this->editorTenant('Mine');
        $other = $this->sendableTenant('Theirs');
        $this->authenticateAs($tenant['customer']);
        $foreign = $this->richDocument($other);
        [$sent] = $this->sendAndCaptureToken($this->richDocument($tenant, null, 'Sent one'));
        $this->authenticateAs($tenant['customer']);

        $this->postJson($this->ed('save-template', $tenant, $sent), ['name' => 'From sent', 'template_type' => 'proposal'])->assertOk();
        $this->postJson($this->ed('save-template', $tenant, $foreign), ['name' => 'Steal', 'template_type' => 'proposal'])->assertNotFound();
        $this->assertSame(1, DocumentTemplate::count());
        $this->assertSame('sent', $sent->fresh()->status->value);
    }

    // ---- use a template (documents.store) ---------------------------------------------------

    public function test_the_new_proposal_picker_lists_blank_my_templates_and_hides_empty_recommended(): void
    {
        $tenant = $this->editorTenant();
        $template = $this->myTemplate($tenant);
        $archived = $this->service()->createBlank($tenant['business'], 'Retired layout', 'proposal', $tenant['customer']->user);
        $this->service()->archive($archived, $tenant['business']);

        $html = $this->get($this->docsUrl($tenant))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="template-slot"', $html);
        $this->assertStringContainsString('data-template-start="blank"', $html);
        $this->assertStringContainsString('name="template_uid" value=""', $html);
        $this->assertStringContainsString('name="template_uid" value="' . $template->uid . '"', $html);
        $this->assertStringContainsString('Wedding layout', $html);
        $this->assertStringContainsString('data-role="np-template-snippet"', $html);
        $this->assertStringContainsString('Proposal for Contact first name', $html);
        $this->assertStringContainsString('keeps the layout, not the product or contact', $html);
        $this->assertStringNotContainsString('Retired layout', $html, 'archived templates are not offered');
        $this->assertStringNotContainsString('data-role="np-recommended"', $html, 'Recommended is hidden while empty');
        $this->assertStringNotContainsString('data-role="np-recommended-label"', $html);
    }

    public function test_the_picker_shows_recommended_templates_only_when_the_seam_supplies_them(): void
    {
        $tenant = $this->editorTenant();
        $platform = $this->platformTemplate([], 'Photographer starter');
        $this->platformTemplate([], 'Not recommended starter');
        $this->recommend($platform);

        $html = $this->get($this->docsUrl($tenant))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="np-recommended"', $html);
        $this->assertStringContainsString('Photographer starter', $html);
        $this->assertStringNotContainsString('Not recommended starter', $html, 'global platform templates are never dumped');
    }

    public function test_the_library_use_link_preselects_the_template_in_the_flow(): void
    {
        $tenant = $this->editorTenant();
        $template = $this->myTemplate($tenant);

        $html = $this->get($this->docsUrl($tenant) . '?use_template=' . $template->uid)->assertOk()->getContent();

        $this->assertStringContainsString('data-use-template="' . $template->uid . '"', $html);
        $this->assertMatchesRegularExpression('#<input type="radio" name="template_uid" value="' . $template->uid . '"[^>]*checked#', $html);
    }

    public function test_using_a_template_creates_a_draft_for_the_chosen_contact_with_a_product_placeholder(): void
    {
        $tenant = $this->editorTenant();
        $template = $this->myTemplate($tenant);
        $templateBefore = DB::table('document_templates')->where('id', $template->id)->first();
        $existing = BusinessDocument::count();

        $response = $this->docsStore($tenant, ['template_uid' => $template->uid, 'title' => 'Jones wedding']);

        $document = BusinessDocument::where('title', 'Jones wedding')->firstOrFail();
        $response->assertRedirect($this->ed('edit', $tenant, $document));
        $this->assertSame($existing + 1, BusinessDocument::count());
        $this->assertSame($tenant['business']->id, $document->business_id);
        $this->assertSame($tenant['contact']->id, $document->contact_id);
        $this->assertSame('draft', $document->status->value);
        $version = $document->versions()->where('state', 'draft')->firstOrFail();
        $this->assertSame($template->blocks, $version->content['blocks']);
        $this->assertContains('product_list', array_column($version->content['blocks'], 'type'));
        $this->assertSame(0, $version->lineItems()->count());
        $this->assertSame(0, $version->paymentScheduleItems()->count());
        $this->assertArrayNotHasKey('payment_plan', $version->content);
        $this->assertEquals($templateBefore, DB::table('document_templates')->where('id', $template->id)->first(), 'the template is never mutated');

        // The editor opens that draft with the placeholder and an empty commerce state: the Add product wizard is asked again.
        $boot = $this->pageBootstrap($this->get($this->ed('edit', $tenant, $document))->assertOk()->getContent());
        $this->assertSame([], $boot['lines']);
        $this->assertNull($boot['plan']);
        $this->assertContains('product_list', array_column($boot['blocks'], 'type'));
    }

    public function test_an_unknown_or_forged_template_uid_fails_closed_and_creates_no_document(): void
    {
        $tenant = $this->editorTenant();
        $existing = BusinessDocument::count();

        $this->docsStore($tenant, ['template_uid' => '11111111-1111-4111-8111-111111111111'])->assertNotFound();
        $this->docsStore($tenant, ['template_uid' => str_repeat('x', 65)])->assertSessionHasErrors('template_uid');

        $this->assertSame($existing, BusinessDocument::count());
    }

    public function test_business_a_cannot_use_business_bs_template(): void
    {
        $a = $this->editorTenant('Alpha Co');
        $b = $this->sendableTenant('Beta Co');
        $this->authenticateAs($a['customer']);
        $theirs = $this->service()->createBlank($b['business'], 'Beta secret', 'proposal', $b['customer']->user);
        $existing = BusinessDocument::count();

        $this->docsStore($a, ['template_uid' => $theirs->uid])->assertNotFound();

        $this->assertSame($existing, BusinessDocument::count());
    }

    public function test_an_unrecommended_platform_template_cannot_be_used(): void
    {
        $tenant = $this->editorTenant();
        $platform = $this->platformTemplate([['id' => 'h', 'type' => 'heading', 'data' => ['level' => 1, 'runs' => [['t' => 'Platform heading']]]]]);
        $existing = BusinessDocument::count();

        $this->docsStore($tenant, ['template_uid' => $platform->uid])->assertNotFound();

        $this->assertSame($existing, BusinessDocument::count());
    }

    public function test_a_recommended_platform_template_can_be_used_and_the_document_is_business_owned(): void
    {
        $tenant = $this->editorTenant();
        $platform = $this->platformTemplate([['id' => 'h', 'type' => 'heading', 'data' => ['level' => 1, 'runs' => [['t' => 'Platform heading']]]], ['id' => 's', 'type' => 'signature', 'data' => ['label' => 'Signature']]]);
        $this->recommend($platform);
        $this->docsStore($tenant, ['template_uid' => $platform->uid, 'title' => 'From platform'])->assertRedirect();
        $document = BusinessDocument::where('title', 'From platform')->firstOrFail();
        $this->assertSame($tenant['business']->id, $document->business_id, 'the new document is Business-owned');
        $this->assertSame(['h', 's'], array_column($document->versions()->first()->content['blocks'], 'id'));
    }

    public function test_an_archived_template_cannot_be_used_and_creates_no_document(): void
    {
        $tenant = $this->editorTenant();
        $template = $this->myTemplate($tenant);
        $this->service()->archive($template, $tenant['business']);
        $existing = BusinessDocument::count();

        $this->docsStore($tenant, ['template_uid' => $template->uid])->assertSessionHasErrors('template_uid');

        $this->assertSame($existing, BusinessDocument::count());
    }

    public function test_a_template_that_cannot_be_applied_never_leaves_a_half_created_document(): void
    {
        $tenant = $this->editorTenant();
        // Planted corrupt content that passes the picker but fails BlockSchema when applied.
        $broken = $this->plantTemplate($tenant['business'], [['id' => 'x', 'type' => 'script', 'data' => []]], ['name' => 'Broken']);
        $existing = BusinessDocument::count();
        $versions = DB::table('business_document_versions')->count();

        $this->docsStore($tenant, ['template_uid' => $broken->uid])->assertSessionHasErrors('template_uid');

        $this->assertSame($existing, BusinessDocument::count(), 'the document insert was rolled back with the failed template');
        $this->assertSame($versions, DB::table('business_document_versions')->count());
    }

    public function test_a_template_uid_is_refused_outside_the_proposal_editor_flow(): void
    {
        $tenant = $this->editorTenant();
        $template = $this->myTemplate($tenant);
        $existing = BusinessDocument::count();

        $this->post(route('customer.workspaces.businesses.documents.store', [$tenant['workspace']->uid, $tenant['business']->uid]), [
            'kind' => 'invoice', 'title' => 'Invoice', 'contact_uid' => $tenant['contact']->uid, 'location_uid' => $tenant['location']->uid, 'template_uid' => $template->uid,
        ])->assertSessionHasErrors('template_uid');

        $this->assertSame($existing, BusinessDocument::count());
    }

    public function test_the_classic_blank_flow_still_works_without_a_template(): void
    {
        $tenant = $this->editorTenant();

        $this->docsStore($tenant, ['template_uid' => '', 'title' => 'Blank one'])->assertRedirect();

        $document = BusinessDocument::where('title', 'Blank one')->firstOrFail();
        $this->assertSame([], $document->versions()->first()->content['blocks']);
    }
}

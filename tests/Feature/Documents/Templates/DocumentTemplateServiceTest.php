<?php

namespace Tests\Feature\Documents\Templates;

use App\Enums\Documents\DocumentStatus;
use App\Enums\Documents\DocumentTemplateStatus;
use App\Exceptions\Documents\DocumentTemplateConflictException;
use App\Exceptions\Documents\DocumentTemplateRefusedException;
use App\Exceptions\Documents\InvalidDocumentBlocksException;
use App\Library\Documents\DocumentManager;
use App\Library\Documents\Templates\DocumentTemplateService;
use App\Models\BusinessDocument;
use App\Models\DocumentTemplate;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Catalog\Concerns\CreatesCatalogHttpFixtures;
use Tests\Feature\Documents\Concerns\CreatesDocumentsTestData;
use Tests\Feature\Documents\Concerns\SendsDocuments;
use Tests\TestCase;

/**
 * Contract 17B §6 — "SAVE THE LAYOUT, NOT THE PRODUCT OR CONTACT" at the service
 * level: save-as-template, use-template, lock_version, duplicate / archive /
 * restore and the access rules.
 */
class DocumentTemplateServiceTest extends TestCase
{
    use RefreshDatabase;
    use SendsDocuments;
    use CreatesDocumentsTestData;
    use CreatesCatalogHttpFixtures;
    use TemplateTestHelpers;

    private ?DocumentTemplateService $instance = null;

    /** One instance per test: lastDroppedImages() is per-instance state. */
    private function service(): DocumentTemplateService
    {
        return $this->instance ??= app(DocumentTemplateService::class);
    }

    // ---- save as template ---------------------------------------------------------------------

    public function test_save_as_template_keeps_the_layout_and_strips_contact_product_prices_plan_and_dates(): void
    {
        $tenant = $this->editorTenant();
        $image = $this->ownImage($tenant['business']);
        $document = $this->richDocument($tenant, $image->uid);
        $this->assertSame(1, $document->versions()->first()->lineItems()->count(), 'fixture: the document really has a line');
        $this->assertCount(2, $document->versions()->first()->paymentScheduleItems()->get(), 'fixture: and a compiled deposit / balance schedule');

        $template = $this->service()->saveFromDocument($document, 'Wedding layout', 'proposal', 'Our standard wedding proposal', $tenant['customer']->user);

        // The whole stored row, serialised: nothing of the contact / product / money may appear anywhere in it.
        $row = json_encode(DB::table('document_templates')->where('id', $template->id)->first());
        foreach (['Pat Rivera', 'client@example.test', $tenant['contact']->phone, 'Design work', 'Secret scope', 'unit_price', 'amount_minor', 'deposit', 'payment_plan', 'recipient', 'full_due', 'balance_due', 'content', 'parties', 'schedule', 'sent_at', 'signature_', 'access_token', $document->uid] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $row, "template row must not contain: {$forbidden}");
        }
        $this->assertStringNotContainsString((string) $tenant['contact']->uid, $row);

        // What it keeps.
        $this->assertSame($tenant['business']->id, $template->business_id);
        $this->assertSame(DocumentTemplateStatus::Active, $template->status);
        $this->assertSame('proposal', $template->template_type->value);
        $this->assertSame('Wedding layout', $template->name);
        $this->assertSame('Our standard wedding proposal', $template->description);
        $blocks = $template->blocks;
        $this->assertSame(['heading', 'text', 'image', 'section', 'product_list', 'payment_terms', 'signature'], array_column($blocks, 'type'), 'block order and signature position are kept');
        $this->assertEquals([['t' => 'Proposal for ', 'b' => true], ['merge' => 'contact.first_name']], $blocks[0]['data']['runs'], 'merge tokens stay tokens, styling stays');
        $this->assertSame('center', $blocks[0]['data']['align']);
        $this->assertSame($image->uid, $blocks[2]['data']['catalog_image_uid'], 'a Business-owned catalog image is kept');
        $this->assertEquals(['show_description' => false, 'show_quantity' => true], $blocks[4]['data'], 'the product area is a presentation-only placeholder');
        $this->assertSame('Client signature', $blocks[6]['data']['label']);
        $this->assertSame(0, $this->service()->lastDroppedImages());
    }

    public function test_save_as_template_never_changes_the_source_document(): void
    {
        $tenant = $this->editorTenant();
        $document = $this->richDocument($tenant);
        $before = $this->fingerprint(['business_documents', 'business_document_versions', 'business_document_line_items', 'business_document_payment_schedule_items']);

        $this->service()->saveFromDocument($document, 'Layout', 'contract', null, $tenant['customer']->user);

        $this->assertSame($before, $this->fingerprint(['business_documents', 'business_document_versions', 'business_document_line_items', 'business_document_payment_schedule_items']));
    }

    public function test_a_sent_document_can_be_saved_as_a_template_from_its_issued_version_read_only(): void
    {
        $tenant = $this->editorTenant();
        $document = $this->richDocument($tenant);
        [$sent] = $this->sendAndCaptureToken($document);
        $this->assertSame(DocumentStatus::Sent, $sent->status);
        $before = $this->fingerprint(['business_documents', 'business_document_versions', 'business_document_line_items']);

        $template = $this->service()->saveFromDocument($sent, 'From a sent proposal', 'proposal', null, $tenant['customer']->user);

        $this->assertSame(['heading', 'text', 'section', 'product_list', 'payment_terms', 'signature'], array_column($template->blocks, 'type'));
        $this->assertSame($before, $this->fingerprint(['business_documents', 'business_document_versions', 'business_document_line_items']), 'a sent document is never written');
        $this->assertStringNotContainsString('Pat Rivera', json_encode(DB::table('document_templates')->first()), 'frozen parties are not copied');
    }

    public function test_a_signed_document_can_be_saved_as_a_template(): void
    {
        $tenant = $this->editorTenant();
        [$sent] = $this->sendAndCaptureToken($this->richDocument($tenant));
        DB::table('business_documents')->where('id', $sent->id)->update(['status' => 'signed']);

        $template = $this->service()->saveFromDocument($sent->refresh(), 'Signed layout', 'contract', null, $tenant['customer']->user);

        $this->assertSame('contract', $template->template_type->value);
        $types = array_column($template->blocks, 'type');
        $this->assertSame('signature', end($types));
    }

    public function test_a_legacy_document_cannot_be_saved_as_a_template(): void
    {
        $tenant = $this->editorTenant();
        $manager = app(DocumentManager::class);
        $document = $manager->create($tenant['business'], $tenant['location'], $tenant['contact'], null, 'proposal', 'Legacy', $tenant['customer']->user);
        $manager->edit($document, ['content' => ['body' => 'Old classic terms.']]);

        try {
            $this->service()->saveFromDocument($document->refresh(), 'Nope', 'proposal', null, $tenant['customer']->user);
            $this->fail('a legacy document must be refused');
        } catch (DocumentTemplateRefusedException $e) {
            $this->assertStringContainsString('classic text editor', $e->getMessage());
        }
        $this->assertSame(0, DocumentTemplate::count());
    }

    public function test_a_document_with_nothing_written_yet_saves_as_an_empty_layout(): void
    {
        $tenant = $this->editorTenant();
        $document = $this->blankDocument($tenant);

        $template = $this->service()->saveFromDocument($document, 'Empty layout', 'proposal', null, $tenant['customer']->user);

        $this->assertSame([], $template->blocks);
    }

    public function test_save_as_template_validates_name_and_type(): void
    {
        $tenant = $this->editorTenant();
        $document = $this->richDocument($tenant);

        foreach ([['  ', 'proposal'], [str_repeat('x', 192), 'proposal'], ['Ok', 'invoice']] as [$name, $type]) {
            try {
                $this->service()->saveFromDocument($document, $name, $type, null, $tenant['customer']->user);
                $this->fail('should be refused');
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $this->assertSame(0, DocumentTemplate::count());
    }

    public function test_an_image_the_business_no_longer_owns_is_left_out_when_saving(): void
    {
        $tenant = $this->editorTenant();
        $image = $this->ownImage($tenant['business']);
        $document = $this->richDocument($tenant, $image->uid);
        DB::table('catalog_item_images')->where('id', $image->id)->delete();

        $template = $this->service()->saveFromDocument($document, 'No image', 'proposal', null, $tenant['customer']->user);

        $this->assertNotContains('image', array_column($template->blocks, 'type'));
        $this->assertSame(1, $this->service()->lastDroppedImages());
    }

    // ---- use a template -----------------------------------------------------------------------

    public function test_using_a_template_puts_the_layout_on_a_draft_without_lines_schedule_or_template_reference(): void
    {
        $tenant = $this->editorTenant();
        $source = $this->richDocument($tenant);
        $template = $this->service()->saveFromDocument($source, 'Layout', 'proposal', null, $tenant['customer']->user);
        $templateBefore = DB::table('document_templates')->where('id', $template->id)->first();

        $manager = app(DocumentManager::class);
        $target = $manager->create($tenant['business'], $tenant['location'], $tenant['contact'], null, 'proposal', 'For the next client', $tenant['customer']->user);
        $manager->saveBlocks($target, []);

        $used = $this->service()->instantiate($template, $target->refresh(), $tenant['customer']->user);

        $version = $used->versions()->where('state', 'draft')->firstOrFail();
        $this->assertSame($template->blocks, $version->content['blocks'], 'layout, order, styling, text and tokens arrive intact');
        $this->assertArrayNotHasKey('payment_plan', $version->content);
        $this->assertSame(0, $version->lineItems()->count(), 'the product area is a placeholder: no lines');
        $this->assertSame(0, $version->paymentScheduleItems()->count(), 'and no schedule');
        $this->assertSame(0, (int) $version->total_minor);
        $this->assertSame(DocumentStatus::Draft, $used->status);
        $this->assertSame($tenant['business']->id, $used->business_id, 'a Business-owned document');
        $this->assertSame($tenant['contact']->id, $used->contact_id, 'the chosen contact is kept, not inherited from the template');
        $this->assertNull($used->recipient_email_snapshot, 'no recipient came with the template');

        // No reference from the document back to the template, anywhere in its stored rows.
        $this->assertNotContains('document_template_id', array_keys((array) DB::table('business_documents')->where('id', $used->id)->first()));
        $this->assertStringNotContainsString($template->uid, json_encode(DB::table('business_documents')->where('id', $used->id)->first()) . json_encode(DB::table('business_document_versions')->where('business_document_id', $used->id)->get()));

        // The template was only read.
        $this->assertEquals($templateBefore, DB::table('document_templates')->where('id', $template->id)->first());
    }

    public function test_editing_the_new_document_never_mutates_the_source_template(): void
    {
        $tenant = $this->editorTenant();
        $template = $this->service()->saveFromDocument($this->richDocument($tenant), 'Layout', 'proposal', null, $tenant['customer']->user);
        $blocksBefore = $template->blocks;
        $lockBefore = $template->lock_version;

        $manager = app(DocumentManager::class);
        $target = $manager->create($tenant['business'], $tenant['location'], $tenant['contact'], null, 'proposal', 'Target', $tenant['customer']->user);
        $this->service()->instantiate($template, $target, $tenant['customer']->user);
        $manager->saveBlocks($target, [['id' => 'only', 'type' => 'text', 'data' => ['runs' => [['t' => 'Rewritten']]]]]);
        $manager->addCustomLine($target, 'Something', null, 1, 5000);

        $fresh = $template->fresh();
        $this->assertSame($blocksBefore, $fresh->blocks);
        $this->assertSame($lockBefore, $fresh->lock_version);
    }

    public function test_using_a_template_on_a_non_draft_or_legacy_document_is_refused(): void
    {
        $tenant = $this->editorTenant();
        $template = $this->service()->saveFromDocument($this->richDocument($tenant), 'Layout', 'proposal', null, $tenant['customer']->user);

        [$sent] = $this->sendAndCaptureToken($this->richDocument($tenant, null, 'To send'));
        $this->expectRefusal(fn () => $this->service()->instantiate($template, $sent, $tenant['customer']->user), 'draft document');

        $manager = app(DocumentManager::class);
        $legacy = $manager->create($tenant['business'], $tenant['location'], $tenant['contact'], null, 'proposal', 'Legacy', $tenant['customer']->user);
        $manager->edit($legacy, ['content' => ['body' => 'Old classic terms.']]);
        $this->expectRefusal(fn () => $this->service()->instantiate($template, $legacy->refresh(), $tenant['customer']->user), 'visual editor');
    }

    public function test_an_archived_template_cannot_be_used(): void
    {
        $tenant = $this->editorTenant();
        $template = $this->service()->createBlank($tenant['business'], 'Old', 'proposal', $tenant['customer']->user);
        $this->service()->archive($template, $tenant['business']);
        $manager = app(DocumentManager::class);
        $target = $manager->create($tenant['business'], $tenant['location'], $tenant['contact'], null, 'proposal', 'Target', $tenant['customer']->user);

        $this->expectRefusal(fn () => $this->service()->instantiate($template->refresh(), $target, $tenant['customer']->user), 'archived');
        $this->assertSame([], $target->refresh()->versions()->first()->content, 'nothing was written');
    }

    public function test_a_foreign_catalog_image_in_a_template_is_dropped_when_it_is_used(): void
    {
        $tenant = $this->editorTenant('Mine');
        $other = $this->sendableTenant('Theirs');
        $this->authenticateAs($tenant['customer']);
        $foreign = $this->ownImage($other['business'], 'Foreign Package');
        $own = $this->ownImage($tenant['business']);
        $template = $this->plantTemplate($tenant['business'], [
            ['id' => 'a', 'type' => 'text', 'data' => ['runs' => [['t' => 'Hello']]]],
            ['id' => 'f', 'type' => 'image', 'data' => ['catalog_image_uid' => $foreign->uid, 'alt' => '', 'width_pct' => 100]],
            ['id' => 'o', 'type' => 'image', 'data' => ['catalog_image_uid' => $own->uid, 'alt' => '', 'width_pct' => 100]],
        ]);
        $manager = app(DocumentManager::class);
        $target = $manager->create($tenant['business'], $tenant['location'], $tenant['contact'], null, 'proposal', 'Target', $tenant['customer']->user);

        $used = $this->service()->instantiate($template, $target, $tenant['customer']->user);

        $blocks = $used->versions()->first()->content['blocks'];
        $this->assertSame(['a', 'o'], array_column($blocks, 'id'), 'the foreign image is dropped, the owned one kept');
        $this->assertSame(1, $this->service()->lastDroppedImages());
        $this->assertStringNotContainsString($foreign->uid, json_encode($blocks));
    }

    public function test_a_template_with_a_foreign_image_cannot_be_saved_through_update(): void
    {
        $tenant = $this->editorTenant('Mine');
        $other = $this->sendableTenant('Theirs');
        $this->authenticateAs($tenant['customer']);
        $foreign = $this->ownImage($other['business'], 'Foreign Package');
        $template = $this->service()->createBlank($tenant['business'], 'T', 'proposal', $tenant['customer']->user);

        $this->expectException(InvalidDocumentBlocksException::class);
        $this->service()->update($template, ['blocks' => [['id' => 'f', 'type' => 'image', 'data' => ['catalog_image_uid' => $foreign->uid, 'alt' => '', 'width_pct' => 100]]]], 1, $tenant['business']);
    }

    // ---- own template CRUD --------------------------------------------------------------------

    public function test_create_blank_and_edit_with_lock_version(): void
    {
        $tenant = $this->editorTenant();
        $template = $this->service()->createBlank($tenant['business'], 'Blank one', 'contract', $tenant['customer']->user);
        $this->assertSame([], $template->blocks);
        $this->assertSame(1, $template->lock_version);
        $this->assertSame(DocumentTemplateStatus::Active, $template->status);

        $saved = $this->service()->update($template, [
            'name' => 'Renamed', 'description' => 'A description', 'template_type' => 'proposal',
            'blocks' => [['id' => 'h', 'type' => 'heading', 'data' => ['level' => 1, 'runs' => [['t' => 'Hello']]]]],
        ], 1, $tenant['business']);

        $this->assertSame(2, $saved->lock_version);
        $this->assertSame('Renamed', $saved->name);
        $this->assertSame('proposal', $saved->template_type->value);
        $this->assertSame('Hello', $saved->blocks[0]['data']['runs'][0]['t']);

        try {
            $this->service()->update($template, ['name' => 'Stale write'], 1, $tenant['business']);
            $this->fail('a stale lock_version must conflict');
        } catch (DocumentTemplateConflictException $e) {
            $this->assertSame(2, $e->currentLockVersion);
        }
        $this->assertSame('Renamed', $template->fresh()->name, 'the stale write changed nothing');
        $this->assertSame(2, $template->fresh()->lock_version);
    }

    public function test_update_rejects_invalid_blocks_without_bumping_the_version(): void
    {
        $tenant = $this->editorTenant();
        $template = $this->service()->createBlank($tenant['business'], 'T', 'proposal', $tenant['customer']->user);

        try {
            $this->service()->update($template, ['blocks' => [['id' => 'x', 'type' => 'script', 'data' => []]]], 1, $tenant['business']);
            $this->fail('unknown block type must be rejected');
        } catch (InvalidDocumentBlocksException) {
            $this->assertSame(1, $template->fresh()->lock_version);
        }
    }

    public function test_a_template_can_hold_payment_terms_as_presentation_only(): void
    {
        $tenant = $this->editorTenant();
        $template = $this->service()->createBlank($tenant['business'], 'T', 'proposal', $tenant['customer']->user);

        $saved = $this->service()->update($template, ['blocks' => [
            ['id' => 'p', 'type' => 'product_list', 'data' => ['show_description' => true, 'show_quantity' => false, 'price_minor' => 5000, 'lines' => [['name' => 'X']]]],
            ['id' => 't', 'type' => 'payment_terms', 'data' => []],
        ]], 1, $tenant['business']);

        $this->assertEquals(['show_description' => true, 'show_quantity' => false], $saved->blocks[0]['data'], 'money never survives BlockSchema');
        $this->assertSame('payment_terms', $saved->blocks[1]['type']);
    }

    public function test_duplicate_copies_blocks_into_a_new_business_template_named_copy_of(): void
    {
        $tenant = $this->editorTenant();
        $original = $this->service()->saveFromDocument($this->richDocument($tenant), 'Wedding layout', 'contract', 'Desc', $tenant['customer']->user);

        $copy = $this->service()->duplicate($original, $tenant['business'], $tenant['customer']->user);

        $this->assertNotSame($original->id, $copy->id);
        $this->assertNotSame($original->uid, $copy->uid);
        $this->assertSame('Copy of Wedding layout', $copy->name);
        $this->assertSame($original->blocks, $copy->blocks);
        $this->assertSame('contract', $copy->template_type->value);
        $this->assertSame('Desc', $copy->description);
        $this->assertSame($tenant['business']->id, $copy->business_id);
        $this->assertSame(DocumentTemplateStatus::Active, $copy->status);
        $this->assertSame(1, $copy->lock_version);
    }

    public function test_archive_hides_from_pickers_restore_brings_it_back_and_documents_are_unaffected(): void
    {
        $tenant = $this->editorTenant();
        $template = $this->service()->saveFromDocument($this->richDocument($tenant), 'Layout', 'proposal', null, $tenant['customer']->user);
        $manager = app(DocumentManager::class);
        $made = $manager->create($tenant['business'], $tenant['location'], $tenant['contact'], null, 'proposal', 'Made from it', $tenant['customer']->user);
        $this->service()->instantiate($template, $made, $tenant['customer']->user);
        $blocksOfMade = $made->refresh()->versions()->first()->content['blocks'];

        $this->service()->archive($template, $tenant['business']);
        $this->assertCount(0, $this->service()->listFor($tenant['business']), 'archived templates are hidden from the picker');
        $this->assertCount(1, $this->service()->listFor($tenant['business'], true));
        $this->assertSame($blocksOfMade, $made->refresh()->versions()->first()->content['blocks'], 'existing documents are unaffected');

        $this->service()->restore($template->refresh(), $tenant['business']);
        $this->assertCount(1, $this->service()->listFor($tenant['business']));
    }

    public function test_an_archived_template_cannot_be_edited_until_restored(): void
    {
        $tenant = $this->editorTenant();
        $template = $this->service()->createBlank($tenant['business'], 'T', 'proposal', $tenant['customer']->user);
        $this->service()->archive($template, $tenant['business']);

        $this->expectRefusal(fn () => $this->service()->update($template->refresh(), ['name' => 'X'], 1, $tenant['business']), 'archived');
    }

    // ---- access: tenancy and platform templates -----------------------------------------------

    public function test_business_a_cannot_edit_duplicate_archive_restore_or_use_business_bs_template(): void
    {
        $a = $this->editorTenant('Alpha Co');
        $b = $this->sendableTenant('Beta Co');
        $this->authenticateAs($a['customer']);
        $theirs = $this->service()->createBlank($b['business'], 'Beta secret', 'proposal', $b['customer']->user);
        $manager = app(DocumentManager::class);
        $target = $manager->create($a['business'], $a['location'], $a['contact'], null, 'proposal', 'Target', $a['customer']->user);

        foreach ([
            fn () => $this->service()->update($theirs, ['name' => 'Hijack'], 1, $a['business']),
            fn () => $this->service()->duplicate($theirs, $a['business'], $a['customer']->user),
            fn () => $this->service()->archive($theirs, $a['business']),
            fn () => $this->service()->restore($theirs, $a['business']),
            fn () => $this->service()->instantiate($theirs, $target, $a['customer']->user),
            fn () => $this->service()->access()->owned($a['business'], $theirs->uid),
            fn () => $this->service()->access()->readable($a['business'], $theirs->uid),
            fn () => $this->service()->access()->usable($a['business'], $theirs->uid),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('a foreign template must be untouchable');
            } catch (ModelNotFoundException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame('Beta secret', $theirs->fresh()->name);
        $this->assertSame(1, $theirs->fresh()->lock_version);
        $this->assertSame(DocumentTemplateStatus::Active, $theirs->fresh()->status);
        $this->assertSame([], $target->refresh()->versions()->first()->content, 'nothing leaked onto the document');
    }

    public function test_a_platform_template_is_never_writable_by_a_business(): void
    {
        $tenant = $this->editorTenant();
        $platform = $this->platformTemplate([['id' => 'p', 'type' => 'text', 'data' => ['runs' => [['t' => 'Platform']]]]]);
        $this->recommend($platform); // even when recommended, a Business may only USE it

        foreach ([
            fn () => $this->service()->update($platform, ['name' => 'Mine now'], 1, $tenant['business']),
            fn () => $this->service()->duplicate($platform, $tenant['business'], $tenant['customer']->user),
            fn () => $this->service()->archive($platform, $tenant['business']),
            fn () => $this->service()->restore($platform, $tenant['business']),
            fn () => $this->service()->access()->owned($tenant['business'], $platform->uid),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('a platform template is not writable through the Business path');
            } catch (ModelNotFoundException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame('Platform template', $platform->fresh()->name);
    }

    public function test_the_platform_path_cannot_edit_a_business_template(): void
    {
        $tenant = $this->editorTenant();
        $template = $this->service()->createBlank($tenant['business'], 'T', 'proposal', $tenant['customer']->user);

        $this->expectException(ModelNotFoundException::class);
        $this->service()->update($template, ['name' => 'X'], 1, null);
    }

    public function test_an_unrecommended_platform_template_cannot_be_read_or_used_and_the_default_recommendation_is_empty(): void
    {
        $tenant = $this->editorTenant();
        $platform = $this->platformTemplate([['id' => 'p', 'type' => 'text', 'data' => ['runs' => [['t' => 'Platform']]]]]);
        $manager = app(DocumentManager::class);
        $target = $manager->create($tenant['business'], $tenant['location'], $tenant['contact'], null, 'proposal', 'Target', $tenant['customer']->user);

        $this->assertCount(0, $this->service()->recommendedFor($tenant['business']), 'the seam returns nothing until the niche stage binds it');
        $this->expectRefusalNotFound(fn () => $this->service()->access()->readable($tenant['business'], $platform->uid));
        $this->expectRefusalNotFound(fn () => $this->service()->access()->usable($tenant['business'], $platform->uid));
        $this->expectRefusalNotFound(fn () => $this->service()->instantiate($platform, $target, $tenant['customer']->user));
        $this->assertSame([], $target->refresh()->versions()->first()->content);
    }

    public function test_a_recommended_platform_template_can_be_used_and_its_images_are_never_trusted(): void
    {
        $tenant = $this->editorTenant();
        $stray = $this->ownImage($tenant['business']);
        $platform = $this->platformTemplate([
            ['id' => 'p', 'type' => 'heading', 'data' => ['level' => 1, 'runs' => [['t' => 'Platform layout']]]],
            ['id' => 'i', 'type' => 'image', 'data' => ['catalog_image_uid' => $stray->uid, 'alt' => '', 'width_pct' => 100]],
            ['id' => 's', 'type' => 'signature', 'data' => ['label' => 'Signature']],
        ]);
        $this->recommend($platform);
        $manager = app(DocumentManager::class);
        $target = $manager->create($tenant['business'], $tenant['location'], $tenant['contact'], null, 'proposal', 'Target', $tenant['customer']->user);

        $used = $this->service()->instantiate($platform, $target, $tenant['customer']->user);

        $this->assertSame(['p', 's'], array_column($used->versions()->first()->content['blocks'], 'id'), 'no image from a platform template, ever (17B §6)');
        $this->assertSame($tenant['business']->id, $used->business_id);
    }

    public function test_a_draft_status_platform_template_is_not_usable_even_when_recommended(): void
    {
        $tenant = $this->editorTenant();
        $platform = $this->platformTemplate([]);
        DB::table('document_templates')->where('id', $platform->id)->update(['status' => 'draft']);
        $this->recommend($platform->refresh());

        $this->expectRefusal(fn () => $this->service()->access()->usable($tenant['business'], $platform->uid), 'not available');
    }

    // ---- helpers ------------------------------------------------------------------------------

    private function expectRefusal(callable $action, string $messagePart): void
    {
        try {
            $action();
            $this->fail('expected a refusal');
        } catch (DocumentTemplateRefusedException $e) {
            $this->assertStringContainsString($messagePart, $e->getMessage());
        }
    }

    private function expectRefusalNotFound(callable $action): void
    {
        try {
            $action();
            $this->fail('expected a 404-style refusal');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }
    }
}

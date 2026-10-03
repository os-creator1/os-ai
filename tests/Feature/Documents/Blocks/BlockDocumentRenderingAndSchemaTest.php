<?php

namespace Tests\Feature\Documents\Blocks;

use App\Enums\Documents\DocumentTemplateStatus;
use App\Enums\Documents\DocumentTemplateType;
use App\Library\Documents\Blocks\BlockSchema;
use App\Library\Documents\DocumentContentHasher;
use App\Library\Documents\DocumentManager;
use App\Models\Business;
use App\Models\BusinessDocumentVersion;
use App\Models\DocumentTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Documents\Concerns\SendsDocuments;
use Tests\TestCase;

/**
 * Contract 17B stages 1-2 — the new tables/columns, the frozen merge context,
 * and the block rendering of the public and Business pages (with the legacy
 * path proven unchanged).
 */
class BlockDocumentRenderingAndSchemaTest extends TestCase
{
    use RefreshDatabase;
    use SendsDocuments;

    /** @return array<int, array<string, mixed>> */
    private function blockContent(): array
    {
        return BlockSchema::document([
            ['type' => 'heading', 'data' => ['level' => 1, 'runs' => [['t' => 'Proposal for '], ['merge' => 'contact.first_name']]]],
            ['type' => 'text', 'data' => ['runs' => [['t' => 'From '], ['merge' => 'business.name'], ['t' => ' <script>alert(1)</script>']]]],
            ['type' => 'product_list', 'data' => []],
            ['type' => 'payment_terms', 'data' => []],
            ['type' => 'page_break'],
            ['type' => 'signature', 'data' => ['label' => 'Client signature']],
        ]);
    }

    /** @return array{0: array, 1: \App\Models\BusinessDocument, 2: string} */
    private function sentBlockDocument(): array
    {
        $tenant = $this->sendableTenant('Harbor Lane Studios');
        $document = $this->draftDocument($tenant);
        app(DocumentManager::class)->edit($document, ['content' => $this->blockContent()]);
        [$document, $token] = $this->sendAndCaptureToken($document->refresh());

        return [$tenant, $document, $token];
    }

    // ---- schema ---------------------------------------------------------

    public function test_document_templates_table_has_the_contracted_shape(): void
    {
        $this->assertTrue(Schema::hasTable('document_templates'));

        foreach (['uid', 'business_id', 'template_type', 'name', 'description', 'blocks', 'schema_version', 'status', 'lock_version', 'created_by_user_id', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('document_templates', $column), $column);
        }

        $columns = collect(DB::select('SHOW COLUMNS FROM document_templates'))->keyBy('Field');
        $this->assertSame('YES', $columns['business_id']->Null, 'platform templates have no Business');
        $this->assertSame('NO', $columns['blocks']->Null);
        $this->assertSame('1', (string) $columns['lock_version']->Default);
        $this->assertStringContainsString('unsigned', $columns['lock_version']->Type);
        $this->assertSame('draft', $columns['status']->Default);

        $indexes = collect(DB::select('SHOW INDEX FROM document_templates'));
        $this->assertTrue($indexes->where('Key_name', 'document_templates_business_status_index')->isNotEmpty());
        $this->assertSame(0, (int) $indexes->where('Column_name', 'uid')->first()->Non_unique, 'uid is unique');
    }

    public function test_template_model_casts_and_enums_and_business_cascade(): void
    {
        $tenant = $this->sendableTenant();

        $platform = DocumentTemplate::create(['template_type' => DocumentTemplateType::Contract, 'name' => 'Platform', 'blocks' => [], 'schema_version' => 2]);
        $private = DocumentTemplate::create(['business_id' => $tenant['business']->id, 'template_type' => 'proposal', 'name' => 'Mine', 'blocks' => $this->blockContent()['blocks'], 'schema_version' => 2]);

        $platform = $platform->fresh();
        $private = $private->fresh();

        $this->assertTrue(Str::isUuid($platform->uid));
        $this->assertTrue($platform->isPlatformOwned());
        $this->assertFalse($private->isPlatformOwned());
        $this->assertSame(DocumentTemplateType::Contract, $platform->template_type);
        $this->assertSame(DocumentTemplateStatus::Draft, $private->status, 'database default');
        $this->assertSame(1, $private->lock_version);
        $this->assertIsArray($private->blocks);

        DB::table('businesses')->where('id', $tenant['business']->id)->update(['updated_at' => now()]);
        $this->assertSame(1, DocumentTemplate::where('business_id', $tenant['business']->id)->count());
    }

    public function test_version_lock_version_and_sms_delivery_columns_exist_with_safe_defaults(): void
    {
        $tenant = $this->sendableTenant();
        $document = $this->draftDocument($tenant);
        $version = BusinessDocumentVersion::where('business_document_id', $document->id)->sole();

        $this->assertSame(1, $version->lock_version);
        $this->assertNull($document->sms_link_delivered_at);
        $this->assertNull($document->sms_link_delivery_failed_at);

        $document->sms_link_delivered_at = now();
        $document->save();
        $this->assertNotNull($document->fresh()->sms_link_delivered_at);

        $row = collect(DB::select('SHOW COLUMNS FROM business_document_versions'))->keyBy('Field');
        $this->assertStringContainsString('unsigned', $row['lock_version']->Type);
        $this->assertSame('NO', $row['lock_version']->Null);
    }

    public function test_resending_clears_the_sms_markers_with_the_email_markers(): void
    {
        [, $document] = $this->sentBlockDocument();
        $document->forceFill(['sms_link_delivered_at' => now(), 'sms_link_delivery_failed_at' => now()])->save();

        $resent = app(DocumentManager::class)->resendLink($document->refresh());

        $this->assertNull($resent->sms_link_delivered_at);
        $this->assertNull($resent->sms_link_delivery_failed_at);
    }

    // ---- frozen merge context -------------------------------------------

    public function test_send_freezes_the_merge_values_into_the_hashed_parties(): void
    {
        [$tenant, $document] = $this->sentBlockDocument();
        $version = BusinessDocumentVersion::findOrFail($document->current_version_id);
        $parties = $version->content['parties'];

        // Existing keys unchanged, new keys additive.
        $this->assertSame('Harbor Lane Studios', $parties['business_name']);
        $this->assertSame('Kitchen renovation proposal', $parties['document_title']);
        $this->assertSame('Pat Rivera', $parties['recipient_name']);
        $this->assertSame('Pat Rivera', $parties['merge']['contact.full_name']);
        $this->assertSame('Pat', $parties['merge']['contact.first_name']);
        $this->assertSame('client@example.test', $parties['merge']['contact.email']);
        $this->assertSame('Harbor Lane Studios', $parties['merge']['business.name']);
        $this->assertSame('Kitchen renovation proposal', $parties['merge']['document.title']);

        // The hash covers it: change one frozen value and the hash moves.
        $hasher = app(DocumentContentHasher::class);
        $this->assertSame($hasher->hash($version), $version->content_hash);

        $tampered = $version->replicate();
        $tampered->setRelation('lineItems', $version->lineItems);
        $tampered->setRelation('paymentScheduleItems', $version->paymentScheduleItems);
        $content = $version->content;
        $content['parties']['merge']['business.name'] = 'Someone Else Ltd';
        $tampered->content = $content;
        $tampered->version_number = $version->version_number;
        $tampered->uid = $version->uid;

        $this->assertNotSame($version->content_hash, $hasher->hash($tampered), 'frozen merge values are inside the hashed content');
    }

    public function test_issued_rendering_ignores_later_live_business_and_contact_edits(): void
    {
        [$tenant, $document, $token] = $this->sentBlockDocument();

        DB::table('businesses')->where('id', $tenant['business']->id)->update(['name' => 'Renamed After Send LLC']);
        DB::table('business_documents')->where('id', $document->id)->update(['title' => 'Edited Title After Send', 'recipient_name_snapshot' => 'Changed Name']);

        $html = $this->get($this->publicUrl($document, $token))->assertOk()->getContent();

        $this->assertStringContainsString('Proposal for Pat', $html);
        $this->assertStringContainsString('From Harbor Lane Studios', $html);
        $this->assertStringNotContainsString('Renamed After Send LLC', $html);
        $this->assertStringNotContainsString('Changed Name', $html);
    }

    // ---- public + Business rendering ------------------------------------

    public function test_public_page_renders_blocks_escaped_with_the_sign_form_at_the_signature_block(): void
    {
        [, $document, $token] = $this->sentBlockDocument();

        $html = $this->get($this->publicUrl($document, $token))->assertOk()->getContent();
        $version = BusinessDocumentVersion::findOrFail($document->current_version_id);

        $this->assertStringContainsString('data-role="document-blocks"', $html);
        $this->assertStringContainsString('data-mode="public"', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);

        // Canonical lines and schedule come from the version, money formatted.
        $this->assertStringContainsString('Design work', $html);
        $this->assertStringContainsString('500.00', $html);
        $this->assertStringNotContainsString('50000', $html, 'minor units are never printed');

        // Page break is CSS-only in public.
        $this->assertStringContainsString('class="doc-page-break"', $html);

        // The sign form is functionally identical and sits inside the signature slot.
        $this->assertStringContainsString('data-role="sign-form"', $html);
        $this->assertSame(1, substr_count($html, 'data-role="sign-form"'));
        $this->assertStringContainsString('name="displayed_version_uid" value="' . $version->uid . '"', $html);
        $this->assertStringContainsString('action="' . $this->signUrl($document, $token) . '"', $html);
        foreach (['signer_name', 'signer_email', 'typed_name'] as $field) {
            $this->assertStringContainsString('name="' . $field . '"', $html);
        }
        $this->assertGreaterThan(strpos($html, 'data-role="document-blocks"'), strpos($html, 'data-role="sign-form"'));
        $this->assertStringNotContainsString('data-role="signature-placeholder"', $html);
    }

    public function test_a_block_document_can_still_be_signed_through_the_unchanged_route(): void
    {
        [, $document, $token] = $this->sentBlockDocument();
        $version = BusinessDocumentVersion::findOrFail($document->current_version_id);

        $this->post($this->signUrl($document, $token), [
            'displayed_version_uid' => $version->uid,
            'signer_name' => 'Pat Rivera',
            'signer_email' => 'client@example.test',
            'typed_name' => 'Pat Rivera',
        ])->assertOk()->assertSee('Signature recorded');

        $this->assertSame(1, $document->signature()->count());
        $html = $this->get($this->publicUrl($document, $token))->getContent();
        $this->assertStringNotContainsString('data-role="sign-form"', $html);
    }

    public function test_sign_form_follows_the_content_when_a_block_version_has_no_signature_block(): void
    {
        $tenant = $this->sendableTenant();
        $document = $this->draftDocument($tenant);
        app(DocumentManager::class)->edit($document, ['content' => BlockSchema::document([['type' => 'text', 'data' => ['runs' => [['t' => 'Hello']]]]])]);
        [$document, $token] = $this->sendAndCaptureToken($document->refresh());

        $html = $this->get($this->publicUrl($document, $token))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-role="sign-form"'));
        $this->assertGreaterThan(strpos($html, 'data-role="document-blocks"'), strpos($html, 'data-role="sign-form"'));
    }

    public function test_legacy_version_still_renders_through_the_original_markup(): void
    {
        $tenant = $this->sendableTenant('Harbor Lane Studios');
        [$document, $token] = $this->sendAndCaptureToken($this->draftDocument($tenant));
        $version = BusinessDocumentVersion::findOrFail($document->current_version_id);

        $this->assertArrayNotHasKey('blocks', $version->content);

        $html = $this->get($this->publicUrl($document, $token))->assertOk()->getContent();

        $this->assertStringNotContainsString('document-blocks', $html);
        $this->assertStringNotContainsString('doc-blocks', $html);

        $normalise = function (string $page) use ($document, $token, $version): string {
            $page = str_replace([$document->uid, $token, $version->uid], ['UID', 'TOKEN', 'VUID'], $page);
            $page = preg_replace('/name="_token" value="[^"]*"/', 'name="_token" value="X"', $page);
            $page = preg_replace('/\s+/', ' ', $page);

            return trim((string) preg_replace('/>\s+</', '><', $page));
        };

        $fixture = (string) file_get_contents(__DIR__ . '/fixtures/legacy_public_show.html');

        $this->assertSame($normalise($fixture), $normalise($html), 'the legacy public page markup is byte-for-byte (modulo whitespace) what it was before 17B');
    }

    public function test_business_show_renders_the_frozen_blocks_read_only_for_a_block_version(): void
    {
        [$tenant, $document] = $this->sentBlockDocument();

        DB::table('businesses')->where('id', $tenant['business']->id)->update(['name' => 'Renamed After Send LLC']);

        $this->allowPublicEntitlement();
        $this->authenticateAs($tenant['customer']);

        $this->get(route('customer.workspaces.businesses.documents.show', [$tenant['workspace']->uid, $tenant['business']->uid, $document->uid]))
            ->assertOk()
            ->assertSee('data-role="issued-blocks"', false)
            ->assertSee('From Harbor Lane Studios')
            ->assertDontSee('From Renamed After Send LLC');
    }
}

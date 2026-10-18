<?php

namespace Tests\Feature\CustomFields;

use App\Library\CustomFields\CustomFieldDefinitionManager;
use App\Library\CustomFields\CustomFieldValueService;
use App\Library\Documents\DocumentContentHasher;
use App\Library\Documents\DocumentManager;
use App\Models\BusinessDocumentVersion;
use App\Models\ContactGroupFields;
use App\Models\ContactsCustomField;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Documents\Concerns\SendsDocuments;
use Tests\TestCase;

/**
 * Proposals / Contracts use the SAME merge engine: tokens in the title and body
 * are resolved at send, and the resolved text is what is frozen and hashed.
 */
class DocumentMergeFieldsTest extends TestCase
{
    use RefreshDatabase;
    use SendsDocuments;

    private function setIdentity(array $tenant, array $identity): void
    {
        foreach ($identity as $tag => $value) {
            $field = ContactGroupFields::query()->firstOrCreate(
                ['contact_group_id' => $tenant['contact']->group_id, 'tag' => $tag],
                ['label' => $tag, 'type' => 'text', 'visible' => true, 'required' => false],
            );
            ContactsCustomField::create(['contact_id' => $tenant['contact']->id, 'field_id' => $field->id, 'value' => $value]);
        }
    }

    public function test_title_and_body_merge_at_send_and_the_resolved_text_is_frozen_and_hashed(): void
    {
        $tenant = $this->sendableTenant();
        $this->setIdentity($tenant, ['FIRST_NAME' => 'Pat', 'LAST_NAME' => 'Rivera']);
        $field = app(CustomFieldDefinitionManager::class)->create($tenant['business'], 'Event Date', 'date');
        app(CustomFieldValueService::class)->set($tenant['business'], $tenant['contact'], $field, '2027-06-14');

        $document = $this->draftDocument($tenant, ['title' => 'Photo Booth Proposal for {{contact.full_name}}']);
        app(DocumentManager::class)->edit($document, ['content' => ['body' => "Event date: {{contact.event_date}}\nVenue: [{{contact.venue}}]\nFrom {{business.name}}"]]);

        // Before send the draft keeps the tokens (they are the author's source text).
        $this->assertSame('Photo Booth Proposal for {{contact.full_name}}', $document->refresh()->title);

        [$document] = $this->sendAndCaptureToken($document->refresh());
        $version = BusinessDocumentVersion::findOrFail($document->current_version_id);

        $this->assertSame('Photo Booth Proposal for Pat Rivera', $document->title);
        $this->assertSame("Event date: 14 Jun 2027\nVenue: []\nFrom " . $tenant['business']->name, $version->content['body']);
        $this->assertStringNotContainsString('{{', json_encode($version->content));
        $this->assertSame(app(DocumentContentHasher::class)->hash($version), $version->content_hash);
    }

    public function test_a_signed_document_never_re_resolves_against_later_contact_edits(): void
    {
        $tenant = $this->sendableTenant();
        $field = app(CustomFieldDefinitionManager::class)->create($tenant['business'], 'Event Date', 'date');
        $values = app(CustomFieldValueService::class);
        $values->set($tenant['business'], $tenant['contact'], $field, '2027-06-14');
        $document = $this->draftDocument($tenant);
        app(DocumentManager::class)->edit($document, ['content' => ['body' => 'Event date: {{contact.event_date}}']]);

        [$document] = $this->sendAndCaptureToken($document->refresh());
        $values->set($tenant['business'], $tenant['contact'], $field, '2030-01-01');

        $version = BusinessDocumentVersion::findOrFail($document->current_version_id)->fresh();
        $this->assertSame('Event date: 14 Jun 2027', $version->content['body']);
        $this->assertSame(app(DocumentContentHasher::class)->hash($version), $version->content_hash);
    }

    public function test_a_title_that_resolves_to_nothing_falls_back_to_the_document_kind_and_plain_text_is_untouched(): void
    {
        $tenant = $this->sendableTenant();
        $document = $this->draftDocument($tenant, ['title' => '{{contact.venue}}']);

        [$document] = $this->sendAndCaptureToken($document);

        $this->assertSame('Proposal', $document->title);

        $plain = $this->draftDocument($tenant, ['title' => 'Kitchen renovation proposal']);
        [$plain] = $this->sendAndCaptureToken($plain);
        $this->assertSame('Kitchen renovation proposal', $plain->title);
    }

    public function test_the_document_editor_offers_the_shared_picker_with_the_documents_own_groups(): void
    {
        $tenant = $this->sendableTenant();
        app(CustomFieldDefinitionManager::class)->create($tenant['business'], 'Event Date', 'date');
        $document = $this->draftDocument($tenant);
        $this->authenticateAs($tenant['customer']);

        $html = $this->get(route('customer.workspaces.businesses.documents.show', [$tenant['workspace']->uid, $tenant['business']->uid, $document->uid]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('data-merge-picker', $html);
        $this->assertStringContainsString('data-merge-insert="{{contact.event_date}}"', $html);
        $this->assertStringContainsString('data-merge-insert="{{business.name}}"', $html);
        $this->assertStringNotContainsString('data-merge-insert="{{appointment.', $html, 'No appointment context here, so no appointment group.');
        $this->assertStringNotContainsString('data-merge-insert="{{opportunity.', $html, 'This document has no opportunity.');
    }
}

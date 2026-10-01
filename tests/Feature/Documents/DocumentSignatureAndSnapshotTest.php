<?php

namespace Tests\Feature\Documents;

use App\Events\DocumentSigned;
use App\Library\Catalog\CatalogItemLocationOverrideManager;
use App\Library\Catalog\CatalogItemManager;
use App\Library\Documents\DocumentContentHasher;
use App\Library\Documents\DocumentManager;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentLineItem;
use App\Models\BusinessDocumentSignature;
use App\Models\BusinessDocumentVersion;
use App\Models\CatalogItem;
use App\Models\PackageSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Documents\Concerns\SendsDocuments;
use Tests\Support\Documents\ShownVersion;
use Tests\TestCase;

/**
 * Implementation Contract 17 §5.3.1 / §5.5 / §6.5 — what was shown is what is
 * signed, the signed record never moves, and no later Catalog or Business
 * change reaches back into it.
 */
class DocumentSignatureAndSnapshotTest extends TestCase
{
    use RefreshDatabase;
    use SendsDocuments;

    private function evidence(BusinessDocument $document, array $overrides = []): array
    {
        return array_merge([
            'displayed_version_uid' => ShownVersion::uid($document),
            'signer_name' => 'Pat Rivera', 'signer_email' => 'pat@example.test', 'typed_name' => 'Pat Rivera',
            'ip_address' => '203.0.113.7', 'user_agent' => 'Mozilla/5.0 (Test)',
        ], $overrides);
    }

    /** A catalog-backed draft: one packaged line x2, full-payment schedule, recipient. */
    private function catalogDocument(array $tenant, ?CatalogItem &$item = null, string $body = 'Terms apply.'): BusinessDocument
    {
        $manager = app(DocumentManager::class);
        $actor = $tenant['customer']->user;
        $item = app(CatalogItemManager::class)->create($tenant['business'], [
            'type' => 'package', 'name' => 'Full Garden Package', 'price_minor' => 50000, 'currency_code' => 'USD',
        ]);
        $document = $manager->create($tenant['business'], $tenant['location'], $tenant['contact'], null, 'proposal', 'Garden proposal', $actor);
        $manager->addCatalogLine($document, $item, 2, $actor);
        $manager->setSchedule($document, [['kind' => 'full', 'amount_minor' => 100000, 'currency_code' => 'USD']]);
        $manager->edit($document, [
            'recipient_email_snapshot' => 'client@example.test', 'recipient_name_snapshot' => 'Pat Rivera',
            'content' => ['body' => $body],
        ]);

        return $document->refresh();
    }

    // -----------------------------------------------------------------
    // Catalog mutation never reaches an issued or signed document
    // -----------------------------------------------------------------

    public function test_catalog_edits_overrides_and_archiving_after_send_never_change_the_issued_line(): void
    {
        $tenant = $this->sendableTenant();
        $document = $this->catalogDocument($tenant, $item);
        [$document, $token] = $this->sendAndCaptureToken($document);
        $this->allowPublicEntitlement();

        $version = BusinessDocumentVersion::findOrFail($document->current_version_id);
        $hashBefore = $version->content_hash;
        $line = $version->lineItems()->firstOrFail();
        $snapshotBefore = PackageSnapshot::where('uid', $line->package_snapshot_uid)->firstOrFail()->only(['name_at_snapshot', 'price_minor_at_snapshot', 'currency_code_at_snapshot']);

        // Rename, reprice, override at the Location, then archive.
        $catalog = app(CatalogItemManager::class);
        $catalog->update($tenant['business'], $item, ['name' => 'Renamed Package', 'price_minor' => 99900, 'currency_code' => 'USD']);
        app(CatalogItemLocationOverrideManager::class)->setPriceOverride($tenant['business'], $item->fresh(), $tenant['location'], 77700);
        $catalog->archive($tenant['business'], $item->fresh());

        $line = $line->fresh();
        $this->assertSame('Full Garden Package', $line->name);
        $this->assertSame(50000, (int) $line->unit_price_minor);
        $this->assertSame(100000, (int) $line->line_total_minor);
        $this->assertEquals($snapshotBefore, PackageSnapshot::where('uid', $line->package_snapshot_uid)->firstOrFail()->only(['name_at_snapshot', 'price_minor_at_snapshot', 'currency_code_at_snapshot']));
        $this->assertSame($hashBefore, $version->fresh()->content_hash);
        $this->assertSame($hashBefore, app(DocumentContentHasher::class)->hash($version->fresh()), 'The frozen hash still matches the stored rows.');

        $this->get($this->publicUrl($document, $token))
            ->assertOk()
            ->assertSee('Full Garden Package')
            ->assertSee('500.00')
            ->assertDontSee('Renamed Package')
            ->assertDontSee('999.00')
            ->assertDontSee('777.00');
    }

    public function test_catalog_changes_after_signing_leave_the_signed_record_and_its_hash_untouched(): void
    {
        $tenant = $this->sendableTenant();
        $document = $this->catalogDocument($tenant, $item);
        [$document, $token] = $this->sendAndCaptureToken($document);
        $signature = app(DocumentManager::class)->sign($document->refresh(), $this->evidence($document));

        app(CatalogItemManager::class)->update($tenant['business'], $item, ['name' => 'Renamed Package', 'price_minor' => 1, 'currency_code' => 'USD']);
        app(CatalogItemManager::class)->archive($tenant['business'], $item->fresh());

        $version = BusinessDocumentVersion::findOrFail($signature->business_document_version_id);
        $this->assertSame($signature->signed_content_hash, $version->fresh()->content_hash);
        $this->assertSame($signature->signed_content_hash, app(DocumentContentHasher::class)->hash($version->fresh()));
        $this->assertSame('Full Garden Package', $version->lineItems()->firstOrFail()->name);
    }

    // -----------------------------------------------------------------
    // Historical party names are frozen into the issued content
    // -----------------------------------------------------------------

    public function test_the_party_names_are_frozen_at_send_and_survive_a_later_rename(): void
    {
        $tenant = $this->sendableTenant('Harbor Lane Studios');
        $this->allowPublicEntitlement();
        [$document, $token] = $this->sendAndCaptureToken($this->catalogDocument($tenant));

        $version = BusinessDocumentVersion::findOrFail($document->current_version_id);
        $this->assertSame('Harbor Lane Studios', $version->content['parties']['business_name']);
        $this->assertSame('Main', $version->content['parties']['business_location_name']);
        $this->assertSame('Garden proposal', $version->content['parties']['document_title']);
        // The frozen names are part of what the hash covers.
        $this->assertSame($version->content_hash, app(DocumentContentHasher::class)->hash($version));

        DB::table('businesses')->where('id', $tenant['business']->id)->update(['name' => 'Totally Different Name']);
        DB::table('business_locations')->where('id', $tenant['location']->id)->update(['name' => 'Moved Branch']);

        $this->get($this->publicUrl($document, $token))
            ->assertOk()
            ->assertSee('Harbor Lane Studios')
            ->assertSee('Main')
            ->assertDontSee('Totally Different Name')
            ->assertDontSee('Moved Branch');

        $this->post($this->signUrl($document, $token), [
            'displayed_version_uid' => ShownVersion::uid($document),
            'signer_name' => 'Pat Rivera', 'signer_email' => 'pat@example.test', 'typed_name' => 'Pat Rivera',
        ])->assertOk()->assertSee('Harbor Lane Studios')->assertDontSee('Totally Different Name');
    }

    public function test_a_browser_supplied_parties_block_in_the_draft_is_overwritten_at_send(): void
    {
        $tenant = $this->sendableTenant('Harbor Lane Studios');
        $document = $this->catalogDocument($tenant);
        app(DocumentManager::class)->edit($document, ['content' => ['body' => 'Terms', 'parties' => ['business_name' => 'Forged Corp']]]);

        [$document] = $this->sendAndCaptureToken($document->refresh());

        $content = BusinessDocumentVersion::findOrFail($document->current_version_id)->content;
        $this->assertSame('Harbor Lane Studios', $content['parties']['business_name']);
    }

    public function test_the_public_page_renders_the_frozen_terms_escaped(): void
    {
        $tenant = $this->sendableTenant();
        $this->allowPublicEntitlement();
        [$document, $token] = $this->sendAndCaptureToken($this->catalogDocument($tenant, $item, "Net 30.\n<script>alert(1)</script>"));

        $response = $this->get($this->publicUrl($document, $token))->assertOk();

        $response->assertSee('Net 30.', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_the_public_page_leaks_no_tenant_metadata(): void
    {
        $tenant = $this->sendableTenant();
        $this->allowPublicEntitlement();
        [$document, $token] = $this->sendAndCaptureToken($this->catalogDocument($tenant));

        $html = $this->get($this->publicUrl($document, $token))->assertOk()->getContent();

        foreach ([
            $tenant['workspace']->uid, $tenant['business']->uid, $tenant['location']->uid, $tenant['contact']->uid,
            (string) $tenant['customer']->user->email,
        ] as $internal) {
            $this->assertStringNotContainsString((string) $internal, $html, 'The public page must not expose internal identifiers.');
        }

        // The only identifier it may carry is the document's own opaque uid /
        // version uid, and the token the visitor already holds.
        $this->assertDoesNotMatchRegularExpression('/data-(business|workspace|location|contact)-id/i', $html);
    }

    // -----------------------------------------------------------------
    // The signature binds the version that was DISPLAYED
    // -----------------------------------------------------------------

    public function test_a_signature_on_a_version_the_signer_was_not_shown_is_refused(): void
    {
        $tenant = $this->sendableTenant();
        $this->allowPublicEntitlement();
        [$document, $token] = $this->sendAndCaptureToken($this->catalogDocument($tenant));
        $shownVersionUid = ShownVersion::uid($document);

        // The signer opens version 1 ... the owner revises and re-sends ...
        $manager = app(DocumentManager::class);
        $manager->revise($document->refresh(), $tenant['customer']->user);
        $manager->edit($document->refresh(), ['content' => ['body' => 'Different terms now.']]);
        [$document, $newToken] = $this->sendAndCaptureToken($document->refresh());
        $this->assertNotSame($shownVersionUid, ShownVersion::uid($document));

        // ... and submits the OLD page against the NEW link.
        $this->post($this->signUrl($document, $newToken), [
            'displayed_version_uid' => $shownVersionUid,
            'signer_name' => 'Pat Rivera', 'signer_email' => 'pat@example.test', 'typed_name' => 'Pat Rivera',
        ])->assertStatus(422)->assertSee('updated after you opened it')->assertSee('Different terms now.');

        $this->assertSame(0, BusinessDocumentSignature::query()->count());
        $this->assertSame('sent', $document->fresh()->status->value);

        // The manager refuses it too, whoever calls it.
        try {
            $manager->sign($document->refresh(), $this->evidence($document, ['displayed_version_uid' => $shownVersionUid]));
            $this->fail('The manager must bind the signature to the displayed version.');
        } catch (ValidationException) {
            // expected
        }

        // Signing what is actually current works.
        $this->post($this->signUrl($document, $newToken), [
            'displayed_version_uid' => ShownVersion::uid($document),
            'signer_name' => 'Pat Rivera', 'signer_email' => 'pat@example.test', 'typed_name' => 'Pat Rivera',
        ])->assertOk()->assertSee('Signature recorded');

        $signature = BusinessDocumentSignature::query()->sole();
        $this->assertSame((int) $document->fresh()->current_version_id, (int) $signature->business_document_version_id);
    }

    public function test_the_sign_form_carries_the_displayed_version_and_a_post_without_it_is_refused(): void
    {
        $tenant = $this->sendableTenant();
        $this->allowPublicEntitlement();
        [$document, $token] = $this->sendAndCaptureToken($this->catalogDocument($tenant));

        $this->get($this->publicUrl($document, $token))
            ->assertOk()
            ->assertSee('name="displayed_version_uid" value="' . ShownVersion::uid($document) . '"', false);

        $this->post($this->signUrl($document, $token), [
            'signer_name' => 'Pat Rivera', 'signer_email' => 'pat@example.test', 'typed_name' => 'Pat Rivera',
        ])->assertStatus(422);

        $this->assertSame(0, BusinessDocumentSignature::query()->count());
    }

    // -----------------------------------------------------------------
    // Idempotent signing — one transition, one event, one result
    // -----------------------------------------------------------------

    public function test_signing_twice_with_the_same_act_returns_the_same_signature_and_one_event(): void
    {
        $tenant = $this->sendableTenant();
        [$document] = $this->sendAndCaptureToken($this->catalogDocument($tenant));
        Event::fake([DocumentSigned::class]);
        $manager = app(DocumentManager::class);

        $first = $manager->sign($document->refresh(), $this->evidence($document));
        $signedAt = $document->fresh()->signed_at;
        $replay = $manager->sign($document->refresh(), $this->evidence($document, ['ip_address' => '198.51.100.9']));

        $this->assertSame($first->id, $replay->id);
        $this->assertSame(1, BusinessDocumentSignature::query()->count());
        $this->assertSame($signedAt->toDateTimeString(), $document->fresh()->signed_at->toDateTimeString());
        // The replay did not overwrite the first act's evidence.
        $this->assertSame('203.0.113.7', $replay->fresh()->ip_address);
        Event::assertDispatchedTimes(DocumentSigned::class, 1);
    }

    public function test_a_different_act_against_a_signed_document_is_refused_and_changes_nothing(): void
    {
        $tenant = $this->sendableTenant();
        [$document] = $this->sendAndCaptureToken($this->catalogDocument($tenant));
        Event::fake([DocumentSigned::class]);
        $manager = app(DocumentManager::class);
        $manager->sign($document->refresh(), $this->evidence($document));

        foreach ([
            ['typed_name' => 'Someone Else'],
            ['signer_name' => 'Someone Else'],
            ['signer_email' => 'other@example.test'],
            ['displayed_version_uid' => '00000000-0000-4000-8000-000000000000'],
        ] as $different) {
            try {
                $manager->sign($document->refresh(), $this->evidence($document, $different));
                $this->fail('A different signing act must be refused.');
            } catch (ValidationException) {
                // expected
            }
        }

        $signature = BusinessDocumentSignature::query()->sole();
        $this->assertSame('Pat Rivera', $signature->typed_name);
        $this->assertSame('pat@example.test', $signature->signer_email);
        Event::assertDispatchedTimes(DocumentSigned::class, 1);
    }

    public function test_the_signed_event_carries_identity_and_a_stable_occurrence_key(): void
    {
        $tenant = $this->sendableTenant();
        [$document] = $this->sendAndCaptureToken($this->catalogDocument($tenant));
        Event::fake([DocumentSigned::class]);

        $signature = app(DocumentManager::class)->sign($document->refresh(), $this->evidence($document));

        Event::assertDispatched(DocumentSigned::class, function (DocumentSigned $event) use ($signature, $tenant, $document) {
            $this->assertSame((int) $document->id, $event->documentId);
            $this->assertSame((int) $tenant['business']->id, $event->businessId);
            $this->assertSame((int) $tenant['location']->id, $event->businessLocationId);
            $this->assertSame((int) $tenant['contact']->id, $event->contactId);
            $this->assertSame('document_signature:' . $signature->id, $event->occurrenceKey());

            return true;
        });
    }

    // -----------------------------------------------------------------
    // Nothing about a signed document can change
    // -----------------------------------------------------------------

    public function test_a_signed_document_refuses_every_authoring_and_lifecycle_mutation(): void
    {
        $tenant = $this->sendableTenant();
        $document = $this->catalogDocument($tenant, $item);
        [$document] = $this->sendAndCaptureToken($document);
        $manager = app(DocumentManager::class);
        $actor = $tenant['customer']->user;
        $manager->sign($document->refresh(), $this->evidence($document));

        $version = BusinessDocumentVersion::findOrFail($document->fresh()->current_version_id);
        $line = $version->lineItems()->firstOrFail();
        $before = [
            'hash' => $version->content_hash,
            'content' => $version->fresh()->content,
            'lines' => BusinessDocumentLineItem::query()->where('business_document_version_id', $version->id)->get()->toJson(),
            'versions' => BusinessDocumentVersion::query()->where('business_document_id', $document->id)->count(),
            'document' => DB::table('business_documents')->where('id', $document->id)->first(),
        ];

        $attempts = [
            'edit title' => fn () => $manager->edit($document->refresh(), ['title' => 'Renegotiated']),
            'edit content' => fn () => $manager->edit($document->refresh(), ['content' => ['body' => 'Changed after signing']]),
            'edit recipient' => fn () => $manager->edit($document->refresh(), ['recipient_email_snapshot' => 'attacker@example.test']),
            'add catalog line' => fn () => $manager->addCatalogLine($document->refresh(), $item, 1, $actor),
            'add custom line' => fn () => $manager->addCustomLine($document->refresh(), 'Extra', null, 1, 100),
            'remove line' => fn () => $manager->removeLine($document->refresh(), $line),
            'reorder' => fn () => $manager->reorderLines($document->refresh(), [$line->id]),
            'set schedule' => fn () => $manager->setSchedule($document->refresh(), [['kind' => 'full', 'amount_minor' => 1, 'currency_code' => 'USD']]),
            'revise' => fn () => $manager->revise($document->refresh(), $actor),
            'send' => fn () => $manager->send($document->refresh()),
        ];

        foreach ($attempts as $label => $attempt) {
            try {
                $attempt();
                $this->fail("{$label} must be refused on a signed document.");
            } catch (ValidationException) {
                // expected
            }
        }

        $document = $document->fresh();
        $this->assertSame('signed', $document->status->value);
        $this->assertSame($before['hash'], $version->fresh()->content_hash);
        $this->assertEquals($before['content'], $version->fresh()->content);
        $this->assertSame($before['lines'], BusinessDocumentLineItem::query()->where('business_document_version_id', $version->id)->get()->toJson());
        $this->assertSame($before['versions'], BusinessDocumentVersion::query()->where('business_document_id', $document->id)->count());
        $this->assertEquals($before['document'], DB::table('business_documents')->where('id', $document->id)->first());
        $this->assertSame($before['hash'], app(DocumentContentHasher::class)->hash($version->fresh()));
    }
}

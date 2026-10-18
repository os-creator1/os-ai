<?php

namespace Tests\Feature\Documents;

use App\Enums\Business\BusinessStatus;
use App\Http\Controllers\Customer\Business\DocumentsController;
use App\Library\Documents\DocumentManager;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\BusinessDocument;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Documents\Concerns\CreatesDocumentsTestData;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Proposals and invoices page: three action cards, the New invoice panel ABOVE the
 * documents (same POST as before), and the documents table with real amount / sent data
 * and the Sent / Drafts filters.
 */
class DocumentsInvoicePanelTest extends TestCase
{
    use CreatesCustomerContextFixtures, CreatesDocumentsTestData, RefreshDatabase;

    private function bundle(): array
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();
        $bundle = $this->documentsBundle();
        $bundle['business']->status = BusinessStatus::Active;
        $bundle['business']->save();
        $owner = Customer::where('user_id', $bundle['business']->workspace->owner_user_id)->firstOrFail();
        $permissions = is_string($owner->permissions) ? json_decode($owner->permissions, true) : $owner->permissions;
        $owner->permissions = json_encode(array_values(array_unique([...($permissions ?: []), 'payments_contracts'])));
        $owner->save();

        $this->app->bind(DocumentsController::class, fn ($app) => new class($app->make(DocumentManager::class), $app->make(EntitlementManager::class), $app->make(LocationAccessGuard::class), $app->make(\App\Library\Payments\PaymentManager::class)) extends DocumentsController {
            protected function entitlementAllows(\App\Models\Workspace $workspace, \App\Models\Business $business): bool { return true; }
        });
        $this->authenticateAs($owner);

        return $bundle;
    }

    private function url(array $bundle, array $query = []): string
    {
        return route('customer.workspaces.businesses.documents.index', array_merge([$bundle['business']->workspace->uid, $bundle['business']->uid], $query));
    }

    private function document(array $bundle, array $document, ?int $totalMinor = null): BusinessDocument
    {
        $id = $this->insertDocument($bundle, $document);

        if ($totalMinor !== null) {
            $version = $this->insertVersion($id, ['total_minor' => $totalMinor, 'subtotal_minor' => $totalMinor]);
            DB::table('business_documents')->where('id', $id)->update(['current_version_id' => $version]);
        }

        return BusinessDocument::findOrFail($id);
    }

    public function test_three_action_cards_and_the_invoice_panel_sits_above_the_documents_closed_by_default(): void
    {
        $bundle = $this->bundle();
        $html = $this->get($this->url($bundle))->assertOk()->getContent();

        foreach (['new-proposal-open', 'templates-link', 'new-invoice-toggle'] as $role) {
            $this->assertGreaterThanOrEqual(1, substr_count($html, 'data-role="' . $role . '"'), $role);
        }
        $this->assertStringContainsString('aria-expanded="false"', $html);
        // The panel is in the page but hidden, and comes BEFORE the documents card.
        $this->assertMatchesRegularExpression('/<section class="pd-invoice"[^>]*data-role="new-invoice"[^>]*hidden/', $html);
        $this->assertLessThan(strpos($html, 'data-section="documents"'), strpos($html, 'data-role="new-invoice"'));
        $this->assertStringContainsString("Who are you billing? You'll add line items and amounts in the draft.", $html);
        $this->assertStringContainsString('Create invoice draft', $html);
        $this->assertStringContainsString('data-role="new-invoice-cancel"', $html);
        $this->assertStringContainsString('data-role="new-invoice-close"', $html);
        // The old collapsed form under the table is gone.
        $this->assertStringNotContainsString('<details class="card p-2 mb-2" data-role="new-invoice">', $html);
    }

    public function test_the_invoice_form_posts_the_same_fields_and_creates_a_draft_invoice(): void
    {
        $bundle = $this->bundle();
        $html = $this->get($this->url($bundle))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<form method="post"[^>]*data-role="new-invoice-form">\s*<input type="hidden" name="_token"[^>]*>\s*<input type="hidden" name="kind" value="invoice">/', $html);
        foreach (['title', 'contact_uid', 'location_uid', 'opportunity_uid'] as $field) {
            $this->assertStringContainsString('name="' . $field . '"', $html);
        }

        $this->post($this->url($bundle), [
            'kind' => 'invoice', 'title' => 'Wedding balance',
            'contact_uid' => DB::table('contacts')->where('id', $bundle['contactId'])->value('uid'),
            'location_uid' => $bundle['location']->uid,
        ])->assertRedirect();
        $this->assertDatabaseHas('business_documents', ['business_id' => $bundle['business']->id, 'kind' => 'invoice', 'title' => 'Wedding balance', 'status' => 'draft']);
    }

    public function test_a_failed_invoice_submission_reopens_the_panel_keeps_the_values_and_shows_the_error(): void
    {
        $bundle = $this->bundle();
        $contactUid = DB::table('contacts')->where('id', $bundle['contactId'])->value('uid');

        $response = $this->from($this->url($bundle))->post($this->url($bundle), [
            'kind' => 'invoice', 'title' => 'Half-filled invoice', 'contact_uid' => $contactUid,
            // no location_uid: the server refuses it
        ]);
        $response->assertRedirect($this->url($bundle))->assertSessionHasErrors('location_uid');

        $html = $this->followingRedirects()->from($this->url($bundle))->post($this->url($bundle), [
            'kind' => 'invoice', 'title' => 'Half-filled invoice', 'contact_uid' => $contactUid,
        ])->getContent();

        $this->assertDoesNotMatchRegularExpression('/<section class="pd-invoice"[^>]*data-role="new-invoice"[^>]*hidden/', $html, 'The panel is open again.');
        $this->assertStringContainsString('aria-expanded="true"', $html);
        $this->assertStringContainsString('value="Half-filled invoice"', $html);
        $this->assertStringContainsString('pd-field--error', $html);
        $this->assertDatabaseMissing('business_documents', ['title' => 'Half-filled invoice']);
    }

    public function test_documents_show_real_amount_sent_and_counts_and_never_invent_them(): void
    {
        $bundle = $this->bundle();
        $this->document($bundle, ['kind' => 'invoice', 'title' => 'Invoice for Hannah', 'status' => 'sent', 'sent_at' => now()->subHours(18)], 249900);
        $this->document($bundle, ['kind' => 'proposal', 'title' => 'Draft for Maya', 'status' => 'draft']);

        $html = $this->get($this->url($bundle))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-role="documents-count">\s*2 documents · 1 sent/', $html);
        $this->assertMatchesRegularExpression('/data-role="document-amount">USD 2,499</', $html);
        $this->assertStringContainsString('18 hours ago', $html);
        $this->assertStringContainsString('Not sent', $html);
        // A document with no priced version shows a dash, not 0.
        $this->assertMatchesRegularExpression('/data-role="document-amount"><span class="pd-muted">—<\/span>/', $html);
        foreach (['Title', 'Type', 'Status', 'Amount', 'Sent', 'Location'] as $column) {
            $this->assertStringContainsString('<span>' . $column . '</span>', $html);
        }
        $this->assertStringContainsString('Drafts stay private until you send them.', $html);
    }

    public function test_sent_and_drafts_filters_narrow_the_list_and_the_counts_stay_whole(): void
    {
        $bundle = $this->bundle();
        $this->document($bundle, ['kind' => 'invoice', 'title' => 'Sent one', 'status' => 'sent', 'sent_at' => now()->subDay()], 1000);
        $this->document($bundle, ['kind' => 'proposal', 'title' => 'Private draft', 'status' => 'draft']);

        $sent = $this->get($this->url($bundle, ['state' => 'sent']))->assertOk()->getContent();
        $this->assertSame(1, substr_count($sent, 'data-role="document-row"'));
        $this->assertStringContainsString('Sent one', $sent);
        $this->assertStringNotContainsString('Private draft', $sent);
        $this->assertMatchesRegularExpression('/data-role="documents-count">\s*2 documents · 1 sent/', $sent, 'Counts describe all documents, not the filter.');
        $this->assertMatchesRegularExpression('/class="is-active"\s+aria-current="page"\s+data-filter="sent"/', $sent);

        $drafts = $this->get($this->url($bundle, ['state' => 'draft']))->assertOk()->getContent();
        $this->assertSame(1, substr_count($drafts, 'data-role="document-row"'));
        $this->assertStringContainsString('Private draft', $drafts);
        $this->assertStringNotContainsString('Sent one', $drafts);

        // The kind filters still work, and an unknown state means All.
        $this->assertSame(1, substr_count($this->get($this->url($bundle, ['kind' => 'invoice']))->getContent(), 'data-role="document-row"'));
        $this->assertSame(2, substr_count($this->get($this->url($bundle, ['state' => 'bogus']))->getContent(), 'data-role="document-row"'));
    }
}

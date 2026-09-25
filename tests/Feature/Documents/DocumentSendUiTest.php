<?php

namespace Tests\Feature\Documents;

use App\Enums\Business\BusinessStatus;
use App\Http\Controllers\Customer\Business\DocumentsController;
use App\Library\Documents\DocumentManager;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\BusinessDocument;
use App\Models\Customer;
use App\Notifications\Documents\DocumentIssuedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Documents\Concerns\CreatesDocumentsTestData;
use Tests\Feature\Documents\Concerns\SendsDocuments;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * The document editor's own UI lacked a recipient-email field and a Send
 * control, even though DocumentManager::send() and the `send` route always
 * supported both — a Business owner could never actually send a document
 * through the app. These tests drive the real HTTP routes exactly as the
 * Blade form now does, proving the whole owner-facing path end to end
 * rather than re-proving DocumentSendAndSignTest's manager-level coverage.
 */
class DocumentSendUiTest extends TestCase
{
    use RefreshDatabase, CreatesDocumentsTestData, CreatesCustomerContextFixtures, SendsDocuments;

    private function activeBundle(): array
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();
        $bundle = $this->documentsBundle();
        $bundle['business']->status = BusinessStatus::Active;
        $bundle['business']->currency_code = 'USD';
        $bundle['business']->save();
        $owner = Customer::where('user_id', $bundle['business']->workspace->owner_user_id)->firstOrFail();
        $permissions = $owner->permissions;
        if (is_string($permissions)) {
            $permissions = json_decode($permissions, true);
        }
        $owner->permissions = json_encode(array_values(array_unique([...($permissions ?: []), 'payments_contracts'])));
        $owner->save();

        return $bundle;
    }

    private function url(array $bundle, string $tail = ''): string
    {
        return route('customer.workspaces.businesses.documents.index', [$bundle['business']->workspace->uid, $bundle['business']->uid]).$tail;
    }

    private function allowEntitlement(): void
    {
        $this->app->bind(DocumentsController::class, fn ($app) => new class($app->make(DocumentManager::class), $app->make(EntitlementManager::class), $app->make(LocationAccessGuard::class), $app->make(\App\Library\Payments\PaymentManager::class)) extends DocumentsController {
            protected function entitlementAllows(\App\Models\Workspace $workspace, \App\Models\Business $business): bool { return true; }
        });
    }

    /** A draft document with one custom line and a full-payment schedule, built through the real HTTP routes — not the manager directly. */
    private function draftDocumentViaHttp(array $bundle): BusinessDocument
    {
        $owner = Customer::where('user_id', $bundle['business']->workspace->owner_user_id)->firstOrFail();
        $this->authenticateAs($owner);
        $this->allowEntitlement();

        $this->post($this->url($bundle), [
            'kind' => 'proposal', 'title' => 'UI-sent proposal',
            'location_uid' => $bundle['location']->uid,
            'contact_uid' => DB::table('contacts')->where('id', $bundle['contactId'])->value('uid'),
        ])->assertRedirect();

        $document = BusinessDocument::where('business_id', $bundle['business']->id)->firstOrFail();

        $this->post($this->url($bundle, '/'.$document->uid.'/custom-lines'), [
            'name' => 'Consulting', 'quantity' => 1, 'unit_price_minor' => 10000,
        ])->assertRedirect();

        $this->put($this->url($bundle, '/'.$document->uid.'/schedule'), [
            'terms' => [['kind' => 'full', 'amount_minor' => 10000, 'currency_code' => 'USD']],
        ])->assertRedirect();

        return $document->fresh();
    }

    public function test_owner_can_enter_the_recipient_email_and_send_through_the_normal_ui(): void
    {
        $bundle = $this->activeBundle();
        $document = $this->draftDocumentViaHttp($bundle);
        $this->assertNull($document->recipient_email_snapshot);

        $this->patch($this->url($bundle, '/'.$document->uid), [
            'recipient_name_snapshot' => 'Pat Rivera',
            'recipient_email_snapshot' => 'pat@example.test',
        ])->assertRedirect();

        $document = $document->fresh();
        $this->assertSame('pat@example.test', $document->recipient_email_snapshot);
        $this->assertSame('Pat Rivera', $document->recipient_name_snapshot);

        Notification::fake();
        $token = null;

        $this->post($this->url($bundle, '/'.$document->uid.'/send'))->assertRedirect();

        Notification::assertSentOnDemand(
            DocumentIssuedNotification::class,
            function (DocumentIssuedNotification $notification, array $channels, object $notifiable) use (&$token) {
                $token = (new \ReflectionProperty($notification, 'plaintextToken'))->getValue($notification);

                return $notifiable->routes['mail'] === 'pat@example.test';
            }
        );

        $document = $document->fresh();
        $this->assertSame('sent', $document->status->value);
        $this->assertNotNull($token);

        // The exact link a real customer would open — obtained the same way
        // the acceptance flow obtains it, off the delivered notification.
        $this->allowPublicEntitlement();
        $this->get($this->publicUrl($document, $token))
            ->assertOk()
            ->assertSee('UI-sent proposal');
    }

    public function test_sending_without_a_recipient_email_is_refused_and_the_document_stays_draft(): void
    {
        $bundle = $this->activeBundle();
        $document = $this->draftDocumentViaHttp($bundle);

        Notification::fake();

        $this->post($this->url($bundle, '/'.$document->uid.'/send'))->assertSessionHasErrors();

        Notification::assertNothingSent();
        $this->assertSame('draft', $document->fresh()->status->value);
    }

    public function test_an_already_sent_document_cannot_be_sent_again_without_first_revising(): void
    {
        $bundle = $this->activeBundle();
        $document = $this->draftDocumentViaHttp($bundle);

        $this->patch($this->url($bundle, '/'.$document->uid), [
            'recipient_email_snapshot' => 'pat@example.test',
        ])->assertRedirect();

        Notification::fake();
        $this->post($this->url($bundle, '/'.$document->uid.'/send'))->assertRedirect();
        $sentAt = $document->fresh()->sent_at;
        $this->assertSame('sent', $document->fresh()->status->value);

        // The lifecycle rule DocumentSendAndSignTest pins at the manager
        // level holds through the UI too: no open draft version remains, so
        // a second Send is refused rather than silently re-freezing anything.
        $this->post($this->url($bundle, '/'.$document->uid.'/send'))->assertSessionHasErrors();

        $document = $document->fresh();
        $this->assertSame('sent', $document->status->value);
        $this->assertSame($sentAt->toDateTimeString(), $document->sent_at->toDateTimeString());
    }

    public function test_signing_the_ui_sent_document_still_locks_it_against_a_second_signature(): void
    {
        $bundle = $this->activeBundle();
        $document = $this->draftDocumentViaHttp($bundle);
        $this->patch($this->url($bundle, '/'.$document->uid), [
            'recipient_email_snapshot' => 'pat@example.test',
        ])->assertRedirect();

        Notification::fake();
        $token = null;
        $this->post($this->url($bundle, '/'.$document->uid.'/send'))->assertRedirect();
        Notification::assertSentOnDemand(
            DocumentIssuedNotification::class,
            function (DocumentIssuedNotification $notification) use (&$token) {
                $token = (new \ReflectionProperty($notification, 'plaintextToken'))->getValue($notification);

                return true;
            }
        );

        $this->allowPublicEntitlement();
        $document = $document->fresh();

        $this->post($this->signUrl($document, $token), [
            'signer_name' => 'Pat Rivera',
            'signer_email' => 'pat@example.test',
            'typed_name' => 'Pat Rivera',
        ])->assertOk();

        $this->assertSame('signed', $document->fresh()->status->value);

        // Preserves the existing draft/sent/signed rule: a signed document
        // can never be sent again through the UI either.
        $this->post($this->url($bundle, '/'.$document->uid.'/send'))->assertSessionHasErrors();
        $this->assertSame('signed', $document->fresh()->status->value);
    }
}

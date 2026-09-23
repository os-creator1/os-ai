<?php

namespace App\Listeners\Documents;

use App\Events\DocumentExpired;
use App\Events\DocumentFullyPaid;
use App\Events\DocumentPaymentSucceeded;
use App\Events\DocumentRefunded;
use App\Events\DocumentSent;
use App\Events\DocumentSigned;
use App\Events\DocumentVoided;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Models\Notifications;

/**
 * Implementation Contract 17 §12.G, Blueprint §24 — payment/document events
 * reach the Activity Center, the existing per-user notification inbox (the
 * bell in `resources/views/panels/navbar.blade.php`). Written through the
 * exact existing convention every other producer already uses —
 * `Notifications::create(['notification_type' => '...', ...])`, the same
 * mechanism DLRController uses for the `chatbox` "New chat message arrived"
 * row — so this is one more case on an existing mechanism, never a second
 * activity system and never a new payment state machine.
 *
 * LOCATION SAFETY BY CONSTRUCTION, not a per-row check. `notifications` has
 * no Location column anywhere in this codebase — its own `chatbox` precedent
 * does not filter by Location either — so nothing here could check one. This
 * listener instead notifies only the Business owner
 * (`business.customer_id`), the one actor LocationAccessGuard's
 * resolveLocationReach() always grants 'all' Location access to for their own
 * Business. A Workspace staff member is never notified here, so a staff
 * member whose grant excludes this document's Location is never handed a
 * hint that it exists (§12.G's "neither may reveal, or hint at the existence
 * of, a document the viewing actor could not open").
 *
 * FAIL CLOSED, the same guard AutomationActivitySource already applies on the
 * read side: an event naming a document or Business that no longer resolves
 * writes nothing rather than guess.
 */
class SurfaceDocumentActivityInActivityCenter
{
    public function handleSent(DocumentSent $event): void
    {
        $this->notify($event->documentId, 'document_sent', 'Your proposal was sent.');
    }

    public function handleSigned(DocumentSigned $event): void
    {
        $this->notify($event->documentId, 'document_signed', 'Your proposal was signed.');
    }

    public function handlePaymentSucceeded(DocumentPaymentSucceeded $event): void
    {
        $this->notify($event->documentId, 'document_payment_succeeded', 'A payment was received.');
    }

    public function handleFullyPaid(DocumentFullyPaid $event): void
    {
        $this->notify($event->documentId, 'document_fully_paid', 'Your document is now fully paid.');
    }

    public function handleExpired(DocumentExpired $event): void
    {
        $this->notify($event->documentId, 'document_expired', 'Your document expired unsigned.');
    }

    public function handleVoided(DocumentVoided $event): void
    {
        $this->notify($event->documentId, 'document_voided', 'Your document was voided.');
    }

    public function handleRefunded(DocumentRefunded $event): void
    {
        $this->notify($event->documentId, 'document_refunded', 'A payment was refunded.');
    }

    private function notify(int $documentId, string $type, string $message): void
    {
        $document = BusinessDocument::query()->find($documentId);

        if ($document === null) {
            return;
        }

        $business = Business::query()->find($document->business_id);

        if ($business === null || $business->customer_id === null) {
            return;
        }

        Notifications::create([
            'user_id' => $business->customer_id,
            'notification_for' => 'customer',
            'notification_type' => $type,
            'message' => $message,
        ]);
    }
}

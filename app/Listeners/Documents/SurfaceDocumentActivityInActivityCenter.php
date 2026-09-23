<?php

namespace App\Listeners\Documents;

use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Events\DocumentExpired;
use App\Events\DocumentFullyPaid;
use App\Events\DocumentPaymentSucceeded;
use App\Events\DocumentRefunded;
use App\Events\DocumentSent;
use App\Events\DocumentSigned;
use App\Events\DocumentVoided;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Models\User;
use App\Notifications\Documents\DocumentActivityCenterNotification;
use App\Repositories\Contracts\WorkspaceMembershipBusinessRepository;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use Illuminate\Support\Facades\Notification;

/**
 * Implementation Contract 17 §12.G, Blueprint §24 — payment/document events
 * reach the Activity Center. Written through the existing `database`
 * notification substrate (`platform_database_notifications`), the same
 * mechanism Automations V2's "Notify the team" step already uses — one more
 * producer on an existing table, never a second activity system.
 *
 * WRITE-TIME RECIPIENTS ARE CANDIDATES ONLY. Recipients here are resolved by
 * the same Business-reach authority InternalNotificationNodeExecutor already
 * uses for "Notify the team" (owner + active Workspace members whose reach
 * covers this Business, deduped) — deliberately NOT Location-narrowed at
 * write time. THE READ IS AUTHORITATIVE: DocumentActivityCenterReader
 * re-derives tenancy, the `payments_contracts` capability, the entitlement,
 * document existence and LocationAccessGuard fresh before anything is ever
 * shown, so a recipient without today's Location grant simply sees nothing —
 * the correct outcome whether that grant was missing from the start or was
 * revoked after this event was written.
 *
 * FAIL CLOSED, the same guard AutomationActivitySource already applies on
 * the read side: an event naming a document or Business that no longer
 * resolves, or a Business with no reachable recipient, writes nothing.
 */
class SurfaceDocumentActivityInActivityCenter
{
    public function __construct(
        private readonly WorkspaceMembershipRepository $memberships,
        private readonly WorkspaceMembershipBusinessRepository $membershipBusinesses,
    ) {
    }

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

    private function notify(int $documentId, string $event, string $message): void
    {
        $document = BusinessDocument::query()->find($documentId);

        if ($document === null || $document->business_location_id === null) {
            return;
        }

        $business = Business::query()->find($document->business_id);

        if ($business === null) {
            return;
        }

        $recipients = $this->recipients($business);

        if ($recipients === []) {
            return;
        }

        Notification::send(array_values($recipients), new DocumentActivityCenterNotification(
            event: $event,
            businessId: (int) $business->id,
            businessLocationId: (int) $document->business_location_id,
            businessDocumentId: (int) $document->id,
            message: $message,
        ));
    }

    /**
     * The Business owner plus every active Workspace member whose reach
     * covers this Business — the exact InternalNotificationNodeExecutor
     * recipient algorithm, applied here independently rather than reused
     * directly, since that class belongs to Automations and this slice does
     * not touch it (§4/§11.1's own module boundaries).
     *
     * @return array<int, User> keyed by user id, which is what makes it a set
     */
    private function recipients(Business $business): array
    {
        $recipients = [];

        $owner = User::query()->find((int) $business->customer_id);

        if ($owner instanceof User) {
            $recipients[(int) $owner->id] = $owner;
        }

        $workspace = $business->workspace;

        if ($workspace === null) {
            return $recipients;
        }

        foreach ($this->memberships->activeForWorkspace($workspace) as $membership) {
            if (! $membership->is_active) {
                continue;
            }

            $covers = $membership->business_access_scope === WorkspaceBusinessAccessScope::All
                || $this->membershipBusinesses->isAssigned($membership, (int) $business->id);

            if (! $covers) {
                continue;
            }

            $userId = (int) $membership->user_id;

            if (isset($recipients[$userId])) {
                continue;
            }

            $user = $membership->user;

            if ($user instanceof User) {
                $recipients[$userId] = $user;
            }
        }

        return $recipients;
    }
}

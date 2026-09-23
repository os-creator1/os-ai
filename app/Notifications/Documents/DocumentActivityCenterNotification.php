<?php

namespace App\Notifications\Documents;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Implementation Contract 17 §12.G / Blueprint §24 — a document/payment
 * lifecycle event reaching the Activity Center, on the `database` channel
 * only (the internal in-app inbox; no email here — DocumentIssuedNotification
 * and DocumentReminderNotification already own the email side).
 *
 * WRITTEN THROUGH THE EXISTING SUBSTRATE, NOT A NEW TABLE. `via()` returns
 * `database`, which — per `User::routeNotificationForDatabase()` — lands in
 * `platform_database_notifications`, Laravel's own standard notification
 * schema (id, type, notifiable, data, read_at). This is the same table and
 * routing the Automations V2 "Notify the team" step already uses
 * (WorkflowInternalNotification); this is one more producer on an existing
 * mechanism, not a second one.
 *
 * SCALARS ONLY, NO PII, NO SECRETS. `data` carries only what
 * DocumentActivityCenterReader needs to RE-DERIVE authorization at read time
 * (business/location/document ids) plus a short safe display message —
 * never a token, a Stripe identifier, or signature evidence. The write side
 * is not the authority: a recipient here is a CANDIDATE only, re-checked in
 * full by the reader before anything is ever shown (§12.G).
 */
class DocumentActivityCenterNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $event,
        public readonly int $businessId,
        public readonly int $businessLocationId,
        public readonly int $businessDocumentId,
        public readonly string $message,
    ) {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'document_activity',
            'event' => $this->event,
            'business_id' => $this->businessId,
            'business_location_id' => $this->businessLocationId,
            'business_document_id' => $this->businessDocumentId,
            'message' => $this->message,
        ];
    }
}

<?php

namespace App\Jobs\Documents;

use App\Jobs\Base;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentPaymentScheduleItem;
use App\Notifications\Documents\DocumentReminderNotification;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Notification;

/**
 * Implementation Contract 17 §8.4 — delivery only.
 *
 * THIS JOB WRITES NOTHING. The durable marker (`reminder_last_sent_at` /
 * `reminder_count` on the schedule item, `expiry_reminder_*` on the document)
 * was already claimed by DocumentManager inside the same locked transaction
 * that selected the row, following the `low_balance_notified_at` precedent the
 * contract names: "the manager owns the durable marker and the dispatch
 * decision; the job never writes the table." Making the job the deduper is
 * exactly what §8.4 forbids — a job can be retried, replayed, or run twice.
 *
 * AFTER COMMIT, so a reminder is never emailed for a marker whose transaction
 * later rolled back — which would otherwise consume the window silently.
 *
 * A null `$scheduleItemId` means the offer-expiry warning rather than a
 * payment reminder.
 */
class SendDocumentReminderEmail extends Base implements ShouldQueueAfterCommit
{
    public function __construct(
        private readonly int $documentId,
        private readonly ?int $scheduleItemId = null,
    ) {
    }

    public function handle(): void
    {
        $document = BusinessDocument::query()->find($this->documentId);

        if ($document === null || blank($document->recipient_email_snapshot)) {
            return;
        }

        $item = $this->scheduleItemId === null
            ? null
            : BusinessDocumentPaymentScheduleItem::query()->find($this->scheduleItemId);

        if ($this->scheduleItemId !== null && $item === null) {
            return;
        }

        $business = Business::query()->find($document->business_id);
        $due = $item?->due_at ?? ($item === null ? $document->expires_at : null);

        Notification::route('mail', $document->recipient_email_snapshot)->notify(new DocumentReminderNotification(
            (string) ($business?->name ?? config('app.name')),
            (string) $document->title,
            $item === null ? null : number_format(((int) $item->amount_minor) / 100, 2) . ' ' . (string) $item->currency_code,
            $due === null ? null : 'on ' . $due->toFormattedDateString(),
            isExpiryWarning: $item === null,
        ));
    }
}

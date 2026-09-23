<?php

namespace App\Jobs\Documents;

use App\Jobs\Base;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Notifications\Documents\DocumentIssuedNotification;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Implementation Contract 17 §11.3 / §7.1 — delivery of the secure link.
 *
 * AFTER COMMIT, STRUCTURALLY. ShouldQueueAfterCommit makes the
 * ClientInvitationManager rule a property of the class rather than of one
 * call site: "a recipient must never be emailed a claim link for a row that
 * a later failure inside the transaction rolled back." A rollback therefore
 * dispatches nothing at all, even though send() enqueues from inside its own
 * transactional flow.
 *
 * ENCRYPTED, NON-NEGOTIABLY. This job carries the ONE plaintext token that
 * will ever exist for this link, and §6.3 requires that plaintext to be
 * "never stored, never logged, never recoverable". This application's default
 * queue connection is `database`, so an ordinary job would write the token
 * into the `jobs` row in the clear — and into `failed_jobs`, indefinitely, on
 * any delivery failure. ShouldBeEncrypted means the serialized command is
 * encrypted with the application key before it is ever persisted, so neither
 * table ever holds a readable token. The durable record remains the bcrypt
 * `access_token_hash`, which is not reversible at all.
 *
 * NOTHING HERE LOGS THE TOKEN. The two Log::warning() calls below carry only
 * the document id, and no exception thrown from this job interpolates the
 * token into its message.
 *
 * EMAIL ONLY. Notification::route('mail', …) to the document's own frozen
 * recipient_email_snapshot. It never re-reads live Contact identity (§5.2),
 * and never touches quickSend(), ManagedMessageDispatcher, the wallet or any
 * messaging/metering path (§11.3).
 *
 * Inherits Base's $tries = 1 / $maxExceptions = 1: a delivery failure is
 * resolved by the owner re-sending the document — which rotates the token —
 * never by a silent retry that could race that rotation.
 */
class SendDocumentLinkEmail extends Base implements ShouldQueueAfterCommit, ShouldBeEncrypted
{
    public function __construct(
        private readonly int $documentId,
        private readonly string $plaintextToken,
    ) {
    }

    public function handle(): void
    {
        $document = BusinessDocument::query()->find($this->documentId);

        if ($document === null) {
            Log::warning('Document link email skipped: document no longer exists.', ['document_id' => $this->documentId]);

            return;
        }

        $recipient = $document->recipient_email_snapshot;

        if (blank($recipient)) {
            // Unreachable through send(), which validates and freezes the
            // snapshot before it commits. Logged rather than thrown so a data
            // repair is never mistaken for a delivery outage — and logged
            // WITHOUT the address or the token.
            Log::warning('Document link email skipped: no frozen recipient.', ['document_id' => $this->documentId]);

            return;
        }

        $business = Business::query()->find($document->business_id);

        Notification::route('mail', $recipient)->notify(new DocumentIssuedNotification(
            (string) $document->uid,
            $this->plaintextToken,
            (string) ($business?->name ?? config('app.name')),
            (string) $document->title,
            (bool) $document->requires_signature,
        ));
    }
}

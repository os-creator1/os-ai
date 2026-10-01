<?php

namespace App\Jobs\Documents;

use App\Jobs\Base;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Notifications\Documents\DocumentIssuedNotification;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

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
 * DELIVERY IS RECORDED, HONESTLY. `status = sent` says the draft was frozen and a
 * link minted; it cannot say the email reached the provider. This job writes
 * that second fact — `link_delivered_at` on success, `link_delivery_failed_at`
 * on failure (failed()) — but ONLY against the link whose token it carries: a
 * link that has since been rotated is neither emailed nor recorded, because the
 * token it holds is already dead and the newer send owns the outcome. A failure
 * never un-sends the document; the owner sees it and re-sends the link
 * (DocumentManager::resendLink()).
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

        // A rotated or revoked link is dead: emailing its token would hand the
        // recipient a link that already refuses them.
        if (! self::holdsCurrentLink($document, $this->plaintextToken)) {
            Log::warning('Document link email skipped: the link was rotated or revoked.', ['document_id' => $this->documentId]);

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

        self::recordOutcome($this->documentId, $this->plaintextToken, true);
    }

    /**
     * The queue worker (or the synchronous queue) calls this when handle()
     * throws or the job times out. Logs the exception CLASS only: the message
     * of a mail-transport failure is not ours to vouch for, and the token must
     * never reach a log or `failed_jobs` in readable form.
     */
    public function failed(Throwable $exception): void
    {
        Log::warning('Document link email failed.', [
            'document_id' => $this->documentId,
            'exception' => $exception::class,
        ]);

        self::recordOutcome($this->documentId, $this->plaintextToken, false);
    }

    /**
     * Writes the delivery fact for the link whose plaintext token is given —
     * and for no other. The token is checked against the stored hash, so a
     * stale job can never overwrite the outcome of a newer send.
     */
    public static function recordOutcome(int $documentId, string $plaintextToken, bool $delivered): void
    {
        $document = BusinessDocument::query()->find($documentId);

        if ($document === null || ! self::holdsCurrentLink($document, $plaintextToken)) {
            return;
        }

        BusinessDocument::query()->whereKey($documentId)
            ->where('access_token_hash', $document->access_token_hash)
            ->update($delivered
                ? ['link_delivered_at' => now(), 'link_delivery_failed_at' => null]
                : ['link_delivered_at' => null, 'link_delivery_failed_at' => now()]);
    }

    private static function holdsCurrentLink(BusinessDocument $document, string $plaintextToken): bool
    {
        $hash = $document->access_token_hash;

        return is_string($hash) && $hash !== '' && Hash::check($plaintextToken, $hash);
    }
}

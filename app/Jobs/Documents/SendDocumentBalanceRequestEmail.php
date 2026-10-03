<?php

namespace App\Jobs\Documents;

use App\Jobs\Base;
use App\Library\Documents\Delivery\DocumentBalanceRequestDispatcher;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentPaymentScheduleItem;
use App\Notifications\Documents\DocumentBalanceRequestNotification;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Contract 17B §3/§7 — delivery of the automatic balance payment request.
 *
 * Mirrors SendDocumentLinkEmail's token handling: AFTER COMMIT (never mail a
 * link for a rotation that rolled back) and ENCRYPTED (the payload carries the
 * ONE plaintext token of the freshly rotated link, which must never sit in
 * `jobs` / `failed_jobs` in the clear). Nothing here logs the token.
 *
 * The claim, the token rotation and the attempts cap belong to
 * DocumentBalanceRequestDispatcher. This job only (1) re-checks, under fresh
 * locks, that the balance is STILL owed and the document STILL payable, (2)
 * emails the frozen recipient, and (3) records the outcome — and it records
 * ONLY against the link whose token it carries: a rotated link means a newer
 * claim owns the outcome, so a stale or replayed job sends nothing and records
 * nothing. After success `payment_request_sent_at` is set exactly once and the
 * item is never selected again, so replaying the job also sends nothing.
 *
 * It never calls Stripe, charges, invoices, or touches terms and dates.
 *
 * A failure records `payment_request_failed_at` and releases the claim; the
 * next sweep may retry (rotating the link again) until the attempts cap.
 * Inherits Base's $tries = 1: retry is the sweep's decision, never a silent
 * queue retry that could race the rotation.
 */
class SendDocumentBalanceRequestEmail extends Base implements ShouldQueueAfterCommit, ShouldBeEncrypted
{
    public function __construct(
        private readonly int $documentId,
        private readonly int $scheduleItemId,
        private readonly string $plaintextToken,
    ) {
    }

    public function handle(DocumentBalanceRequestDispatcher $dispatcher): void
    {
        $document = BusinessDocument::query()->find($this->documentId);

        if ($document === null || ! self::holdsCurrentLink($document, $this->plaintextToken)) {
            Log::warning('Balance payment request skipped: the link was rotated, revoked or the document is gone.', ['document_id' => $this->documentId]);

            return;
        }

        $item = $dispatcher->stillOwed($this->documentId, $this->scheduleItemId);

        if ($item === null) {
            Log::info('Balance payment request skipped: the balance is no longer owed or payable.', ['document_id' => $this->documentId]);

            return;
        }

        $business = Business::query()->find($document->business_id);
        $timezone = (string) ($business?->timezone ?: config('app.timezone'));

        Notification::route('mail', $document->recipient_email_snapshot)->notify(new DocumentBalanceRequestNotification(
            (string) $document->uid,
            $this->plaintextToken,
            (string) ($business?->name ?? config('app.name')),
            (string) $document->title,
            number_format(((int) $item->amount_minor) / 100, 2) . ' ' . (string) $item->currency_code,
            $item->due_at->copy()->setTimezone($timezone)->isoFormat('D MMMM YYYY'),
        ));

        self::recordSent($this->documentId, $this->scheduleItemId, $this->plaintextToken);
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('Balance payment request email failed.', [
            'document_id' => $this->documentId,
            'exception' => $exception::class,
        ]);

        self::recordFailure($this->documentId, $this->scheduleItemId, $this->plaintextToken);
    }

    /** The success marker, written once and only for the current link. */
    public static function recordSent(int $documentId, int $itemId, string $plaintextToken): void
    {
        $document = BusinessDocument::query()->find($documentId);

        if ($document === null || ! self::holdsCurrentLink($document, $plaintextToken)) {
            return;
        }

        BusinessDocumentPaymentScheduleItem::query()->whereKey($itemId)
            ->whereNull('payment_request_sent_at')
            ->update(['payment_request_sent_at' => now(), 'payment_request_failed_at' => null]);

        SendDocumentLinkEmail::recordOutcome($documentId, $plaintextToken, true);
    }

    /** The failure marker: releases the claim so the sweep may retry within the cap. */
    public static function recordFailure(int $documentId, int $itemId, string $plaintextToken): void
    {
        $document = BusinessDocument::query()->find($documentId);

        if ($document === null || ! self::holdsCurrentLink($document, $plaintextToken)) {
            return;
        }

        BusinessDocumentPaymentScheduleItem::query()->whereKey($itemId)
            ->whereNull('payment_request_sent_at')
            ->update(['payment_request_failed_at' => now(), 'payment_request_claimed_at' => null]);

        SendDocumentLinkEmail::recordOutcome($documentId, $plaintextToken, false);
    }

    private static function holdsCurrentLink(BusinessDocument $document, string $plaintextToken): bool
    {
        $hash = $document->access_token_hash;

        return is_string($hash) && $hash !== '' && Hash::check($plaintextToken, $hash);
    }
}

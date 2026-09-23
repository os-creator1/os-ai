<?php

namespace App\Jobs\BusinessPayments;

use App\Jobs\Base;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentPayment;
use App\Notifications\Documents\PaymentReceiptNotification;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Implementation Contract 17 §8.4 — the payment receipt, dispatched from
 * DocumentPaymentSucceeded.
 *
 * DEDUPED BY A DURABLE MARKER, NOT BY THE JOB. `receipt_sent_at` is claimed
 * with one conditional UPDATE (`WHERE id = ? AND receipt_sent_at IS NULL`)
 * inside its own short transaction; zero rows updated means another delivery
 * already owns this receipt and this one returns silently. That follows the
 * `low_balance_notified_at` precedent the contract names — the marker decides,
 * so a replayed event, a retried job and a duplicated webhook all send exactly
 * one receipt.
 *
 * AFTER COMMIT, so a receipt is never emailed for a payment whose transaction
 * later rolled back.
 */
class SendPaymentReceiptEmail extends Base implements ShouldQueueAfterCommit
{
    public function __construct(private readonly int $paymentId)
    {
    }

    public function handle(): void
    {
        // Claim the marker first; only the winner sends.
        $claimed = DB::table('business_document_payments')
            ->where('id', $this->paymentId)
            ->whereNull('receipt_sent_at')
            ->update(['receipt_sent_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        $payment = BusinessDocumentPayment::query()->find($this->paymentId);
        $document = $payment === null ? null : BusinessDocument::query()->find($payment->business_document_id);

        if ($payment === null || $document === null || blank($document->recipient_email_snapshot)) {
            return;
        }

        $business = Business::query()->find($document->business_id);

        Notification::route('mail', $document->recipient_email_snapshot)->notify(new PaymentReceiptNotification(
            (string) ($business?->name ?? config('app.name')),
            (string) $document->title,
            number_format(((int) $payment->amount_minor) / 100, 2) . ' ' . (string) $payment->currency_code,
        ));
    }
}

<?php

namespace App\Listeners\BusinessPayments;

use App\Events\DocumentPaymentSucceeded;
use App\Jobs\BusinessPayments\SendPaymentReceiptEmail;

/**
 * Implementation Contract 17 §12.E — the receipt email job is dispatched from
 * DocumentPaymentSucceeded, and deduped by `receipt_sent_at` inside the job.
 *
 * The listener itself is deliberately trivial: it holds no dedupe logic of its
 * own, because the durable marker on the row is the single authority (§8.4).
 * A replayed event therefore reaches this listener again and still produces
 * exactly one receipt.
 */
class SendReceiptOnPaymentSucceeded
{
    public function handle(DocumentPaymentSucceeded $event): void
    {
        SendPaymentReceiptEmail::dispatch($event->paymentId);
    }
}

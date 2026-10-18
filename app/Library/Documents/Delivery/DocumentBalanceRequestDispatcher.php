<?php

namespace App\Library\Documents\Delivery;

use App\Enums\Documents\DocumentStatus;
use App\Enums\Documents\DocumentVersionState;
use App\Enums\Documents\PaymentScheduleItemKind;
use App\Enums\Documents\PaymentScheduleItemStatus;
use App\Exceptions\Documents\DocumentLinkException;
use App\Jobs\Documents\SendDocumentBalanceRequestEmail;
use App\Library\Documents\DocumentManager;
use App\Library\Documents\PublicDocumentGuard;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentPaymentScheduleItem;
use App\Models\BusinessDocumentVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Contract 17B §3/§7 — the automatic BALANCE PAYMENT REQUEST.
 *
 * Deposit + Balance: the deposit is paid, the balance stays scheduled, and
 * when the balance item's due DATE arrives (frozen `due_at`, day start) the customer is emailed the
 * canonical secure link so they can pay it. `due_at` never gated payability
 * (the balance is payable once the deposit is paid); this only chases it.
 *
 * WHY A SEPARATE SWEEP. The reminder sweep cannot carry a link — the access
 * token plaintext is unrecoverable (bcrypt hash). This one rotates the token
 * with the SAME DocumentManager::rotateAccessToken() resendLink() uses and
 * hands the fresh plaintext to ONE encrypted after-commit job
 * (SendDocumentBalanceRequestEmail). No SMS / provider code lives here or in
 * DocumentManager; nothing is charged, invoiced or re-dated.
 *
 * WHO IS ELIGIBLE (all re-verified under row locks at claim time AND again
 * inside the job, so an item paid, voided or refunded in between sends
 * nothing): balance item, pending, `due_at` set and its DUE DAY begun (now >= start of the due day in the Business timezone), the version's
 * deposit (sequence 1) paid, no item refunded/void, the item belongs to the
 * document's CURRENT issued version, the document is Signed (or Sent when no
 * signature is required) with a live offer, and the frozen recipient email is
 * valid. A NULL `due_at` ("right after the deposit") is never selected: the
 * balance is already payable and nothing is scheduled. The account conditions
 * are PublicDocumentGuard::assertAccountOperable() — the exact checks a public
 * request runs (account lifecycle, Payments & Contracts entitlement, Location).
 * The Contact is not an input: the recipient is `recipient_email_snapshot` and
 * the date is the frozen schedule row.
 *
 * AT MOST ONCE PER CLAIM, SUCCESS RECORDED ONCE. The claim (a conditional
 * UPDATE under the document + schedule row locks) stamps
 * `payment_request_claimed_at` and bumps `payment_request_attempts` only while
 * `payment_request_sent_at` is null, attempts are under the cap and no fresh
 * claim exists; two concurrent sweeps therefore cannot both win. A claim whose
 * job never reported back expires after a lease. A delivery failure records
 * `payment_request_failed_at`, releases the claim and leaves the item eligible
 * for the next sweep until the attempts cap, after which it stops.
 */
class DocumentBalanceRequestDispatcher
{
    public function __construct(private readonly DocumentManager $documents)
    {
    }

    /**
     * Claim and queue every due balance request, up to `$limit` candidates.
     * Returns how many were queued.
     */
    public function dispatchDue(int $limit): int
    {
        $now = now();

        $candidates = BusinessDocumentPaymentScheduleItem::query()
            ->join('business_documents as request_doc', 'request_doc.current_version_id', '=', 'business_document_payment_schedule_items.business_document_version_id')
            ->whereIn('request_doc.status', [DocumentStatus::Signed->value, DocumentStatus::Sent->value])
            ->where('business_document_payment_schedule_items.kind', PaymentScheduleItemKind::Balance->value)
            ->where('business_document_payment_schedule_items.status', PaymentScheduleItemStatus::Pending->value)
            ->whereNotNull('business_document_payment_schedule_items.due_at')
            ->where('business_document_payment_schedule_items.due_at', '<=', $now->copy()->addHours(26)) // coarse prefilter; exact start-of-day check is per Business timezone
            ->whereNull('business_document_payment_schedule_items.payment_request_sent_at')
            ->where('business_document_payment_schedule_items.payment_request_attempts', '<', $this->maxAttempts())
            ->where(function ($query) use ($now) {
                $query->whereNull('business_document_payment_schedule_items.payment_request_claimed_at')
                    ->orWhere('business_document_payment_schedule_items.payment_request_claimed_at', '<', $this->leaseCutoff($now));
            })
            ->orderBy('business_document_payment_schedule_items.due_at')
            ->orderBy('business_document_payment_schedule_items.id')
            ->limit($limit)
            ->get(['business_document_payment_schedule_items.id as item_id', 'request_doc.id as document_id']);

        $queued = 0;

        foreach ($candidates as $candidate) {
            try {
                $token = $this->claim((int) $candidate->document_id, (int) $candidate->item_id);

                if ($token === null) {
                    continue;
                }

                $this->deliver((int) $candidate->document_id, (int) $candidate->item_id, $token);
                $queued++;
            } catch (Throwable $e) {
                Log::error('DocumentBalanceRequestDispatcher failed for a schedule item', [
                    'business_document_payment_schedule_item_id' => $candidate->item_id,
                    'exception' => $e,
                ]);
            }
        }

        return $queued;
    }

    /**
     * Inside the job: is this item STILL owed and the document STILL in a
     * state that may be asked for payment? Re-verifies everything under fresh
     * locks and returns the item, or null (send nothing).
     */
    public function stillOwed(int $documentId, int $itemId): ?BusinessDocumentPaymentScheduleItem
    {
        return DB::transaction(function () use ($documentId, $itemId) {
            $state = $this->lockedEligibility($documentId, $itemId, claiming: false);

            return $state === null ? null : $state[1];
        });
    }

    /** The plaintext token of the freshly rotated link, or null when nothing was claimed. */
    private function claim(int $documentId, int $itemId): ?string
    {
        $plaintextToken = Str::random(64);

        $claimed = DB::transaction(function () use ($documentId, $itemId, $plaintextToken) {
            $now = now();
            $state = $this->lockedEligibility($documentId, $itemId, claiming: true);

            if ($state === null) {
                return false;
            }

            [$document, $item] = $state;

            // The conditional UPDATE is the claim itself; the locks above are
            // what make "one winner" hold, this keeps it true on its own.
            $won = BusinessDocumentPaymentScheduleItem::query()
                ->whereKey($item->id)
                ->whereNull('payment_request_sent_at')
                ->where('payment_request_attempts', '<', $this->maxAttempts())
                ->where(function ($query) use ($now) {
                    $query->whereNull('payment_request_claimed_at')
                        ->orWhere('payment_request_claimed_at', '<', $this->leaseCutoff($now));
                })
                ->update([
                    'payment_request_claimed_at' => $now,
                    'payment_request_attempts' => DB::raw('payment_request_attempts + 1'),
                ]);

            if ($won !== 1) {
                return false;
            }

            $this->documents->rotateAccessToken($document, $plaintextToken);

            return true;
        });

        return $claimed ? $plaintextToken : null;
    }

    private function deliver(int $documentId, int $itemId, string $plaintextToken): void
    {
        try {
            SendDocumentBalanceRequestEmail::dispatch($documentId, $itemId, $plaintextToken);
        } catch (Throwable $e) {
            Log::warning('Balance payment request email could not be delivered.', [
                'document_id' => $documentId,
                'exception' => $e::class,
            ]);

            SendDocumentBalanceRequestEmail::recordFailure($documentId, $itemId, $plaintextToken);
        }
    }

    /**
     * Document (tier 1) -> version -> schedule items (tier 2, ascending
     * sequence), the §7.0 order. MUST run inside a transaction.
     *
     * @return array{0: BusinessDocument, 1: BusinessDocumentPaymentScheduleItem}|null
     */
    private function lockedEligibility(int $documentId, int $itemId, bool $claiming): ?array
    {
        $now = now();
        $document = BusinessDocument::query()->whereKey($documentId)->lockForUpdate()->first();

        if ($document === null || $document->current_version_id === null) {
            return null;
        }

        // Signed, or Sent where no signature is required (payable as sent).
        $statusOk = $document->status === DocumentStatus::Signed
            || ($document->status === DocumentStatus::Sent && ! $document->requires_signature);

        // The link this email carries expires with the offer; a lapsed offer
        // would be mailed a dead link (resendLink refuses it for the same reason).
        if (! $statusOk || ($document->expires_at !== null && $document->expires_at->lte($now))) {
            return null;
        }

        $version = BusinessDocumentVersion::query()
            ->where('business_document_id', $document->id)
            ->whereKey($document->current_version_id)
            ->lockForUpdate()
            ->first();

        if ($version === null || $version->state !== DocumentVersionState::Issued) {
            return null;
        }

        $schedule = BusinessDocumentPaymentScheduleItem::query()
            ->where('business_document_version_id', $version->id)
            ->orderBy('sequence')
            ->lockForUpdate()
            ->get();

        if ($schedule->count() !== 2) {
            return null;
        }

        [$deposit, $balance] = [$schedule[0], $schedule[1]];

        if ((int) $balance->id !== $itemId
            || (int) $deposit->sequence !== 1
            || $deposit->kind !== PaymentScheduleItemKind::Deposit
            || $balance->kind !== PaymentScheduleItemKind::Balance
            || $deposit->status !== PaymentScheduleItemStatus::Paid
            || $balance->status !== PaymentScheduleItemStatus::Pending
            || $schedule->contains(fn ($row) => in_array($row->status, [PaymentScheduleItemStatus::Refunded, PaymentScheduleItemStatus::Void], true))) {
            return null;
        }

        if ($balance->due_at === null || $this->dueDayStart($balance->due_at, $document)->gt($now) || $balance->payment_request_sent_at !== null) {
            return null;
        }

        if ($claiming) {
            if ((int) $balance->payment_request_attempts >= $this->maxAttempts()
                || ($balance->payment_request_claimed_at !== null && $balance->payment_request_claimed_at->gte($this->leaseCutoff($now)))) {
                return null;
            }
        } elseif ($balance->payment_request_claimed_at === null) {
            // The job only ever delivers a claimed item.
            return null;
        }

        $recipient = $document->recipient_email_snapshot;

        if (! is_string($recipient) || trim($recipient) === '' || filter_var(trim($recipient), FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        // Fail closed on exactly the conditions a public request runs: account
        // lifecycle, Payments & Contracts entitlement, Location. Resolved from
        // the container so it is the same (overridable) guard the pay page uses.
        try {
            app(PublicDocumentGuard::class)->assertAccountOperable($document);
        } catch (DocumentLinkException) {
            return null;
        }

        return [$document, $balance];
    }

    /**
     * The request goes out when the due DATE arrives: the first moment of the
     * due day in the Business timezone. `due_at` itself stays the frozen
     * end-of-day instant (reminders and display still use it); it is only
     * read here, never changed.
     */
    private function dueDayStart(\Illuminate\Support\Carbon $dueAt, BusinessDocument $document): \Illuminate\Support\Carbon
    {
        $timezone = (string) (\App\Models\Business::query()->whereKey($document->business_id)->value('timezone') ?: config('app.timezone'));

        try {
            return $dueAt->copy()->setTimezone($timezone)->startOfDay();
        } catch (Throwable) {
            return $dueAt->copy()->setTimezone((string) config('app.timezone'))->startOfDay();
        }
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('documents.balance_request_max_attempts', 3));
    }

    private function leaseCutoff(\Illuminate\Support\Carbon $now): \Illuminate\Support\Carbon
    {
        return $now->copy()->subMinutes(max(1, (int) config('documents.balance_request_lease_minutes', 30)));
    }
}

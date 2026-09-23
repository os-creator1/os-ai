<?php

namespace App\Library\Documents;

use App\Enums\Documents\DocumentSignatureMethod;
use App\Enums\Documents\DocumentStatus;
use App\Enums\Documents\DocumentVersionState;
use App\Enums\Documents\BusinessDocumentPaymentStatus;
use App\Enums\Documents\PaymentScheduleItemStatus;
use App\Events\DocumentExpired;
use App\Events\DocumentSent;
use App\Events\DocumentSigned;
use App\Events\DocumentVoided;
use App\Jobs\Documents\SendDocumentLinkEmail;
use App\Jobs\Documents\SendDocumentReminderEmail;
use App\Library\Catalog\PackageSnapshotService;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentLineItem;
use App\Models\BusinessDocumentPaymentScheduleItem;
use App\Models\BusinessDocumentSignature;
use App\Models\BusinessDocumentVersion;
use App\Models\BusinessLocation;
use App\Models\CatalogItem;
use App\Models\Contacts;
use App\Models\CrmOpportunity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class DocumentManager
{
    /**
     * §5.5/§6.5 — the exact agreement text shown to the signer, stored
     * VERBATIM on the signature row. It lives here, as one server-side
     * constant, for two reasons: the view and the stored evidence can never
     * disagree, and the text can never be chosen by the browser that is
     * signing. It is stated as a plain technical record and makes NO claim
     * that the signature is qualified, advanced, identity-verified or
     * legally sufficient in any jurisdiction (§6.5).
     */
    public const CONSENT_STATEMENT = 'By typing my name below and submitting this form, I agree to the contents of this document as shown on this page, and I intend my typed name to act as my signature.';

    public function __construct(
        private readonly PackageSnapshotService $snapshots,
        private readonly DocumentContentHasher $hasher,
    ) {}

    public function create(Business $business, BusinessLocation $location, Contacts $contact, ?CrmOpportunity $opportunity, string $kind, string $title, User $actor): BusinessDocument
    {
        return DB::transaction(function () use ($business, $location, $contact, $opportunity, $kind, $title, $actor) {
            $business = Business::findOrFail($business->id);
            $location = BusinessLocation::findOrFail($location->id);
            $contact = Contacts::findOrFail($contact->id);
            $opportunity = $opportunity ? CrmOpportunity::findOrFail($opportunity->id) : null;
            $this->assertIdentity($business, $location, $contact, $opportunity);
            $this->require(in_array($kind, ['proposal', 'invoice'], true), 'Invalid document kind.');
            $this->require(trim($title) !== '' && mb_strlen($title) <= 200, 'Invalid document title.');
            $document = BusinessDocument::create([
                'business_id' => $business->id, 'business_location_id' => $location->id,
                'contact_id' => $contact->id, 'crm_opportunity_id' => $opportunity?->id,
                'kind' => $kind, 'requires_signature' => $kind === 'proposal',
                'title' => trim($title), 'currency_code' => $business->currency_code,
                'created_by_user_id' => $actor->id,
            ]);
            BusinessDocumentVersion::create([
                'business_document_id' => $document->id, 'version_number' => 1,
                'content' => [], 'subtotal_minor' => 0, 'total_minor' => 0,
                'currency_code' => $document->currency_code, 'schema_version' => 1,
                'created_by_user_id' => $actor->id,
            ]);
            return $document->refresh();
        });
    }

    public function edit(BusinessDocument $document, array $attributes): BusinessDocument
    {
        return DB::transaction(function () use ($document, $attributes) {
            [$document, $version] = $this->draft($document);
            if (array_key_exists('title', $attributes)) {
                $this->require($document->status === DocumentStatus::Draft, 'The title is frozen after send.');
                $title = trim((string) $attributes['title']);
                $this->require($title !== '' && mb_strlen($title) <= 200, 'Invalid document title.');
                $document->title = $title;
            }
            if (array_key_exists('content', $attributes)) {
                $this->require(is_array($attributes['content']), 'Invalid draft content.');
                $version->content = $attributes['content'];
                $version->save();
            }
            // §5.2/§5.3.1 — the recipient snapshot may be prefilled and
            // corrected while the document has never been sent, and is FROZEN
            // from the first send onwards: it is the delivery identity every
            // later resend, reminder and receipt uses, and the identity any
            // signature is taken against. A later Contact edit must never
            // redirect an already-issued document to a different address.
            foreach (['recipient_name_snapshot' => 191, 'recipient_email_snapshot' => 255, 'recipient_phone_snapshot' => 32] as $field => $max) {
                if (! array_key_exists($field, $attributes)) {
                    continue;
                }
                $this->require($document->sent_at === null, 'Recipient details are frozen after send.');
                $value = $attributes[$field];
                $this->require($value === null || is_string($value), 'Invalid recipient detail.');
                $value = $value === null ? null : trim($value);
                $value = $value === '' ? null : $value;
                $this->require($value === null || mb_strlen($value) <= $max, 'Invalid recipient detail.');
                if ($field === 'recipient_email_snapshot' && $value !== null) {
                    $this->require(filter_var($value, FILTER_VALIDATE_EMAIL) !== false, 'Invalid recipient email address.');
                }
                $document->{$field} = $value;
            }
            $document->save();
            return $document->refresh();
        });
    }

    /**
     * Implementation Contract 17 §7.1 SEND — the transition that freezes a
     * draft into an immutable issued version, mints the secure link, and
     * (only after commit) emails it.
     *
     * The plaintext token is generated BEFORE the transaction and never
     * stored: only Hash::make()'s value reaches `access_token_hash`, exactly
     * as ClientInvitationManager does. Re-sending ROTATES the token, which
     * invalidates every previously issued link immediately (§5.2).
     *
     * Delivery is dispatched strictly AFTER commit — a recipient must never
     * be emailed a link for a row a later failure rolled back.
     */
    public function send(BusinessDocument $document): BusinessDocument
    {
        $plaintextToken = Str::random(64);

        $result = DB::transaction(function () use ($document, $plaintextToken) {
            $document = BusinessDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
            $this->require(in_array($document->status, [DocumentStatus::Draft, DocumentStatus::Sent], true), 'Only a draft or sent document can be sent.');
            $this->require($document->expires_at === null || $document->expires_at->isFuture(), 'The document offer has already expired.');

            $version = BusinessDocumentVersion::where('business_document_id', $document->id)
                ->where('state', DocumentVersionState::Draft->value)->lockForUpdate()->first();
            $this->require($version !== null, 'No open draft version.');

            $lines = $version->lineItems()->get();
            $this->require($lines->isNotEmpty(), 'A document needs at least one line before it is sent.');
            $this->require((int) $version->total_minor > 0, 'A document needs a resolvable total before it is sent.');
            $this->require($version->currency_code === $document->currency_code, 'Version currency differs from document currency.');

            // §7.1 — THAT version's schedule, valid and summing exactly to the
            // version total in the document's currency.
            $schedule = $version->paymentScheduleItems()->orderBy('sequence')->get();
            $this->require($schedule->isNotEmpty(), 'A payment schedule is required before a document is sent.');
            $expected = $schedule->count() === 1 ? ['full'] : ['deposit', 'balance'];
            $this->require($schedule->count() <= 2, 'Schedule requires full or deposit and balance.');
            $sum = 0;
            foreach ($schedule->values() as $index => $item) {
                $kind = $item->kind instanceof \BackedEnum ? $item->kind->value : (string) $item->kind;
                $this->require((int) $item->sequence === $index + 1 && $kind === $expected[$index]
                    && $item->currency_code === $document->currency_code, 'Invalid payment schedule.');
                $sum += (int) $item->amount_minor;
            }
            $this->require($sum === (int) $version->total_minor, 'Schedule must equal document total.');

            // §5.2 — required and validated before send, then frozen.
            $recipient = $document->recipient_email_snapshot;
            $this->require(is_string($recipient) && trim($recipient) !== ''
                && filter_var(trim($recipient), FILTER_VALIDATE_EMAIL) !== false, 'A valid recipient email address is required before sending.');

            // §5.3.1 — the previous issued version makes its ONE authorized
            // lifecycle transition. Its commercial content is untouched.
            $previous = BusinessDocumentVersion::where('business_document_id', $document->id)
                ->where('state', DocumentVersionState::Issued->value)->orderBy('id')->lockForUpdate()->get();
            foreach ($previous as $old) {
                $old->state = DocumentVersionState::Superseded;
                $old->superseded_at = now();
                $old->save();
            }

            $version->content_hash = $this->hasher->hash($version);
            $version->state = DocumentVersionState::Issued;
            $version->issued_at = now();
            $version->save();

            $document->current_version_id = $version->id;
            $document->access_token_hash = Hash::make($plaintextToken);
            $document->access_token_expires_at = $document->expires_at
                ?? now()->addDays((int) config('documents.link_ttl_days'));
            $document->access_token_rotated_at = now();
            $document->status = DocumentStatus::Sent;
            $document->sent_at = $document->sent_at ?? now();
            $document->save();

            return $document->refresh();
        });

        $version = BusinessDocumentVersion::findOrFail($result->current_version_id);

        // §7.1/§11.3 — delivery ONLY after commit, through the Base-extending
        // SendDocumentLinkEmail job. A recipient must never be emailed a link
        // for a row a later failure inside the transaction rolled back, so
        // that guarantee is held in two independent places: this afterCommit
        // callback, and the job's own ShouldQueueAfterCommit contract.
        //
        // The job is ShouldBeEncrypted because it carries the ONE plaintext
        // token that will ever exist for this link, and §6.3 requires that
        // plaintext never to be stored or recoverable — the default queue
        // connection here is `database`, so an unencrypted payload would sit
        // in `jobs`, and in `failed_jobs` indefinitely on any failure.
        DB::afterCommit(function () use ($result, $version, $plaintextToken) {
            SendDocumentLinkEmail::dispatch((int) $result->id, $plaintextToken);

            DocumentSent::dispatch($result->id, (int) $version->id, (int) $version->version_number);
        });

        return $result;
    }

    /**
     * Implementation Contract 17 §7.1 REVISE — a new draft version N+1 that
     * COPIES the current issued version's lines and schedule commercial
     * terms into NEW rows.
     *
     * The prior version's rows are never touched: that is what makes an
     * already-issued document's commercial content immutable, and what keeps
     * a signature's bound content hash meaningful. The document stays `sent`
     * and the existing link keeps working until the new version is sent.
     *
     * `draft_guard` makes a concurrent second attempt lose at the database
     * rather than producing two open drafts.
     */
    public function revise(BusinessDocument $document, User $actor): BusinessDocumentVersion
    {
        return DB::transaction(function () use ($document, $actor) {
            $document = BusinessDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();

            // A signed agreement is renegotiated by voiding and issuing a new
            // document — never by superseding the version someone signed.
            $this->require($document->status === DocumentStatus::Sent, 'Only a sent document can be revised.');
            $this->require(! $document->signature()->exists(), 'A signed document cannot be revised.');

            $current = BusinessDocumentVersion::where('business_document_id', $document->id)
                ->whereKey($document->current_version_id)->lockForUpdate()->first();
            $this->require($current !== null && $current->state === DocumentVersionState::Issued, 'No issued version to revise.');

            $next = BusinessDocumentVersion::create([
                'business_document_id' => $document->id,
                'version_number' => (int) $current->version_number + 1,
                'content' => $current->content,
                'subtotal_minor' => $current->subtotal_minor,
                'total_minor' => $current->total_minor,
                'currency_code' => $current->currency_code,
                'schema_version' => $current->schema_version,
                'created_by_user_id' => $actor->id,
            ]);

            foreach ($current->lineItems()->orderBy('position')->orderBy('id')->get() as $line) {
                BusinessDocumentLineItem::create([
                    'business_document_version_id' => $next->id,
                    'position' => $line->position,
                    'source' => $line->source,
                    'package_snapshot_uid' => $line->package_snapshot_uid,
                    'name' => $line->name,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit_price_minor' => $line->unit_price_minor,
                    'line_total_minor' => $line->line_total_minor,
                    'currency_code' => $line->currency_code,
                ]);
            }

            // COMMERCIAL TERMS ONLY. status/paid_at/reminder markers are
            // progress, belong to the version that is currently payable, and
            // are never carried into a new version.
            foreach ($current->paymentScheduleItems()->orderBy('sequence')->get() as $item) {
                BusinessDocumentPaymentScheduleItem::create([
                    'business_document_version_id' => $next->id,
                    'sequence' => $item->sequence,
                    'kind' => $item->kind instanceof \BackedEnum ? $item->kind->value : $item->kind,
                    'amount_minor' => $item->amount_minor,
                    'currency_code' => $item->currency_code,
                    'due_at' => $item->due_at,
                ]);
            }

            return $next->refresh();
        });
    }

    /**
     * Implementation Contract 17 §7.1 SIGN / §5.5 / §6.5 — first-party,
     * provider-neutral TYPED signature evidence for exactly one signer.
     *
     * The row binds `business_document_version_id` + `signed_content_hash`,
     * so "what did they actually agree to" is answerable from the row alone.
     * The hash is the one already frozen at issue — it is read from the
     * version, never recomputed here, because recomputing would silently
     * accept content that had drifted.
     *
     * `unique(business_document_id)` makes a double-submit impossible even
     * under a race; the pre-check below simply turns that into a clean
     * refusal rather than a constraint violation.
     *
     * This is a TECHNICAL signing record. Nothing here asserts that it is a
     * qualified or advanced signature, that the signer's identity was
     * verified, or that it is legally sufficient anywhere (§6.5).
     *
     * @param  array{signer_name: string, signer_email: string, typed_name: string, ip_address: string, user_agent: ?string}  $evidence
     */
    public function sign(BusinessDocument $document, array $evidence): BusinessDocumentSignature
    {
        $result = DB::transaction(function () use ($document, $evidence) {
            $document = BusinessDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();

            // Checked FIRST so a second submission gets the accurate refusal
            // rather than the generic status one — the document is already
            // `signed` by then, and unique(business_document_id) would refuse
            // it anyway, even under a race.
            $this->require(! $document->signature()->exists(), 'This document has already been signed.');
            $this->require($document->status === DocumentStatus::Sent, 'This document is not awaiting signature.');
            $this->require((bool) $document->requires_signature, 'This document does not require a signature.');
            $this->require($document->expires_at === null || $document->expires_at->isFuture(), 'This document has expired.');

            $version = BusinessDocumentVersion::where('business_document_id', $document->id)
                ->whereKey($document->current_version_id)->lockForUpdate()->first();
            $this->require($version !== null && $version->state === DocumentVersionState::Issued, 'No issued version to sign.');
            $this->require(is_string($version->content_hash) && strlen($version->content_hash) === 64, 'The issued version has no content hash.');

            $signerName = trim((string) ($evidence['signer_name'] ?? ''));
            $signerEmail = trim((string) ($evidence['signer_email'] ?? ''));
            $typedName = trim((string) ($evidence['typed_name'] ?? ''));
            $this->require($signerName !== '' && mb_strlen($signerName) <= 160, 'A signer name is required.');
            $this->require($typedName !== '' && mb_strlen($typedName) <= 160, 'A typed signature is required.');
            $this->require($signerEmail !== '' && mb_strlen($signerEmail) <= 255
                && filter_var($signerEmail, FILTER_VALIDATE_EMAIL) !== false, 'A valid signer email address is required.');

            $signature = BusinessDocumentSignature::create([
                'business_document_id' => $document->id,
                'business_document_version_id' => $version->id,
                'signed_content_hash' => $version->content_hash,
                'signer_name' => $signerName,
                'signer_email' => $signerEmail,
                'typed_name' => $typedName,
                'signature_method' => DocumentSignatureMethod::Typed->value,
                'consent_statement' => self::CONSENT_STATEMENT,
                'consent_statement_hash' => hash('sha256', self::CONSENT_STATEMENT),
                'ip_address' => mb_substr((string) ($evidence['ip_address'] ?? ''), 0, 45),
                'user_agent' => $evidence['user_agent'] === null ? null : mb_substr((string) $evidence['user_agent'], 0, 512),
                'signed_at' => now(),
            ]);

            $document->status = DocumentStatus::Signed;
            $document->signed_at = now();
            $document->save();

            return $signature;
        });

        DB::afterCommit(fn () => DocumentSigned::dispatch(
            (int) $result->business_document_id,
            (int) $result->business_document_version_id,
            (int) $result->id,
        ));

        return $result;
    }

    public function addCatalogLine(BusinessDocument $document, CatalogItem $item, int $quantity, User $actor, ?int $explicitPriceMinor = null): BusinessDocumentLineItem
    {
        return DB::transaction(function () use ($document, $item, $quantity, $actor, $explicitPriceMinor) {
            [$document, $version] = $this->draft($document);
            $this->validQuantity($quantity);
            $location = BusinessLocation::findOrFail($document->business_location_id);
            $this->require((int) $location->business_id === (int) $document->business_id && $location->isActive(), 'Invalid document Location.');
            $item = CatalogItem::findOrFail($item->id);
            $this->require((int) $item->business_id === (int) $document->business_id, 'Foreign catalog item.');
            $snapshot = $this->snapshots->snapshot($item, $location, $actor, $explicitPriceMinor);
            $this->require($snapshot->currency_code_at_snapshot === $document->currency_code, 'Catalog currency differs from document currency.');
            $line = $this->insertLine($version, 'catalog', $snapshot->uid, $snapshot->name_at_snapshot, $snapshot->description_at_snapshot, $quantity, (int) $snapshot->price_minor_at_snapshot, $document->currency_code);
            $this->recalculate($version);
            return $line;
        });
    }

    public function addCustomLine(BusinessDocument $document, string $name, ?string $description, int $quantity, int $unitPriceMinor): BusinessDocumentLineItem
    {
        return DB::transaction(function () use ($document, $name, $description, $quantity, $unitPriceMinor) {
            [$document, $version] = $this->draft($document);
            $this->validQuantity($quantity);
            $this->require(trim($name) !== '' && mb_strlen($name) <= 200 && $unitPriceMinor >= 0, 'Invalid custom line.');
            $line = $this->insertLine($version, 'custom', null, trim($name), $description, $quantity, $unitPriceMinor, $document->currency_code);
            $this->recalculate($version);
            return $line;
        });
    }

    public function removeLine(BusinessDocument $document, BusinessDocumentLineItem $line): void
    {
        DB::transaction(function () use ($document, $line) {
            [, $version] = $this->draft($document);
            $line = BusinessDocumentLineItem::where('business_document_version_id', $version->id)->findOrFail($line->id);
            $line->delete();
            $this->recalculate($version);
        });
    }

    public function reorderLines(BusinessDocument $document, array $lineIds): void
    {
        DB::transaction(function () use ($document, $lineIds) {
            [, $version] = $this->draft($document);
            $lines = $version->lineItems()->orderBy('id')->get();
            $this->require(count($lineIds) === $lines->count() && count(array_unique($lineIds)) === count($lineIds)
                && array_diff($lineIds, $lines->pluck('id')->all()) === [], 'Invalid line order.');
            foreach ($lineIds as $position => $id) {
                BusinessDocumentLineItem::whereKey($id)->where('business_document_version_id', $version->id)->update(['position' => $position]);
            }
        });
    }

    public function setSchedule(BusinessDocument $document, array $terms): void
    {
        DB::transaction(function () use ($document, $terms) {
            [$document, $version] = $this->draft($document);
            $this->require(count($terms) === 1 || count($terms) === 2, 'Schedule requires full or deposit and balance.');
            $expected = count($terms) === 1 ? ['full'] : ['deposit', 'balance'];
            $sum = 0;
            foreach ($terms as $index => $term) {
                $amount = $term['amount_minor'] ?? null;
                $this->require(is_int($amount) && $amount >= 0 && ($term['kind'] ?? null) === $expected[$index]
                    && ($term['currency_code'] ?? null) === $document->currency_code, 'Invalid payment schedule.');
                $this->require($sum <= PHP_INT_MAX - $amount, 'Schedule overflow.');
                $sum += $amount;
            }
            $this->require($sum === (int) $version->total_minor, 'Schedule must equal document total.');
            $version->paymentScheduleItems()->delete();
            foreach ($terms as $index => $term) {
                BusinessDocumentPaymentScheduleItem::create([
                    'business_document_version_id' => $version->id, 'sequence' => $index + 1,
                    'kind' => $term['kind'], 'amount_minor' => $term['amount_minor'],
                    'currency_code' => $document->currency_code, 'due_at' => $term['due_at'] ?? null,
                ]);
            }
        });
    }

    public function void(BusinessDocument $document, string $reason): BusinessDocument
    {
        $result = DB::transaction(function () use ($document, $reason) {
            $document = BusinessDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
            $this->require(in_array($document->status, [DocumentStatus::Draft, DocumentStatus::Sent, DocumentStatus::Signed], true), 'Document cannot be voided.');
            $this->require(trim($reason) !== '' && mb_strlen($reason) <= 255, 'Void reason required.');
            $version = $document->current_version_id
                ? BusinessDocumentVersion::whereKey($document->current_version_id)->lockForUpdate()->first()
                : BusinessDocumentVersion::where('business_document_id', $document->id)->where('state', 'draft')->lockForUpdate()->first();
            $this->require($version === null || (int) $version->business_document_id === (int) $document->id, 'Invalid current version.');
            $schedule = $version ? $version->paymentScheduleItems()->orderBy('id')->lockForUpdate()->get() : collect();
            $payments = $document->payments()->orderBy('id')->lockForUpdate()->get();
            $refunds = $payments->isEmpty() ? collect() : DB::table('business_document_refunds')
                ->whereIn('business_document_payment_id', $payments->pluck('id'))
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($payments as $payment) {
                if ($payment->status === BusinessDocumentPaymentStatus::Succeeded) {
                    $refunded = $refunds->filter(fn ($refund) => (int) $refund->business_document_payment_id === (int) $payment->id
                        && $refund->status === 'succeeded')->sum('amount_minor');
                    $this->require($refunded >= $payment->amount_minor, 'Captured payment must be refunded before void.');
                }
            }
            foreach ($schedule as $item) {
                if ($item->status->value === 'pending') {
                    $item->status = 'void';
                    $item->save();
                }
            }
            $document->status = DocumentStatus::Void;
            $document->voided_at = now();
            $document->void_reason = trim($reason);
            $document->access_token_hash = null;
            $document->save();
            return $document->refresh();
        });
        DB::afterCommit(fn () => DocumentVoided::dispatch($result->id));
        return $result;
    }

    // =================================================================
    // Sub-slice F — the two bounded document sweeps (§8.6 / §8.4)
    // =================================================================

    /**
     * §8.6 — expire OFFERS, and nothing else.
     *
     * `expires_at` is an offer / unpaid-document expiry. A document may be
     * swept only when ALL THREE hold, and they are re-verified under the lock:
     *
     *   1. `status = sent` — so a `signed` proposal is structurally out of
     *      reach, as are the terminal `paid` / `void` / `expired`;
     *   2. NO signature row exists;
     *   3. ZERO succeeded payments exist.
     *
     * Condition 3 is what keeps a deposit from being stranded: a deposit-paid,
     * balance-outstanding document stays `sent`, its balance stays due on its
     * own `due_at`, and reminders continue.
     *
     * NO SCHEDULE TRANSITION. §8.6 prescribes exactly one state change — the
     * document's — and unlike §7.1's void, it does not void the pending
     * schedule items. Nothing is invented here: an expired document is already
     * unpayable (assertDocumentPayable refuses `expired`) and already
     * un-remindable (dispatchDueReminders selects only `sent`/`signed`), so
     * the pending rows are inert, and the version's content stays exactly as
     * it was issued.
     *
     * BOUNDED, never drain-to-empty; the scheduler's cadence provides eventual
     * coverage. Each row is its own short transaction, so one failure cannot
     * abort the batch.
     */
    public function expireDue(int $limit): int
    {
        $candidates = BusinessDocument::query()
            ->where('status', DocumentStatus::Sent->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $expired = 0;
        foreach ($candidates as $documentId) {
            try {
                $result = DB::transaction(function () use ($documentId) {
                    $document = BusinessDocument::whereKey($documentId)->lockForUpdate()->first();
                    if ($document === null || $document->status !== DocumentStatus::Sent) {
                        return null;
                    }
                    if ($document->expires_at === null || $document->expires_at->gt(now())) {
                        return null;
                    }
                    if ($document->signature()->exists()) {
                        return null;
                    }
                    $succeeded = $document->payments()
                        ->where('status', BusinessDocumentPaymentStatus::Succeeded->value)
                        ->exists();
                    if ($succeeded) {
                        return null;
                    }
                    $document->status = DocumentStatus::Expired;
                    $document->expired_at = now();
                    $document->save();
                    return $document->id;
                });
                if ($result !== null) {
                    $expired++;
                    DB::afterCommit(fn () => DocumentExpired::dispatch($result));
                }
            } catch (Throwable $e) {
                Log::error('DocumentManager::expireDue failed to expire a document', [
                    'business_document_id' => $documentId,
                    'exception' => $e,
                ]);
            }
        }
        return $expired;
    }

    /**
     * §8.4 — payment and offer-expiry reminders, deduped by DURABLE MARKERS
     * this manager writes inside the same locked transaction that selects the
     * row. The job never writes the table.
     *
     * THE WINDOW RULE. `documents.reminder_offsets_days` (e.g. [3, 1]) defines
     * one window per offset: window N opens at `anchor - offsets[N-1]` days.
     * For any row we take the LATEST window already open, W, and send only if
     * `reminder_count < W` — then set the count to W, not W+1. Two
     * consequences, both deliberate:
     *
     *   - a rerun sends NOTHING, because W has not moved and the count now
     *     equals it. That is what makes the command safe to run every few
     *     minutes;
     *   - a document that appears when several windows are already open gets
     *     ONE reminder, not a backlog of them.
     *
     * CURRENT VERSION ONLY (§8.4/§5.9). The schedule is read through
     * `current_version_id`, so an item belonging to a superseded version is
     * never reminded — its version is not the payable one, and reminding for
     * it would ask the recipient to pay terms that no longer apply.
     *
     * DEPOSIT / BALANCE SEMANTICS INTACT (§7.2 step 7). Only the CURRENTLY
     * PAYABLE item — the earliest pending item with every earlier sequence
     * already paid — is reminded. So an unpaid deposit is reminded and the
     * balance behind it is not; once the deposit succeeds, the balance becomes
     * the payable item and starts receiving its own reminders.
     *
     * NO STARVATION. Each driving query excludes rows that have already used
     * every window (`reminder_count < count($offsets)`) and orders
     * NEVER-REMINDED ROWS FIRST, then least-recently-reminded. Ordering by id
     * would let the same already-reminded rows fill every bounded batch and
     * starve everything behind them forever.
     *
     * `--limit` bounds EACH of the two candidate sets: outstanding schedule
     * items, and documents approaching their offer expiry.
     */
    public function dispatchDueReminders(int $limit): int
    {
        $offsets = $this->reminderOffsets();
        if ($offsets === []) {
            return 0;
        }
        $now = now();
        $horizon = $now->copy()->addDays($offsets[0]);
        $windows = count($offsets);
        $dispatched = 0;
        // (a) PAYMENT reminders, driven by the item itself and joined to the
        // document through current_version_id — which is exactly what makes a
        // superseded version's item unreachable here (§5.9).
        $items = BusinessDocumentPaymentScheduleItem::query()
            ->join('business_documents as reminder_doc', 'reminder_doc.current_version_id', '=', 'business_document_payment_schedule_items.business_document_version_id')
            ->whereIn('reminder_doc.status', [DocumentStatus::Sent->value, DocumentStatus::Signed->value])
            ->where('business_document_payment_schedule_items.status', PaymentScheduleItemStatus::Pending->value)
            ->whereNotNull('business_document_payment_schedule_items.due_at')
            ->where('business_document_payment_schedule_items.due_at', '<=', $horizon)
            ->where('business_document_payment_schedule_items.reminder_count', '<', $windows)
            ->orderByRaw('business_document_payment_schedule_items.reminder_last_sent_at IS NOT NULL')
            ->orderBy('business_document_payment_schedule_items.reminder_last_sent_at')
            ->orderBy('business_document_payment_schedule_items.id')
            ->limit($limit)
            ->get(['business_document_payment_schedule_items.id as item_id', 'reminder_doc.id as document_id']);
        foreach ($items as $candidate) {
            try {
                $dispatched += $this->remindPayment((int) $candidate->document_id, (int) $candidate->item_id, $offsets);
            } catch (Throwable $e) {
                Log::error('DocumentManager::dispatchDueReminders failed for a schedule item', [
                    'business_document_payment_schedule_item_id' => $candidate->item_id,
                    'exception' => $e,
                ]);
            }
        }
        // (b) OFFER-EXPIRY warnings, on the document's own marker.
        $documents = BusinessDocument::query()
            ->where('status', DocumentStatus::Sent->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', $now)
            ->where('expires_at', '<=', $horizon)
            ->where('expiry_reminder_count', '<', $windows)
            ->orderByRaw('expiry_reminder_last_sent_at IS NOT NULL')
            ->orderBy('expiry_reminder_last_sent_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        foreach ($documents as $documentId) {
            try {
                $dispatched += $this->remindExpiry((int) $documentId, $offsets);
            } catch (Throwable $e) {
                Log::error('DocumentManager::dispatchDueReminders failed for a document expiry', [
                    'business_document_id' => $documentId,
                    'exception' => $e,
                ]);
            }
        }
        return $dispatched;
    }

    /**
     * One item, one short transaction, §7.0 order: document (tier 1) then the
     * current version's schedule items (tier 2, ascending sequence).
     *
     * Every precondition is RE-VERIFIED under the lock, because the candidate
     * query ran without one: the document may have been voided, the version
     * superseded, or the item paid in between.
     *
     * @param  array<int, int>  $offsets
     */
    private function remindPayment(int $documentId, int $itemId, array $offsets): int
    {
        return DB::transaction(function () use ($documentId, $itemId, $offsets) {
            $now = now();
            $document = BusinessDocument::whereKey($documentId)->lockForUpdate()->first();
            if ($document === null
                || ! in_array($document->status, [DocumentStatus::Sent, DocumentStatus::Signed], true)
                || $document->current_version_id === null) {
                return 0;
            }
            $schedule = BusinessDocumentPaymentScheduleItem::where('business_document_version_id', $document->current_version_id)
                ->orderBy('sequence')->lockForUpdate()->get();
            $payable = $schedule->first(fn ($row) => $row->status === PaymentScheduleItemStatus::Pending);
            // §7.2 step 7 — only the item that is actually owed NOW is chased:
            // the earliest pending one, with every earlier sequence settled.
            if ($payable === null || (int) $payable->id !== $itemId) {
                return 0;
            }
            $blocked = $schedule->contains(fn ($row) => (int) $row->sequence < (int) $payable->sequence
                && $row->status !== PaymentScheduleItemStatus::Paid);
            if ($blocked) {
                return 0;
            }
            $window = $this->dueReminderWindow($payable->due_at, $offsets, (int) $payable->reminder_count, $payable->reminder_last_sent_at, $now);
            if ($window === null) {
                return 0;
            }
            $payable->forceFill(['reminder_last_sent_at' => $now, 'reminder_count' => $window])->save();
            DB::afterCommit(fn () => SendDocumentReminderEmail::dispatch($documentId, $itemId));
            return 1;
        });
    }

    /**
     * The offer-expiry warning is only meaningful while the document can still
     * ACTUALLY expire under §8.6 — `sent`, unsigned, and with nothing captured
     * against it. Warning about an expiry that will never happen is a lie.
     *
     * @param  array<int, int>  $offsets
     */
    private function remindExpiry(int $documentId, array $offsets): int
    {
        return DB::transaction(function () use ($documentId, $offsets) {
            $now = now();
            $document = BusinessDocument::whereKey($documentId)->lockForUpdate()->first();
            if ($document === null
                || $document->status !== DocumentStatus::Sent
                || $document->expires_at === null
                || $document->expires_at->lte($now)
                || $document->signature()->exists()
                || $document->payments()->where('status', BusinessDocumentPaymentStatus::Succeeded->value)->exists()) {
                return 0;
            }
            $window = $this->dueReminderWindow($document->expires_at, $offsets, (int) $document->expiry_reminder_count, $document->expiry_reminder_last_sent_at, $now);
            if ($window === null) {
                return 0;
            }
            $document->forceFill(['expiry_reminder_last_sent_at' => $now, 'expiry_reminder_count' => $window])->save();
            DB::afterCommit(fn () => SendDocumentReminderEmail::dispatch($documentId, null));
            return 1;
        });
    }

    /**
     * The latest OPEN window a row has not been reminded in yet, 1-based, or
     * null when nothing is due.
     *
     * @param  array<int, int>  $offsets  descending days-before-anchor
     */
    private function dueReminderWindow(?object $anchor, array $offsets, int $sentCount, ?object $lastSentAt, object $now): ?int
    {
        if ($anchor === null) {
            return null;
        }
        $open = null;
        $openedAt = null;
        foreach ($offsets as $index => $days) {
            $opensAt = $anchor->copy()->subDays($days);
            if ($now->gte($opensAt)) {
                $open = $index + 1;
                $openedAt = $opensAt;
            }
        }
        if ($open === null || $sentCount >= $open) {
            return null;
        }
        // Belt and braces: even if a count were ever repaired by hand, one
        // window still yields at most one reminder.
        if ($lastSentAt !== null && $lastSentAt->gte($openedAt)) {
            return null;
        }
        return $open;
    }

    /**
     * Sanitised, DESCENDING, so index 0 is the widest (earliest) window.
     *
     * @return array<int, int>
     */
    private function reminderOffsets(): array
    {
        $configured = config('documents.reminder_offsets_days', []);
        if (! is_array($configured)) {
            return [];
        }
        $offsets = [];
        foreach ($configured as $value) {
            if ((is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value >= 0) {
                $offsets[] = (int) $value;
            }
        }
        $offsets = array_values(array_unique($offsets));
        rsort($offsets);
        return $offsets;
    }

    private function draft(BusinessDocument $document): array
    {
        $document = BusinessDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
        $this->require(in_array($document->status, [DocumentStatus::Draft, DocumentStatus::Sent], true), 'Only an open draft can be authored.');
        $version = BusinessDocumentVersion::where('business_document_id', $document->id)->where('state', DocumentVersionState::Draft->value)->lockForUpdate()->first();
        $this->require($version !== null, 'No open draft version.');
        return [$document, $version];
    }

    private function assertIdentity(Business $business, BusinessLocation $location, Contacts $contact, ?CrmOpportunity $opportunity): void
    {
        $this->require((int) $location->business_id === (int) $business->id && $location->isActive(), 'Invalid Business Location.');
        $this->require((int) $contact->business_id === (int) $business->id && $contact->location_id !== null
            && (int) $contact->location_id === (int) $location->id, 'Invalid Contact.');
        if ($opportunity) {
            $this->require((int) $opportunity->business_id === (int) $business->id && $opportunity->location_id !== null
                && (int) $opportunity->location_id === (int) $location->id && (int) $opportunity->contact_id === (int) $contact->id, 'Invalid Opportunity.');
        }
    }

    private function insertLine(BusinessDocumentVersion $version, string $source, ?string $snapshotUid, string $name, ?string $description, int $quantity, int $unitPrice, string $currency): BusinessDocumentLineItem
    {
        $this->require($unitPrice >= 0 && $unitPrice <= intdiv(PHP_INT_MAX, $quantity), 'Line total overflow.');
        $position = (int) $version->lineItems()->max('position') + 1;
        return BusinessDocumentLineItem::create([
            'business_document_version_id' => $version->id, 'position' => $position, 'source' => $source,
            'package_snapshot_uid' => $snapshotUid, 'name' => $name, 'description' => $description,
            'quantity' => $quantity, 'unit_price_minor' => $unitPrice, 'line_total_minor' => $unitPrice * $quantity,
            'currency_code' => $currency,
        ]);
    }

    private function recalculate(BusinessDocumentVersion $version): void
    {
        $sum = 0;
        foreach ($version->lineItems as $line) {
            $this->require($sum <= PHP_INT_MAX - $line->line_total_minor, 'Document total overflow.');
            $sum += $line->line_total_minor;
        }
        $version->subtotal_minor = $sum;
        $version->total_minor = $sum;
        $version->save();
        $version->paymentScheduleItems()->delete();
    }

    private function validQuantity(int $quantity): void
    {
        $this->require($quantity > 0 && $quantity <= 4294967295, 'Invalid quantity.');
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['document' => $message]);
        }
    }
}

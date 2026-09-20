<?php

namespace App\Library\Documents;

use App\Enums\Documents\DocumentStatus;
use App\Enums\Documents\DocumentVersionState;
use App\Enums\Documents\BusinessDocumentPaymentStatus;
use App\Events\DocumentVoided;
use App\Library\Catalog\PackageSnapshotService;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentLineItem;
use App\Models\BusinessDocumentPaymentScheduleItem;
use App\Models\BusinessDocumentVersion;
use App\Models\BusinessLocation;
use App\Models\CatalogItem;
use App\Models\Contacts;
use App\Models\CrmOpportunity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DocumentManager
{
    public function __construct(private readonly PackageSnapshotService $snapshots) {}

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
            $this->require($document->status === DocumentStatus::Draft, 'Only an open draft can be edited.');
            if (array_key_exists('title', $attributes)) {
                $title = trim((string) $attributes['title']);
                $this->require($title !== '' && mb_strlen($title) <= 200, 'Invalid document title.');
                $document->title = $title;
            }
            if (array_key_exists('content', $attributes)) {
                $this->require(is_array($attributes['content']), 'Invalid draft content.');
                $version->content = $attributes['content'];
                $version->save();
            }
            $document->save();
            return $document->refresh();
        });
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
            $schedule = $version ? $version->paymentScheduleItems()->orderBy('id')->lockForUpdate()->get() : collect();
            $payments = $document->payments()->orderBy('id')->lockForUpdate()->get();
            foreach ($payments as $payment) {
                if ($payment->status === BusinessDocumentPaymentStatus::Succeeded) {
                    $refunded = DB::table('business_document_refunds')->where('business_document_payment_id', $payment->id)->where('status', 'succeeded')->sum('amount_minor');
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

    private function draft(BusinessDocument $document): array
    {
        $document = BusinessDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
        $this->require($document->status === DocumentStatus::Draft, 'Only an open draft can be authored.');
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

<?php

declare(strict_types=1);

namespace App\Library\Growth\Readers;

use App\Enums\Entitlement\PlatformFeature;
use App\Library\Growth\GrowthFactReader;
use App\Library\Growth\GrowthFactSet;
use App\Library\Growth\GrowthThresholds;
use App\Library\Growth\Readers\Concerns\BucketsByLocation;
use App\Models\Business;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Proposal / invoice and receivable facts (domain `documents`), read from the
 * canonical document, version, schedule-item and payment tables. Growth
 * creates no second receivables system: the amounts are the schedule items'
 * own `amount_minor`, and what is "unpaid" is the schedule item's own
 * `pending` status.
 *
 * Four constant queries however many documents exist.
 *
 * A document is PAYABLE when it is `signed`, or it is `sent` and needs no
 * signature (an invoice). Only a payable document's CURRENT version's
 * `pending` items are receivables; void/expired/paid/draft documents and
 * superseded versions never count.
 *
 * Fact shape:
 *   sent_total        int   documents ever sent (sent, signed or paid) — the population
 *   by_location       array<int, array{unsigned, signed_unpaid, overdue, failed_payment}>
 *                      keyed by Location id; each a bucket
 *                      {count, value_minor, currency, mixed_currency, uids}
 *
 *   unsigned        sent, requires a signature, sent_at older than
 *                   proposal_unsigned_days, not expired. Value = version total.
 *   signed_unpaid   signed at least signed_unpaid_days ago, has pending items,
 *                   NONE of them overdue. Value = pending items.
 *   overdue         payable, with one or more pending items past due_at.
 *                   Value = the overdue items.
 *   failed_payment  payable, with a payment attempt that FAILED inside
 *                   failed_payment_lookback_days while its item is still
 *                   pending. Value = that item. (A document can appear here AND
 *                   in overdue/signed_unpaid: "the card was declined" and "this
 *                   is owed" are two different things to fix.)
 * "Not yet due" never counts as overdue, and a document with no due date can
 * only ever be signed_unpaid.
 */
final class GrowthDocumentFactReader implements GrowthFactReader
{
    use BucketsByLocation;

    private const ROW_CAP = 5000;

    public function domain(): string
    {
        return 'documents';
    }

    public function feature(): ?PlatformFeature
    {
        return PlatformFeature::PaymentsContracts;
    }

    public function read(Business $business, CarbonImmutable $now, GrowthThresholds $thresholds): GrowthFactSet
    {
        $sentTotal = (int) DB::table('business_documents')
            ->where('business_id', $business->id)
            ->whereIn('status', ['sent', 'signed', 'paid'])
            ->count();

        $unsigned = DB::table('business_documents as d')
            ->leftJoin('business_document_versions as v', 'v.id', '=', 'd.current_version_id')
            ->where('d.business_id', $business->id)
            ->where('d.status', 'sent')
            ->where('d.requires_signature', true)
            ->where('d.sent_at', '<=', $now->subDays($thresholds->get('proposal_unsigned_days')))
            ->where(fn ($q) => $q->whereNull('d.expires_at')->orWhere('d.expires_at', '>', $now))
            ->orderBy('d.id')
            ->limit(self::ROW_CAP)
            ->get(['d.uid', 'd.business_location_id', 'd.currency_code', 'v.total_minor']);

        $pending = DB::table('business_documents as d')
            ->join('business_document_payment_schedule_items as i', 'i.business_document_version_id', '=', 'd.current_version_id')
            ->where('d.business_id', $business->id)
            ->where('i.status', 'pending')
            ->where(fn ($q) => $q
                ->where('d.status', 'signed')
                ->orWhere(fn ($q2) => $q2->where('d.status', 'sent')->where('d.requires_signature', false)))
            ->orderBy('d.id')
            ->limit(self::ROW_CAP)
            ->get(['d.id as document_id', 'd.uid', 'd.business_location_id', 'd.signed_at', 'i.id as item_id', 'i.amount_minor', 'i.currency_code', 'i.due_at']);

        $failedItemIds = DB::table('business_document_payments')
            ->where('business_id', $business->id)
            ->where('status', 'failed')
            ->where('created_at', '>=', $now->subDays($thresholds->get('failed_payment_lookback_days')))
            ->pluck('schedule_item_id')
            ->flip();

        $byLocation = [];
        $bucketFor = function (int $key, string $name) use (&$byLocation): array {
            return $byLocation[$key][$name] ?? $this->emptyBucket();
        };
        $seed = function (int $key) use (&$byLocation): void {
            $byLocation[$key] ??= [
                'unsigned' => $this->emptyBucket(),
                'signed_unpaid' => $this->emptyBucket(),
                'overdue' => $this->emptyBucket(),
                'failed_payment' => $this->emptyBucket(),
            ];
        };

        foreach ($unsigned as $row) {
            $key = $this->locationKey($row->business_location_id);
            $seed($key);
            $byLocation[$key]['unsigned'] = $this->addToBucket($bucketFor($key, 'unsigned'), $row->uid, is_null($row->total_minor) ? null : (int) $row->total_minor, $row->currency_code);
        }

        $signedBefore = $now->subDays($thresholds->get('signed_unpaid_days'));
        $documents = $pending->groupBy('document_id');

        foreach ($documents as $items) {
            $first = $items->first();
            $key = $this->locationKey($first->business_location_id);
            $seed($key);

            $overdueItems = $items->filter(fn ($i) => $i->due_at !== null && CarbonImmutable::parse($i->due_at)->lt($now));

            if ($overdueItems->isNotEmpty()) {
                $bucket = $bucketFor($key, 'overdue');
                $uid = $first->uid;
                $first = $overdueItems->first();
                $bucket = $this->addToBucket($bucket, $uid, (int) $overdueItems->sum('amount_minor'), $first->currency_code);
                $byLocation[$key]['overdue'] = $bucket;
            } elseif ($first->signed_at !== null && CarbonImmutable::parse($first->signed_at)->lte($signedBefore)) {
                $byLocation[$key]['signed_unpaid'] = $this->addToBucket(
                    $bucketFor($key, 'signed_unpaid'),
                    $first->uid,
                    (int) $items->sum('amount_minor'),
                    $first->currency_code,
                );
            }

            $failed = $items->filter(fn ($i) => $failedItemIds->has($i->item_id));

            if ($failed->isNotEmpty()) {
                $byLocation[$key]['failed_payment'] = $this->addToBucket(
                    $bucketFor($key, 'failed_payment'),
                    $items->first()->uid,
                    (int) $failed->sum('amount_minor'),
                    $failed->first()->currency_code,
                );
            }
        }

        return GrowthFactSet::available($this->domain(), [
            'sent_total' => $sentTotal,
            'by_location' => $byLocation,
        ]);
    }
}

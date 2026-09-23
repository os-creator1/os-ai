<?php

namespace App\Library\Timeline\Sources;

use App\Enums\Documents\DocumentKind;
use App\Enums\Timeline\TimelineItemKind;
use App\Enums\Timeline\TimelineTone;
use App\Library\Timeline\Contracts\TimelineSource;
use App\Library\Timeline\TimelineItem;
use App\Library\Timeline\TimelineSubject;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Implementation Contract 17 §12.G — the Payments & Contracts lifecycle
 * (`business_documents`, `business_document_payments`,
 * `business_document_refunds`) surfaced on the same person-centric timeline
 * every other domain joins by implementing TimelineSource (§10, Blueprint
 * §24's "payment events reach the Activity Center" plus the timeline's own
 * finished-sentence convention).
 *
 * CONTACT-KEYED, exactly like AutomationActivitySource: nothing is shown
 * unless the conversation resolves to exactly one Contact of this Business,
 * and that guard is this source's own — a document's Location was already
 * checked before this Contact's Conversation could ever be opened (Contract
 * 08B, ChatBoxController::resolveBusinessChatBox()), so this source needs no
 * second Location check of its own, the same division of responsibility
 * every existing source in this list already relies on.
 *
 * NO DocumentViewed. The public GET is genuinely side-effect-free (§10) and
 * this source reads only durable lifecycle timestamps and payment/refund
 * rows — never an inferred or invented event.
 */
final class DocumentActivitySource implements TimelineSource
{
    public function recent(TimelineSubject $subject, int $limit): array
    {
        if ($subject->contact === null || (int) $subject->contact->business_id !== (int) $subject->business->id) {
            return [];
        }

        $businessId = (int) $subject->business->id;
        $contactId = (int) $subject->contact->id;

        $documents = DB::table('business_documents')
            ->where('business_id', $businessId)
            ->where('contact_id', $contactId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'kind', 'sent_at', 'signed_at', 'paid_at', 'expired_at', 'voided_at']);

        $documentIds = $documents->pluck('id')->map(fn ($id) => (int) $id)->all();

        $items = array_merge(
            $this->documentLifecycle($documents),
            $this->payments($businessId, $documentIds, $limit),
            $this->refunds($businessId, $documentIds, $limit),
        );

        usort($items, static fn (TimelineItem $a, TimelineItem $b): int => [$b->at->getTimestamp(), $b->sequence] <=> [$a->at->getTimestamp(), $a->sequence]);

        return array_slice($items, 0, $limit);
    }

    /** @return list<TimelineItem> */
    private function documentLifecycle(\Illuminate\Support\Collection $documents): array
    {
        $items = [];

        foreach ($documents as $row) {
            $id = (int) $row->id;
            $name = self::kindName($row->kind);

            foreach ([
                ['sent_at', 'document_sent:', $name . ' sent', TimelineTone::Neutral],
                ['signed_at', 'document_signed:', $name . ' signed', TimelineTone::Success],
                ['paid_at', 'document_paid:', $name . ' paid', TimelineTone::Success],
                ['expired_at', 'document_expired:', $name . ' expired', TimelineTone::Warning],
                ['voided_at', 'document_voided:', $name . ' voided', TimelineTone::Neutral],
            ] as [$column, $keyPrefix, $title, $tone]) {
                if ($row->{$column} === null) {
                    continue;
                }

                $items[] = new TimelineItem(
                    key: $keyPrefix . $id,
                    kind: TimelineItemKind::Activity,
                    at: self::time($row->{$column}),
                    title: $title,
                    tone: $tone,
                    icon: 'file-text',
                    sequence: $id,
                );
            }
        }

        return $items;
    }

    /**
     * @param  list<int>  $documentIds
     * @return list<TimelineItem>
     */
    private function payments(int $businessId, array $documentIds, int $limit): array
    {
        if ($documentIds === []) {
            return [];
        }

        return DB::table('business_document_payments')
            ->where('business_id', $businessId)
            ->whereIn('business_document_id', $documentIds)
            ->where('status', 'succeeded')
            ->whereNotNull('succeeded_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'succeeded_at'])
            ->map(static fn (object $row): TimelineItem => new TimelineItem(
                key: 'document_payment:' . $row->id,
                kind: TimelineItemKind::Activity,
                at: self::time($row->succeeded_at),
                title: 'Payment received',
                tone: TimelineTone::Success,
                icon: 'credit-card',
                sequence: (int) $row->id,
            ))
            ->all();
    }

    /**
     * @param  list<int>  $documentIds
     * @return list<TimelineItem>
     */
    private function refunds(int $businessId, array $documentIds, int $limit): array
    {
        if ($documentIds === []) {
            return [];
        }

        return DB::table('business_document_refunds as r')
            ->join('business_document_payments as p', 'p.id', '=', 'r.business_document_payment_id')
            ->where('r.business_id', $businessId)
            ->whereIn('p.business_document_id', $documentIds)
            ->where('r.status', 'succeeded')
            ->whereNotNull('r.succeeded_at')
            ->orderByDesc('r.id')
            ->limit($limit)
            ->get(['r.id', 'r.succeeded_at'])
            ->map(static fn (object $row): TimelineItem => new TimelineItem(
                key: 'document_refund:' . $row->id,
                kind: TimelineItemKind::Activity,
                at: self::time($row->succeeded_at),
                title: 'Payment refunded',
                tone: TimelineTone::Neutral,
                icon: 'credit-card',
                sequence: (int) $row->id,
            ))
            ->all();
    }

    private static function kindName(string $kind): string
    {
        return match ($kind) {
            DocumentKind::Invoice->value => 'Invoice',
            default => 'Proposal',
        };
    }

    private static function time(mixed $value): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $value, config('app.timezone'));
    }
}

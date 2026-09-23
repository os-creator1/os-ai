<?php

namespace App\Library\Documents;

use App\Library\Support\CanonicalJson;
use App\Models\BusinessDocumentVersion;
use Illuminate\Support\Carbon;

/**
 * Implementation Contract 17 §5.3.2 — `content_hash`, defined.
 *
 * "A hash over an undefined serialization is not a hash." This class is that
 * definition, in one place, so the bytes a signature is bound to can never
 * drift between the send path, the sign path and a test.
 *
 * THE CANONICAL INPUT, exactly:
 *  - `content` as its canonical object;
 *  - line items as a list ordered by `position` then `uid` (a stable
 *    tie-break), each contributing ONLY its commercial fields;
 *  - schedule items as a list ordered by `sequence`, each contributing ONLY
 *    its commercial terms;
 *  - totals (`subtotal_minor`, `total_minor`, `currency_code`) and
 *    `schema_version`.
 *
 * DELIBERATELY EXCLUDED, and this is the load-bearing half: every
 * progress/mutable field — schedule `status`/`paid_at`/reminder markers, any
 * payment or refund id, any provider reference, the version's own `state`
 * and `superseded_at`, and all row timestamps. **Payment progress must never
 * change a hash a signature is bound to**, or the signature stops answering
 * "what did they actually agree to".
 *
 * Encoding is App\Library\Support\CanonicalJson's, unchanged: map keys
 * recursively sorted by byte order, list order preserved, strings
 * NFC-normalized, integers kept integral, non-finite floats and unsupported
 * types refused rather than coerced.
 */
final class DocumentContentHasher
{
    /**
     * The exact UTF-8 bytes §5.3.2 defines. Exposed separately from hash()
     * so a test can assert the serialization itself, not merely its digest.
     */
    public function canonicalBytes(BusinessDocumentVersion $version): string
    {
        $lines = $version->lineItems()
            ->get()
            ->sortBy([['position', 'asc'], ['uid', 'asc']])
            ->values()
            ->map(fn ($line) => [
                'source' => self::scalar($line->source),
                'package_snapshot_uid' => $line->package_snapshot_uid === null ? null : (string) $line->package_snapshot_uid,
                'name' => (string) $line->name,
                'description' => $line->description === null ? null : (string) $line->description,
                'quantity' => (int) $line->quantity,
                'unit_price_minor' => (int) $line->unit_price_minor,
                'line_total_minor' => (int) $line->line_total_minor,
                'currency_code' => (string) $line->currency_code,
            ])
            ->all();

        $schedule = $version->paymentScheduleItems()
            ->get()
            ->sortBy('sequence')
            ->values()
            ->map(fn ($item) => [
                'sequence' => (int) $item->sequence,
                'kind' => self::scalar($item->kind),
                'amount_minor' => (int) $item->amount_minor,
                'currency_code' => (string) $item->currency_code,
                'due_at' => self::instant($item->due_at),
            ])
            ->all();

        return CanonicalJson::encode([
            'content' => $version->content ?? [],
            'line_items' => $lines,
            'schedule_items' => $schedule,
            'subtotal_minor' => (int) $version->subtotal_minor,
            'total_minor' => (int) $version->total_minor,
            'currency_code' => (string) $version->currency_code,
            'schema_version' => (int) $version->schema_version,
        ]);
    }

    public function hash(BusinessDocumentVersion $version): string
    {
        return hash('sha256', $this->canonicalBytes($version));
    }

    /**
     * A column cast to a BackedEnum must hash as its persisted STRING value,
     * not as an object: the bytes have to be reproducible from the row alone,
     * by any reader, without knowing this application's cast map.
     */
    private static function scalar(mixed $value): string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
    }

    /**
     * A commercial `due_at` is a moment, not a formatted local string: it is
     * normalized to UTC ISO-8601 seconds so the same instant always produces
     * the same bytes regardless of the connection's or the request's
     * timezone.
     */
    private static function instant(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}

<?php

namespace App\Library\Seo;

use App\Enums\Seo\SeoNapFieldResult;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileReadMask;
use App\Models\Business;
use App\Models\BusinessLocation;
use Normalizer;

/**
 * Contract 18 §8.5 — the deterministic, read-time NAP comparator.
 *
 * PURE. No I/O, no persistence, no clock, no cache: the result is computed
 * from the arguments each time and is never stored, never returned to a
 * writer, and never changes a citation's status. There is no score,
 * percentage, severity or recommendation — one of four words per field.
 *
 * CANONICAL NAP (§8.5): name = businesses.name; phone = businesses.phone;
 * address = the Location's address ONLY when the private-address predicate
 * permits. When it does not, the street address is never read into this
 * class, so it cannot be compared, logged or returned (GBP §23).
 *
 * NORMALIZATION agrees with GBP contract §22.3 for the same inputs and is
 * re-stated here rather than imported: SEO must not depend on GBP's
 * comparator internals (GBP §37.2 — no circular dependency). The single,
 * documented, one-directional SEO -> GBP dependency is the pure public
 * predicate GoogleBusinessProfileReadMask::addressPermittedForLocation().
 * A shared conformance fixture table in the test suite asserts that the two
 * implementations agree; drift fails that test.
 *
 *  - text (name, address): trim, collapse internal whitespace, Unicode NFC,
 *    case-insensitive. No punctuation stripping, no legal-suffix stripping.
 *  - phone: keep digits and a single leading '+'; no region inference.
 *
 * RESULT PER FIELD, in this order:
 *   1. no canonical value          -> NotComparable
 *   2. canonical, no listed value  -> Unchecked
 *   3. both, equal after normalize -> Consistent
 *   4. both, different             -> Mismatch
 */
final class SeoNapComparator
{
    public const FIELDS = ['name', 'phone', 'address'];

    public function __construct(private readonly GoogleBusinessProfileReadMask $addressPredicate)
    {
    }

    /**
     * The canonical NAP for a Location. The address is null — and the
     * Location's address columns are not read — unless the predicate permits.
     *
     * @return array{name: ?string, phone: ?string, address: ?string}
     */
    public function canonicalFor(Business $business, BusinessLocation $location): array
    {
        return [
            'name' => $this->present($business->name),
            'phone' => $this->present($business->phone),
            'address' => $this->addressPredicate->addressPermittedForLocation($location)
                ? $this->composeAddress($location)
                : null,
        ];
    }

    /**
     * @param  array{name: ?string, phone: ?string, address: ?string}  $canonical
     * @param  array{name: ?string, phone: ?string, address: ?string}  $listed
     * @return array{name: SeoNapFieldResult, phone: SeoNapFieldResult, address: SeoNapFieldResult}
     */
    public function compare(array $canonical, array $listed): array
    {
        return [
            'name' => $this->field($canonical['name'] ?? null, $listed['name'] ?? null, self::normalizeText(...)),
            'phone' => $this->field($canonical['phone'] ?? null, $listed['phone'] ?? null, self::normalizePhone(...)),
            'address' => $this->field($canonical['address'] ?? null, $listed['address'] ?? null, self::normalizeText(...)),
        ];
    }

    public static function normalizeText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $collapsed = preg_replace('/\s+/u', ' ', trim($value));

        if (! is_string($collapsed) || $collapsed === '') {
            return null;
        }

        if (class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($collapsed, Normalizer::FORM_C);

            if (is_string($normalized) && $normalized !== '') {
                $collapsed = $normalized;
            }
        }

        return mb_strtolower($collapsed);
    }

    public static function normalizePhone(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $trimmed);

        if (! is_string($digits) || $digits === '') {
            return null;
        }

        return (str_starts_with($trimmed, '+') ? '+' : '') . $digits;
    }

    /**
     * @param  callable(?string): ?string  $normalize
     */
    private function field(?string $canonical, ?string $listed, callable $normalize): SeoNapFieldResult
    {
        $canonicalNormalized = $normalize($canonical);

        if ($canonicalNormalized === null) {
            return SeoNapFieldResult::NotComparable;
        }

        $listedNormalized = $normalize($listed);

        if ($listedNormalized === null) {
            return SeoNapFieldResult::Unchecked;
        }

        return $canonicalNormalized === $listedNormalized ? SeoNapFieldResult::Consistent : SeoNapFieldResult::Mismatch;
    }

    private function present(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function composeAddress(BusinessLocation $location): ?string
    {
        $parts = array_values(array_filter(array_map(
            fn ($part) => $this->present($part),
            [$location->address_line_1, $location->address_line_2, $location->city, $location->region, $location->postal_code, $location->country_code],
        )));

        return $parts === [] ? null : implode(', ', $parts);
    }
}

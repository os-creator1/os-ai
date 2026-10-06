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
 * NORMALIZATION of NAME agrees with GBP contract §22.3 for the same inputs and
 * is re-stated here rather than imported: SEO must not depend on GBP's
 * comparator internals (GBP §37.2 — no circular dependency). The single,
 * documented, one-directional SEO -> GBP dependency is the pure public
 * predicate GoogleBusinessProfileReadMask::addressPermittedForLocation().
 * A shared conformance fixture table in the test suite asserts that the two
 * implementations agree; drift fails that test.
 *
 *  - name: trim, collapse internal whitespace, Unicode NFC,
 *    case-insensitive. No punctuation stripping, no legal-suffix stripping.
 *  - normalizePhone(): keep digits and a single leading '+'; no region
 *    inference. Kept as the GBP-conformant primitive; the field comparison
 *    below layers country awareness on top of it.
 *
 * PHONE and ADDRESS are free text a person typed into a directory, so a
 * byte-for-byte comparison would report a false "Mismatch" for the same number
 * or address written differently. They are therefore compared with the
 * Business's country as context, and the comparison is CONSERVATIVE in the
 * same way GBP's is (it refuses address equality outright because "address
 * equality is a normalization problem that produces confident wrong answers"):
 * when it cannot be confident either way it answers NotComparable — "unable to
 * verify" — never Mismatch. Only a difference that cannot be formatting (a
 * different house number, postal code or phone digits) is a Mismatch.
 *
 *  - phone: digits only; a leading '+' / '00' marks an international form;
 *    the Business country's calling code is dropped from an international form
 *    (and a leading '1' from a NANP number written without '+'), so
 *    "(312) 555-1212" equals "+1 312 555 1212" for a US/CA Business. With no
 *    known country, a '+' form and a bare form of the same digits are
 *    unverifiable, not different.
 *  - address: case, punctuation, whitespace, street-suffix / direction / unit /
 *    state abbreviations, the postal-code extension and the Location's own
 *    country (a Location is compared with its own listing, so the country adds
 *    nothing) are not differences; token ORDER is not a difference either.
 *
 * RESULT PER FIELD, in this order:
 *   1. no canonical value          -> NotComparable
 *   2. canonical, no listed value  -> Unchecked
 *   3. both, equal after normalize -> Consistent
 *   4. both, provably different    -> Mismatch
 *   5. both, not confidently equal
 *      or different               -> NotComparable ("unable to verify")
 */
final class SeoNapComparator
{
    public const FIELDS = ['name', 'phone', 'address'];

    /**
     * ISO 3166-1 alpha-2 -> international calling code, for the markets where
     * a leading national code can be told apart safely. A country that is not
     * listed simply gets no calling-code inference (its '+' and bare forms are
     * "unable to verify" rather than guessed).
     */
    private const CALLING_CODES = [
        'US' => '1', 'CA' => '1', 'GB' => '44', 'IE' => '353', 'AU' => '61', 'NZ' => '64',
        'DE' => '49', 'FR' => '33', 'ES' => '34', 'IT' => '39', 'NL' => '31', 'BE' => '32',
        'SE' => '46', 'NO' => '47', 'DK' => '45', 'MX' => '52', 'BR' => '55', 'IN' => '91', 'ZA' => '27',
    ];

    /** Street types, directions and unit designators: written-out form => short form. */
    private const ADDRESS_WORDS = [
        'street' => 'st', 'avenue' => 'ave', 'av' => 'ave', 'boulevard' => 'blvd', 'road' => 'rd', 'drive' => 'dr',
        'lane' => 'ln', 'court' => 'ct', 'place' => 'pl', 'circle' => 'cir', 'terrace' => 'ter', 'parkway' => 'pkwy',
        'highway' => 'hwy', 'square' => 'sq', 'trail' => 'trl', 'building' => 'bldg', 'floor' => 'fl',
        'north' => 'n', 'south' => 's', 'east' => 'e', 'west' => 'w',
        'northeast' => 'ne', 'northwest' => 'nw', 'southeast' => 'se', 'southwest' => 'sw',
        'suite' => 'unit', 'ste' => 'unit', 'apartment' => 'unit', 'apt' => 'unit', 'room' => 'unit', 'rm' => 'unit',
        'shop' => 'unit', 'flat' => 'unit', 'level' => 'unit', 'lot' => 'unit',
    ];

    /** Words that introduce a sub-unit number ("Shop 3", "Level 2"): the number after one is never the house number. */
    private const UNIT_WORDS = ['unit', 'fl', 'bldg'];

    /** US states, DC and Canadian provinces: full name => postal abbreviation (a name that contains another comes first). */
    private const REGIONS = [
        'alabama' => 'al', 'alaska' => 'ak', 'arizona' => 'az', 'arkansas' => 'ar', 'california' => 'ca', 'colorado' => 'co',
        'connecticut' => 'ct', 'delaware' => 'de', 'district of columbia' => 'dc', 'florida' => 'fl', 'georgia' => 'ga',
        'hawaii' => 'hi', 'idaho' => 'id', 'illinois' => 'il', 'indiana' => 'in', 'iowa' => 'ia', 'kansas' => 'ks',
        'kentucky' => 'ky', 'louisiana' => 'la', 'maine' => 'me', 'maryland' => 'md', 'massachusetts' => 'ma',
        'michigan' => 'mi', 'minnesota' => 'mn', 'mississippi' => 'ms', 'missouri' => 'mo', 'montana' => 'mt',
        'nebraska' => 'ne', 'nevada' => 'nv', 'new hampshire' => 'nh', 'new jersey' => 'nj', 'new mexico' => 'nm',
        'new york' => 'ny', 'north carolina' => 'nc', 'north dakota' => 'nd', 'ohio' => 'oh', 'oklahoma' => 'ok',
        'oregon' => 'or', 'pennsylvania' => 'pa', 'rhode island' => 'ri', 'south carolina' => 'sc', 'south dakota' => 'sd',
        'tennessee' => 'tn', 'texas' => 'tx', 'utah' => 'ut', 'vermont' => 'vt', 'west virginia' => 'wv', 'virginia' => 'va',
        'washington' => 'wa', 'wisconsin' => 'wi', 'wyoming' => 'wy',
        'alberta' => 'ab', 'british columbia' => 'bc', 'manitoba' => 'mb', 'new brunswick' => 'nb',
        'newfoundland and labrador' => 'nl', 'nova scotia' => 'ns', 'ontario' => 'on', 'prince edward island' => 'pe',
        'quebec' => 'qc', 'saskatchewan' => 'sk',
    ];

    /** Words a listing may append for the country; dropped when they are the Location's own. */
    private const COUNTRY_WORDS = [
        'US' => ['united states of america', 'united states', 'usa', 'us'],
        'CA' => ['canada', 'can', 'ca'],
        'GB' => ['united kingdom', 'great britain', 'uk', 'gb'],
        'AU' => ['australia', 'aus', 'au'],
        'NZ' => ['new zealand', 'nz'],
        'IE' => ['ireland', 'ie'],
    ];

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
     * The phone is a Business-wide fact while the address belongs to a
     * Location, so a SECONDARY Location of a multi-Location Business may
     * legitimately list its own number. The phone is compared only where it is
     * knowable that the Business phone is what that Location lists: the main
     * Location, or the Business's only Location.
     */
    public static function phoneAppliesAt(BusinessLocation $location, int $businessLocationCount): bool
    {
        return (bool) $location->is_primary || $businessLocationCount <= 1;
    }

    /**
     * @param  array{name: ?string, phone: ?string, address: ?string}  $canonical
     * @param  array{name: ?string, phone: ?string, address: ?string}  $listed
     * @param  ?string  $businessCountry  businesses.country_code — the phone's country
     * @param  ?string  $locationCountry  business_locations.country_code — the address's country
     * @param  bool  $comparePhone  false where the Business phone is not knowable as this Location's (see phoneAppliesAt)
     * @return array{name: SeoNapFieldResult, phone: SeoNapFieldResult, address: SeoNapFieldResult}
     */
    public function compare(array $canonical, array $listed, ?string $businessCountry = null, ?string $locationCountry = null, bool $comparePhone = true): array
    {
        $business = self::countryCode($businessCountry);
        $location = self::countryCode($locationCountry);
        $phoneCountry = $business ?? $location;
        $addressCountry = $location ?? $business;

        return [
            'name' => $this->field($canonical['name'] ?? null, $listed['name'] ?? null, self::normalizeName(...)),
            'phone' => $comparePhone
                ? $this->phoneField($canonical['phone'] ?? null, $listed['phone'] ?? null, self::CALLING_CODES[$phoneCountry ?? ''] ?? null)
                : SeoNapFieldResult::NotComparable,
            'address' => $this->addressField($canonical['address'] ?? null, $listed['address'] ?? null, $addressCountry),
        ];
    }

    /**
     * WEBSITE — compared only where BOTH a business website and a recorded
     * listing website exist; otherwise NotComparable. Many directories store no
     * website, so a missing one is neither "unchecked" nor a mismatch. Kept
     * out of compare() so the three-field NAP contract above is unchanged.
     */
    public function compareWebsite(?string $canonical, ?string $listed): SeoNapFieldResult
    {
        $canonicalNormalized = self::normalizeWebsite($canonical);
        $listedNormalized = self::normalizeWebsite($listed);

        if ($canonicalNormalized === null || $listedNormalized === null) {
            return SeoNapFieldResult::NotComparable;
        }

        if ($canonicalNormalized === $listedNormalized) {
            return SeoNapFieldResult::Consistent;
        }

        // The same site with another path is often a landing page for the Location, not a
        // different business: unable to verify. Only another host is a definite difference.
        return self::websiteHost($canonicalNormalized) === self::websiteHost($listedNormalized)
            ? SeoNapFieldResult::NotComparable
            : SeoNapFieldResult::Mismatch;
    }

    private static function websiteHost(string $normalized): string
    {
        return explode('/', $normalized, 2)[0];
    }

    /**
     * Scheme (http/https), a leading "www.", host case, a trailing slash, the
     * query string (tracking parameters such as ?utm_source=gbp) and any
     * #fragment are not differences; the host is. The path is kept so the
     * caller can tell "same site, another page" (unable to verify) from "another
     * site" (a mismatch). No fuzzy matching of hosts.
     */
    public static function normalizeWebsite(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        $withScheme = preg_match('#^[a-z][a-z0-9+.-]*://#i', $trimmed) === 1 ? $trimmed : 'https://' . $trimmed;
        $parts = parse_url($withScheme);

        if ($parts === false || ! isset($parts['host']) || $parts['host'] === '') {
            return null;
        }

        if (! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower($parts['host']);
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;

        // A registrable-looking hostname only: not a sentence, not a bare word.
        if (preg_match('/\A[a-z0-9]([a-z0-9.-]*[a-z0-9])?\z/', $host) !== 1 || ! str_contains($host, '.')) {
            return null;
        }
        $port = isset($parts['port']) && ! in_array((int) $parts['port'], [80, 443], true) ? ':' . $parts['port'] : '';
        $path = rtrim((string) ($parts['path'] ?? ''), '/');

        return $host . $port . $path;
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

    /** Trailing legal-form words that are not part of what a customer calls the business. */
    private const LEGAL_SUFFIXES = ['llc', 'inc', 'incorporated', 'ltd', 'limited', 'co', 'company', 'corp', 'corporation', 'llp', 'pty', 'plc'];

    /**
     * A business NAME as a person would recognise it: apostrophes and curly
     * quotes, "&" / "and" / "&amp;", repeated punctuation and a trailing legal
     * suffix ("LLC", "Inc.", ", Ltd") are not differences. A genuinely different
     * name still differs. (normalizeText() stays the GBP-conformant primitive.)
     */
    public static function normalizeName(?string $value): ?string
    {
        $text = self::normalizeText($value === null ? null : html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($text === null) {
            return null;
        }

        $text = str_replace(['&'], [' and '], $text);
        $text = (string) preg_replace('/[\'’‘`´]+/u', '', $text);
        $words = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        while (count($words) > 1 && in_array(end($words), self::LEGAL_SUFFIXES, true)) {
            array_pop($words);
        }

        return $words === [] ? $text : implode(' ', $words);
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

    // ------------------------------------------------------------------
    // Phone
    // ------------------------------------------------------------------

    private function phoneField(?string $canonical, ?string $listed, ?string $callingCode): SeoNapFieldResult
    {
        $a = self::parsePhone($canonical, $callingCode);

        if ($a === null) {
            return SeoNapFieldResult::NotComparable;
        }

        $b = self::parsePhone($listed, $callingCode);

        if ($b === null) {
            return SeoNapFieldResult::Unchecked;
        }

        if ($a['digits'] === $b['digits'] && $a['scope'] === $b['scope']) {
            return SeoNapFieldResult::Consistent;
        }

        // An explicitly other-country number is a different number.
        if ($a['scope'] === 'foreign' || $b['scope'] === 'foreign') {
            return SeoNapFieldResult::Mismatch;
        }

        // No known calling code: a "+" form beside a national form, or a national
        // trunk 0, cannot be read either way — unable to verify, not different.
        if ($a['scope'] === 'unknown' && (($a['plus'] ?? false) !== ($b['plus'] ?? false) || ($a['trunk'] ?? false) || ($b['trunk'] ?? false))) {
            return SeoNapFieldResult::NotComparable;
        }

        // One a tail of the other (a "+" form beside a bare form with no known
        // country, or a number listed without its area code) may be the same
        // number written differently: unable to verify, not different.
        return self::isSuffixOf($a['digits'], $b['digits']) || self::isSuffixOf($b['digits'], $a['digits'])
            ? SeoNapFieldResult::NotComparable
            : SeoNapFieldResult::Mismatch;
    }

    /**
     * @return array{digits: string, scope: string, plus?: bool, trunk?: bool}|null  scope: `national` (the Business country, calling code
     *         removed), `foreign` (an international number of another country, calling code kept) or `unknown`
     *         (no country to read it against)
     */
    private static function parsePhone(?string $value, ?string $callingCode): ?array
    {
        if ($value === null) {
            return null;
        }

        // An extension ("ext. 5", "x5") is not part of the number.
        $trimmed = trim((string) preg_replace('/\s*(?:ext\.?|extension|x)\s*\d+\s*\z/i', '', trim($value)));
        $digits = preg_replace('/\D+/', '', $trimmed);

        if ($trimmed === '' || ! is_string($digits) || $digits === '') {
            return null;
        }

        $international = str_starts_with($trimmed, '+');

        if (! $international && str_starts_with($digits, '00') && strlen($digits) > 2) {
            $international = true;
            $digits = substr($digits, 2);
        }

        if ($callingCode === null) {
            return ['digits' => $digits, 'scope' => 'unknown', 'plus' => $international, 'trunk' => str_starts_with($digits, '0') && ! $international];
        }

        if ($international) {
            if (! str_starts_with($digits, $callingCode)) {
                return ['digits' => $digits, 'scope' => 'foreign'];
            }

            $digits = substr($digits, strlen($callingCode));
        } elseif ($callingCode === '1' && strlen($digits) === 11 && $digits[0] === '1') {
            // A NANP number written "1 (312) 555-1212" without the "+".
            $digits = substr($digits, 1);
        }

        // A national trunk prefix ("020 7946 0958") is not part of the number.
        if ($callingCode !== '1' && strlen($digits) > 1 && $digits[0] === '0') {
            $digits = substr($digits, 1);
        }

        return ['digits' => $digits, 'scope' => 'national'];
    }

    private static function isSuffixOf(string $shorter, string $longer): bool
    {
        return strlen($shorter) >= 6 && strlen($shorter) <= strlen($longer) && str_ends_with($longer, $shorter);
    }

    // ------------------------------------------------------------------
    // Address
    // ------------------------------------------------------------------

    private function addressField(?string $canonical, ?string $listed, ?string $country): SeoNapFieldResult
    {
        $a = self::addressParts($canonical, $country);

        if ($a === null) {
            return SeoNapFieldResult::NotComparable;
        }

        $b = self::addressParts($listed, $country);

        if ($b === null) {
            return SeoNapFieldResult::Unchecked;
        }

        $same = array_intersect($a['tokens'], $b['tokens']);

        // Same words in any order (a postal code before or after the city).
        if (count($same) === count($a['tokens']) && count($same) === count($b['tokens'])) {
            return SeoNapFieldResult::Consistent;
        }

        // The listing records LESS than the profile and everything it does
        // record agrees — provided it at least names the house number.
        if (count($same) === count($b['tokens']) && ($a['house'] === null || $a['house'] === $b['house'])) {
            return SeoNapFieldResult::Consistent;
        }

        // Differences that cannot be formatting.
        if ($a['house'] !== null && $b['house'] !== null && $a['house'] !== $b['house']
            && ! in_array($a['house'], $b['numbers'], true) && ! in_array($b['house'], $a['numbers'], true)) {
            return SeoNapFieldResult::Mismatch;
        }

        if ($a['postal'] !== null && $b['postal'] !== null && $a['postal'] !== $b['postal']) {
            return SeoNapFieldResult::Mismatch;
        }

        // Anything else (a suite the profile lacks, an unfamiliar spelling, a
        // different street with the same number) is not provably different.
        return SeoNapFieldResult::NotComparable;
    }

    /**
     * @return array{tokens: array<int, string>, house: ?string, numbers: array<int, string>, postal: ?string}|null
     */
    private static function addressParts(?string $value, ?string $country): ?array
    {
        $text = self::normalizeText($value);

        if ($text === null) {
            return null;
        }

        $text = str_replace(['&', '#'], [' and ', ' unit '], $text);
        // "3/123 Smith St" and "Shop 3/123 Smith St": the number before a slash is a unit, not the house.
        $text = (string) preg_replace('/\b(\d+[a-z]?)\s*\/\s*(\d+[a-z]?)\b/', ' unit $1 $2', $text);
        // "60601-1234" is the same postal code as "60601"; "M5V 2T6" as "M5V2T6".
        $text = (string) preg_replace('/\b(\d{5})\s*-\s*\d{4}\b/', '$1', $text);
        $text = (string) preg_replace('/\b([a-z]\d[a-z])\s*(\d[a-z]\d)\b/', '$1$2', $text);

        // The Location's own country adds nothing to its own listing.
        $text = rtrim($text, " 	.,;");

        foreach (self::COUNTRY_WORDS[$country ?? ''] ?? [] as $word) {
            $text = (string) preg_replace('/(?:^|[\s,;])' . preg_quote($word, '/') . '\s*\z/u', ' ', $text);
        }

        // Full state / province names to their postal abbreviation, longest first.
        foreach (self::REGIONS as $name => $abbreviation) {
            $text = (string) preg_replace('/\b' . preg_quote($name, '/') . '\b/u', ' ' . $abbreviation . ' ', $text);
        }

        $words = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_map(fn (string $word) => self::ADDRESS_WORDS[$word] ?? $word, $words);

        if ($words === []) {
            return null;
        }

        $postal = null;
        $house = null;
        $numbers = [];
        $isHouseNumber = static fn (string $word): bool => preg_match('/\A\d+[a-z]?\z/', $word) === 1;

        foreach ($words as $index => $word) {
            // A number straight after a sub-unit word ("shop 3", "level 2", "flat 2") is the unit.
            if (! $isHouseNumber($word) || in_array($words[$index - 1] ?? null, self::UNIT_WORDS, true)) {
                continue;
            }

            $numbers[] = $word;
            $house ??= $word;
        }

        foreach ($words as $index => $word) {
            $isPostal = match ($country) {
                'US' => preg_match('/\A\d{5}\z/', $word) === 1,
                'CA' => preg_match('/\A[a-z]\d[a-z]\d[a-z]\d\z/', $word) === 1,
                default => false,
            };

            // A leading 5-digit number is a house number, not a postal code.
            if ($isPostal && $index > 0) {
                $postal = $word;
            }
        }

        if ($postal !== null && $postal === $house) {
            $house = null;
        }

        $numbers = array_values(array_diff(array_unique($numbers), [$postal]));

        return ['tokens' => array_values(array_unique($words)), 'house' => $house, 'numbers' => $numbers, 'postal' => $postal];
    }

    private static function countryCode(?string $value): ?string
    {
        $code = strtoupper(trim((string) $value));

        return preg_match('/\A[A-Z]{2}\z/', $code) === 1 ? $code : null;
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

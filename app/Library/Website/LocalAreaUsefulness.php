<?php

namespace App\Library\Website;

use Illuminate\Support\Str;

/**
 * Would a dedicated page for this service area tell a visitor anything a list entry does not?
 *
 * A page that differs from its siblings only by the place name is a doorway page (Google's spam policies), and a business's
 * city appearing in a "Serving <city>" heading is not local relevance. So a service area gets its own page only when the
 * OWNER has confirmed something specific to that place that the generator can state without inventing anything:
 *
 *   venues     venues in or near it the business has actually worked at (a short list the owner typed or ticked);
 *   logistics  how serving it really works (travel time or fee policy, load-in or parking realities, lead time);
 *   photos     event photographs taken there (website gallery assets the owner attributed to it).
 *
 * One confirmed fact is enough to say something real; zero is not. Anything else stays a line in the "Areas we serve" list,
 * visible and crawlable, and the owner is told exactly what to add to earn a page. The business's own base city never gets a
 * second page competing with the homepage for the same search.
 *
 * Pure: no queries, no AI, no network.
 */
final class LocalAreaUsefulness
{
    public const VENUES = 'venues';
    public const LOGISTICS = 'logistics';
    public const PHOTOS = 'photos';

    private const MAX_VENUES = 8;
    private const MAX_PHOTOS = 6;

    /**
     * An area entry as the owner's list carries it: a bare name, or a name with the local facts they confirmed.
     *
     * @return array{name: string, details: array{venues?: array<int, string>, logistics?: string, photos?: array<int, string>}}|null
     */
    public static function normalize(mixed $entry): ?array
    {
        $name = trim((string) (is_array($entry) ? ($entry['name'] ?? '') : $entry));

        if ($name === '') {
            return null;
        }

        $details = is_array($entry) && is_array($entry['details'] ?? null) ? $entry['details'] : [];
        $clean = [];

        $venues = array_values(array_unique(array_filter(array_map(fn ($venue) => self::text($venue, 120), (array) ($details[self::VENUES] ?? [])))));
        if ($venues !== []) {
            $clean[self::VENUES] = array_slice($venues, 0, self::MAX_VENUES);
        }

        $logistics = self::text($details[self::LOGISTICS] ?? null, 600);
        if ($logistics !== null) {
            $clean[self::LOGISTICS] = $logistics;
        }

        $photos = array_values(array_unique(array_filter(array_map(fn ($uid) => is_string($uid) && preg_match('/^[0-9a-f-]{36}$/i', $uid) === 1 ? $uid : null, (array) ($details[self::PHOTOS] ?? [])))));
        if ($photos !== []) {
            $clean[self::PHOTOS] = array_slice($photos, 0, self::MAX_PHOTOS);
        }

        return ['name' => $name, 'details' => $clean];
    }

    /**
     * @param  array{name: string, details: array<string, mixed>}  $area  see normalize()
     * @return array{qualifies: bool, signals: array<int, string>, reason: string}
     */
    public static function assess(array $area, ?string $baseCity): array
    {
        if ($baseCity !== null && Str::slug($baseCity) !== '' && Str::slug($baseCity) === Str::slug($area['name'])) {
            return [
                'qualifies' => false,
                'signals' => [],
                'reason' => 'This is your base city, so your home page already targets it. A second page would compete with it; it stays in your areas list.',
            ];
        }

        $signals = array_keys(array_intersect_key($area['details'], array_flip([self::VENUES, self::LOGISTICS, self::PHOTOS])));

        if ($signals === []) {
            return [
                'qualifies' => false,
                'signals' => [],
                'reason' => 'Listed in "Areas we serve" with no page of its own: a page that differs only by the city name would be a doorway page. Add a venue you have worked in ' . $area['name'] . ', how serving it works (travel, load-in, lead time) or an event photo from there, and it can become a page.',
            ];
        }

        return ['qualifies' => true, 'signals' => $signals, 'reason' => 'You confirmed local details for this area (' . implode(', ', $signals) . ').'];
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? '');

        return $clean === '' ? null : Str::limit($clean, $max, '');
    }
}

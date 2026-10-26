<?php

namespace App\Library\Website\Seo;

use App\Models\Business;
use App\Models\BusinessService;
use Illuminate\Support\Str;

/**
 * Gives every published page a role and a subject, for sites whose pages carry no stored page-intent map (anything built
 * before it existed, or written by the owner, or moved from another builder).
 *
 * Order of trust: the stored role (WebsitePageIntent) -> the generator's own address conventions -> the Business's OWN
 * services, event types and places matched against the page's address and title -> the generic words of the page's
 * address (contact, about, privacy ...). Nothing here knows an industry: the vocabulary is the Business's saved services
 * and areas plus a short list of universal page words. A page that matches nothing stays `other` — never a guess.
 */
final class WebsitePageIntentClassifier
{
    /** Words every offer of a business shares or that name no particular offer. */
    private const GENERIC = ['event', 'events', 'rental', 'rentals', 'hire', 'service', 'services', 'package', 'packages', 'and', 'the', 'for', 'of', 'in', 'a', 'to', 'our', 'with'];

    private const LEGAL = ['privacy', 'policy', 'terms', 'conditions', 'cookie', 'cookies', 'disclaimer', 'refund', 'accessibility', 'legal'];
    private const CONTACT = ['contact', 'availability', 'quote', 'booking', 'book', 'request'];
    private const ABOUT = ['about', 'team', 'story', 'meet'];
    private const FAQ = ['faq', 'faqs', 'questions'];
    private const GALLERY = ['gallery', 'portfolio', 'projects'];
    private const PACKAGES = ['packages', 'pricing', 'prices', 'rates'];
    private const HUB_WORDS = ['area', 'areas', 'location', 'locations', 'coverage', 'serve', 'serving'];

    /** @var array<int, array{name: string, distinct: array<int, string>}> */
    private array $offers;

    /** @var array<int, array{name: string, tokens: array<int, string>}> */
    private array $places;

    /**
     * @param  array<int, string>  $offerNames  the Business's active services and event types
     * @param  array<int, string>  $placeNames  its base city and every service area, as bare place names
     */
    public function __construct(array $offerNames = [], array $placeNames = [])
    {
        $offers = [];

        foreach (array_values(array_unique(array_filter(array_map('trim', $offerNames)))) as $name) {
            $offers[] = ['name' => $name, 'tokens' => self::tokens($name, true)];
        }

        $all = [];
        foreach ($offers as $offer) {
            foreach (array_unique($offer['tokens']) as $token) {
                $all[$token] = ($all[$token] ?? 0) + 1;
            }
        }

        // A word only counts as "this offer's" when no other offer shares it ("wedding" yes, "booth" no).
        $this->offers = array_map(fn (array $offer) => ['name' => $offer['name'], 'distinct' => array_values(array_filter(array_unique($offer['tokens']), fn ($t) => ($all[$t] ?? 0) === 1))], $offers);

        $this->places = array_map(fn (string $name) => ['name' => $name, 'tokens' => self::tokens($name, false)], array_values(array_unique(array_filter(array_map('trim', $placeNames)))));
    }

    public static function forBusiness(Business $business): self
    {
        $offers = BusinessService::query()->where('business_id', $business->id)->where('status', 'active')->pluck('name')->all();
        $places = [];

        foreach ($business->locations()->get() as $location) {
            foreach (array_merge([(string) $location->city], (array) ($location->service_area_cities ?? [])) as $entry) {
                $place = trim((string) Str::before((string) $entry, ','));

                if ($place !== '') {
                    $places[] = $place;
                }
            }
        }

        return new self($offers, $places);
    }

    /**
     * @param  array<string, mixed>  $page  keys: slug, is_home, title, role?, intent?, text? (the page's visible words)
     * @return array{role: string, place: ?string, offer: ?string, source: string}
     */
    public function classify(array $page): array
    {
        $stored = WebsitePageIntent::validRole($page['role'] ?? null);
        $intent = is_array($page['intent'] ?? null) ? $page['intent'] : [];

        if ($stored !== null) {
            return ['role' => $stored, 'place' => $intent['place'] ?? null, 'offer' => null, 'source' => 'stored'];
        }

        $slug = (string) ($page['slug'] ?? '');

        // "service-areas" starts like a generated service page ("service-<name>"); what it lists settles it.
        if (empty($page['is_home']) && self::hasWord(self::tokens(str_replace('-', ' ', $slug), false), self::HUB_WORDS) && $this->mentionedPlaces((string) ($page['text'] ?? '')) >= 3) {
            return ['role' => WebsitePageIntent::AREA_HUB, 'place' => null, 'offer' => null, 'source' => 'business'];
        }

        $legacy = WebsitePageIntent::legacyRole(! empty($page['is_home']), $slug);

        if ($legacy !== null) {
            return ['role' => $legacy, 'place' => $legacy === WebsitePageIntent::LOCATION ? $this->placeFromLegacySlug($slug) : null, 'offer' => null, 'source' => 'convention'];
        }

        $words = self::tokens(str_replace('-', ' ', $slug), false);

        if ($words === []) {
            return ['role' => WebsitePageIntent::OTHER, 'place' => null, 'offer' => null, 'source' => 'none'];
        }

        foreach ([[self::LEGAL, WebsitePageIntent::LEGAL], [self::FAQ, WebsitePageIntent::FAQ], [self::ABOUT, WebsitePageIntent::ABOUT], [self::GALLERY, WebsitePageIntent::GALLERY]] as [$vocabulary, $role]) {
            if (self::hasWord($words, $vocabulary)) {
                return ['role' => $role, 'place' => null, 'offer' => null, 'source' => 'words'];
            }
        }

        $place = $this->placeFor($words);
        $offer = $this->offerFor($words);

        // "Wedding photo booth rental Spokane" names both: the offer is what it sells, so it is a service page.
        if ($offer !== null) {
            return ['role' => WebsitePageIntent::SERVICE, 'place' => $place, 'offer' => $offer, 'source' => 'business'];
        }

        if ($place !== null) {
            return ['role' => WebsitePageIntent::LOCATION, 'place' => $place, 'offer' => null, 'source' => 'business'];
        }

        if (self::hasWord($words, self::HUB_WORDS) && $this->mentionedPlaces((string) ($page['text'] ?? '')) >= 3) {
            return ['role' => WebsitePageIntent::AREA_HUB, 'place' => null, 'offer' => null, 'source' => 'business'];
        }

        foreach ([[self::CONTACT, WebsitePageIntent::CONTACT], [self::PACKAGES, WebsitePageIntent::PACKAGES]] as [$vocabulary, $role]) {
            if (self::hasWord($words, $vocabulary)) {
                return ['role' => $role, 'place' => null, 'offer' => null, 'source' => 'words'];
            }
        }

        return ['role' => WebsitePageIntent::OTHER, 'place' => null, 'offer' => null, 'source' => 'none'];
    }

    /** @param array<int, string> $words already-normalised words; $vocabulary raw words, normalised the same way */
    private static function hasWord(array $words, array $vocabulary): bool
    {
        return array_intersect($words, self::tokens(implode(' ', $vocabulary), false)) !== [];
    }

    /** The place a page's address words are entirely about: an exact name wins, else a name that contains them all. */
    private function placeFor(array $words): ?string
    {
        $best = null;

        foreach ($this->places as $place) {
            if ($place['tokens'] === []) {
                continue;
            }

            if ($place['tokens'] === $words) {
                return $place['name'];
            }

            if (array_diff($words, $place['tokens']) === [] && $best === null) {
                $best = $place['name'];
            }
        }

        return $best;
    }

    private function offerFor(array $words): ?string
    {
        foreach ($this->offers as $offer) {
            if (array_intersect($offer['distinct'], $words) !== []) {
                return $offer['name'];
            }
        }

        return null;
    }

    private function mentionedPlaces(string $text): int
    {
        $haystack = ' ' . implode(' ', self::tokens($text, false)) . ' ';
        $count = 0;

        foreach ($this->places as $place) {
            if ($place['tokens'] !== [] && str_contains($haystack, ' ' . implode(' ', $place['tokens']) . ' ')) {
                $count++;
            }
        }

        return $count;
    }

    private function placeFromLegacySlug(string $slug): ?string
    {
        $words = self::tokens(str_replace('-', ' ', (string) preg_replace('/^serving-/', '', $slug)), false);

        return $this->placeFor($words) ?? ($words === [] ? null : Str::title(implode(' ', $words)));
    }

    /**
     * @return array<int, string> lowercase words, singular where a plain "s" plural; generic words dropped when $dropGeneric
     */
    public static function tokens(string $text, bool $dropGeneric): array
    {
        // An apostrophe joins ("Coeur d'Alene" is the address "coeur-dalene"), it does not split.
        $words = preg_split('/[^\p{L}\p{N}]+/u', str_replace(["'", '’'], '', mb_strtolower($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];

        foreach ($words as $word) {
            if ($dropGeneric && in_array($word, self::GENERIC, true)) {
                continue;
            }

            $out[] = match (true) {
                mb_strlen($word) > 4 && str_ends_with($word, 'ies') => mb_substr($word, 0, -3) . 'y',
                mb_strlen($word) > 3 && str_ends_with($word, 's') && ! str_ends_with($word, 'ss') => mb_substr($word, 0, -1),
                default => $word,
            };
        }

        return $out;
    }
}

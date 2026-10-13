<?php

namespace App\Library\Website\Setup;

use App\Library\Geo\GeoText;
use App\Library\Geo\NearbyPlaceSuggester;
use App\Models\Business;

/**
 * The wizard's "Where do you provide your services?" suggestions: real
 * communities near the business's confirmed base location, from the bundled
 * GeoNames extract (see App\Library\Geo). Never writes anything — a
 * suggestion is only ever a row the owner reviews, edits, deletes or keeps,
 * and is explicitly not presented as a verified service claim.
 */
final class ServiceAreaSuggestions
{
    public const DEFAULT_COUNT = 10;

    public function __construct(private readonly NearbyPlaceSuggester $suggester)
    {
    }

    /**
     * The base location the suggestions are built from: what the owner
     * confirmed on this setup's "where are you based" question, otherwise the
     * Business's canonical primary location. Null when neither names a city.
     *
     * @param  array<string, mixed>  $answers
     * @return array{city: string, region: ?string, country_code: string}|null
     */
    public function baseFor(Business $business, array $answers): ?array
    {
        $answered = $answers['base_location'] ?? null;
        $candidates = [is_array($answered) ? $answered : null, WizardPrefill::primaryLocationPlace($business)];

        foreach ($candidates as $place) {
            $city = trim((string) ($place['city'] ?? ''));
            $country = strtoupper(trim((string) ($place['country_code'] ?? '')));

            if ($city !== '' && $country !== '') {
                return ['city' => $city, 'region' => ($place['region'] ?? null) ?: null, 'country_code' => $country];
            }
        }

        return null;
    }

    /**
     * A stable fingerprint of a base location, stored beside the owner's
     * service-area list so a later change of base can be noticed (and offered
     * as a deliberate refresh) without ever rewriting the list silently.
     *
     * @param  array{city?: ?string, region?: ?string, country_code?: ?string}|null  $base
     */
    public static function signature(?array $base): ?string
    {
        if ($base === null || trim((string) ($base['city'] ?? '')) === '') {
            return null;
        }

        return implode('|', [
            GeoText::fold((string) $base['city']),
            GeoText::fold((string) ($base['region'] ?? '')),
            strtoupper((string) ($base['country_code'] ?? '')),
        ]);
    }

    /**
     * @param  array{city: string, region: ?string, country_code: string}  $base
     * @param  array<int, string>  $exclude  entries already on the owner's list
     * @return array{resolved: bool, base: ?array<string, mixed>, suggestions: array<int, array<string, mixed>>}
     */
    public function forBase(array $base, int $limit = self::DEFAULT_COUNT, array $exclude = []): array
    {
        $place = $this->suggester->resolveBase($base['city'], $base['region'] ?? null, $base['country_code']);

        if ($place === null) {
            // A town the dataset does not know (or a state that does not match): say so, never guess.
            return ['resolved' => false, 'base' => null, 'suggestions' => []];
        }

        return [
            'resolved' => true,
            'base' => $place->toArray(),
            'suggestions' => $this->suggester->suggest($place, max(1, min($limit, 20)), [], $exclude),
        ];
    }

    /**
     * The suggested list as plain labels, ready to prefill the editable rows.
     *
     * @param  array{city: string, region: ?string, country_code: string}  $base
     * @return array<int, string>
     */
    public function labelsFor(array $base, int $limit = self::DEFAULT_COUNT): array
    {
        return ServiceAreaList::normalize(array_column($this->forBase($base, $limit)['suggestions'], 'label'));
    }
}

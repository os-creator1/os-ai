<?php

namespace App\Library\Website\Setup;

use App\Library\Website\LocalAreaUsefulness;
use App\Library\Website\Setup\Exceptions\InvalidAnswerException;
use Illuminate\Support\Str;

/**
 * The optional local facts an owner confirms for a service area, stored beside (never inside) the `service_area_cities`
 * list as the companion answer `_service_area_details`, keyed by the area's slug. The list itself stays a plain list of
 * names, so everything that reads it (shared business facts, older questionnaire versions) is unaffected, and adding,
 * deleting, renaming or reordering an area can never shift another area's facts: a row's details travel with the row
 * (the form submits them index-aligned with the names) and are re-keyed by slug on every save. Details of a removed area
 * are dropped with it.
 *
 * Only what the owner typed or ticked is kept — never an example, and never a photo that is not one of THIS website's own
 * gallery assets (the caller passes the website's uids, so another customer's image can never be attached).
 */
final class ServiceAreaDetails
{
    public const ANSWER_KEY = '_service_area_details';

    /** Greyed-out examples the form shows as placeholders only. They are suggestions, never saved or published. */
    public const EXAMPLES = [
        'venues' => "e.g. The Davenport Hotel\nRiverfront Pavilion",
        'logistics' => 'e.g. About 25 minutes from our studio, no travel fee; we load in 90 minutes before the event.',
    ];

    /**
     * @param  array<int, mixed>  $names  the submitted rows, in order
     * @param  array<int, mixed>  $rows  the submitted per-row detail payloads (JSON strings), index-aligned with $names
     * @param  array<int, string>  $ownedPhotoUids  the uids of this website's own gallery assets
     * @return array<string, array{name: string, venues?: array<int, string>, logistics?: string, photos?: array<int, string>}>
     */
    public static function fromSubmission(array $names, array $rows, array $ownedPhotoUids): array
    {
        $owned = array_flip($ownedPhotoUids);
        $out = [];

        foreach (array_values($names) as $index => $name) {
            $name = ServiceAreaList::normalize([is_scalar($name) ? $name : ''])[0] ?? null;
            $slug = $name === null ? '' : Str::slug($name);

            if ($slug === '' || isset($out[$slug])) {
                continue;
            }

            $payload = $rows[$index] ?? null;
            $payload = is_string($payload) ? json_decode($payload, true) : $payload;

            if (! is_array($payload)) {
                continue;
            }

            $venues = $payload['venues'] ?? [];
            $venues = is_string($venues) ? preg_split('/\R/u', $venues) : (is_array($venues) ? $venues : []);

            $photos = array_values(array_unique(array_filter((array) ($payload['photos'] ?? []), fn ($uid) => is_string($uid) && isset($owned[$uid]))));

            // Never silently truncated: more than the limit is refused, and nothing is saved (the earlier selection stays).
            if (count($photos) > LocalAreaUsefulness::MAX_PHOTOS) {
                throw new InvalidAnswerException('Choose at most ' . LocalAreaUsefulness::MAX_PHOTOS . ' photos for ' . $name . ' (you selected ' . count($photos) . '). Untick one and try again; your earlier selection was kept.');
            }

            $entry = LocalAreaUsefulness::normalize([
                'name' => $name,
                'details' => [
                    'venues' => $venues,
                    'logistics' => $payload['logistics'] ?? null,
                    'photos' => $photos,
                ],
            ]);

            if ($entry !== null && $entry['details'] !== []) {
                $out[$slug] = ['name' => $entry['name']] + $entry['details'];
            }
        }

        return $out;
    }

    /**
     * The service-area entries for the generator: a bare name when the owner confirmed nothing for it, otherwise the name
     * with its details. Details are re-sanitized here, so an answer saved by anything else is held to the same rules.
     *
     * @param  array<int, string>  $names
     * @return array<int, string|array{name: string, details: array<string, mixed>}>
     */
    public static function entries(array $names, mixed $stored): array
    {
        $stored = is_array($stored) ? $stored : [];

        return array_map(function (string $name) use ($stored) {
            $saved = $stored[Str::slug($name)] ?? null;
            $entry = is_array($saved) ? LocalAreaUsefulness::normalize(['name' => $name, 'details' => $saved]) : null;

            return $entry !== null && $entry['details'] !== [] ? $entry : $name;
        }, $names);
    }
}

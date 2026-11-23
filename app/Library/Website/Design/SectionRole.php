<?php

namespace App\Library\Website\Design;

/**
 * The semantic role of a generated "services" section, read from what the section already says about itself: an
 * explicit `role` in its data wins, otherwise the deterministic Photo Booth blueprint's own headings are recognised
 * ("Events we cover", "How booking works", "Why choose …", "Areas we serve", "Our photo booths"). A design may then
 * lay the roles out differently (photo event cards, a numbered process band, an area grid) without any new section
 * type and without a site-specific word in a shared component. Null = a plain services section.
 */
final class SectionRole
{
    public const ROLES = ['events', 'booths', 'process', 'advantages', 'areas'];

    /** @param array<string, mixed> $section */
    public static function of(array $section): ?string
    {
        if (($section['type'] ?? '') !== 'services') {
            return null;
        }

        $data = (array) ($section['data'] ?? []);

        foreach ((array) ($data['items'] ?? []) as $item) {
            if (is_array($item) && ! empty($item['catalog_item_uid'])) {
                return null; // a packages section keeps its own presentation
            }
        }

        $explicit = (string) ($data['role'] ?? '');

        if (in_array($explicit, self::ROLES, true)) {
            return $explicit;
        }

        $heading = mb_strtolower(trim((string) ($data['heading'] ?? '')));

        return match (true) {
            str_contains($heading, 'events we cover'), str_contains($heading, 'occasions we') => 'events',
            str_contains($heading, 'how booking works'), str_contains($heading, 'how it works') => 'process',
            str_starts_with($heading, 'why choose') => 'advantages',
            str_contains($heading, 'areas we serve') => 'areas',
            str_contains($heading, 'photo booths'), str_contains($heading, 'our booths') => 'booths',
            default => null,
        };
    }
}

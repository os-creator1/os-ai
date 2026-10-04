<?php

namespace App\Library\Catalog;

/**
 * A package's "included features" have no column of their own — the
 * canonical `catalog_items.description` carries them as a trailing
 * "- feature" bullet block under the prose (the shape the Website wizard
 * has always written). This one helper owns BOTH directions of that
 * convention so every caller (wizard apply, wizard edit hydration) agrees
 * and an edit made in Packages & Products round-trips cleanly.
 */
final class CatalogFeatureList
{
    private function __construct()
    {
    }

    /**
     * @param  array<int, string>  $features
     */
    public static function join(?string $description, array $features): ?string
    {
        $prose = $description !== null ? trim($description) : '';
        $features = array_values(array_filter(array_map(fn ($f) => trim((string) $f), $features), fn ($f) => $f !== ''));

        if ($features === []) {
            return $prose !== '' ? $prose : null;
        }

        $bullets = implode("\n", array_map(fn ($f) => '- ' . $f, $features));

        return trim($prose . "\n\n" . $bullets);
    }

    /**
     * @return array{0: ?string, 1: array<int, string>} [prose, features]
     */
    public static function split(?string $description): array
    {
        if ($description === null || trim($description) === '') {
            return [null, []];
        }

        $lines = explode("\n", str_replace("\r\n", "\n", trim($description)));
        $features = [];
        $i = count($lines) - 1;

        while ($i >= 0 && preg_match('/^- (.+)$/', $lines[$i], $match) === 1) {
            array_unshift($features, trim($match[1]));
            $i--;
        }

        // Only treat the trailing bullets as a feature list when they sit
        // at the very start of the text or under a blank separator line —
        // never carve bullets out of the middle of hand-written prose.
        if ($features !== [] && ($i < 0 || trim($lines[$i]) === '')) {
            $prose = trim(implode("\n", array_slice($lines, 0, max($i, 0))));

            return [$prose !== '' ? $prose : null, $features];
        }

        return [trim($description), []];
    }
}

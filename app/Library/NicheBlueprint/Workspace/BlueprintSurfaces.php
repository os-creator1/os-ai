<?php

namespace App\Library\NicheBlueprint\Workspace;

/**
 * The configuration surfaces a Blueprint can carry, in INSTALL ORDER: later
 * surfaces may reference (by name) rows an earlier surface created, so the
 * order is also the dependency order. The Workspace sets a new component's
 * `position` from this rank so the installer processes them correctly.
 */
final class BlueprintSurfaces
{
    /** @var array<string, array{label: string, rank: int}> */
    public const ALL = [
        'crm' => ['label' => 'CRM', 'rank' => 1],
        'forms' => ['label' => 'Forms', 'rank' => 2],
        'calendar' => ['label' => 'Calendar', 'rank' => 3],
        'packages' => ['label' => 'Packages', 'rank' => 4],
        'documents' => ['label' => 'Documents', 'rank' => 5],
        'automations' => ['label' => 'Automations', 'rank' => 6],
        'website' => ['label' => 'Website', 'rank' => 7],
        'seo' => ['label' => 'SEO', 'rank' => 8],
        'citations' => ['label' => 'Citations', 'rank' => 9],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::ALL);
    }

    public static function has(string $surface): bool
    {
        return isset(self::ALL[$surface]);
    }

    public static function label(string $surface): string
    {
        return self::ALL[$surface]['label'] ?? $surface;
    }

    public static function positionFor(string $surface, int $ordinal): int
    {
        return (self::ALL[$surface]['rank'] ?? 99) * 1000 + $ordinal;
    }
}

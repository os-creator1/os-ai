<?php

namespace App\Library\Website;

/**
 * Small, operator-owned starter choices. These are presentation choices,
 * independent of a business's industry or future niche blueprint.
 */
final class WebsiteStarterDesigns
{
    private const DESIGNS = [
        'clean' => [
            'name' => 'Clean',
            'description' => 'Clear and welcoming, with room for your services to lead.',
            'theme' => [
                'font' => 'system',
                'primary_color' => '#2563eb',
                'secondary_color' => '#172554',
                'button_style' => 'solid',
                'content_width' => '1040px',
                'header_variant' => 'clean',
                'footer_variant' => 'clean',
            ],
        ],
        'bold' => [
            'name' => 'Bold',
            'description' => 'High contrast and confident, built around a strong first impression.',
            'theme' => [
                'font' => 'system',
                'primary_color' => '#e85d3f',
                'secondary_color' => '#172033',
                'button_style' => 'rounded',
                'content_width' => '1120px',
                'header_variant' => 'bold',
                'footer_variant' => 'bold',
            ],
        ],
        'premium' => [
            'name' => 'Premium',
            'description' => 'Calm typography and generous space for a considered look.',
            'theme' => [
                'font' => 'serif',
                'primary_color' => '#8b653e',
                'secondary_color' => '#28251f',
                'button_style' => 'outline',
                'content_width' => '1000px',
                'header_variant' => 'premium',
                'footer_variant' => 'premium',
            ],
        ],
    ];

    public static function all(): array
    {
        return self::DESIGNS;
    }

    public static function theme(string $key): ?array
    {
        return self::DESIGNS[$key]['theme'] ?? null;
    }
}

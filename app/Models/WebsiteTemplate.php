<?php

namespace App\Models;

use App\Enums\Website\WebsiteSectionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Website Generator + Local SEO Completion. An operator-owned,
 * platform-seeded visual-template catalog (never customer-editable, no
 * customer-facing write path exists anywhere in this codebase). A
 * Website's `template_key` (nullable string reference, App\Models\
 * Website::template()) selects visual presentation only — layout
 * variants, header/footer/hero/card/CTA treatment, typography, spacing —
 * never SEO architecture: the same business facts and the same
 * WebsitePageStrategy decisions produce the same page set and internal
 * links regardless of which template renders them (§7.4's renderer
 * boundary, extended by this lane's own authorized, bounded
 * presentation-variant keys below — never arbitrary HTML/CSS/JS).
 *
 * `theme` must validate against ALLOWED_THEME_VALUES before a row can
 * ever be saved (the `saving` hook below), mirroring QuestionPack's own
 * model-event validation discipline — an invalid template can never
 * reach a customer because it can never be persisted in the first
 * place, not because a request-time check happens to catch it.
 */
class WebsiteTemplate extends Model
{
    protected $fillable = [
        'key',
        'display_name',
        'description',
        'theme',
        'page_manifest',
        'manifest_version',
        'preview_image_path',
        'is_active',
    ];

    protected $casts = [
        'theme' => 'array',
        'page_manifest' => 'array',
        'manifest_version' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Every bounded presentation-variant dimension a template may set,
     * and the closed list of values the public renderer's Blade
     * partials actually know how to draw for each one. 'default'
     * exists in every dimension for the pre-existing, pre-template-
     * system Websites whose `theme` carries none of these keys at all
     * (page.blade.php's own `?? 'default'` fallback).
     */
    public const ALLOWED_THEME_VALUES = [
        'font' => ['system', 'serif', 'display'],
        'button_style' => ['solid', 'rounded', 'outline', 'pill'],
        'header_variant' => ['default', 'clean', 'bold', 'premium', 'modern', 'editorial', 'luxury', 'conversion'],
        'footer_variant' => ['default', 'clean', 'bold', 'premium', 'modern', 'editorial', 'luxury', 'conversion'],
        'nav_style' => ['default', 'modern', 'editorial', 'luxury', 'conversion'],
        'hero_style' => ['default', 'modern', 'editorial', 'luxury', 'conversion'],
        'card_style' => ['default', 'modern', 'editorial', 'luxury', 'conversion'],
        'testimonial_style' => ['default', 'modern', 'editorial', 'luxury', 'conversion'],
        'faq_style' => ['default', 'modern', 'editorial', 'luxury', 'conversion'],
        'cta_style' => ['default', 'modern', 'editorial', 'luxury', 'conversion'],
        'process_style' => ['default', 'modern', 'editorial', 'luxury', 'conversion'],
    ];

    public const REQUIRED_THEME_KEYS = ['primary_color', 'secondary_color', 'content_width'];

    protected static function booted(): void
    {
        static::saving(function (WebsiteTemplate $template) {
            $template->validateTheme($template->theme ?? []);
            $template->validatePageManifest($template->page_manifest ?? []);
        });
    }

    public static function findActiveOrFail(string $key): self
    {
        return static::where('key', $key)->where('is_active', true)->firstOrFail();
    }

    /**
     * @throws ValidationException
     */
    private function validateTheme(array $theme): void
    {
        foreach (self::REQUIRED_THEME_KEYS as $key) {
            if (empty($theme[$key])) {
                throw ValidationException::withMessages(['theme' => ["Template theme is missing required key '{$key}'."]]);
            }
        }

        foreach (self::ALLOWED_THEME_VALUES as $key => $allowed) {
            if (! array_key_exists($key, $theme)) {
                continue;
            }

            if (! in_array($theme[$key], $allowed, true)) {
                throw ValidationException::withMessages(['theme' => ["Template theme key '{$key}' has an unsupported value."]]);
            }
        }
    }

    /**
     * Mirrors the Website Guided Generation contract §7.3's manifest
     * validation: every section type a page type allows must be one of
     * the existing 8 `WebsiteSectionType` values — an invalid template
     * can never reach a customer because it fails here, at seed/save
     * time, not at generation request time.
     *
     * @throws ValidationException
     */
    private function validatePageManifest(array $manifest): void
    {
        if (empty($manifest['pages']) || ! is_array($manifest['pages'])) {
            throw ValidationException::withMessages(['page_manifest' => ['page_manifest.pages must be a non-empty array.']]);
        }

        $validTypes = array_map(fn ($case) => $case->value, WebsiteSectionType::cases());

        foreach ($manifest['pages'] as $page) {
            if (empty($page['page_type']) || ! isset($page['is_home'])) {
                throw ValidationException::withMessages(['page_manifest' => ['Each manifest page requires page_type and is_home.']]);
            }

            foreach ($page['allowed_section_types'] ?? [] as $type) {
                if (! in_array($type, $validTypes, true)) {
                    throw ValidationException::withMessages(['page_manifest' => ["Unknown section type '{$type}' in manifest page '{$page['page_type']}'."]]);
                }
            }
        }
    }
}

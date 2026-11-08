<?php

namespace App\Library\Seo;

use App\Library\Website\Seo\WebsiteHeadMeta;

/**
 * The one place the audit page, the approval endpoints and the fresh-audit verification agree on what "fixed" means for the page-metadata
 * rules a customer can correct from the audit: which page field a rule concerns, how values are compared for duplicates (the audit's own
 * normalisation: lower-case, whitespace collapsed), and when a given value satisfies a rule. Pure: no queries, no provider.
 */
final class SeoMetadataRules
{
    public const TITLE = 'seo_title';

    public const DESCRIPTION = 'meta_description';

    /** rule => [field, mode]; mode: fill (was blank), replace (too short), shorten (composed title too long), unique (shared with another page). */
    public const RULES = [
        SeoAuditRuleRegistry::SEO_TITLE_BLANK => [self::TITLE, 'fill'],
        SeoAuditRuleRegistry::SEO_TITLE_OVER_RECOMMENDED => [self::TITLE, 'shorten'],
        SeoAuditRuleRegistry::DUPLICATE_SEO_TITLE => [self::TITLE, 'unique'],
        SeoAuditRuleRegistry::META_DESCRIPTION_BLANK => [self::DESCRIPTION, 'fill'],
        SeoAuditRuleRegistry::META_DESCRIPTION_SHORT => [self::DESCRIPTION, 'replace'],
        SeoAuditRuleRegistry::DUPLICATE_META_DESCRIPTION => [self::DESCRIPTION, 'unique'],
    ];

    public static function field(string $ruleKey): ?string
    {
        return self::RULES[$ruleKey][0] ?? null;
    }

    public static function mode(string $ruleKey): ?string
    {
        return self::RULES[$ruleKey][1] ?? null;
    }

    /** Comparison-only normalisation, identical to SeoAuditEvaluator::normalize(). */
    public static function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }

    /** The <title> searchers actually get: the same rule the public page uses (page name and Business/site name composed). */
    public static function effectiveTitle(string $seoTitle, string $pageTitle, string $siteName): string
    {
        return WebsiteHeadMeta::title($seoTitle, $pageTitle, $siteName);
    }

    /**
     * Whether $value satisfies the rule for this page.
     *
     * @param  array<int, string>  $others  the values of the OTHER pages (raw), for the duplicate rules
     */
    public static function satisfies(string $ruleKey, string $value, string $pageTitle, string $siteName, array $others, int $titleMax, int $descriptionMin): bool
    {
        $value = trim($value);

        return match (self::mode($ruleKey)) {
            'fill' => $value !== '',
            'replace' => mb_strlen($value) >= $descriptionMin,
            'shorten' => $value !== '' && mb_strlen(self::effectiveTitle($value, $pageTitle, $siteName)) <= $titleMax,
            'unique' => $value !== '' && ! in_array(self::normalize($value), array_map(fn (string $o): string => self::normalize($o), array_filter($others, fn ($o): bool => trim((string) $o) !== '')), true),
            default => false,
        };
    }
}

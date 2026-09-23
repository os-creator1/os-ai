<?php

namespace App\Library\Seo;

use App\Enums\Seo\SeoAuditSeverity;

/**
 * Contract 18 §8.7 — the CLOSED technical-audit rule set, v1.
 *
 * THE ONE PLACE A FINDING'S WORDS COME FROM. §8.7 requires a finding's
 * user-visible text to be "registry template + validated facts, never free
 * text — the RFC-002 discipline". That is enforced structurally, not by
 * convention:
 *
 *   - the eight rule keys below are the only ones that exist, and
 *     `describe()` refuses any other key;
 *   - each rule declares the exact fact keys it accepts, and `validateFacts()`
 *     rejects an unknown key, a non-scalar value and anything over §8.7's
 *     8-key ceiling;
 *   - the stored finding carries only a key, a severity and those scalars —
 *     there is nowhere to put prose, so page body text, provider text and
 *     model output cannot reach a customer through this path even by mistake.
 *
 * ONLY CUSTOMER-ACTIONABLE FIELDS ARE FINDABLE (§8.7). Every rule here names
 * something the customer can change today in the existing Website page
 * editor. That is why this list is shorter than a generic SEO checklist would
 * be, and why it must not grow on taste.
 *
 * PLATFORM LIMITATIONS ARE NEVER FINDINGS (§8.7, G-2/G-3). There is
 * deliberately no rule for a missing canonical tag, missing JSON-LD /
 * structured data, sitemap scope, or the platform-path `noindex` that every
 * hosted site currently carries: the platform does not offer those controls,
 * so presenting them as the customer's mistakes would be dishonest. The
 * platform-path state is reported separately as an indexability STATUS
 * (SeoIndexabilityState), which is a property of the product, not a defect of
 * the customer's site. Note the distinction from `page_marked_noindex` below:
 * that rule fires only for a page the CUSTOMER chose to mark noindex, which
 * they can un-mark.
 *
 * LOCATION-PAGE RULES ARE DEFERRED (G-1) until Website provides a canonical
 * page<->Location association. Nothing here infers Location ownership from a
 * slug, a URL or page text.
 *
 * The 60/70 character thresholds are CONVENTIONAL GUIDANCE, not Google-stated
 * requirements (§8.7). They are labelled "recommended" in the copy and live in
 * config through SeoConfig, never as literals in a rule body.
 *
 * Pure: facts in, descriptions out. No database, no clock, no network, no AI.
 */
final class SeoAuditRuleRegistry
{
    /**
     * §8.7 — the rule-set version stored on every run and half of the
     * idempotency key. Bump it only when the rules themselves change, so an
     * already-audited revision can be re-audited under a new rule set without
     * destroying the old result.
     */
    public const VERSION = 1;

    /** §8.7 — a finding's `facts` may hold at most this many keys. */
    public const MAX_FACT_KEYS = 8;

    public const SEO_TITLE_BLANK = 'seo_title_blank';

    public const SEO_TITLE_OVER_RECOMMENDED = 'seo_title_over_recommended';

    public const META_DESCRIPTION_BLANK = 'meta_description_blank';

    public const META_DESCRIPTION_SHORT = 'meta_description_short';

    public const DUPLICATE_SEO_TITLE = 'duplicate_seo_title';

    public const DUPLICATE_META_DESCRIPTION = 'duplicate_meta_description';

    public const PAGE_MARKED_NOINDEX = 'page_marked_noindex';

    public const ASSET_MISSING_ALT = 'asset_missing_alt';

    /**
     * The closed rule set. `facts` lists the EXACT keys that rule may carry;
     * `template` may reference only those keys as `{key}` placeholders.
     *
     * @var array<string, array{severity: SeoAuditSeverity, site_level: bool, title: string, template: string, facts: array<int, string>}>
     */
    private const RULES = [
        self::SEO_TITLE_BLANK => [
            'severity' => SeoAuditSeverity::Info,
            'site_level' => false,
            'title' => 'No SEO title set',
            'template' => 'This page has no SEO title, so its page name is used in search results instead. Setting one lets you control that wording.',
            'facts' => [],
        ],
        self::SEO_TITLE_OVER_RECOMMENDED => [
            'severity' => SeoAuditSeverity::Warning,
            'site_level' => false,
            'title' => 'SEO title longer than recommended',
            'template' => 'This page\'s SEO title is {length} characters. A recommended length is {recommended_max} or fewer, so longer titles may be shortened in search results.',
            'facts' => ['length', 'recommended_max'],
        ],
        self::META_DESCRIPTION_BLANK => [
            'severity' => SeoAuditSeverity::Warning,
            'site_level' => false,
            'title' => 'No meta description set',
            'template' => 'This page has no meta description, so search engines choose their own summary of it.',
            'facts' => [],
        ],
        self::META_DESCRIPTION_SHORT => [
            'severity' => SeoAuditSeverity::Info,
            'site_level' => false,
            'title' => 'Meta description shorter than recommended',
            'template' => 'This page\'s meta description is {length} characters. A recommended length is around {recommended_min} or more, which gives searchers a fuller summary.',
            'facts' => ['length', 'recommended_min'],
        ],
        self::DUPLICATE_SEO_TITLE => [
            'severity' => SeoAuditSeverity::Warning,
            'site_level' => false,
            'title' => 'SEO title used on more than one page',
            'template' => 'This page\'s SEO title is also used on {shared_by} other page(s). A distinct title per page helps search engines tell them apart.',
            'facts' => ['shared_by'],
        ],
        self::DUPLICATE_META_DESCRIPTION => [
            'severity' => SeoAuditSeverity::Warning,
            'site_level' => false,
            'title' => 'Meta description used on more than one page',
            'template' => 'This page\'s meta description is also used on {shared_by} other page(s). A distinct description per page describes each one more accurately.',
            'facts' => ['shared_by'],
        ],
        self::PAGE_MARKED_NOINDEX => [
            'severity' => SeoAuditSeverity::Info,
            'site_level' => false,
            'title' => 'Page marked as not indexable',
            'template' => 'This page is marked "noindex", so search engines are asked not to list it. That is intentional for some pages; clear the setting if this one should be found.',
            'facts' => [],
        ],
        self::ASSET_MISSING_ALT => [
            'severity' => SeoAuditSeverity::Warning,
            'site_level' => true,
            'title' => 'Images without alternative text',
            'template' => '{asset_count} image(s) on your site have no alternative text. Alt text describes an image to search engines and to people using a screen reader.',
            'facts' => ['asset_count'],
        ],
    ];

    /** @return array<int, string> every rule key, in fixed order */
    public static function ruleKeys(): array
    {
        return array_keys(self::RULES);
    }

    public static function has(string $ruleKey): bool
    {
        return array_key_exists($ruleKey, self::RULES);
    }

    /**
     * The severity the REGISTRY assigns. A caller never chooses one, so a
     * rule cannot be quietly escalated at the point it is emitted.
     */
    public static function severityFor(string $ruleKey): SeoAuditSeverity
    {
        self::assertKnown($ruleKey);

        return self::RULES[$ruleKey]['severity'];
    }

    public static function isSiteLevel(string $ruleKey): bool
    {
        self::assertKnown($ruleKey);

        return self::RULES[$ruleKey]['site_level'];
    }

    public static function titleFor(string $ruleKey): string
    {
        self::assertKnown($ruleKey);

        return self::RULES[$ruleKey]['title'];
    }

    /**
     * §8.7 — compose the customer-visible sentence from the registry template
     * plus validated facts. This is the ONLY way a finding becomes words.
     *
     * A placeholder with no matching fact is left as-is rather than guessed
     * at, and every substituted value is cast from an already-validated
     * scalar, so nothing unvalidated can enter the string.
     *
     * @param  array<string, scalar|null>  $facts
     */
    public static function describe(string $ruleKey, array $facts): string
    {
        self::assertKnown($ruleKey);

        $validated = self::validateFacts($ruleKey, $facts);
        $template = self::RULES[$ruleKey]['template'];

        foreach ($validated as $key => $value) {
            $template = str_replace('{' . $key . '}', self::scalarToString($value), $template);
        }

        return $template;
    }

    /**
     * §8.7 — "scalar JSON, <= 8 keys". Returns the facts a finding may store,
     * and throws if the caller tried to store anything else.
     *
     * FAIL CLOSED in three ways at once: an unknown fact key is rejected (so
     * a rule cannot grow silently), a non-scalar value is rejected (so no
     * array of page text can be smuggled in), and a key the rule declares but
     * the caller omitted is simply absent rather than defaulted.
     *
     * @param  array<string, mixed>  $facts
     * @return array<string, scalar|null>
     */
    public static function validateFacts(string $ruleKey, array $facts): array
    {
        self::assertKnown($ruleKey);

        $allowed = self::RULES[$ruleKey]['facts'];

        if (count($facts) > self::MAX_FACT_KEYS) {
            throw new \InvalidArgumentException(
                "Rule [{$ruleKey}] may carry at most " . self::MAX_FACT_KEYS . ' fact keys.'
            );
        }

        $validated = [];

        foreach ($facts as $key => $value) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException(
                    "Rule [{$ruleKey}] does not declare the fact key [" . (is_string($key) ? $key : gettype($key)) . '].'
                );
            }

            if ($value !== null && ! is_scalar($value)) {
                throw new \InvalidArgumentException(
                    "Rule [{$ruleKey}]'s fact [{$key}] must be a scalar, " . gettype($value) . ' given.'
                );
            }

            $validated[$key] = $value;
        }

        return $validated;
    }

    private static function scalarToString(string|int|float|bool|null $value): string
    {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        return (string) $value;
    }

    private static function assertKnown(string $ruleKey): void
    {
        if (! array_key_exists($ruleKey, self::RULES)) {
            throw new \InvalidArgumentException("Unknown SEO audit rule [{$ruleKey}].");
        }
    }
}

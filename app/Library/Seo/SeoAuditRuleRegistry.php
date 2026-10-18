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
 * PLATFORM-HANDLED TECHNICALITIES ARE NEVER FINDINGS (§8.7, G-2/G-3). There is
 * deliberately no rule for a canonical tag, JSON-LD / structured data, Open
 * Graph tags, the sitemap, or the platform-path `noindex`. Since SEO V1 final
 * the platform EMITS the first four itself on a custom domain (a canonical
 * URL, LocalBusiness and BreadcrumbList JSON-LD, og: tags and a sitemap of the
 * indexable pages) and the customer has no control over them, so there is
 * nothing for them to fix and nothing honest to report as their mistake. They
 * are not "checked" and not "missing": they are the platform's job. Whether
 * search engines can find the site at all is reported separately as an
 * indexability STATUS (SeoIndexability), a property of how the site is set up,
 * not a defect. Note the distinction from `page_marked_noindex` below: that
 * rule fires only for a page the CUSTOMER chose to hide from search, which
 * they can change.
 *
 * LOCATION-PAGE RULES ARE DEFERRED (G-1) until Website provides a canonical
 * page<->Location association. Nothing here infers Location ownership from a
 * slug, a URL or page text.
 *
 * The 60/70 character thresholds are CONVENTIONAL GUIDANCE, not Google-stated
 * requirements (§8.7). They are labelled "recommended" in the copy and live in
 * config through SeoConfig, never as literals in a rule body.
 *
 * EXTERNAL WEBSITES (External Website Audit Mode V1). The same registry serves a
 * customer's own, externally hosted website, where the owner DOES control the
 * canonical tag, headings, sharing preview and structured data. Those extra rules
 * live in EXTERNAL_RULES and only ever fire from facts a crawl supplies; they are
 * not part of ruleKeys(), so the hosted rule set, its stored runs and its tests
 * are unchanged.
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

    // EXTERNAL-ONLY rules (External Website Audit Mode V1). They fire only when the audit SOURCE supplied
    // the fact they need, which only a crawl of the owner's own site does; a hosted revision never supplies
    // them, so they can never become findings against a hosted customer (§8.7, G-2/G-3 stands).
    public const PAGE_NOT_REACHABLE = 'page_not_reachable';

    public const BROKEN_INTERNAL_LINK = 'broken_internal_link';

    public const H1_MISSING = 'h1_missing';

    public const H1_MULTIPLE = 'h1_multiple';

    public const CANONICAL_MISSING = 'canonical_missing';

    public const OPEN_GRAPH_MISSING = 'open_graph_missing';

    public const STRUCTURED_DATA_MISSING = 'structured_data_missing';

    /**
     * The closed rule set. `facts` lists the EXACT keys that rule may carry;
     * `template` may reference only those keys as `{key}` placeholders.
     *
     * @var array<string, array{severity: SeoAuditSeverity, site_level: bool, title: string, template: string, facts: array<int, string>}>
     */
    private const RULES = [
        // OWNER-FACING WORDS ARE PLAIN. The rule KEYS keep their technical names
        // (they are stored and pinned by tests); what a customer reads says
        // "page title", "search result description", "hidden from search" and
        // "image description" instead of SEO jargon.
        self::SEO_TITLE_BLANK => [
            'severity' => SeoAuditSeverity::Info,
            'site_level' => false,
            'title' => 'No page title set',
            'template' => 'This page has no page title of its own, so its page name is used in search results instead. Setting one lets you control that wording.',
            'facts' => [],
        ],
        self::SEO_TITLE_OVER_RECOMMENDED => [
            'severity' => SeoAuditSeverity::Warning,
            'site_level' => false,
            // Measured on the title visitors and search engines really get:
            // the page title plus your business name, composed exactly as the
            // public page composes it (WebsiteHeadMeta::title).
            'title' => 'Page title longer than recommended',
            'template' => 'This page\'s title in search results is {length} characters, including your business name. A recommended length is {recommended_max} or fewer, so longer titles may be cut off in search results.',
            'facts' => ['length', 'recommended_max'],
        ],
        self::META_DESCRIPTION_BLANK => [
            'severity' => SeoAuditSeverity::Warning,
            'site_level' => false,
            'title' => 'No search result description set',
            'template' => 'This page has no search result description, so search engines choose their own summary of it.',
            'facts' => [],
        ],
        self::META_DESCRIPTION_SHORT => [
            'severity' => SeoAuditSeverity::Info,
            'site_level' => false,
            'title' => 'Search result description shorter than recommended',
            'template' => 'This page\'s search result description is {length} characters. A recommended length is around {recommended_min} or more, which gives searchers a fuller summary.',
            'facts' => ['length', 'recommended_min'],
        ],
        self::DUPLICATE_SEO_TITLE => [
            'severity' => SeoAuditSeverity::Warning,
            'site_level' => false,
            'title' => 'Page title used on more than one page',
            'template' => 'This page\'s title is also used on {shared_by} other page(s). A distinct title per page helps search engines tell them apart.',
            'facts' => ['shared_by'],
        ],
        self::DUPLICATE_META_DESCRIPTION => [
            'severity' => SeoAuditSeverity::Warning,
            'site_level' => false,
            'title' => 'Search result description used on more than one page',
            'template' => 'This page\'s search result description is also used on {shared_by} other page(s). A distinct description per page describes each one more accurately.',
            'facts' => ['shared_by'],
        ],
        self::PAGE_MARKED_NOINDEX => [
            'severity' => SeoAuditSeverity::Info,
            'site_level' => false,
            'title' => 'Page hidden from search',
            'template' => 'This page is hidden from search engines, so it will not appear in results. That is intentional for some pages; allow search engines if this one should be found.',
            'facts' => [],
        ],
        self::ASSET_MISSING_ALT => [
            'severity' => SeoAuditSeverity::Warning,
            'site_level' => true,
            'title' => 'Images without a description',
            'template' => '{asset_count} image(s) on your site have no image description. A description tells search engines, and people using a screen reader, what the image shows.',
            'facts' => ['asset_count'],
        ],
    ];

    /**
     * The additional rules that apply ONLY to an external website, where the owner
     * controls the canonical tag, headings, sharing preview and structured data.
     * Same shape, same registry-owned words, same fact validation as RULES. They are
     * kept in their own table on purpose: ruleKeys() — the closed set Contract 18 §8.7
     * fixes for a hosted revision — is unchanged, and no hosted audit can emit one.
     *
     * @var array<string, array{severity: SeoAuditSeverity, site_level: bool, title: string, template: string, facts: array<int, string>}>
     */
    private const EXTERNAL_RULES = [
        self::PAGE_NOT_REACHABLE => [
            'severity' => SeoAuditSeverity::Critical,
            'site_level' => false,
            'title' => 'Page could not be loaded',
            'template' => 'This page could not be opened when we checked it, so visitors and search engines cannot see it. Check that the address is right and that the page still exists.',
            'facts' => ['http_status'],
        ],
        self::BROKEN_INTERNAL_LINK => [
            'severity' => SeoAuditSeverity::Warning,
            'site_level' => false,
            'title' => 'Links to pages that do not work',
            'template' => '{link_count} link(s) on this page lead to pages on your own site that did not load. Visitors who click them reach an error.',
            'facts' => ['link_count'],
        ],
        self::H1_MISSING => [
            'severity' => SeoAuditSeverity::Warning,
            'site_level' => false,
            'title' => 'No main heading',
            'template' => 'This page has no main heading. A clear main heading tells visitors and search engines what the page is about.',
            'facts' => [],
        ],
        self::H1_MULTIPLE => [
            'severity' => SeoAuditSeverity::Info,
            'site_level' => false,
            'title' => 'More than one main heading',
            'template' => 'This page has {h1_count} main headings. One clear main heading is easier for search engines to understand.',
            'facts' => ['h1_count'],
        ],
        self::CANONICAL_MISSING => [
            'severity' => SeoAuditSeverity::Info,
            'site_level' => false,
            'title' => 'No preferred address set',
            'template' => 'This page does not say which web address is its preferred one. Without that, search engines may treat copies of it (for example with and without www) as separate pages.',
            'facts' => [],
        ],
        self::OPEN_GRAPH_MISSING => [
            'severity' => SeoAuditSeverity::Info,
            'site_level' => false,
            'title' => 'No sharing preview set',
            'template' => 'This page has no sharing preview, so a link to it shared on social networks or in messages may look plain.',
            'facts' => [],
        ],
        self::STRUCTURED_DATA_MISSING => [
            'severity' => SeoAuditSeverity::Info,
            'site_level' => true,
            'title' => 'No structured business information',
            'template' => 'None of the pages we checked describe your business in a form search engines can read directly, such as your address and opening hours. Adding it can help search engines show accurate details.',
            'facts' => [],
        ],
    ];

    /** @return array<int, string> every HOSTED rule key, in fixed order (the closed §8.7 set) */
    public static function ruleKeys(): array
    {
        return array_keys(self::RULES);
    }

    /** @return array<int, string> the rules that apply only to an external website, in fixed order */
    public static function externalRuleKeys(): array
    {
        return array_keys(self::EXTERNAL_RULES);
    }

    /** @return array{severity: SeoAuditSeverity, site_level: bool, title: string, template: string, facts: array<int, string>}|null */
    private static function definition(string $ruleKey): ?array
    {
        return self::RULES[$ruleKey] ?? self::EXTERNAL_RULES[$ruleKey] ?? null;
    }

    public static function has(string $ruleKey): bool
    {
        return self::definition($ruleKey) !== null;
    }

    /**
     * The severity the REGISTRY assigns. A caller never chooses one, so a
     * rule cannot be quietly escalated at the point it is emitted.
     */
    public static function severityFor(string $ruleKey): SeoAuditSeverity
    {
        self::assertKnown($ruleKey);

        return self::definition($ruleKey)['severity'];
    }

    public static function isSiteLevel(string $ruleKey): bool
    {
        self::assertKnown($ruleKey);

        return self::definition($ruleKey)['site_level'];
    }

    public static function titleFor(string $ruleKey): string
    {
        self::assertKnown($ruleKey);

        return self::definition($ruleKey)['title'];
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
        $template = self::definition($ruleKey)['template'];

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

        $allowed = self::definition($ruleKey)['facts'];

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
        if (self::definition($ruleKey) === null) {
            throw new \InvalidArgumentException("Unknown SEO audit rule [{$ruleKey}].");
        }
    }
}

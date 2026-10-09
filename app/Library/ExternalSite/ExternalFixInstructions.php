<?php

namespace App\Library\ExternalSite;

use App\Library\Seo\SeoAuditRuleRegistry;
use App\Models\Business;
use App\Models\ExternalSitePage;

/**
 * External Website Audit Mode V1 — the plain-language "Show me how to fix this"
 * for each audit rule, written for an owner whose site lives in someone else's
 * editor (WordPress, Wix, Squarespace, Shopify, a developer's code...).
 *
 * MotionGrove does NOT edit that site, so there is no "Fix" button anywhere: only
 * where to look, what to change and (for titles) a copy-able suggestion built
 * mechanically from the page address and the business name. A suggestion is
 * wording to adapt, never a claim about the business.
 */
final class ExternalFixInstructions
{
    /** @return list<string> */
    public static function steps(string $ruleKey): array
    {
        $editor = 'Open this page in your website editor (or ask whoever looks after your site).';

        return match ($ruleKey) {
            SeoAuditRuleRegistry::SEO_TITLE_BLANK => [$editor, 'Find the page\'s search settings, often called "SEO" or "Page title". Add a short, clear title that says what the page is about.', 'Save and publish the page.'],
            SeoAuditRuleRegistry::SEO_TITLE_OVER_RECOMMENDED => [$editor, 'Shorten the page title to roughly 60 characters, keeping the most important words at the start.', 'Save and publish the page.'],
            SeoAuditRuleRegistry::DUPLICATE_SEO_TITLE => [$editor, 'Give this page its own title that describes what is different about it from the other pages that share the title.', 'Save and publish the page.'],
            SeoAuditRuleRegistry::META_DESCRIPTION_BLANK, SeoAuditRuleRegistry::META_DESCRIPTION_SHORT => [$editor, 'Find the page\'s search settings and write a description of one or two sentences, around 70 to 155 characters, saying what the visitor will find and why to choose you.', 'Save and publish the page.'],
            SeoAuditRuleRegistry::DUPLICATE_META_DESCRIPTION => [$editor, 'Rewrite the description so it describes this page specifically. Each page should have its own.', 'Save and publish the page.'],
            SeoAuditRuleRegistry::PAGE_MARKED_NOINDEX => [$editor, 'Look for a setting like "Hide this page from search engines" and switch it off if you want the page to be found. Leave it on for pages that should stay private, such as thank-you pages.', 'Save and publish the page.'],
            SeoAuditRuleRegistry::ASSET_MISSING_ALT => ['In your website editor open the media library or each page\'s images.', 'For each picture that shows something meaningful, add a short description of what it shows (the "alt text" or "image description" field).', 'Pictures that are purely decorative can be left without a description.'],
            SeoAuditRuleRegistry::PAGE_NOT_REACHABLE => ['Open the address in your browser to see what visitors see.', 'If the page was deleted or moved, either restore it or set up a redirect from the old address to the new page. Then update or remove any links that point to the old address.', 'If the page should exist, ask whoever hosts your website why it is not loading.'],
            SeoAuditRuleRegistry::BROKEN_INTERNAL_LINK => [$editor, 'Find the links on this page that lead to a page that does not work (the list of affected pages shows which). Update them to the right address or remove them.', 'Save and publish the page.'],
            SeoAuditRuleRegistry::H1_MISSING => [$editor, 'Make sure the page has one main heading at the top that says what the page is about. In most editors this is the "Heading 1" or "Title" style.', 'Save and publish the page.'],
            SeoAuditRuleRegistry::H1_MULTIPLE => [$editor, 'Keep one main heading ("Heading 1") on the page and change the others to smaller heading styles ("Heading 2" and below).', 'Save and publish the page.'],
            SeoAuditRuleRegistry::CANONICAL_MISSING => ['This is usually a setting in your website platform or an SEO plugin called "Canonical URL" or "Preferred address". Turn on automatic canonical addresses, or ask your developer to add one to each page.'],
            SeoAuditRuleRegistry::OPEN_GRAPH_MISSING => ['Most SEO plugins and website platforms have a "Social sharing" or "Open Graph" setting. Turn it on and choose a picture and a short description for the page.'],
            SeoAuditRuleRegistry::STRUCTURED_DATA_MISSING => ['Many SEO plugins add "Local business" information automatically. Turn that on and fill in your address, phone number and opening hours, or ask your developer to add it.'],
            default => [],
        };
    }

    /** True when a title suggestion is useful for this rule. */
    public static function suggestsTitle(string $ruleKey): bool
    {
        return in_array($ruleKey, [SeoAuditRuleRegistry::SEO_TITLE_BLANK, SeoAuditRuleRegistry::SEO_TITLE_OVER_RECOMMENDED, SeoAuditRuleRegistry::DUPLICATE_SEO_TITLE], true);
    }

    /**
     * "Topic | Business name" from the page address and the business name,
     * at most 60 characters. Purely mechanical wording to adapt.
     */
    public static function suggestedTitle(ExternalSitePage $page, Business $business): string
    {
        $path = trim((string) parse_url((string) $page->url, PHP_URL_PATH), '/');
        $last = $path === '' ? 'Home' : basename($path);
        $topic = ucwords(trim(preg_replace('/[-_]+/', ' ', preg_replace('/\.[a-z0-9]+$/i', '', $last) ?? $last) ?? $last));
        $name = trim((string) $business->name);
        $title = $name === '' ? $topic : $topic.' | '.$name;

        return mb_strlen($title) <= 60 ? $title : rtrim(mb_substr($title, 0, 57)).'...';
    }
}

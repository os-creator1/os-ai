<?php

namespace App\Library\Seo\Content;

use App\Library\Website\WebsiteSlugRules;
use App\Models\WebsiteArticle;
use App\Models\WebsiteArticleSlugHistory;
use Illuminate\Support\Str;

/**
 * SEO Content Engine V1 — article slugs: lowercase, human-readable, stable and collision-safe.
 *
 * Validity is the Website's own WebsiteSlugRules (ASCII, lowercase, hyphen-separated, never a reserved
 * word), so an article and a page can never disagree about what a legal slug is. A slug is taken when ANY
 * article of the same Website holds it now, or ever held it (website_article_slug_history) — a retired
 * slug keeps redirecting to its article and is never handed to a different one.
 */
final class ArticleSlugger
{
    public static function fromTitle(string $title): string
    {
        $slug = Str::slug(Str::ascii($title));

        return trim(mb_substr($slug, 0, WebsiteSlugRules::MAX_LENGTH), '-');
    }

    public static function isValid(string $slug): bool
    {
        return WebsiteSlugRules::isValid($slug);
    }

    /** Whether $slug is held (now or historically) by an article other than $exceptArticleId. */
    public static function isTaken(int $websiteId, string $slug, ?int $exceptArticleId = null): bool
    {
        $live = WebsiteArticle::query()->where('website_id', $websiteId)->where('slug', $slug)
            ->when($exceptArticleId !== null, fn ($q) => $q->where('id', '!=', $exceptArticleId))
            ->exists();

        if ($live) {
            return true;
        }

        return WebsiteArticleSlugHistory::query()->where('website_id', $websiteId)->where('slug', $slug)
            ->when($exceptArticleId !== null, fn ($q) => $q->where('website_article_id', '!=', $exceptArticleId))
            ->exists();
    }

    /** The first free slug for a title: "how-much-does-a-photo-booth-cost", then "...-2", "...-3". */
    public static function unique(int $websiteId, string $title, ?int $exceptArticleId = null): string
    {
        $base = self::fromTitle($title);

        if ($base === '' || ! self::isValid($base)) {
            $base = 'article';
        }

        $candidate = $base;
        $n = 2;

        while (self::isTaken($websiteId, $candidate, $exceptArticleId) || ! self::isValid($candidate)) {
            $suffix = '-' . $n++;
            $candidate = trim(mb_substr($base, 0, WebsiteSlugRules::MAX_LENGTH - strlen($suffix)), '-') . $suffix;
        }

        return $candidate;
    }
}

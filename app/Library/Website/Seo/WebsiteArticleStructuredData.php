<?php

namespace App\Library\Website\Seo;

use App\Models\WebsiteArticle;

/**
 * SEO Content Engine V1 — BlogPosting structured data (the BreadcrumbList comes from the canonical WebsiteBreadcrumbStructuredData) for a published article.
 *
 * Real data only. Every value comes from a field the owner or the platform actually holds: the article's
 * own title, description, dates and image, the Business name (publisher, and author when the owner has not
 * set a display name) and the Business logo (only when the Website has one). Nothing is invented — no
 * author credentials, no ratings, no awards, no fake dates — and an optional property with no real value
 * is simply left out.
 *
 * Callers pass this only for an indexable article, like every other schema block on the site.
 */
final class WebsiteArticleStructuredData
{
    /**
     * @param  array{url: string, width?: ?int, height?: ?int}|null  $image  absolute URL of the featured image
     * @param  array{url: string}|null  $logo  the Business logo, if the Website has one
     * @return array<string, mixed>
     */
    public function blogPosting(WebsiteArticle $article, string $businessName, string $canonicalUrl, ?array $image, ?array $logo): array
    {
        $description = trim((string) ($article->meta_description ?: $article->excerpt));
        $authorName = trim((string) $article->author_name) !== '' ? trim((string) $article->author_name) : $businessName;

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $canonicalUrl],
            'headline' => mb_substr((string) $article->title, 0, 110),
            'url' => $canonicalUrl,
            'datePublished' => $article->published_at?->toIso8601String(),
            'dateModified' => $article->dateModified()?->toIso8601String(),
            // A display name set by the owner is a person's name; with none, the Business itself is the author.
            'author' => trim((string) $article->author_name) !== ''
                ? ['@type' => 'Person', 'name' => $authorName]
                : ['@type' => 'Organization', 'name' => $authorName],
            'publisher' => array_filter([
                '@type' => 'Organization',
                'name' => $businessName,
                'logo' => $logo !== null ? ['@type' => 'ImageObject', 'url' => $logo['url']] : null,
            ]),
        ];

        if ($description !== '') {
            $data['description'] = $description;
        }

        if ($image !== null) {
            $data['image'] = [$image['url']];
        }

        return array_filter($data, fn ($value) => $value !== null);
    }
}

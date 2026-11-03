<?php

namespace App\Library\Seo\Content;

use App\Library\Seo\SeoPublishedContentReader;
use App\Library\Seo\SeoPublishedPage;
use App\Library\Website\Seo\WebsitePageIntent;
use App\Library\Website\Seo\WebsitePageIntentClassifier;
use App\Models\Business;

/**
 * SEO Content Engine V1 — the Website's PUBLISHED pages, as the content engine sees them: each page
 * classified (home / service hub / service / location / area hub / packages / other) by the Website's page-intent
 * map: the role the generator stored on the page, else the generator's own address conventions, else the page's
 * address matched against THIS Business's services and places (WebsitePageIntentClassifier). A site moved from another
 * builder, with pages like /wedding-photo-booth-rental or /cheney, is classified by what they are about, not left as "other". It is the single input for
 * "which page does this article support", "which pages are money pages we must not compete with" and
 * "which pages may an article link to".
 *
 * It reads ONLY through SeoPublishedContentReader — the one parser of the immutable published
 * revision — so it can never see a draft page, a Preview URL or another Business's pages, and it
 * makes no provider or AI call.
 *
 * `linkable` is the single rule for "may an article link to this page": published and not noindex.
 */
final class ArticleSiteInventory
{
    public const KIND_HOME = 'home';
    public const KIND_SERVICES_HUB = 'services_hub';
    public const KIND_SERVICE = 'service';
    public const KIND_EVENT = 'event';
    public const KIND_LOCATION = 'location';
    public const KIND_AREA_HUB = 'area_hub';
    public const KIND_PACKAGES = 'packages';
    public const KIND_CONTACT = 'contact';
    public const KIND_OTHER = 'other';

    /** Kinds that carry a high-intent "hire / book this" search of their own. */
    public const MONEY_KINDS = [self::KIND_HOME, self::KIND_SERVICES_HUB, self::KIND_SERVICE, self::KIND_EVENT, self::KIND_LOCATION, self::KIND_PACKAGES];

    public function __construct(private readonly SeoPublishedContentReader $content)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pages(Business $business): array
    {
        $published = $this->content->forBusiness($business);

        if ($published === null) {
            return [];
        }

        $classifier = WebsitePageIntentClassifier::forBusiness($business);

        return array_values(array_map(fn (SeoPublishedPage $page) => $this->describe($page, $classifier), $published->pages));
    }

    /** @return array<string, mixed>|null */
    public function find(Business $business, string $pageUid): ?array
    {
        foreach ($this->pages($business) as $page) {
            if ($page['uid'] === $pageUid) {
                return $page;
            }
        }

        return null;
    }

    /** @return array{uid: string, slug: ?string, title: string, seo_title: ?string, kind: string, role: string, place: ?string, noindex: bool, money: bool, linkable: bool} */
    private function describe(SeoPublishedPage $page, WebsitePageIntentClassifier $classifier): array
    {
        $identity = $classifier->classify([
            'slug' => $page->slug, 'is_home' => $page->isHome, 'title' => $page->title, 'role' => $page->role, 'intent' => $page->intent,
            'text' => $page->textSurfaces()['body'],
        ]);
        $kind = self::kindForRole($identity['role']);

        return [
            'uid' => $page->uid,
            'slug' => $page->slug,
            'title' => $page->title,
            'seo_title' => $page->hasSeoTitle() ? (string) $page->seoTitle : null,
            'kind' => $kind,
            'role' => $identity['role'],
            'place' => $identity['place'],
            'noindex' => $page->noindex,
            'money' => in_array($kind, self::MONEY_KINDS, true),
            'linkable' => ! $page->noindex,
        ];
    }

    public static function kindOf(bool $isHome, string $slug): string
    {
        return self::kindForRole(WebsitePageIntent::legacyRole($isHome, $slug) ?? WebsitePageIntent::OTHER);
    }

    public static function kindForRole(string $role): string
    {
        return match ($role) {
            WebsitePageIntent::HOME => self::KIND_HOME,
            WebsitePageIntent::SERVICES_HUB => self::KIND_SERVICES_HUB,
            WebsitePageIntent::SERVICE => self::KIND_SERVICE,
            WebsitePageIntent::EVENT => self::KIND_EVENT,
            WebsitePageIntent::LOCATION => self::KIND_LOCATION,
            WebsitePageIntent::AREA_HUB => self::KIND_AREA_HUB,
            WebsitePageIntent::PACKAGES => self::KIND_PACKAGES,
            WebsitePageIntent::CONTACT => self::KIND_CONTACT,
            default => self::KIND_OTHER,
        };
    }

    /**
     * The searches a page is built to answer: its title, SEO title and the words of its slug
     * ("service-wedding-photo-booth-rental" → "wedding photo booth rental"). These are what an article
     * must not duplicate.
     *
     * @param  array{slug: ?string, title: string, seo_title: ?string, kind: string}  $page
     * @return array<int, string>
     */
    public static function targetPhrases(array $page): array
    {
        $phrases = [$page['title']];

        if ($page['seo_title'] !== null) {
            $phrases[] = $page['seo_title'];
        }

        $slug = (string) ($page['slug'] ?? '');

        if ($slug !== '') {
            $phrases[] = str_replace('-', ' ', (string) preg_replace('/^(service|serving)-/', '', $slug));
        }

        return array_values(array_unique(array_filter(array_map('trim', $phrases))));
    }
}

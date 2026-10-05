<?php

namespace App\Http\View\Composers;

use App\Library\Website\Design\BrandColors;
use App\Library\Website\Design\WebsiteCtaResolver;
use App\Library\Website\Design\WebsiteDesigns;
use App\Library\Website\Design\WebsiteNavigationBuilder;
use App\Library\Website\Seo\WebsiteBreadcrumbStructuredData;
use App\Library\Website\Seo\WebsiteHeadMeta;
use Illuminate\Contracts\View\View;

/**
 * Website V1 final — gives the shared public layout (preview, platform
 * path and custom domain all render `public.website.page`) everything the
 * template-driven chrome needs, computed ONE way for all three:
 *
 *   $design   the template's WebsiteDesign (null = a legacy non-template site,
 *             which keeps the original generic header/footer untouched)
 *   $nav      the compact primary navigation + footer link groups
 *   $siteCta  the resolved main button (booking -> form -> contact -> none)
 *   $brandStyle  safe CSS custom properties from the one brand colour
 *   $logo     the owner's logo asset (url + alt), if any
 *   $siteContact  phone/email shown in the header strip and footer
 *   $isHomePage   whether the current page is the Home page
 *
 * The three controllers only have to hand over `navigationPages` (each now
 * carrying `slug` and `has_form`) — they never compute design or CTA logic.
 */
final class WebsitePageComposer
{
    public function __construct(
        private readonly WebsiteNavigationBuilder $navigation,
        private readonly WebsiteCtaResolver $cta,
        private readonly WebsiteHeadMeta $headMeta,
    ) {}

    public function compose(View $view): void
    {
        $data = $view->getData();
        $website = $data['website'] ?? null;
        $meta = $data['websiteMeta'] ?? [];
        $theme = $meta['theme'] ?? [];
        $pages = array_values($data['navigationPages'] ?? []);
        $page = $data['page'] ?? null;
        $currentUid = $page->uid ?? null;
        $isPreview = (bool) ($data['isPreview'] ?? false);

        $design = $data['designOverride'] ?? WebsiteDesigns::resolve($website?->template_key, $theme);
        $business = $website?->business;

        $currentNav = collect($pages)->firstWhere('uid', $currentUid);
        $isHome = (bool) ($currentNav['is_home'] ?? false);

        $siteCta = $data['siteCtaOverride'] ?? $this->cta->resolve($business, $pages, $isPreview);

        $logoUid = $theme['logo_asset_uid'] ?? null;
        $assets = $data['assetsByUid'] ?? [];
        $logo = $logoUid !== null && isset($assets[$logoUid])
            ? array_merge($assets[$logoUid], ['alt_text' => ($assets[$logoUid]['alt_text'] ?? null) ?: ((string) ($meta['name'] ?? '') . ' logo')])
            : null;

        $view->with([
            'design' => $design,
            'nav' => $this->navigation->build($pages, $currentUid),
            'siteCta' => $siteCta,
            'pageUrls' => collect($pages)->filter(fn ($candidate) => ! empty($candidate['url']))->mapWithKeys(fn ($candidate) => [(! empty($candidate['is_home']) ? '' : (string) ($candidate['slug'] ?? '')) => $candidate['url']])->all(),
            'brandStyle' => BrandColors::inlineStyle($theme['brand_color'] ?? null),
            'logo' => $logo,
            'siteContact' => $this->contact($meta, $business, $isPreview),
            'isHomePage' => $isHome,
            'breadcrumbs' => $currentNav !== null ? WebsiteBreadcrumbStructuredData::trail($currentNav, $pages) : [],
            'head' => $this->headMeta->build(
                json_decode(json_encode($page), true) ?: [],
                $meta,
                $data['canonicalUrl'] ?? null,
                $assets,
            ),
        ]);
    }

    /**
     * Public pages show the facts frozen at publish time (only the ones the
     * owner chose to display); the editor preview reads the Business live,
     * exactly as the next publish will.
     *
     * @return array{phone: ?string, email: ?string}
     */
    private function contact(array $meta, $business, bool $isPreview): array
    {
        if (isset($meta['contact']) && is_array($meta['contact'])) {
            return ['phone' => $meta['contact']['phone'] ?? null, 'email' => $meta['contact']['email'] ?? null];
        }

        if ($isPreview && $business !== null) {
            return ['phone' => $business->phone ?: null, 'email' => $business->email ?: null];
        }

        return ['phone' => null, 'email' => null];
    }
}

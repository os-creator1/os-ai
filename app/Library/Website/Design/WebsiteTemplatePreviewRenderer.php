<?php

namespace App\Library\Website\Design;

use App\Models\Business;
use App\Models\Website;
use App\Models\WebsiteTemplate;

/**
 * Website V1 final — renders the REAL public layout (public.website.page) for
 * one template, fed the owner's own business (WebsiteTemplatePreviewBuilder).
 * Used for the Review screen's preview cards (embedded, so no extra request
 * per card) and the "open full preview" page — one renderer, never a mock-up.
 */
final class WebsiteTemplatePreviewRenderer
{
    public function __construct(private readonly WebsiteTemplatePreviewBuilder $builder) {}

    public function render(Business $business, ?Website $website, WebsiteTemplate $template): string
    {
        $design = WebsiteDesigns::forTemplateKey($template->key);
        abort_unless($design !== null, 404);

        $website ??= new Website([
            'name' => $business->name,
            'template_key' => $template->key,
            'theme' => $template->theme,
        ]);

        // The owner's own brand colour / logo / hero show in every card.
        $theme = array_merge($template->theme, WebsiteLookService::ownerTokens($website->theme));
        $website->setRelation('business', $business);

        $assetsByUid = $website->exists
            ? $website->assets()->get()->keyBy('uid')->map(fn ($asset) => ['uid' => $asset->uid, 'url' => $asset->url(), 'alt_text' => $asset->alt_text])->all()
            : [];

        return view('public.website.page', [
            'website' => $website,
            'websiteMeta' => ['name' => $website->name, 'theme' => $theme],
            'page' => (object) ['uid' => 'home', 'title' => 'Home', 'seo' => (object) ['seo_title' => $business->name, 'meta_description' => null, 'noindex' => true]],
            'sections' => $this->builder->homeSections($business, $website),
            'assetsByUid' => $assetsByUid,
            'formsByUid' => [],
            'isPreview' => true,
            'previewBannerText' => 'Sample preview — this is how ' . $design->label . ' will look with your business',
            'designOverride' => $design,
            'inlineCss' => $this->inlineCss(),
            'navigationPages' => $this->builder->navigation(),
        ])->render();
    }

    /** Our own two static stylesheets, inlined once per request (never any owner content). */
    private function inlineCss(): string
    {
        static $css = null;

        return $css ??= str_ireplace('</style', '', (string) (@file_get_contents(public_path('css/website-public.css')) . "
" . @file_get_contents(public_path('css/website-design.css'))));
    }
}

<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Library\Website\Seo\WebsiteAddressPrivacyGate;
use App\Library\Website\Seo\WebsiteCrawlFiles;
use App\Library\Website\Seo\WebsiteRedirectMap;
use App\Library\Website\WebsitePublicEntitlementGate;
use App\Models\Website;
use App\Models\WebsiteRevision;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Website Generation + Hosting Slice A contract §3.3/§9/§24/§26.4 — the
 * anonymous, unauthenticated public renderer. Reads ONLY the immutable
 * WebsiteRevision snapshot reached via published_revision_id — never
 * website_pages (the mutable draft). No Auth::id()/session dependency
 * anywhere on this path. Every response is gated by
 * WebsitePublicEntitlementGate::allows() before any cached content is
 * ever returned (contract §26.4) — a snapshot-cache hit never bypasses
 * that gate. Every HTML response emits X-Robots-Tag: noindex, follow
 * (contract §20/§21) — Slice A Websites are publicly viewable but never
 * search-indexed.
 */
class WebsiteController extends Controller
{
    private const SNAPSHOT_CACHE_TTL_SECONDS = 300;

    public function __construct(
        private readonly WebsitePublicEntitlementGate $gate,
        private readonly WebsiteAddressPrivacyGate $privacyGate,
    ) {
    }

    public function home(Website $website): View|Response|RedirectResponse
    {
        $snapshot = $this->resolveSnapshotOrAbort($website);

        $page = collect($snapshot['pages'])->firstWhere('is_home', true);

        abort_unless($page !== null, 404);

        return $this->renderPage($website, $snapshot, $page);
    }

    public function page(Website $website, string $slug): View|Response|RedirectResponse
    {
        $snapshot = $this->resolveSnapshotOrAbort($website);

        // Strict comparison: a loose one would make "010" answer the page whose slug is "10".
        $page = collect($snapshot['pages'])->first(fn ($candidate) => (string) ($candidate['slug'] ?? '') === $slug && empty($candidate['is_home']));

        if ($page === null) {
            // The page's address changed since the live revision was made.
            $target = WebsiteRedirectMap::target($snapshot, $slug);

            abort_unless($target !== null, 404);

            // With an Active domain the final address is on that domain: one hop, not two.
            $domain = $website->activePrimaryDomain();

            if ($domain !== null) {
                return redirect()->away('https://'.$domain->domain.($target === '' ? '/' : '/'.$target), 301);
            }

            return redirect()->to($this->addressOf($website, $target === '' ? null : $target), 301);
        }

        return $this->renderPage($website, $snapshot, $page);
    }

    /**
     * SEO V1 final — the platform path is never indexable, so it has no
     * sitemap of its own (a sitemap must list only indexable canonical
     * URLs). With an Active primary domain the real sitemap lives there.
     */
    public function sitemap(Website $website): RedirectResponse
    {
        $this->resolveSnapshotOrAbort($website);

        $domain = $website->activePrimaryDomain();

        abort_unless($domain !== null, 404);

        return redirect()->away('https://'.$domain->domain.'/sitemap.xml', 301);
    }

    /**
     * The one address of a page on the platform path ($slug null = home).
     */
    private function addressOf(Website $website, ?string $slug): string
    {
        return $slug === null
            ? route('public.website.home', $website->public_id)
            : route('public.website.page', [$website->public_id, $slug]);
    }

    private function resolveSnapshotOrAbort(Website $website): array
    {
        abort_unless($this->gate->allows($website), 404);

        $cacheKey = "website_public_{$website->public_id}_v{$website->published_revision_id}";

        $snapshot = Cache::remember($cacheKey, self::SNAPSHOT_CACHE_TTL_SECONDS, function () use ($website) {
            return WebsiteRevision::find($website->published_revision_id)?->snapshot;
        });

        abort_unless($snapshot !== null, 404);

        return $snapshot;
    }

    private function renderPage(Website $website, array $snapshot, array $page): Response|RedirectResponse
    {
        $assetsByUid = collect($snapshot['assets'] ?? [])->keyBy('uid')->all();
        $formsByUid = collect($snapshot['forms'] ?? [])->keyBy('uid')->all();

        // This platform-path response is never indexable (the header
        // below is unconditional), but when the Business also has a
        // live custom domain, this exact content is also served there
        // under a different URL — pointing the canonical tag at that
        // one true address, on both hosts, is what actually prevents
        // the two from ever competing as duplicate content (contract 18
        // §3.2 G-3's gap). With no active domain, there is no better
        // canonical than this URL itself, so the tag is simply omitted.
        $domain = $website->activePrimaryDomain();
        $canonicalUrl = null;

        if ($domain !== null) {
            // The same content is served on the Business's own domain. A
            // noindex here combined with a canonical pointing at another
            // host is a mixed signal crawlers may resolve the wrong way, so
            // the platform path simply moves permanently to the real address.
            return redirect()->away('https://'.$domain->domain.($page['is_home'] ? '/' : '/'.$page['slug']), 301);
        }

        // Contract §7.5 — same live, per-request address-privacy check
        // App\Http\Middleware\ResolveCustomDomainWebsite applies for a
        // custom domain: a revoked address permission must be withheld
        // here too, since this platform-path response renders the exact
        // same `contact_details` component.
        $sections = $this->privacyGate->redactSections($page['sections'], $website->business, $page['slug'] ?? null);

        $response = response()->view('public.website.page', [
            'website' => $website,
            'websiteMeta' => $snapshot['website'],
            'page' => (object) array_merge($page, ['seo' => (object) $page['seo']]),
            'sections' => $sections,
            'assetsByUid' => $assetsByUid,
            'formsByUid' => $formsByUid,
            'isPreview' => false,
            'canonicalUrl' => $canonicalUrl,
            // Public navigation is built only from the immutable revision.
            'navigationPages' => collect($snapshot['pages'])->map(fn ($candidate) => [
                'uid' => $candidate['uid'],
                'title' => $candidate['title'],
                'is_home' => $candidate['is_home'],
                'slug' => $candidate['slug'] ?? null,
                'has_form' => collect($candidate['sections'] ?? [])->contains(fn ($section) => ($section['type'] ?? null) === 'form'),
                'url' => $candidate['is_home']
                    ? route('public.website.home', $website->public_id)
                    : route('public.website.page', [$website->public_id, $candidate['slug']]),
            ])->all(),
        ]);

        return $response->header('X-Robots-Tag', 'noindex, follow');
    }
}

<?php

namespace App\Http\Middleware;

use App\Enums\Website\WebsiteDomainStatus;
use App\Library\Website\Seo\WebsiteAddressPrivacyGate;
use App\Library\Website\Seo\WebsiteBreadcrumbStructuredData;
use App\Library\Website\Seo\WebsiteLocalBusinessStructuredData;
use App\Library\Website\WebsitePublicEntitlementGate;
use App\Models\Website;
use App\Models\WebsiteDomain;
use App\Models\WebsiteRevision;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Website Generation + Hosting Slice B (custom domains, contract §40.1).
 * A GLOBAL middleware (registered in App\Http\Kernel::$middleware, never
 * a route-group middleware) — it has to run BEFORE Laravel's router
 * attempts to match a route at all, because a real custom-domain page
 * slug (e.g. example.com/gallery) matches no registered platform route
 * and would otherwise 404 at the router itself before any route-group
 * middleware ever ran.
 *
 * Deliberately narrow and fail-safe: for every request whose Host is
 * the platform's OWN host, or is not an exact, currently Active
 * WebsiteDomain, this does nothing at all and normal routing proceeds
 * completely unaffected — including every existing customer/admin/API
 * route on the platform's own domain. It never trusts an unrecognized
 * Host for anything; a spoofed or unknown Host simply finds no matching
 * row and falls through.
 *
 * Once a request's Host IS matched to a currently Active domain, this
 * becomes an ALLOWLIST, not a passthrough: only a GET/HEAD page view (or
 * sitemap) and a POST to that Website's own quote-request form are ever
 * served. Laravel's routing is otherwise host-agnostic — without this,
 * `customerdomain.com/login`, an admin route, a webhook, or any other
 * platform POST would be processed exactly as it would be on the
 * platform's own domain, since nothing about route matching itself
 * considers Host. Everything else on a matched custom domain is
 * refused with a 404 rather than ever reaching normal routing.
 */
class ResolveCustomDomainWebsite
{
    private const SNAPSHOT_CACHE_TTL_SECONDS = 300;

    public function __construct(
        private readonly WebsitePublicEntitlementGate $gate,
        private readonly WebsiteLocalBusinessStructuredData $structuredData,
        private readonly WebsiteAddressPrivacyGate $privacyGate,
        private readonly WebsiteBreadcrumbStructuredData $breadcrumbs,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $host = strtolower((string) $request->getHost());
        } catch (SuspiciousOperationException) {
            // TrustHosts (registered ahead of this in Kernel::$middleware)
            // rejects a Host matching neither the platform's own domain
            // nor a currently trusted custom domain — treat exactly like
            // "no match" rather than letting this bubble up raw.
            return $next($request);
        }

        $platformHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        if ($host === '' || $host === $platformHost) {
            return $next($request);
        }

        // Deliberately NOT cached (a removed, reassigned, or newly
        // Active domain must be correct on the very next request) —
        // this lookup only ever runs for a request whose Host isn't the
        // platform's own, a small fraction of total traffic, so a
        // fresh, cheap, indexed query costs nothing meaningful.
        $domain = WebsiteDomain::where('domain', $host)
            ->where('status', WebsiteDomainStatus::Active->value)
            ->first();

        if ($domain === null) {
            return $next($request);
        }

        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            return $this->render($request, $domain);
        }

        if ($request->isMethod('POST') && $this->isWebsiteFormSubmission($request)) {
            return $next($request);
        }

        abort(404);
    }

    /**
     * True only for a request Laravel's OWN public.website.form.submit
     * route (routes/public.php) would itself match — never a hand-rolled
     * regex duplicating that route's shape, so the two can never drift
     * apart. This is the ONE non-GET/HEAD request a matched custom
     * domain is ever allowed to reach normal routing for.
     */
    private function isWebsiteFormSubmission(Request $request): bool
    {
        $route = app('router')->getRoutes()->getByName('public.website.form.submit');

        return $route !== null && $route->matches($request, true);
    }

    private function render(Request $request, WebsiteDomain $domain): Response
    {
        if (! $domain->is_primary) {
            $primary = $domain->website->activePrimaryDomain();

            // No active primary to redirect to (e.g. it just failed
            // renewal) — a stale alias serving nothing is worse than a
            // 404, and the platform-path URL always still works.
            abort_if($primary === null, 404);

            // Always canonicalizes to https, regardless of the scheme
            // this particular request arrived on: the primary domain is
            // Active only with a live certificate, so https is always
            // correct for it.
            return redirect()->away(
                'https://'.$primary->domain.$request->getRequestUri(),
                301,
            );
        }

        $website = $domain->website;

        abort_unless($this->gate->allows($website), 404);

        $cacheKey = "website_public_{$website->public_id}_v{$website->published_revision_id}";
        $snapshot = Cache::remember($cacheKey, self::SNAPSHOT_CACHE_TTL_SECONDS, function () use ($website) {
            return WebsiteRevision::find($website->published_revision_id)?->snapshot;
        });

        abort_unless($snapshot !== null, 404);

        $path = trim((string) $request->path(), '/');

        if ($path === 'sitemap') {
            return $this->renderSitemap($domain, $snapshot);
        }

        $page = $path === ''
            ? collect($snapshot['pages'])->firstWhere('is_home', true)
            : collect($snapshot['pages'])->firstWhere('slug', $path);

        abort_unless($page !== null, 404);

        return $this->renderPage($domain, $website, $snapshot, $page);
    }

    private function renderPage(WebsiteDomain $domain, Website $website, array $snapshot, array $page): Response
    {
        $urlFor = fn (array $candidate) => 'https://'.$domain->domain.($candidate['is_home'] ? '/' : '/'.$candidate['slug']);
        $canonicalUrl = $urlFor($page);

        $assetsByUid = collect($snapshot['assets'] ?? [])->keyBy('uid')->all();
        $formsByUid = collect($snapshot['forms'] ?? [])->keyBy('uid')->all();

        $indexable = ! ($page['seo']['noindex'] ?? false);

        // Contract §7.5 — the address's PRIVACY PERMISSION (never its
        // resolved value, which stays frozen from publish time) is
        // re-checked live, against the Business's CURRENT primary
        // location, on every request: a revoked `public_address` must
        // stop showing the address immediately, on this exact revision
        // (even one reached through rollback), never only starting with
        // the next publish. `$business` is a live read on purpose.
        $business = $website->business;
        $sections = $this->privacyGate->redactSections($page['sections'], $business);
        $localBusiness = $this->privacyGate->redactLocalBusiness($snapshot['website']['localBusiness'] ?? [], $business);

        // LocalBusiness structured data is otherwise built ONLY from the
        // frozen snapshot's own localBusiness facts (WebsiteSnapshotBuilder::
        // localBusinessFacts(), computed once at publish time) — never
        // a live Business/Location read here beyond the address-privacy
        // gate immediately above. A phone change made after publishing,
        // or never confirmed at publish time, never appears until the
        // next publish, exactly like every other published fact on the
        // site. Also mirrors the page's own indexability: never rendered
        // on a page the owner has marked noindex, so Google's
        // structured-data guidance ("reflect visible, intended-for-search
        // content") is never in tension with the robots directive on the
        // same response.
        $localBusinessJsonLd = $indexable
            ? $this->structuredData->build($localBusiness, $canonicalUrl)
            : null;

        // BreadcrumbList: the same real, deterministic page/URL facts
        // every other piece of structured data on this page already
        // uses — never AI, never fabricated — and only on an indexable
        // page for the same reason localBusinessJsonLd is.
        $breadcrumbJsonLd = $indexable
            ? $this->breadcrumbs->build($page, $urlFor(['is_home' => true, 'slug' => null]), $urlFor, $snapshot['pages'])
            : null;

        // The site "actually works" on this domain — active certificate,
        // published, gate passed, this exact page resolved from the
        // live snapshot — so indexing is allowed unless the page opted
        // out itself (page.blade.php makes the final call).
        $response = response()->view('public.website.page', [
            'website' => $website,
            'websiteMeta' => $snapshot['website'],
            'page' => (object) array_merge($page, ['seo' => (object) $page['seo']]),
            'sections' => $sections,
            'assetsByUid' => $assetsByUid,
            'formsByUid' => $formsByUid,
            'isPreview' => false,
            'allowIndexing' => true,
            'canonicalUrl' => $canonicalUrl,
            'localBusinessJsonLd' => $localBusinessJsonLd,
            'breadcrumbJsonLd' => $breadcrumbJsonLd,
            'navigationPages' => collect($snapshot['pages'])->map(fn ($candidate) => [
                'uid' => $candidate['uid'],
                'title' => $candidate['title'],
                'is_home' => $candidate['is_home'],
                'slug' => $candidate['slug'] ?? null,
                'has_form' => collect($candidate['sections'] ?? [])->contains(fn ($section) => ($section['type'] ?? null) === 'form'),
                'url' => $urlFor($candidate),
            ])->all(),
        ]);

        return $response->header('X-Robots-Tag', $indexable ? 'index, follow' : 'noindex, follow');
    }

    /**
     * Only canonical, indexable pages — a noindex page's own URL is
     * never a location Google is asked to discover via the sitemap
     * (Search Central's sitemap guidance: list only the URLs you want
     * to see in search results). An alias domain never reaches this
     * method (it only ever redirects, see render()), so every URL
     * listed here is already this Website's one canonical address.
     */
    private function renderSitemap(WebsiteDomain $domain, array $snapshot): Response
    {
        $urls = collect($snapshot['pages'])
            ->reject(fn ($page) => $page['seo']['noindex'] ?? false)
            ->map(function ($page) use ($domain) {
                $loc = 'https://'.$domain->domain.($page['is_home'] ? '/' : '/'.$page['slug']);

                return '<url><loc>'.e($loc).'</loc></url>';
            })->implode('');

        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$urls.'</urlset>';

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }
}

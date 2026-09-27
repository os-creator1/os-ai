<?php

namespace App\Http\Middleware;

use App\Enums\Website\WebsiteDomainStatus;
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
 * Only GET/HEAD are handled here — a POST (e.g. the quote form, or a
 * webhook) always falls through to normal path-based routing, which
 * already works regardless of Host (Laravel's router is host-agnostic
 * unless a route explicitly declares Route::domain()).
 */
class ResolveCustomDomainWebsite
{
    private const SNAPSHOT_CACHE_TTL_SECONDS = 300;

    public function __construct(
        private readonly WebsitePublicEntitlementGate $gate,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return $next($request);
        }

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

        $domain = Cache::remember(
            "website_custom_domain_lookup_{$host}",
            self::SNAPSHOT_CACHE_TTL_SECONDS,
            fn () => WebsiteDomain::where('domain', $host)
                ->where('status', WebsiteDomainStatus::Active->value)
                ->first(),
        );

        if ($domain === null) {
            return $next($request);
        }

        return $this->render($request, $domain);
    }

    private function render(Request $request, WebsiteDomain $domain): Response
    {
        if (! $domain->is_primary) {
            $primary = $domain->website->domains()
                ->where('is_primary', true)
                ->where('status', WebsiteDomainStatus::Active->value)
                ->first();

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

        $assetsByUid = collect($snapshot['assets'] ?? [])->keyBy('uid')->all();
        $formsByUid = collect($snapshot['forms'] ?? [])->keyBy('uid')->all();

        // The site "actually works" on this domain — active certificate,
        // published, gate passed, this exact page resolved from the
        // live snapshot — so indexing is allowed unless the page opted
        // out itself (page.blade.php makes the final call).
        $response = response()->view('public.website.page', [
            'website' => $website,
            'websiteMeta' => $snapshot['website'],
            'page' => (object) array_merge($page, ['seo' => (object) $page['seo']]),
            'sections' => $page['sections'],
            'assetsByUid' => $assetsByUid,
            'formsByUid' => $formsByUid,
            'isPreview' => false,
            'allowIndexing' => true,
            'navigationPages' => collect($snapshot['pages'])->map(fn ($candidate) => [
                'uid' => $candidate['uid'],
                'title' => $candidate['title'],
                'is_home' => $candidate['is_home'],
                'url' => $urlFor($candidate),
            ])->all(),
        ]);

        $indexable = ! ($page['seo']['noindex'] ?? false);

        return $response->header('X-Robots-Tag', $indexable ? 'index, follow' : 'noindex, follow');
    }

    private function renderSitemap(WebsiteDomain $domain, array $snapshot): Response
    {
        $urls = collect($snapshot['pages'])->map(function ($page) use ($domain) {
            $loc = 'https://'.$domain->domain.($page['is_home'] ? '/' : '/'.$page['slug']);

            return '<url><loc>'.e($loc).'</loc></url>';
        })->implode('');

        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$urls.'</urlset>';

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }
}

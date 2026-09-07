<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Library\Website\WebsitePublicEntitlementGate;
use App\Models\Website;
use App\Models\WebsiteRevision;
use Illuminate\Contracts\View\View;
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
    ) {
    }

    public function home(Website $website): View|Response
    {
        $snapshot = $this->resolveSnapshotOrAbort($website);

        $page = collect($snapshot['pages'])->firstWhere('is_home', true);

        abort_unless($page !== null, 404);

        return $this->renderPage($website, $snapshot, $page);
    }

    public function page(Website $website, string $slug): View|Response
    {
        $snapshot = $this->resolveSnapshotOrAbort($website);

        $page = collect($snapshot['pages'])->firstWhere('slug', $slug);

        abort_unless($page !== null, 404);

        return $this->renderPage($website, $snapshot, $page);
    }

    public function sitemap(Website $website): Response
    {
        $snapshot = $this->resolveSnapshotOrAbort($website);

        $urls = collect($snapshot['pages'])->map(function ($page) use ($website) {
            $loc = $page['is_home']
                ? route('public.website.home', $website->public_id)
                : route('public.website.page', [$website->public_id, $page['slug']]);

            return '<url><loc>' . e($loc) . '</loc></url>';
        })->implode('');

        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . $urls . '</urlset>';

        // Contract §21 — extensionless by design: the root .htaccess
        // rewrites any *.xml request straight to a public/ static-file
        // lookup before Laravel's router ever runs. The Content-Type
        // header is what tells a crawler this is XML, not the URL path.
        return response($xml, 200, ['Content-Type' => 'application/xml']);
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

    private function renderPage(Website $website, array $snapshot, array $page): Response
    {
        $assetsByUid = collect($snapshot['assets'] ?? [])->keyBy('uid')->all();

        $response = response()->view('public.website.page', [
            'website' => $website,
            'websiteMeta' => $snapshot['website'],
            'page' => (object) array_merge($page, ['seo' => (object) $page['seo']]),
            'sections' => $page['sections'],
            'assetsByUid' => $assetsByUid,
            'isPreview' => false,
        ]);

        return $response->header('X-Robots-Tag', 'noindex, follow');
    }
}

<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Library\Website\Blog\WebsiteBlogRenderer;
use App\Library\Website\Blog\WebsiteBlogSurface;
use App\Models\Website;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * SEO Content Engine V1 — the platform-path blog (/sites/{public_id}/blog and /blog/{slug}). Thin: all
 * rendering is WebsiteBlogRenderer, the same renderer the custom domain uses. This path is never
 * indexable (noindex header); its canonical points at the custom domain when the Website has one.
 */
class WebsiteBlogController extends Controller
{
    public function __construct(private readonly WebsiteBlogRenderer $blog)
    {
    }

    public function index(Request $request, Website $website): Response
    {
        $page = filter_var($request->query('page', 1), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $this->blog->index($website, WebsiteBlogSurface::platform($website), $page === false ? 1 : $page);
    }

    public function show(Website $website, string $slug): Response|RedirectResponse
    {
        return $this->blog->article($website, WebsiteBlogSurface::platform($website), $slug);
    }
}

<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Library\Legal\LegalRegistry;
use App\Library\Marketing\MarketingSite;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The MotionGrove Legal Center: read-only legal documents rendered from the versioned sources in resources/legal.
 *
 * A document answers only while it is published (approved and sealed) — or, on a staging host with
 * LEGAL_PREVIEW_DRAFTS on, as a clearly bannered, noindex draft. Anything else is a plain 404, so the existing
 * operative pages are never displaced by unapproved text. Platform host only: custom (Agency) domains are not served.
 */
class LegalController extends Controller
{
    public function __construct(
        private readonly MarketingSite $site,
        private readonly LegalRegistry $registry,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($this->site->servesRequest($request), 404);

        $docs = $this->registry->visibleDocuments();
        abort_if($docs === [], 404);

        $drafts = collect($docs)->contains(fn ($d) => ! $d->isPublished());

        return $this->respond($request, 'marketing.legal.index', [
            'documents' => $docs,
            'drafts' => $drafts,
        ], 'Legal Center — MotionGrove', 'The agreements and policies that govern MotionGrove, operated by Jazmin Media MB.', $this->registry->indexUrl(), $drafts);
    }

    public function show(Request $request, ?string $slug = null): Response
    {
        abort_unless($this->site->servesRequest($request), 404);

        $doc = $this->registry->visible((string) $slug) ?? abort(404);
        $rendered = $this->registry->render($doc);

        return $this->respond($request, 'marketing.legal.show', [
            'doc' => $doc,
            'html' => $rendered['html'],
            'sections' => $rendered['sections'],
            'draft' => ! $doc->isPublished(),
            'documents' => $this->registry->visibleDocuments(),
            'registry' => $this->registry,
        ], $doc->title.' — MotionGrove', $doc->description, $this->registry->url($doc->slug), ! $doc->isPublished());
    }

    private function respond(Request $request, string $view, array $data, string $title, string $description, string $canonical, bool $noindex): Response
    {
        $response = response()->view($view, $data + [
            'mg' => $this->site,
            'page' => 'legal',
            'registry' => $this->registry,
            'meta' => [
                'title' => $title,
                'description' => $description,
                'canonical' => $canonical,
                'image' => $this->site->asset((string) config('marketing.site.og_image')),
            ],
        ]);

        return $noindex ? $response->header('X-Robots-Tag', 'noindex, nofollow') : $response;
    }
}

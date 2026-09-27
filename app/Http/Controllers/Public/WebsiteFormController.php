<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Library\Website\WebsiteFormSubmissionService;
use App\Library\Website\WebsitePublicEntitlementGate;
use App\Models\Website;
use App\Models\WebsiteForm;
use App\Models\WebsiteRevision;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The one public mutation the Website feature accepts: a form
 * submission. Gated by the same WebsitePublicEntitlementGate the
 * read-only renderer uses (contract §26.4) — a disabled, suspended or
 * unpublished Business's form 404s exactly like its pages already do.
 *
 * A submission is accepted only for a form the CURRENT PUBLISHED
 * REVISION actually renders on one of its pages — never merely because a
 * WebsiteForm row with that uid exists. This closes two things at once:
 * a form removed from every page (or never published at all) can no
 * longer be posted to, and the "source page" recorded on the submission
 * is read from that same snapshot, never from anything the visitor
 * posted — a page_slug field would be spoofable and is not trusted here.
 */
class WebsiteFormController extends Controller
{
    private const SNAPSHOT_CACHE_TTL_SECONDS = 300;

    public function __construct(
        private readonly WebsitePublicEntitlementGate $gate,
        private readonly WebsiteFormSubmissionService $submissions,
    ) {
    }

    public function submit(Request $request, Website $website, string $formUid): RedirectResponse
    {
        abort_unless($this->gate->allows($website), 404);

        $snapshot = $this->resolvePublishedSnapshot($website);
        $formSnapshot = collect($snapshot['forms'] ?? [])->firstWhere('uid', $formUid);
        abort_unless($formSnapshot !== null, 404);

        $page = collect($snapshot['pages'] ?? [])->first(
            fn ($candidate) => collect($candidate['sections'] ?? [])->contains(
                fn ($section) => ($section['type'] ?? null) === 'form' && ($section['data']['form_uid'] ?? null) === $formUid
            )
        );
        abort_unless($page !== null, 404);

        // The snapshot proves the form is genuinely live; the row itself
        // (for its integer id — the only thing the FK needs) must still
        // exist too. It always will in Slice A, since nothing deletes a
        // WebsiteForm — this is defense in depth, not an expected path.
        $form = WebsiteForm::where('website_id', $website->id)->where('uid', $formUid)->first();
        abort_unless($form !== null, 404);

        $this->submissions->submit(
            $form,
            $formSnapshot['fields'],
            $formSnapshot['name'],
            $request->except(['_token']),
            $page['is_home'] ? null : $page['slug'],
            $request->ip(),
        );

        return redirect()->back()->with([
            'status' => 'success',
            'message' => 'Thanks — we received your request and will be in touch soon.',
        ]);
    }

    /**
     * Mirrors Public\WebsiteController::resolveSnapshotOrAbort() exactly
     * — same cache key shape, so a recent page render and a submission
     * for the same published revision share the one cached snapshot.
     */
    private function resolvePublishedSnapshot(Website $website): array
    {
        $cacheKey = "website_public_{$website->public_id}_v{$website->published_revision_id}";

        $snapshot = Cache::remember($cacheKey, self::SNAPSHOT_CACHE_TTL_SECONDS, function () use ($website) {
            return WebsiteRevision::find($website->published_revision_id)?->snapshot;
        });

        abort_unless($snapshot !== null, 404);

        return $snapshot;
    }
}

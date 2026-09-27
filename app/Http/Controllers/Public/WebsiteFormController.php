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
 * The route names the exact page the visitor was on, not just the form:
 * the same form can be placed on more than one published page (a "Get a
 * quote" section on the homepage AND on a dedicated quote page), so
 * form_uid alone cannot say which one a given submission came from. The
 * recorded source page is the one the CURRENT PUBLISHED REVISION's
 * snapshot proves both exists and actually carries a `form` section for
 * this exact form_uid — never merely because a WebsiteForm row exists,
 * and never from a posted page_slug, which would be spoofable and is not
 * read at all. A page or form that has since been removed, or a page/form
 * pairing that never matched, 404s as stale rather than guessing.
 */
class WebsiteFormController extends Controller
{
    private const SNAPSHOT_CACHE_TTL_SECONDS = 300;

    public function __construct(
        private readonly WebsitePublicEntitlementGate $gate,
        private readonly WebsiteFormSubmissionService $submissions,
    ) {
    }

    public function submit(Request $request, Website $website, string $formUid, string $pageUid): RedirectResponse
    {
        abort_unless($this->gate->allows($website), 404);

        $snapshot = $this->resolvePublishedSnapshot($website);
        $formSnapshot = collect($snapshot['forms'] ?? [])->firstWhere('uid', $formUid);
        abort_unless($formSnapshot !== null, 404);

        $page = collect($snapshot['pages'] ?? [])->firstWhere('uid', $pageUid);
        abort_unless($page !== null, 404);

        $pageActuallyHasThisForm = collect($page['sections'] ?? [])->contains(
            fn ($section) => ($section['type'] ?? null) === 'form' && ($section['data']['form_uid'] ?? null) === $formUid
        );
        abort_unless($pageActuallyHasThisForm, 404);

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

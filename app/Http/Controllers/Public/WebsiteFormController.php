<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Library\Website\WebsiteFormSubmissionService;
use App\Library\Website\WebsitePublicEntitlementGate;
use App\Models\Website;
use App\Models\WebsiteForm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The one public mutation the Website feature accepts: a form
 * submission. Gated by the same WebsitePublicEntitlementGate the
 * read-only renderer uses (contract §26.4) — a disabled, suspended or
 * unpublished Business's form 404s exactly like its pages already do.
 * The form must belong to THIS website; a foreign or unknown uid 404s,
 * never leaking whether some other website's form exists.
 */
class WebsiteFormController extends Controller
{
    public function __construct(
        private readonly WebsitePublicEntitlementGate $gate,
        private readonly WebsiteFormSubmissionService $submissions,
    ) {}

    public function submit(Request $request, Website $website, string $formUid): RedirectResponse
    {
        abort_unless($this->gate->allows($website), 404);

        $form = WebsiteForm::where('website_id', $website->id)->where('uid', $formUid)->first();
        abort_unless($form !== null, 404);

        $this->submissions->submit(
            $form,
            $request->except(['_token', 'page_slug']),
            $request->input('page_slug') ?: null,
            $request->ip(),
        );

        return redirect()->back()->with([
            'status' => 'success',
            'message' => 'Thanks — we received your request and will be in touch soon.',
        ]);
    }
}

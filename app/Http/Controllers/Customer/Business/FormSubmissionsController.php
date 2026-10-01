<?php

namespace App\Http\Controllers\Customer\Business;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Customer\Business\Concerns\AuthorizesFormsRequests;
use App\Library\Forms\FormSubmissionReader;
use App\Models\Form;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Forms V1 — viewing submissions, filtered by Location.
 *
 * Gates 1-3 of the Forms chain run first (tenancy, `forms` capability, `forms`
 * entitlement); VISIBILITY then follows the operational Location, through
 * FormSubmissionReader and LocationAccessGuard — the one ACL algorithm. The
 * list is bounded to the Locations the actor may reach, a `location` filter that
 * names one they cannot is a 404 (never silently ignored), and a single
 * submission of an unreachable Location is a 404 exactly like an unknown uid.
 * Read-only: nothing on this controller writes.
 */
class FormSubmissionsController extends Controller
{
    use AuthorizesFormsRequests;

    public function __construct(private readonly FormSubmissionReader $reader)
    {
    }

    public function index(Request $request, string $workspaceUid, string $businessUid): View
    {
        [$workspace, $business] = $this->formsScope($workspaceUid, $businessUid);

        $visible = $this->reader->visibleLocations($business, (int) Auth::id());

        $formUid = $request->query('form');
        $form = is_string($formUid) && $formUid !== ''
            ? Form::query()->where('business_id', $business->id)->where('uid', $formUid)->first() ?? abort(404)
            : null;

        $locationUid = $request->query('location');

        $submissions = $this->reader->page(
            $business,
            $visible,
            is_string($locationUid) ? $locationUid : null,
            $form,
            (int) $request->query('page', 1),
        ) ?? abort(404);

        return view('customer.business.forms.submissions', [
            'workspace' => $workspace,
            'business' => $business,
            'submissions' => $submissions,
            'locations' => $visible,
            'selectedLocationUid' => is_string($locationUid) ? $locationUid : null,
            'form' => $form,
        ]);
    }

    public function show(string $workspaceUid, string $businessUid, string $submissionUid): View
    {
        [$workspace, $business] = $this->formsScope($workspaceUid, $businessUid);

        $submission = $this->visibleSubmissionOrAbort($business, $submissionUid);
        $submission->load(['form:id,uid,name', 'version', 'location:id,uid,name', 'contact:id,uid,phone', 'opportunity:id,uid,title']);

        return view('customer.business.forms.submission', [
            'workspace' => $workspace,
            'business' => $business,
            'submission' => $submission,
        ]);
    }
}

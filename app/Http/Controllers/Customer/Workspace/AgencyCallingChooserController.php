<?php

namespace App\Http\Controllers\Customer\Workspace;

use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Navigation\CustomerShellComposer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Contracts\View\View;

/**
 * Agency Settings -> Calling entry point when the Agency Workspace has more than one Business: choose which Business's
 * Calling settings to open. It only lists Businesses the signed-in actor may already enter (the same context list the
 * switcher uses) and links to the existing business-scoped Calling page, which re-runs tenancy, entitlement and permission.
 * A single Business goes straight there. Nothing about calling is configured or stored here.
 */
class AgencyCallingChooserController extends CustomerBaseController
{
    public function choose(string $workspaceUid, CustomerShellComposer $shell): View|RedirectResponse
    {
        $context = $shell->currentContext(Auth::user());

        $workspace = collect($context->workspaces)->first(fn ($w) => (string) $w->uid === $workspaceUid && $w->isAgency() && $w->isActive);
        if ($workspace === null) {
            abort(404);
        }

        $businesses = array_values($workspace->selectableBusinesses());
        if ($businesses === []) {
            abort(404);
        }
        if (count($businesses) === 1) {
            return redirect()->route('customer.workspaces.businesses.calling.show', [$workspaceUid, $businesses[0]->uid]);
        }

        return view('customer.workspaces.calling-chooser', ['workspaceUid' => $workspaceUid, 'businesses' => $businesses]);
    }
}

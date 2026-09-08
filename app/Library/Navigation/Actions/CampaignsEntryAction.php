<?php

namespace App\Library\Navigation\Actions;

use App\Library\Navigation\CustomerShellComposer;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * GET /outreach/campaigns — the E-11 correction (contract §3 row E-11,
 * Appendix A). The legacy sidebar link pointed at a URL no route served.
 * This bare entry resolves it through the canonical context: with a
 * selected Business it redirects to the ONE campaign list that exists,
 * customer.workspaces.businesses.outreach.campaigns; otherwise it hands
 * over to the existing Outreach chooser, which asks for an explicit
 * Business selection or explains that none is available. It never guesses
 * a Business and it creates no second campaign surface.
 */
final class CampaignsEntryAction
{
    public function __construct(private readonly CustomerShellComposer $shell)
    {
    }

    public function __invoke(Request $request): RedirectResponse
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(404);
        }

        $context = $this->shell->currentContext($user);

        if ($context->isBusinessFrame() && $context->selectedWorkspace !== null && $context->selectedBusiness !== null) {
            return redirect()->route('customer.workspaces.businesses.outreach.campaigns', [
                $context->selectedWorkspace->uid,
                $context->selectedBusiness->uid,
            ]);
        }

        return redirect()->route('customer.outreach.index');
    }
}

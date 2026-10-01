<?php

namespace App\Http\Controllers\Customer\Agency;

use App\Exceptions\Branding\AgencyWhiteLabelException;
use App\Exceptions\Workspace\AgencyWorkspaceNotEligibleException;
use App\Http\Controllers\Controller;
use App\Library\Branding\AgencyWhiteLabelManager;
use App\Library\Workspace\AgencyClientRelationshipManager;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceRepository;
use App\Rules\ValidBrandingImageRule;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Agency V1 completion — the Agency's own White Label surface (Blueprint §28).
 *
 * A thin layer: the controller resolves the Agency Workspace from the route
 * and asks the SAME authority every other Agency surface asks (membership
 * authority + live management eligibility, answered 404 so existence is never
 * disclosed). READING is Agency-team work; WRITING is the Workspace owner's
 * alone and is asserted inside AgencyWhiteLabelManager from the locked
 * Workspace row, never here — a request that reaches update() as a non-owner
 * is refused by the manager, whatever this controller did or did not check.
 *
 * Nothing on this surface is reachable through View As: the whole
 * `customer.workspaces.agency.white-label.` family is a ViewAsProhibitedActions
 * prefix.
 */
class AgencyWhiteLabelController extends Controller
{
    public function __construct(
        private readonly WorkspaceRepository $workspaces,
        private readonly AgencyClientRelationshipManager $relationships,
        private readonly AgencyWhiteLabelManager $whiteLabel,
    ) {
    }

    public function show(string $workspaceUid): View
    {
        $agency = $this->authorizedAgency($workspaceUid);

        return view('customer.agency.white-label.show', [
            'agencyWorkspace' => $agency,
            'setting' => $this->whiteLabel->find($agency),
            'history' => $this->whiteLabel->history($agency),
            'isOwner' => $this->isOwner($agency),
            'entitled' => $this->whiteLabel->isEntitled($agency),
        ]);
    }

    public function update(Request $request, string $workspaceUid): RedirectResponse
    {
        $agency = $this->authorizedAgency($workspaceUid);

        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:' . AgencyWhiteLabelManager::NAME_MAX],
            'tagline' => ['nullable', 'string', 'max:' . AgencyWhiteLabelManager::TAGLINE_MAX],
            'accent_color' => ['nullable', 'string', 'regex:/\A#[0-9a-fA-F]{6}\z/'],
            'support_email' => ['nullable', 'email', 'max:' . AgencyWhiteLabelManager::EMAIL_MAX],
            'is_enabled' => ['nullable', 'boolean'],
            'remove_logo' => ['nullable', 'boolean'],
            'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp,gif', 'max:2048', new ValidBrandingImageRule('logo')],
        ]);

        try {
            $this->whiteLabel->save(
                (int) Auth::id(),
                $agency,
                [
                    'display_name' => $data['display_name'],
                    'tagline' => $data['tagline'] ?? null,
                    'accent_color' => $data['accent_color'] ?? null,
                    'support_email' => $data['support_email'] ?? null,
                    'is_enabled' => $request->boolean('is_enabled'),
                ],
                $request->file('logo'),
                $request->boolean('remove_logo'),
            );
        } catch (AgencyWhiteLabelException $e) {
            return back()->withInput()->with(['status' => 'error', 'message' => $e->getMessage()]);
        }

        return redirect()
            ->route('customer.workspaces.agency.white-label.show', [$agency->uid])
            ->with(['status' => 'success', 'message' => 'White label settings saved.']);
    }

    /**
     * A real Agency Workspace the actor holds Agency authority over, that is
     * currently an eligible Agency. 404 for every failure, the same
     * non-disclosure the Clients and SaaS surfaces use.
     */
    private function authorizedAgency(string $workspaceUid): Workspace
    {
        $agency = $this->workspaces->findByUid($workspaceUid) ?? abort(404);

        if (! $this->relationships->actorHasAgencyAuthority((int) Auth::id(), $agency)) {
            abort(404);
        }

        try {
            $this->relationships->assertAgencyWorkspaceHasManagementEligibility($agency);
        } catch (AgencyWorkspaceNotEligibleException) {
            abort(404);
        }

        return $agency;
    }

    private function isOwner(Workspace $agency): bool
    {
        return (int) $agency->owner_user_id === (int) Auth::id();
    }
}

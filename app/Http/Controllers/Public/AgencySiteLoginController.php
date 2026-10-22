<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Controller;
use App\Library\Branding\AgencyBrandResolver;
use App\Library\Branding\DatabaseAgencyBrandSource;
use App\Library\Branding\AgencyWhiteLabelManager;
use App\Library\Workspace\AgencyClientRelationshipManager;
use App\Models\AgencySite;
use App\Models\AgencyWhiteLabelSetting;
use App\Models\Workspace;
use Illuminate\Http\Request;

/**
 * White Label — the sign-in screen reached from an Agency's published site ("Log in").
 *
 * It is the platform's ordinary login page (same form, same POST target, same security) shown in the Agency's brand.
 * The Agency comes from the PUBLISHED site row addressed by the URL — the same server-side mapping the site itself
 * uses — and is then authorised like any host-resolved brand: active Agency, entitled, brand switched on. Anything
 * else is the unbranded platform login, never an error and never another Agency's brand.
 */
class AgencySiteLoginController extends Controller
{
    public function __construct(
        private readonly AgencyClientRelationshipManager $relationships,
        private readonly AgencyWhiteLabelManager $whiteLabel,
    ) {
    }

    public function show(Request $request, string $publicId)
    {
        $site = AgencySite::query()->where('public_id', $publicId)->first();
        abort_if($site === null || ! $site->isLive(), 404);

        $agency = Workspace::query()->find($site->agency_workspace_id);
        abort_if($agency === null || ! $agency->is_active
            || ! $this->relationships->agencyWorkspaceHasManagementEligibility($agency)
            || ! $this->whiteLabel->isEntitled($agency), 404);

        $setting = AgencyWhiteLabelSetting::query()->where('agency_workspace_id', $agency->id)->where('is_enabled', true)->with('agencyWorkspace')->first();

        if ($setting !== null) {
            $request->attributes->set(AgencyBrandResolver::HOSTED_SITE_ATTRIBUTE, DatabaseAgencyBrandSource::brandFor($setting));
        }

        return app(LoginController::class)->showLoginForm();
    }
}

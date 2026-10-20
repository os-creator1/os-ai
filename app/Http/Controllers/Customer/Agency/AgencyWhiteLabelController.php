<?php

namespace App\Http\Controllers\Customer\Agency;

use App\Exceptions\Branding\AgencyWhiteLabelException;
use App\Exceptions\Workspace\AgencyWorkspaceNotEligibleException;
use App\Http\Controllers\Controller;
use App\Library\Branding\AgencyAppDomainService;
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
        private readonly AgencyAppDomainService $appDomains,
    ) {
    }

    public function show(Request $request, string $workspaceUid): View
    {
        $agency = $this->authorizedAgency($workspaceUid);
        $setting = $this->whiteLabel->find($agency);

        return view('customer.agency.white-label.show', [
            'tab' => $request->query('tab') === 'landing' ? 'landing' : 'branding',
            'domainInstructions' => $setting?->app_domain !== null ? $this->appDomains->dnsInstructions($setting) : null,
            'landing' => $this->landingStatus($agency, $setting),
            'agencyWorkspace' => $agency,
            'setting' => $setting,
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
            'secondary_color' => ['nullable', 'string', 'regex:/\A#[0-9a-fA-F]{6}\z/'],
            'support_url' => ['nullable', 'url:https', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:120'],
            'social' => ['nullable', 'array'],
            'social.*' => ['nullable', 'string', 'max:255'],
            'is_enabled' => ['nullable', 'boolean'],
            'remove_logo' => ['nullable', 'boolean'],
            'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp,gif', 'max:2048', new ValidBrandingImageRule('logo')],
            'logo_dark' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp,gif', 'max:2048', new ValidBrandingImageRule('logo_dark')],
            'logo_compact' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp,gif', 'max:2048', new ValidBrandingImageRule('logo_compact')],
            'favicon' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp,gif', 'max:2048', new ValidBrandingImageRule('favicon')],
            'remove_logo_dark' => ['nullable', 'boolean'],
            'remove_logo_compact' => ['nullable', 'boolean'],
            'remove_favicon' => ['nullable', 'boolean'],
        ]);

        // Extra fields are forwarded only when the form actually sent them, so a client that predates
        // the Branding tab never has these cleared by omission.
        $extra = [];

        foreach (['secondary_color', 'support_url', 'legal_name'] as $field) {
            if ($request->exists($field)) {
                $extra[$field] = $data[$field] ?? null;
            }
        }

        if ($request->exists('social')) {
            $extra['social_links'] = $data['social'] ?? [];
        }

        $assets = array_filter([
            'logo_dark' => $request->file('logo_dark'),
            'logo_compact' => $request->file('logo_compact'),
            'favicon' => $request->file('favicon'),
        ]);
        $removeAssets = array_values(array_filter(['logo_dark', 'logo_compact', 'favicon'], fn ($k) => $request->boolean('remove_' . $k)));

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
                ] + $extra,
                $request->file('logo'),
                $request->boolean('remove_logo'),
                $assets,
                $removeAssets,
            );
        } catch (AgencyWhiteLabelException $e) {
            return back()->withInput()->with(['status' => 'error', 'message' => $e->getMessage()]);
        }

        return redirect()
            ->route('customer.workspaces.agency.white-label.show', [$agency->uid])
            ->with(['status' => 'success', 'message' => 'White label settings saved.']);
    }

    public function attachDomain(Request $request, string $workspaceUid): RedirectResponse
    {
        $agency = $this->authorizedAgency($workspaceUid);
        $data = $request->validate(['app_domain' => ['required', 'string', 'max:191']]);

        return $this->domainAction(
            $agency,
            fn () => $this->appDomains->attach((int) Auth::id(), $agency, $data['app_domain']),
            'App domain saved. Add the DNS record below, then check it.',
        );
    }

    public function checkDomain(string $workspaceUid): RedirectResponse
    {
        $agency = $this->authorizedAgency($workspaceUid);

        return $this->domainAction($agency, function () use ($agency) {
            $setting = $this->appDomains->check((int) Auth::id(), $agency);

            if ($setting->app_domain_status !== AgencyAppDomainService::STATUS_VERIFIED) {
                throw AgencyWhiteLabelException::because('dns_not_found', "We couldn't find that TXT record yet. DNS changes can take a few minutes to take effect — check again shortly.");
            }
        }, 'Domain ownership verified.');
    }

    public function removeDomain(string $workspaceUid): RedirectResponse
    {
        $agency = $this->authorizedAgency($workspaceUid);

        return $this->domainAction($agency, fn () => $this->appDomains->remove((int) Auth::id(), $agency), 'App domain removed.');
    }

    private function domainAction(Workspace $agency, callable $action, string $success): RedirectResponse
    {
        $back = redirect()->route('customer.workspaces.agency.white-label.show', [$agency->uid]);

        try {
            $action();
        } catch (AgencyWhiteLabelException $e) {
            return $back->withInput()->with(['status' => 'error', 'message' => $e->getMessage()]);
        }

        return $back->with(['status' => 'success', 'message' => $success]);
    }

    /**
     * Honest readiness of the pieces the Landing Page needs, read from the existing Stripe-connection,
     * plan and booking models. Presentation only: nothing here grants or activates anything.
     *
     * @return array{stripe: bool, plans: int, brandSaved: bool, domainVerified: bool}
     */
    private function landingStatus(Workspace $agency, ?\App\Models\AgencyWhiteLabelSetting $setting): array
    {
        $connection = \App\Models\AgencyStripeConnection::query()->where('agency_workspace_id', $agency->id)->first();

        return [
            'stripe' => $connection !== null && $connection->charges_enabled && $connection->disconnected_at === null,
            'plans' => \App\Models\AgencySaasPlan::query()->where('agency_workspace_id', $agency->id)->count(),
            'brandSaved' => $setting !== null,
            'domainVerified' => $setting?->app_domain_status === AgencyAppDomainService::STATUS_VERIFIED,
        ];
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

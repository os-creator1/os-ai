<?php

namespace App\Library\Branding;

use App\Enums\Entitlement\PlatformFeature;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Navigation\CustomerContext;
use App\Library\Support\RequestScopedCache;
use App\Library\Workspace\AgencyClientRelationshipManager;
use App\Models\AgencyWhiteLabelSetting;
use App\Models\Workspace;
use App\Repositories\Contracts\AgencyClientWorkspaceRelationshipRepository;
use Illuminate\Http\Request;
use Throwable;

/**
 * Agency V1 completion — which Agency brand, if any, a signed-in CLIENT
 * Workspace's chrome renders.
 *
 * THE AUTHORITY IS THE PERSISTED MANAGEMENT RELATIONSHIP, re-derived on every
 * request, in this order — and any failure yields null, which means the
 * platform/default branding the shell already renders:
 *
 *  1. the request's framed Workspace (the Slice 1B context the server built
 *     from the authenticated user — never a query parameter, a submitted uid
 *     or a session value);
 *  2. an ACTIVE agency_client_workspace_relationships row for exactly that
 *     Workspace. Not a creator id, not an invited email, not a tier. A
 *     terminated relationship therefore returns the client to default
 *     branding at once, with nothing to clean up (the client never owns a
 *     copy of the brand);
 *  3. THAT Agency's own white-label row, which must be enabled — another
 *     Agency's row is unreachable because the lookup is keyed by the
 *     relationship's agency_workspace_id;
 *  4. the Agency Workspace is active, currently holds Agency management
 *     eligibility (Agency tier, usable account) and is still entitled to the
 *     `white_label` feature through the existing entitlement architecture.
 *     A downgraded, locked, suspended or inactive Agency stops branding its
 *     clients without anyone editing a row.
 *
 * Cheap for the common case: a Workspace with no managing Agency costs one
 * indexed statement, and an Agency that has not enabled branding costs two;
 * the entitlement checks only run for a client that would actually be
 * branded. The answer is memoized on the Request object, never in a
 * container singleton or a cache, so it cannot outlive the request or be
 * shared between Workspaces.
 *
 * Returns the RAW brand; AuthBrandPresenter::forAgencyBrand() normalizes
 * every field with the login screen's own rules before anything is rendered.
 */
class ClientWorkspaceBrandResolver
{
    private const MEMO = 'agencyClientChromeBrand';

    public function __construct(
        private readonly AgencyClientWorkspaceRelationshipRepository $relationships,
        private readonly AgencyClientRelationshipManager $relationshipManager,
        private readonly EntitlementManager $entitlements,
        private readonly RequestScopedCache $requestCache,
    ) {
    }

    public function forRequest(Request $request): ?AgencyBrand
    {
        $context = $request->attributes->get('customerContext');

        if (! $context instanceof CustomerContext) {
            return null;
        }

        $frame = $context->frameWorkspace();

        if ($frame === null) {
            return null;
        }

        $memo = $request->attributes->get(self::MEMO);

        if (is_array($memo) && ($memo['workspaceId'] ?? null) === $frame->id) {
            return $memo['brand'];
        }

        $brand = $this->forClientWorkspaceId($frame->id);

        $request->attributes->set(self::MEMO, ['workspaceId' => $frame->id, 'brand' => $brand]);

        return $brand;
    }

    public function forClientWorkspaceId(int $clientWorkspaceId): ?AgencyBrand
    {
        try {
            // Shared with the customer menu's own check (one statement per request).
            $relationship = $this->requestCache->remember(
                RequestScopedCache::ACTIVE_AGENCY_RELATIONSHIP_PREFIX . $clientWorkspaceId,
                fn () => $this->relationships->findActiveForClientWorkspace($clientWorkspaceId),
            );

            if ($relationship === null) {
                return null;
            }

            $setting = AgencyWhiteLabelSetting::query()
                ->where('agency_workspace_id', $relationship->agency_workspace_id)
                ->where('is_enabled', true)
                ->first();

            if ($setting === null || trim((string) $setting->display_name) === '') {
                return null;
            }

            $agency = Workspace::query()->find($relationship->agency_workspace_id);

            if ($agency === null || ! $agency->is_active) {
                return null;
            }

            if (! $this->relationshipManager->agencyWorkspaceHasManagementEligibility($agency)) {
                return null;
            }

            if (! $this->entitlements->decideForWorkspace($agency, PlatformFeature::WhiteLabel->value)->allowed) {
                return null;
            }

            return new AgencyBrand(
                workspaceUid: (string) $agency->uid,
                displayName: (string) $setting->display_name,
                logoPath: $setting->logo_path,
                tagline: $setting->tagline,
                accentColor: $setting->accent_color,
                supportEmail: $setting->support_email,
            );
        } catch (Throwable) {
            // Branding is cosmetic: a failure here must never take the page
            // down, and must never fall back to "some" Agency's brand.
            return null;
        }
    }
}

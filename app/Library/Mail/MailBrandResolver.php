<?php

namespace App\Library\Mail;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Website\WebsiteAssetPurpose;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Workspace\AgencyClientRelationshipManager;
use App\Models\AgencyWhiteLabelSetting;
use App\Models\Business;
use App\Models\Website;
use App\Models\WebsiteAsset;
use App\Models\Workspace;
use Throwable;

/**
 * Decides which identity a transactional email renders. Branding is cosmetic, so
 * every lookup is keyed ONLY by the notification's own subject and any failure
 * degrades to the platform brand — never to another tenant's, and never to
 * whatever account is signed in.
 */
class MailBrandResolver
{
    public function __construct(
        private readonly EntitlementManager $entitlements,
        private readonly AgencyClientRelationshipManager $relationships,
    ) {
    }

    /** The Business's actual name, canonical Website brand colour and Website logo. */
    public function forBusiness(?Business $business): MailBrand
    {
        if ($business === null) {
            return MailBrand::platform();
        }

        $accent = null;
        $logo = null;

        try {
            $website = Website::query()->where('business_id', $business->id)->first();
            $theme = is_array($website?->theme) ? $website->theme : [];
            $accent = $theme['brand_color'] ?? null;
            $logoUid = $theme['logo_asset_uid'] ?? null;

            if ($website !== null && is_string($logoUid) && $logoUid !== '') {
                $asset = WebsiteAsset::query()
                    ->where('website_id', $website->id)
                    ->where('uid', $logoUid)
                    ->where('purpose', WebsiteAssetPurpose::Logo)
                    ->first();

                $logo = $asset?->url();
            }
        } catch (Throwable) {
            // fall through to name-only branding
        }

        return MailBrand::business((string) $business->name, $accent, $logo);
    }

    /**
     * The white-label brand of the inviting Agency when it is enabled and still
     * entitled (the same checks ClientWorkspaceBrandResolver applies); otherwise the
     * platform brand. The Agency is always named by the caller either way.
     */
    public function forAgencyWorkspace(?int $agencyWorkspaceId): MailBrand
    {
        if ($agencyWorkspaceId === null) {
            return MailBrand::platform();
        }

        try {
            $setting = AgencyWhiteLabelSetting::query()
                ->where('agency_workspace_id', $agencyWorkspaceId)
                ->where('is_enabled', true)
                ->first();
            $agency = $setting === null ? null : Workspace::query()->find($agencyWorkspaceId);

            if ($setting === null
                || trim((string) $setting->display_name) === ''
                || $agency === null
                || ! $agency->is_active
                || ! $this->relationships->agencyWorkspaceHasManagementEligibility($agency)
                || ! $this->entitlements->decideForWorkspace($agency, PlatformFeature::WhiteLabel->value)->allowed) {
                return MailBrand::platform();
            }

            return MailBrand::agency((string) $setting->display_name, $setting->accent_color, self::agencyLogoUrl($setting->logo_path), $setting->support_email, $setting->support_url);
        } catch (Throwable) {
            return MailBrand::platform();
        }
    }

    /**
     * The brand for mail addressed to a PERSON about their own account (password reset, email verification): the
     * white-label brand of the ONE Agency that manages the workspaces this person OWNS, else the platform's (no
     * Agency, or more than one). Keyed on the
     * account being mailed, never on the request or the signed-in user, so one Agency's brand cannot reach another
     * Agency's customers or a direct platform customer.
     */
    public function forUser(?object $user): MailBrand
    {
        $userId = (int) ($user->id ?? 0);

        if ($userId <= 0) {
            return MailBrand::platform();
        }

        try {
            $agencyIds = \App\Models\AgencyClientWorkspaceRelationship::query()
                ->whereIn('client_workspace_id', Workspace::query()->where('owner_user_id', $userId)->select('id'))
                ->where('status', \App\Enums\Workspace\AgencyClientRelationshipStatus::Active->value)
                ->whereNull('terminated_at')
                ->distinct()
                ->pluck('agency_workspace_id');
        } catch (Throwable) {
            return MailBrand::platform();
        }

        // Exactly one managing Agency is unambiguous. Several are not, and "the oldest" is not a reason to show a
        // customer one Agency's identity on mail that concerns their account with another: use the platform's.
        $agencyId = $agencyIds->count() === 1 ? (int) $agencyIds->first() : null;

        return $this->forAgencyWorkspace($agencyId === null ? null : (int) $agencyId);
    }

    private static function agencyLogoUrl(?string $path): ?string
    {
        $path = trim(str_replace('\\', '/', (string) $path));

        if (! str_starts_with($path, 'images/branding/agency/') || str_contains($path, '..') || str_contains($path, ':') || str_contains($path, '//')) {
            return null;
        }

        return asset($path);
    }
}

<?php

namespace App\Library\Branding;

use App\Models\AgencyWhiteLabelSetting;

/**
 * White Label — resolves an Agency brand for a request HOST, for the unauthenticated screens (login, reset).
 *
 * A host maps to a brand only when ALL of these hold: the Agency recorded exactly this hostname as its app domain,
 * ownership of that hostname was proven by DNS (`verified`), and the Agency has switched its brand on. An unknown,
 * unverified, removed or merely similar host (different case is normalised; a suffix or subdomain never matches)
 * resolves to nothing, and AgencyBrandResolver then re-checks the Agency itself (active, Agency tier) before any of it
 * is rendered.
 *
 * Which hostnames the platform ANSWERS on is a separate, infrastructure decision (TrustHosts + DNS + certificate).
 * This class never widens it: an untrusted Host header is refused before routing, so a manipulated header cannot reach
 * this lookup, and a verified-but-not-yet-live domain is simply not requested.
 */
final class DatabaseAgencyBrandSource implements AgencyBrandSource
{
    public function forHost(string $host): ?AgencyBrand
    {
        $host = strtolower(trim($host));

        if ($host === '' || ! str_contains($host, '.')) {
            return null;
        }

        $setting = AgencyWhiteLabelSetting::query()
            ->where('app_domain', $host)
            ->where('app_domain_status', AgencyAppDomainService::STATUS_VERIFIED)
            ->where('is_enabled', true)
            ->with('agencyWorkspace')
            ->first();

        return $setting === null ? null : self::brandFor($setting);
    }

    public static function brandFor(AgencyWhiteLabelSetting $setting): ?AgencyBrand
    {
        $workspace = $setting->agencyWorkspace;

        if ($workspace === null || trim((string) $setting->display_name) === '') {
            return null;
        }

        return new AgencyBrand(
            workspaceUid: (string) $workspace->uid,
            displayName: (string) $setting->display_name,
            logoPath: $setting->logo_path,
            tagline: $setting->tagline,
            accentColor: $setting->accent_color,
            supportEmail: $setting->support_email,
            secondaryColor: $setting->secondary_color,
            faviconPath: $setting->favicon_path,
            logoCompactPath: $setting->logo_compact_path,
            supportUrl: $setting->support_url,
        );
    }
}

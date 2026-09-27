<?php

namespace App\Http\Middleware;

use App\Enums\Website\WebsiteDomainStatus;
use App\Models\WebsiteDomain;
use Illuminate\Http\Middleware\TrustHosts as Middleware;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Website Generation + Hosting Slice B (custom domains, contract §40.1)
 * — the hosting contract confirmed this middleware existed but was
 * absent from the global stack, and named activating it correctly as a
 * prerequisite for routing on any customer-supplied Host header. Now
 * that App\Http\Middleware\ResolveCustomDomainWebsite does exactly that,
 * this is registered in Kernel::$middleware, extended to ALSO trust
 * every currently Active custom domain (App\Models\WebsiteDomain), on
 * top of the platform's own host it always trusted.
 *
 * A domain leaves the Active set (removed, reassigned, certificate
 * failure) within one cache TTL of no longer being trusted here either
 * — the same bound already accepted for public-rendering entitlement
 * (WebsitePublicEntitlementGate) and the middleware's own domain-lookup
 * cache.
 */
class TrustHosts extends Middleware
{
    private const CACHE_TTL_SECONDS = 60;

    /**
     * Get the host patterns that should be trusted.
     *
     * @return array<int, string|null>
     */
    public function hosts(): array
    {
        return array_filter([
            $this->allSubdomainsOfApplicationUrl(),
            ...$this->activeCustomDomainPatterns(),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function activeCustomDomainPatterns(): array
    {
        return Cache::remember('website_trust_hosts_active_domains', self::CACHE_TTL_SECONDS, function () {
            try {
                return WebsiteDomain::where('status', WebsiteDomainStatus::Active->value)
                    ->pluck('domain')
                    ->map(fn (string $domain) => '^'.preg_quote($domain, '#').'$')
                    ->all();
            } catch (Throwable) {
                // e.g. this table doesn't exist yet in a not-fully-migrated
                // environment — fail safe to "no extra trusted hosts"
                // rather than break every single request on the platform's
                // own host too.
                return [];
            }
        });
    }
}

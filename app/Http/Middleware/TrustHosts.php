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
 * A domain entering or leaving the Active set is reflected here on the
 * very next request, not after a cache TTL lapses:
 * App\Library\Website\Domains\WebsiteDomainService explicitly
 * Cache::forget()s ACTIVE_DOMAINS_CACHE_KEY the instant a domain
 * activates or a previously Active domain is removed.
 */
class TrustHosts extends Middleware
{
    /**
     * Public so App\Library\Website\Domains\WebsiteDomainService can
     * Cache::forget() it the instant a domain becomes Active or a
     * previously Active domain is removed — this middleware runs on
     * EVERY request (including the platform's own), so its cache TTL
     * has to be long enough to matter for performance, which means it
     * MUST be invalidated on write rather than left to lapse on its own.
     */
    public const ACTIVE_DOMAINS_CACHE_KEY = 'website_trust_hosts_active_domains';

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
        return Cache::remember(self::ACTIVE_DOMAINS_CACHE_KEY, self::CACHE_TTL_SECONDS, function () {
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

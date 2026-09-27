<?php

namespace App\Library\Website\Domains;

use App\Enums\Website\WebsiteDomainCertificateStatus;
use App\Enums\Website\WebsiteDomainStatus;
use App\Http\Middleware\TrustHosts;
use App\Models\Website;
use App\Models\WebsiteDomain;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Website Generation + Hosting Slice B (custom domains, contract §40).
 * The sole writer of `website_domains`, mirroring
 * WebsiteDraftPageService's own "one authorized seam" pattern.
 *
 * Lifecycle: pending_verification -> verified -> provisioning -> active,
 * or -> failed at either of the last two steps (WebsiteDomainStatus).
 * Only an Active domain is ever used for public Host-based routing or
 * search indexing — see App\Http\Middleware\ResolveCustomDomainWebsite
 * and resources/views/public/website/page.blade.php.
 */
final class WebsiteDomainService
{
    /**
     * A generous, arbitrary ceiling — this is a safety bound against
     * runaway automation, not a real product limit.
     */
    private const MAX_DOMAINS_PER_WEBSITE = 5;

    public function __construct(
        private readonly DnsVerifier $dns,
        private readonly ForgeDomainProvisioner $provisioner,
    ) {}

    /**
     * @throws ValidationException
     */
    public function attach(Website $website, string $rawDomain): WebsiteDomain
    {
        if ($website->published_revision_id === null) {
            throw ValidationException::withMessages([
                'domain' => ['Publish your website at least once before connecting a custom domain.'],
            ]);
        }

        $domain = self::normalize($rawDomain);
        self::validateFormat($domain);

        return DB::transaction(function () use ($website, $domain) {
            // Locks any row for this EXACT domain string first — the
            // last line of defense against two simultaneous claims both
            // reading "not taken" before either commits. The column's
            // own DB-level unique constraint backs this up regardless.
            $existing = WebsiteDomain::where('domain', $domain)->lockForUpdate()->first();

            if ($existing !== null) {
                throw ValidationException::withMessages([
                    'domain' => ['This domain is already connected to another website.'],
                ]);
            }

            Website::whereKey($website->id)->lockForUpdate()->first();

            if ($website->domains()->count() >= self::MAX_DOMAINS_PER_WEBSITE) {
                throw ValidationException::withMessages([
                    'domain' => ['A website may not have more than '.self::MAX_DOMAINS_PER_WEBSITE.' domains.'],
                ]);
            }

            return $website->domains()->create([
                'domain' => $domain,
                // The first domain a website ever connects becomes its
                // canonical address automatically; every domain added
                // after that starts as an alias until the owner
                // explicitly promotes it (makePrimary()).
                'is_primary' => ! $website->domains()->exists(),
                'status' => WebsiteDomainStatus::PendingVerification,
                'verification_token' => Str::random(40),
            ]);
        });
    }

    /**
     * @return array{ownership: array{type: string, host: string, value: string}, traffic: array{type: string, host: string, cname_target: ?string, a_record_ip: ?string}}
     */
    public function dnsInstructions(WebsiteDomain $domain): array
    {
        return [
            'ownership' => [
                'type' => 'TXT',
                'host' => $domain->verificationTxtHost(),
                'value' => $domain->verification_token,
            ],
            'traffic' => [
                'type' => 'CNAME or A',
                'host' => $domain->domain,
                'cname_target' => config('services.forge.cname_target'),
                'a_record_ip' => config('services.forge.a_record_ip'),
            ],
        ];
    }

    /**
     * Idempotent: calling this again once already verified (or beyond)
     * simply returns the domain unchanged rather than erroring on a
     * stray double-click.
     */
    public function checkVerification(WebsiteDomain $domain): WebsiteDomain
    {
        if ($domain->status !== WebsiteDomainStatus::PendingVerification) {
            return $domain;
        }

        $domain->last_checked_at = now();

        if ($this->dns->hasTxtRecord($domain->verificationTxtHost(), $domain->verification_token)) {
            $domain->status = WebsiteDomainStatus::Verified;
            $domain->verified_at = now();
            $domain->failure_reason = null;
        } else {
            $domain->failure_reason = "We couldn't find that TXT record yet. DNS changes can take a few minutes to take effect — add the record shown below, then check again.";
        }

        $domain->save();

        return $domain;
    }

    /**
     * @throws ValidationException when DNS ownership has not been verified yet
     */
    public function provisionCertificate(WebsiteDomain $domain): WebsiteDomain
    {
        if ($domain->status === WebsiteDomainStatus::Provisioning || $domain->status === WebsiteDomainStatus::Active) {
            return $domain;
        }

        if ($domain->status !== WebsiteDomainStatus::Verified) {
            throw ValidationException::withMessages([
                'domain' => ['Verify DNS ownership for this domain before requesting a certificate.'],
            ]);
        }

        $domain->last_checked_at = now();

        try {
            // Attaching and certifying are two separate Forge operations
            // (see ForgeDomainProvisioner's docblock) — attach first, so
            // the site actually responds to $domain before Forge's own
            // HTTP-01 challenge for the certificate request even runs.
            $this->provisioner->attachDomain($domain->domain);
            $domain->certificate_reference = $this->provisioner->requestCertificateForDomains(
                $this->certificateDomainList($domain->domain)
            );
            $domain->status = WebsiteDomainStatus::Provisioning;
            $domain->failure_reason = null;
        } catch (Throwable $exception) {
            $domain->status = WebsiteDomainStatus::Failed;
            $domain->failure_reason = 'Certificate request failed: '.$exception->getMessage();
        }

        $domain->save();

        return $domain;
    }

    /**
     * Every domain the ONE shared Forge site's certificate must keep
     * covering: every other currently Active custom domain (across
     * every Business — they all share this one site), the platform's
     * own host (the same site also serves the platform itself), and the
     * one being requested now. Recomputed fresh on every call — never
     * cached — so a request made moments after another Business's
     * domain went Active still covers it, and a certificate is never
     * requested with a stale, shrunk SAN list that would drop existing
     * domains' HTTPS coverage.
     *
     * @return array<int, string>
     */
    private function certificateDomainList(string $newDomain): array
    {
        $platformHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        $domains = WebsiteDomain::where('status', WebsiteDomainStatus::Active->value)
            ->pluck('domain')
            ->push($newDomain);

        if ($platformHost !== '') {
            $domains->push($platformHost);
        }

        return $domains->unique()->values()->all();
    }

    /**
     * Idempotent: a no-op once already Active or Failed.
     */
    public function checkCertificate(WebsiteDomain $domain): WebsiteDomain
    {
        if ($domain->status !== WebsiteDomainStatus::Provisioning) {
            return $domain;
        }

        $domain->last_checked_at = now();
        $status = $this->provisioner->certificateStatus((string) $domain->certificate_reference);

        if ($status === WebsiteDomainCertificateStatus::Active) {
            $domain->status = WebsiteDomainStatus::Active;
            $domain->activated_at = now();
            $domain->failure_reason = null;
        } elseif ($status === WebsiteDomainCertificateStatus::Failed) {
            $domain->status = WebsiteDomainStatus::Failed;
            $domain->failure_reason = 'Certificate provisioning failed. Remove this domain and try adding it again.';
        }
        // Pending: stays Provisioning, nothing else to persist beyond
        // last_checked_at above.

        $domain->save();

        // The trusted-host allowlist (App\Http\Middleware\TrustHosts) has
        // to know about a newly Active domain on the VERY NEXT request,
        // not after its own cache TTL lapses — see that class's own
        // ACTIVE_DOMAINS_CACHE_KEY docblock for why this coupling exists.
        if ($domain->status === WebsiteDomainStatus::Active) {
            Cache::forget(TrustHosts::ACTIVE_DOMAINS_CACHE_KEY);
        }

        return $domain;
    }

    /**
     * Always succeeds locally even when the provider-side cleanup call
     * fails (ForgeDomainProvisioner::detachDomain() never throws) — the
     * domain string is immediately free for anyone, including the same
     * business, to claim again from scratch. The public-serving lookup
     * in App\Http\Middleware\ResolveCustomDomainWebsite is never cached,
     * so a removed domain stops serving the former Website on the very
     * next request without any invalidation step here; the trusted-host
     * allowlist below is the one cache this method still has to clear.
     */
    public function remove(Website $website, WebsiteDomain $domain): void
    {
        abort_unless($domain->website_id === $website->id, 404);

        $this->provisioner->detachDomain($domain->domain);

        $wasActive = $domain->isActive();
        $wasPrimary = $domain->is_primary;
        $domain->delete();

        if ($wasPrimary) {
            $website->domains()->where('status', WebsiteDomainStatus::Active->value)
                ->orderBy('id')
                ->first()
                ?->update(['is_primary' => true]);
        }

        if ($wasActive) {
            Cache::forget(TrustHosts::ACTIVE_DOMAINS_CACHE_KEY);
        }
    }

    /**
     * @throws ValidationException when $domain is not yet Active
     */
    public function makePrimary(Website $website, WebsiteDomain $domain): WebsiteDomain
    {
        abort_unless($domain->website_id === $website->id, 404);

        if (! $domain->isActive()) {
            throw ValidationException::withMessages([
                'domain' => ['Only an active domain — verified, with a live certificate — can become the primary address.'],
            ]);
        }

        return DB::transaction(function () use ($website, $domain) {
            Website::whereKey($website->id)->lockForUpdate()->first();

            $website->domains()->where('id', '!=', $domain->id)->update(['is_primary' => false]);
            $domain->update(['is_primary' => true]);

            return $domain->fresh();
        });
    }

    private static function normalize(string $raw): string
    {
        $domain = trim($raw);
        $domain = preg_replace('#^https?://#i', '', $domain) ?? $domain;
        $domain = strtok($domain, '/');
        $domain = strtok($domain, '?');
        $domain = strtok($domain, ':');

        return strtolower(trim((string) $domain, '.'));
    }

    /**
     * @throws ValidationException
     */
    private static function validateFormat(string $domain): void
    {
        if ($domain === '' || strlen($domain) > 255) {
            throw ValidationException::withMessages(['domain' => ['Enter a valid domain name.']]);
        }

        if (filter_var($domain, FILTER_VALIDATE_IP)) {
            throw ValidationException::withMessages(['domain' => ['Enter a domain name, not an IP address.']]);
        }

        if (! preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/', $domain)) {
            throw ValidationException::withMessages(['domain' => ['Enter a valid domain name, e.g. example.com.']]);
        }

        $platformHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        if ($platformHost !== '' && ($domain === $platformHost || str_ends_with($domain, '.'.$platformHost))) {
            throw ValidationException::withMessages(['domain' => ['This domain is reserved by the platform.']]);
        }
    }
}

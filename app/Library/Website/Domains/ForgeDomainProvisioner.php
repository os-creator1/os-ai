<?php

namespace App\Library\Website\Domains;

use App\Enums\Website\WebsiteDomainCertificateStatus;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Website Generation + Hosting Slice B (custom domains, contract §40 —
 * "certificate issuer and ACME automation approach" was named there as
 * a blocking question; the answer, per the planned managed-server
 * approach, is Laravel Forge's own Let's Encrypt integration on the one
 * server every Website is hosted on).
 *
 * Attaching a domain and covering it with a working certificate are TWO
 * separate Forge operations, not one — confirmed against Forge's own
 * "Alias Domains" announcement (laravel.com/blog/forge-alias-domains):
 * "If your site is using SSL, you are responsible for ensuring that
 * your ACTIVATED SSL certificate contains all of the domains that your
 * site responds to." Adding an alias never auto-extends the active
 * certificate. Every custom domain across every Business shares this
 * ONE Forge site (one `server_id`/`site_id`), so:
 *
 *  1. attachDomain() adds $domain to the site's alias list (Forge API:
 *     PUT .../sites/{site}/aliases, replacing the full list — so this
 *     reads the site's CURRENT aliases first and sends the union back,
 *     never just the one new domain, or every other Business's already
 *     -attached domain would be silently dropped).
 *  2. requestCertificateForDomains() requests ONE Let's Encrypt
 *     certificate whose `domains` SAN list covers EVERY currently
 *     active domain plus the platform's own host plus the new one —
 *     WebsiteDomainService builds that full list, never this class,
 *     since only the service can see every WebsiteDomain row.
 *  3. certificateStatus() reports Active ONLY when Forge's own
 *     `certificate.active` field is true — a certificate can be fully
 *     `installed` on the server without being the one the site is
 *     currently terminating TLS with (Forge's own
 *     activate/{certificate} endpoint exists precisely because
 *     installing and activating are different steps). Reporting Active
 *     off `status` alone would label a domain "Live" while the site
 *     still cannot actually serve it over HTTPS.
 *
 * This class is the ONLY place a Forge API call is ever made. It is a
 * plain (non-final) class specifically so tests can bind a Mockery
 * double in its place at the WebsiteDomainService level — mirroring
 * WebsiteAiGenerationClient's own precedent. Its OWN HTTP-building/
 * response-parsing logic is exercised directly, against `Http::fake()`
 * fixtures shaped like Forge's documented responses, in
 * ForgeDomainProvisionerTest — no test anywhere calls the real Forge
 * API.
 *
 * NOT WIRED UP FOR A REAL DEPLOYMENT YET: `config('services.forge.*')`
 * has no real value in this environment, and the exact request/response
 * shapes below are this class's best-effort mapping of Forge's
 * documented Sites/Aliases/Certificates endpoints (forge.laravel.com/
 * docs/api-reference, the official laravel/forge-sdk source, and the
 * Alias Domains announcement above) — informed by documentation, not
 * verified against a live account.
 */
class ForgeDomainProvisioner
{
    private const API_BASE = 'https://forge.laravel.com/api/v1';

    /**
     * Adds $domain to the site's alias list, preserving every alias
     * already there (every other Business's already-attached custom
     * domain, and any alias set up outside this integration entirely).
     *
     * @throws DomainProvisioningException
     */
    public function attachDomain(string $domain): void
    {
        [$token, $serverId, $siteId] = $this->credentials();

        $site = Http::withToken($token)->get(self::API_BASE."/servers/{$serverId}/sites/{$siteId}");

        if ($site->failed()) {
            throw new DomainProvisioningException(
                "Could not read the Forge site to attach {$domain}: HTTP {$site->status()} {$site->body()}"
            );
        }

        $aliases = collect($site->json('site.aliases') ?? [])
            ->push($domain)
            ->unique()
            ->values()
            ->all();

        $response = Http::withToken($token)->put(
            self::API_BASE."/servers/{$serverId}/sites/{$siteId}/aliases",
            ['aliases' => $aliases],
        );

        if ($response->failed()) {
            throw new DomainProvisioningException(
                "Forge alias update failed for {$domain}: HTTP {$response->status()} {$response->body()}"
            );
        }
    }

    /**
     * Requests ONE Let's Encrypt certificate covering every domain in
     * $domains (the full current SAN list — see class docblock). Never
     * called with just the one new domain: WebsiteDomainService is the
     * one place with visibility into every other currently active
     * domain that the resulting certificate must keep covering.
     *
     * @param  array<int, string>  $domains
     * @return string a Forge certificate id to poll via certificateStatus()
     *
     * @throws DomainProvisioningException
     */
    public function requestCertificateForDomains(array $domains): string
    {
        [$token, $serverId, $siteId] = $this->credentials();

        $response = Http::withToken($token)->post(
            self::API_BASE."/servers/{$serverId}/sites/{$siteId}/certificates/letsencrypt",
            ['domains' => array_values($domains)],
        );

        if ($response->failed()) {
            throw new DomainProvisioningException(
                'Forge certificate request failed: HTTP '.$response->status().' '.$response->body()
            );
        }

        $certificateId = $response->json('certificate.id');

        if ($certificateId === null) {
            throw new DomainProvisioningException('Forge certificate request returned no certificate id.');
        }

        return (string) $certificateId;
    }

    /**
     * Active only when Forge's own `certificate.active` field is true —
     * see class docblock for why `status` alone is never enough.
     */
    public function certificateStatus(string $certificateId): WebsiteDomainCertificateStatus
    {
        [$token, $serverId, $siteId] = $this->credentials();

        $response = Http::withToken($token)->get(
            self::API_BASE."/servers/{$serverId}/sites/{$siteId}/certificates/{$certificateId}"
        );

        if ($response->failed()) {
            return WebsiteDomainCertificateStatus::Failed;
        }

        if ($response->json('certificate.active') === true) {
            return WebsiteDomainCertificateStatus::Active;
        }

        if ($response->json('certificate.status') === 'failed' || $response->json('certificate.request_status') === 'failed') {
            return WebsiteDomainCertificateStatus::Failed;
        }

        // Still installing, or installed-but-not-yet-active — either
        // way, the site cannot serve this domain over HTTPS yet, so
        // this is never reported as Active.
        return WebsiteDomainCertificateStatus::Pending;
    }

    /**
     * Removes $domain from the site's alias list — Nginx immediately
     * stops routing that Host to this site regardless of what any
     * still-installed certificate's SAN list claims to cover, which is
     * what actually stops the site being served on that domain.
     * Deliberately does NOT delete or re-request the shared certificate:
     * every other Business's domain may still be covered by it, and
     * re-issuing with a shrunk domain list risks disrupting them for a
     * single domain's removal. Never throws — WebsiteDomainService::
     * remove() must always succeed locally even when the provider-side
     * call fails.
     */
    public function detachDomain(string $domain): void
    {
        try {
            [$token, $serverId, $siteId] = $this->credentials();

            $site = Http::withToken($token)->get(self::API_BASE."/servers/{$serverId}/sites/{$siteId}");

            if ($site->failed()) {
                return;
            }

            $aliases = collect($site->json('site.aliases') ?? [])
                ->reject(fn ($alias) => $alias === $domain)
                ->values()
                ->all();

            Http::withToken($token)->put(
                self::API_BASE."/servers/{$serverId}/sites/{$siteId}/aliases",
                ['aliases' => $aliases],
            );
        } catch (Throwable) {
            // Best-effort only — see docblock above.
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     *
     * @throws DomainProvisioningException when Forge is not configured for this environment
     */
    private function credentials(): array
    {
        $token = config('services.forge.api_token');
        $serverId = config('services.forge.server_id');
        $siteId = config('services.forge.site_id');

        if (! $token || ! $serverId || ! $siteId) {
            throw new DomainProvisioningException('Custom-domain certificate provisioning is not configured for this environment.');
        }

        return [$token, $serverId, $siteId];
    }
}

<?php

namespace App\Library\Website\Domains;

use App\Enums\Website\WebsiteDomainCertificateStatus;
use Illuminate\Support\Facades\Http;

/**
 * Website Generation + Hosting Slice B (custom domains, contract §40 —
 * "certificate issuer and ACME automation approach" was named there as
 * a blocking question; the answer, per the planned managed-server
 * approach, is Laravel Forge's own Let's Encrypt integration on the one
 * server every Website is hosted on).
 *
 * This class is the ONLY place a Forge API call is ever made. It is a
 * plain (non-final) class specifically so tests can bind a Mockery
 * double in its place — mirroring WebsiteAiGenerationClient's own
 * precedent — and this codebase's test suite never calls the real HTTP
 * methods below: every test that reaches WebsiteDomainService replaces
 * this class in the container first.
 *
 * NOT WIRED UP FOR A REAL DEPLOYMENT YET: `config('services.forge.*')`
 * has no real value in this environment, and the exact Forge API
 * request/response shapes below are this class's best-effort mapping of
 * Forge's documented Sites/Certificates endpoints, unverified against a
 * live server. Treat this as the seam a real deployment wires up, not a
 * confirmed working integration.
 */
class ForgeDomainProvisioner
{
    private const API_BASE = 'https://forge.laravel.com/api/v1';

    /**
     * Asks Forge to request and install a Let's Encrypt certificate for
     * $domain on the platform's one managed site. Forge performs its own
     * HTTP-01 challenge against the domain, which only succeeds once the
     * owner has ALSO pointed traffic-serving DNS (CNAME/A, separate from
     * the ownership TXT record) at the platform.
     *
     * @return string a Forge-side certificate reference to poll via certificateStatus()
     *
     * @throws DomainProvisioningException
     */
    public function requestCertificate(string $domain): string
    {
        [$token, $serverId, $siteId] = $this->credentials();

        $response = Http::withToken($token)->post(
            self::API_BASE."/servers/{$serverId}/sites/{$siteId}/certificates/letsencrypt",
            ['domains' => [$domain]],
        );

        if ($response->failed()) {
            throw new DomainProvisioningException(
                "Forge certificate request failed for {$domain}: HTTP {$response->status()} {$response->body()}"
            );
        }

        $reference = $response->json('certificate.id');

        if ($reference === null) {
            throw new DomainProvisioningException("Forge certificate request for {$domain} returned no certificate id.");
        }

        return (string) $reference;
    }

    public function certificateStatus(string $reference): WebsiteDomainCertificateStatus
    {
        [$token, $serverId, $siteId] = $this->credentials();

        $response = Http::withToken($token)->get(
            self::API_BASE."/servers/{$serverId}/sites/{$siteId}/certificates/{$reference}"
        );

        if ($response->failed()) {
            return WebsiteDomainCertificateStatus::Failed;
        }

        return match ($response->json('certificate.status')) {
            'installed', 'active' => WebsiteDomainCertificateStatus::Active,
            'installing', 'requesting' => WebsiteDomainCertificateStatus::Pending,
            default => WebsiteDomainCertificateStatus::Failed,
        };
    }

    /**
     * Best-effort cleanup when a domain is removed from a Website. Never
     * throws — WebsiteDomainService::remove() must always succeed
     * locally even when the provider-side call fails; a leftover
     * server-side certificate for a domain no longer in
     * `website_domains` is inert (nothing routes to it) and can be
     * garbage-collected out of band.
     */
    public function removeDomain(string $domain): void
    {
        try {
            [$token, $serverId, $siteId] = $this->credentials();

            Http::withToken($token)->delete(self::API_BASE."/servers/{$serverId}/sites/{$siteId}/certificates/{$domain}");
        } catch (\Throwable) {
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

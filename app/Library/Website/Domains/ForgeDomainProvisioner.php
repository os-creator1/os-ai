<?php

namespace App\Library\Website\Domains;

use App\Enums\Website\WebsiteDomainCertificateStatus;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Website Generation + Hosting Slice B (custom domains, contract §40 —
 * "certificate issuer and ACME automation approach" was named there as
 * a blocking question; the answer, per the planned managed-server
 * approach, is Laravel Forge's own Let's Encrypt integration on the one
 * server every Website is hosted on).
 *
 * Targets Forge's CURRENT API (v2, `laravel/forge-sdk` v4 —
 * forge.laravel.com's own live OpenAPI spec at
 * `https://forge.laravel.com/api/docs.openapi` was fetched and read to
 * confirm every shape below) and its per-domain management model for a
 * site created from October 2025 onward — our production Forge site has
 * not been created yet, so it will use this model, never the legacy
 * shared-alias-list one a pre-Oct-2025 site is stuck with.
 *
 * Under this model, a domain is its own resource on the site (created,
 * read, and deleted independently — Forge's own docs: "Each custom
 * domain is managed separately... adding or removing a domain does not
 * impact other domains on the same site") and certificates are scoped
 * to ONE domain each, never a shared SAN list:
 *
 *  1. attachDomain() creates a Forge domain resource for exactly this
 *     hostname (`POST .../sites/{site}/domains`) and returns ITS id —
 *     WebsiteDomainService persists that id immediately, before ever
 *     attempting the certificate step, so a later retry or removal
 *     never leaves an orphaned Forge-side domain this row has forgotten
 *     about. Two businesses attaching domains at the same time each get
 *     their own independent resource — there is no shared list to race
 *     on, unlike the legacy read-then-PUT-whole-list design.
 *  2. requestCertificate() asks for ONE Let's Encrypt certificate for
 *     that one domain id (`POST .../domains/{domain}/certificates`),
 *     never a multi-domain SAN list — removing or failing one domain
 *     can never disrupt another's certificate, because there is no
 *     longer anything shared between them.
 *  3. certificateStatus() reports Active ONLY when Forge's own
 *     `active` attribute on THAT domain's THAT certificate is true —
 *     a certificate can be fully `installed` without being the one the
 *     domain is currently terminating TLS with. Reporting Active off
 *     `status` alone would label a domain "Live" while it still cannot
 *     actually serve traffic over HTTPS.
 *  4. detachDomain() deletes that one Forge domain resource
 *     (`DELETE .../domains/{domain}`) — Forge tears down its Nginx
 *     config and certificates for that domain alone; every other
 *     domain on the site, and its own independent certificate, is
 *     completely untouched. Unlike the other three methods, a failure
 *     here is never swallowed: WebsiteDomainService::remove() must
 *     know when deletion did NOT happen, so it never frees the
 *     hostname or discards the id of a Forge resource that may still
 *     exist — see that method's own docblock.
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
 * has no real value in this environment, and no production Forge site
 * exists yet either. The exact request/response shapes below were
 * confirmed against Forge's own live OpenAPI spec, its official SDK
 * source (github.com/laravel/forge-sdk, tag v4.1.0) including its own
 * integration test (`tests/Integration/SitesTest.php::
 * test_crud_site_domain`), and forge.laravel.com/docs/sites/domains —
 * not verified against a live account.
 */
class ForgeDomainProvisioner
{
    private const API_BASE = 'https://forge.laravel.com/api';

    /**
     * Creates an independent Forge domain resource for exactly this
     * hostname. No wildcard subdomains (this is a multi-tenant
     * platform — letting one business's domain resolve every subdomain
     * would be a tenancy hazard) and no `www.` redirect (our own domain
     * model has no concept of a www variant; auto-creating one on
     * Forge's side could silently collide with a different business
     * later adding that exact www hostname as its own, separate,
     * legitimately-unique WebsiteDomain row).
     *
     * @return string the Forge domain id — persist this immediately,
     *                see class docblock
     *
     * @throws DomainProvisioningException
     */
    public function attachDomain(string $domain): string
    {
        [$token, $org, $serverId, $siteId] = $this->credentials();

        $response = $this->client($token)->post(
            self::API_BASE."/orgs/{$org}/servers/{$serverId}/sites/{$siteId}/domains",
            [
                'name' => $domain,
                'allow_wildcard_subdomains' => false,
                'www_redirect_type' => 'none',
            ],
        );

        if ($response->failed()) {
            throw new DomainProvisioningException(
                "Forge domain creation failed for {$domain}: HTTP {$response->status()} {$response->body()}"
            );
        }

        $domainId = $response->json('data.id');

        if ($domainId === null) {
            throw new DomainProvisioningException("Forge domain creation for {$domain} returned no domain id.");
        }

        return (string) $domainId;
    }

    /**
     * Requests a Let's Encrypt certificate for exactly this one Forge
     * domain — never a multi-domain SAN list. HTTP-01 verification is
     * used deliberately: it needs nothing beyond the domain already
     * resolving to this server, which our own traffic CNAME/A-record
     * instruction (dnsInstructions()) already requires, so the owner is
     * never asked for a THIRD DNS record on top of our ownership TXT
     * record and the traffic record.
     *
     * @return string a Forge certificate id to poll via certificateStatus()
     *
     * @throws DomainProvisioningException
     */
    public function requestCertificate(string $forgeDomainId): string
    {
        [$token, $org, $serverId, $siteId] = $this->credentials();

        $response = $this->client($token)->post(
            self::API_BASE."/orgs/{$org}/servers/{$serverId}/sites/{$siteId}/domains/{$forgeDomainId}/certificates",
            [
                'type' => 'letsencrypt',
                'letsencrypt' => ['verification_method' => 'http-01'],
            ],
        );

        if ($response->failed()) {
            throw new DomainProvisioningException(
                "Forge certificate request failed for domain {$forgeDomainId}: HTTP {$response->status()} {$response->body()}"
            );
        }

        $certificateId = $response->json('data.id');

        if ($certificateId === null) {
            throw new DomainProvisioningException("Forge certificate request for domain {$forgeDomainId} returned no certificate id.");
        }

        return (string) $certificateId;
    }

    /**
     * Active only when Forge's own `active` attribute on this exact
     * domain's exact certificate is true — see class docblock for why
     * `status` alone is never enough. `status` values `failed`,
     * `failed-unknown`, and `failed-runner` (Forge's documented
     * `ResourceState` enum) all mean the request will never complete.
     */
    public function certificateStatus(string $forgeDomainId, string $certificateId): WebsiteDomainCertificateStatus
    {
        [$token, $org, $serverId, $siteId] = $this->credentials();

        $response = $this->client($token)->get(
            self::API_BASE."/orgs/{$org}/servers/{$serverId}/sites/{$siteId}/domains/{$forgeDomainId}/certificates/{$certificateId}"
        );

        if ($response->failed()) {
            return WebsiteDomainCertificateStatus::Failed;
        }

        if ($response->json('data.attributes.active') === true) {
            return WebsiteDomainCertificateStatus::Active;
        }

        if (in_array($response->json('data.attributes.status'), ['failed', 'failed-unknown', 'failed-runner'], true)) {
            return WebsiteDomainCertificateStatus::Failed;
        }

        // Still installing, or installed-but-not-yet-active — either
        // way, the domain cannot serve traffic over HTTPS yet, so this
        // is never reported as Active.
        return WebsiteDomainCertificateStatus::Pending;
    }

    /**
     * Deletes this one Forge domain resource outright — its Nginx
     * config, and its own independent certificate(s), are torn down
     * with it. Every other domain on the shared site is completely
     * unaffected, since nothing is shared between them under Forge's
     * current per-domain model.
     *
     * Unlike every other method here, a failure is NEVER swallowed:
     * WebsiteDomainService::remove() must know deletion did not
     * actually happen, so it never frees the hostname or discards
     * forge_domain_id while the Forge resource may still exist. Both
     * an HTTP failure response and a transport-level failure (timeout,
     * DNS, connection refused — Http::fake() can simulate this as a
     * thrown ConnectionException, which never reaches ->failed()) are
     * reported identically, by throwing.
     *
     * A 404 is the one exception treated as SUCCESS: the domain is
     * already gone, which is exactly the desired end state — the
     * likely result of a previous attempt whose Forge-side deletion
     * succeeded but crashed before this app could record it.
     *
     * @throws DomainProvisioningException
     */
    public function detachDomain(string $forgeDomainId): void
    {
        [$token, $org, $serverId, $siteId] = $this->credentials();

        try {
            $response = $this->client($token)->delete(
                self::API_BASE."/orgs/{$org}/servers/{$serverId}/sites/{$siteId}/domains/{$forgeDomainId}"
            );
        } catch (Throwable $exception) {
            throw new DomainProvisioningException(
                "Forge domain deletion failed for domain {$forgeDomainId}: ".$exception->getMessage(),
                previous: $exception,
            );
        }

        if ($response->failed() && $response->status() !== 404) {
            throw new DomainProvisioningException(
                "Forge domain deletion failed for domain {$forgeDomainId}: HTTP {$response->status()} {$response->body()}"
            );
        }
    }

    private function client(string $token): PendingRequest
    {
        return Http::withToken($token)->withHeaders([
            'Accept' => 'application/vnd.api+json',
            'Content-Type' => 'application/vnd.api+json',
        ]);
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}
     *
     * @throws DomainProvisioningException when Forge is not configured for this environment
     */
    private function credentials(): array
    {
        $token = config('services.forge.api_token');
        $organizationSlug = config('services.forge.organization_slug');
        $serverId = config('services.forge.server_id');
        $siteId = config('services.forge.site_id');

        if (! $token || ! $organizationSlug || ! $serverId || ! $siteId) {
            throw new DomainProvisioningException('Custom-domain certificate provisioning is not configured for this environment.');
        }

        return [$token, $organizationSlug, $serverId, $siteId];
    }
}

<?php

namespace Tests\Feature\Website;

use App\Enums\Website\WebsiteDomainCertificateStatus;
use App\Library\Website\Domains\DomainProvisioningException;
use App\Library\Website\Domains\ForgeDomainProvisioner;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Exercises ForgeDomainProvisioner's OWN HTTP-building/response-parsing
 * logic directly, against Http::fake() fixtures shaped exactly like
 * Forge's CURRENT (v2/API-v2) documented responses — confirmed against
 * Forge's own live OpenAPI spec (`https://forge.laravel.com/api/
 * docs.openapi`), the official laravel/forge-sdk source (github.com/
 * laravel/forge-sdk, tag v4.1.0, `src/Actions/ManagesSites.php` and its
 * own integration test `tests/Integration/SitesTest.php::
 * test_crud_site_domain`), and forge.laravel.com/docs/sites/domains. No
 * test anywhere in this suite calls the real Forge API: this codebase's
 * base TestCase already calls Http::preventStrayRequests() in setUp(),
 * so a request this file forgets to fake would fail loudly rather than
 * silently reach forge.laravel.com.
 */
class ForgeDomainProvisionerTest extends TestCase
{
    private const BASE = 'forge.laravel.com/api/orgs/test-org/servers/999/sites/42';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.forge.api_token' => 'test-forge-token',
            'services.forge.organization_slug' => 'test-org',
            'services.forge.server_id' => 999,
            'services.forge.site_id' => 42,
        ]);
    }

    public function test_attach_domain_posts_the_documented_fields_and_returns_the_domain_id(): void
    {
        Http::fake([
            self::BASE.'/domains' => Http::response([
                'data' => [
                    'id' => '555',
                    'type' => 'domainRecords',
                    'attributes' => [
                        'name' => 'new-domain.test',
                        'type' => 'primary',
                        'status' => 'pending',
                        'www_redirect_type' => 'none',
                        'allow_wildcard_subdomains' => false,
                    ],
                ],
            ], 202),
        ]);

        $domainId = app(ForgeDomainProvisioner::class)->attachDomain('new-domain.test');

        $this->assertSame('555', $domainId);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://'.self::BASE.'/domains'
            && $request['name'] === 'new-domain.test'
            // Multi-tenant platform: never allow one business's domain to
            // resolve every subdomain, and our own domain model has no
            // concept of a www variant — see class docblock.
            && $request['allow_wildcard_subdomains'] === false
            && $request['www_redirect_type'] === 'none');
    }

    public function test_attach_domain_throws_on_a_failed_response(): void
    {
        Http::fake([
            self::BASE.'/domains' => Http::response(['message' => 'Unprocessable'], 422),
        ]);

        $this->expectException(DomainProvisioningException::class);

        app(ForgeDomainProvisioner::class)->attachDomain('new-domain.test');
    }

    public function test_attach_domain_throws_when_no_domain_id_comes_back(): void
    {
        Http::fake([
            self::BASE.'/domains' => Http::response(['data' => ['type' => 'domainRecords', 'attributes' => []]], 202),
        ]);

        $this->expectException(DomainProvisioningException::class);

        app(ForgeDomainProvisioner::class)->attachDomain('new-domain.test');
    }

    public function test_request_certificate_posts_for_exactly_this_domain_and_returns_the_certificate_id(): void
    {
        Http::fake([
            self::BASE.'/domains/555/certificates' => Http::response([
                'data' => [
                    'id' => '777',
                    'type' => 'certificates',
                    'attributes' => [
                        'type' => 'letsencrypt',
                        'verification_method' => 'http-01',
                        'request_status' => 'creating',
                        'status' => 'installing',
                        'active' => false,
                    ],
                ],
            ], 202),
        ]);

        $certificateId = app(ForgeDomainProvisioner::class)->requestCertificate('555');

        $this->assertSame('777', $certificateId);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://'.self::BASE.'/domains/555/certificates'
            && $request['type'] === 'letsencrypt'
            // HTTP-01 needs nothing beyond the domain already resolving to
            // this server — the same requirement our own traffic
            // CNAME/A-record instruction already imposes, so the owner is
            // never asked for a third DNS record (see class docblock).
            && $request['letsencrypt']['verification_method'] === 'http-01');
    }

    public function test_request_certificate_never_touches_any_other_domain(): void
    {
        Http::fake([
            self::BASE.'/domains/555/certificates' => Http::response([
                'data' => ['id' => '777', 'type' => 'certificates', 'attributes' => ['type' => 'letsencrypt', 'active' => false, 'status' => 'installing']],
            ], 202),
        ]);

        app(ForgeDomainProvisioner::class)->requestCertificate('555');

        // No site-wide or multi-domain endpoint is ever touched — unlike
        // the legacy shared-SAN-list design, nothing here could disrupt
        // another domain's certificate.
        Http::assertNotSent(fn ($request) => ! str_contains($request->url(), '/domains/555/certificates'));
    }

    public function test_request_certificate_throws_on_a_failed_response(): void
    {
        Http::fake([
            self::BASE.'/domains/555/certificates' => Http::response(['message' => 'Unprocessable'], 422),
        ]);

        $this->expectException(DomainProvisioningException::class);

        app(ForgeDomainProvisioner::class)->requestCertificate('555');
    }

    public function test_request_certificate_throws_when_no_certificate_id_comes_back(): void
    {
        Http::fake([
            self::BASE.'/domains/555/certificates' => Http::response(['data' => ['type' => 'certificates', 'attributes' => []]], 202),
        ]);

        $this->expectException(DomainProvisioningException::class);

        app(ForgeDomainProvisioner::class)->requestCertificate('555');
    }

    public function test_certificate_status_is_active_only_when_forges_own_active_field_is_true(): void
    {
        Http::fake([
            self::BASE.'/domains/555/certificates/777' => Http::response([
                'data' => ['id' => '777', 'type' => 'certificates', 'attributes' => ['status' => 'installed', 'request_status' => 'created', 'active' => true]],
            ], 200),
        ]);

        $status = app(ForgeDomainProvisioner::class)->certificateStatus('555', '777');

        $this->assertSame(WebsiteDomainCertificateStatus::Active, $status);
    }

    public function test_certificate_status_is_pending_when_installed_but_not_yet_the_active_certificate(): void
    {
        // The exact scenario this correction exists for: Forge reports
        // the certificate as fully installed on the server, but it is
        // NOT the certificate the domain is currently terminating TLS
        // with — this must never be reported as Active.
        Http::fake([
            self::BASE.'/domains/555/certificates/777' => Http::response([
                'data' => ['id' => '777', 'type' => 'certificates', 'attributes' => ['status' => 'installed', 'request_status' => 'created', 'active' => false]],
            ], 200),
        ]);

        $status = app(ForgeDomainProvisioner::class)->certificateStatus('555', '777');

        $this->assertSame(WebsiteDomainCertificateStatus::Pending, $status);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function failedResourceStates(): iterable
    {
        yield 'failed' => ['failed'];
        yield 'failed-unknown' => ['failed-unknown'];
        yield 'failed-runner' => ['failed-runner'];
    }

    #[DataProvider('failedResourceStates')]
    public function test_certificate_status_is_failed_for_every_documented_failure_state(string $resourceState): void
    {
        Http::fake([
            self::BASE.'/domains/555/certificates/777' => Http::response([
                'data' => ['id' => '777', 'type' => 'certificates', 'attributes' => ['status' => $resourceState, 'request_status' => 'created', 'active' => false]],
            ], 200),
        ]);

        $status = app(ForgeDomainProvisioner::class)->certificateStatus('555', '777');

        $this->assertSame(WebsiteDomainCertificateStatus::Failed, $status);
    }

    public function test_certificate_status_is_failed_on_an_http_error(): void
    {
        Http::fake([
            self::BASE.'/domains/555/certificates/777' => Http::response(['message' => 'Not found'], 404),
        ]);

        $status = app(ForgeDomainProvisioner::class)->certificateStatus('555', '777');

        $this->assertSame(WebsiteDomainCertificateStatus::Failed, $status);
    }

    public function test_detach_domain_deletes_only_that_one_domain_resource(): void
    {
        Http::fake([
            self::BASE.'/domains/555' => Http::response(null, 204),
        ]);

        app(ForgeDomainProvisioner::class)->detachDomain('555');

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && $request->url() === 'https://'.self::BASE.'/domains/555');
    }

    public function test_detach_domain_never_throws_even_when_the_forge_call_fails(): void
    {
        Http::fake([
            self::BASE.'/domains/555' => Http::response(['message' => 'Server error'], 500),
        ]);

        // Must not throw — WebsiteDomainService::remove() has to succeed
        // locally regardless of the provider-side outcome.
        app(ForgeDomainProvisioner::class)->detachDomain('555');

        $this->addToAssertionCount(1);
    }

    public function test_detach_domain_never_touches_any_other_domain_or_certificate(): void
    {
        Http::fake([
            self::BASE.'/domains/555' => Http::response(null, 204),
        ]);

        app(ForgeDomainProvisioner::class)->detachDomain('555');

        // Deleting a domain resource under Forge's per-domain model tears
        // down that domain's own Nginx config and certificate(s) with it
        // — nothing shared with any other domain is ever touched, unlike
        // the legacy shared-alias-list design this replaces.
        Http::assertNotSent(fn ($request) => $request->url() !== 'https://'.self::BASE.'/domains/555');
    }
}

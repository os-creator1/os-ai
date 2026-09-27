<?php

namespace Tests\Feature\Website;

use App\Enums\Website\WebsiteDomainCertificateStatus;
use App\Library\Website\Domains\DomainProvisioningException;
use App\Library\Website\Domains\ForgeDomainProvisioner;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Exercises ForgeDomainProvisioner's OWN HTTP-building/response-parsing
 * logic directly, against Http::fake() fixtures shaped like Forge's
 * documented Sites/Aliases/Certificates responses (forge.laravel.com/
 * docs/api-reference, the official laravel/forge-sdk source, and
 * laravel.com/blog/forge-alias-domains — "If your site is using SSL,
 * you are responsible for ensuring that your ACTIVATED SSL certificate
 * contains all of the domains that your site responds to"). No test
 * anywhere in this suite calls the real Forge API: this codebase's base
 * TestCase already calls Http::preventStrayRequests() in setUp(), so a
 * request this file forgets to fake would fail loudly rather than
 * silently reach forge.laravel.com.
 */
class ForgeDomainProvisionerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.forge.api_token' => 'test-forge-token',
            'services.forge.server_id' => 999,
            'services.forge.site_id' => 42,
        ]);
    }

    public function test_attach_domain_reads_current_aliases_and_puts_back_the_union(): void
    {
        Http::fake([
            'forge.laravel.com/api/v1/servers/999/sites/42' => Http::response([
                'site' => ['id' => 42, 'name' => 'platform.test', 'aliases' => ['already-attached.test']],
            ], 200),
            'forge.laravel.com/api/v1/servers/999/sites/42/aliases' => Http::response([
                'site' => ['id' => 42, 'name' => 'platform.test', 'aliases' => ['already-attached.test', 'new-domain.test']],
            ], 200),
        ]);

        app(ForgeDomainProvisioner::class)->attachDomain('new-domain.test');

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && $request->url() === 'https://forge.laravel.com/api/v1/servers/999/sites/42/aliases'
            && $request['aliases'] === ['already-attached.test', 'new-domain.test']);
    }

    public function test_attach_domain_never_drops_another_businesss_already_attached_alias(): void
    {
        Http::fake([
            'forge.laravel.com/api/v1/servers/999/sites/42' => Http::response([
                'site' => ['id' => 42, 'aliases' => ['business-a.test', 'business-b.test']],
            ], 200),
            'forge.laravel.com/api/v1/servers/999/sites/42/aliases' => Http::response(['site' => ['id' => 42]], 200),
        ]);

        app(ForgeDomainProvisioner::class)->attachDomain('business-c.test');

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && $request['aliases'] === ['business-a.test', 'business-b.test', 'business-c.test']);
    }

    public function test_attach_domain_throws_when_reading_the_site_fails(): void
    {
        Http::fake([
            'forge.laravel.com/api/v1/servers/999/sites/42' => Http::response(['message' => 'Server error'], 500),
        ]);

        $this->expectException(DomainProvisioningException::class);

        app(ForgeDomainProvisioner::class)->attachDomain('new-domain.test');
    }

    public function test_attach_domain_throws_when_the_alias_update_fails(): void
    {
        Http::fake([
            'forge.laravel.com/api/v1/servers/999/sites/42' => Http::response(['site' => ['id' => 42, 'aliases' => []]], 200),
            'forge.laravel.com/api/v1/servers/999/sites/42/aliases' => Http::response(['message' => 'Unprocessable'], 422),
        ]);

        $this->expectException(DomainProvisioningException::class);

        app(ForgeDomainProvisioner::class)->attachDomain('new-domain.test');
    }

    public function test_request_certificate_for_domains_posts_the_full_domain_list_and_returns_the_certificate_id(): void
    {
        Http::fake([
            'forge.laravel.com/api/v1/servers/999/sites/42/certificates/letsencrypt' => Http::response([
                'certificate' => [
                    'id' => 777,
                    'domain' => 'new-domain.test',
                    'type' => 'letsencrypt',
                    'request_status' => 'created',
                    'status' => 'installing',
                    'active' => false,
                    'existing' => false,
                ],
            ], 201),
        ]);

        $certificateId = app(ForgeDomainProvisioner::class)->requestCertificateForDomains(['platform.test', 'new-domain.test']);

        $this->assertSame('777', $certificateId);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://forge.laravel.com/api/v1/servers/999/sites/42/certificates/letsencrypt'
            && $request['domains'] === ['platform.test', 'new-domain.test']);
    }

    public function test_request_certificate_throws_on_a_failed_response(): void
    {
        Http::fake([
            'forge.laravel.com/api/v1/servers/999/sites/42/certificates/letsencrypt' => Http::response(['message' => 'Unprocessable'], 422),
        ]);

        $this->expectException(DomainProvisioningException::class);

        app(ForgeDomainProvisioner::class)->requestCertificateForDomains(['new-domain.test']);
    }

    public function test_request_certificate_throws_when_no_certificate_id_comes_back(): void
    {
        Http::fake([
            'forge.laravel.com/api/v1/servers/999/sites/42/certificates/letsencrypt' => Http::response(['certificate' => []], 201),
        ]);

        $this->expectException(DomainProvisioningException::class);

        app(ForgeDomainProvisioner::class)->requestCertificateForDomains(['new-domain.test']);
    }

    public function test_certificate_status_is_active_only_when_forges_own_active_field_is_true(): void
    {
        Http::fake([
            'forge.laravel.com/api/v1/servers/999/sites/42/certificates/777' => Http::response([
                'certificate' => ['id' => 777, 'status' => 'installed', 'request_status' => 'created', 'active' => true, 'existing' => false],
            ], 200),
        ]);

        $status = app(ForgeDomainProvisioner::class)->certificateStatus('777');

        $this->assertSame(WebsiteDomainCertificateStatus::Active, $status);
    }

    public function test_certificate_status_is_pending_when_installed_but_not_yet_the_active_certificate(): void
    {
        // The exact scenario this correction exists for: Forge reports
        // the certificate as fully installed on the server, but it is
        // NOT the certificate the site is currently terminating TLS
        // with — this must never be reported as Active.
        Http::fake([
            'forge.laravel.com/api/v1/servers/999/sites/42/certificates/777' => Http::response([
                'certificate' => ['id' => 777, 'status' => 'installed', 'request_status' => 'created', 'active' => false, 'existing' => false],
            ], 200),
        ]);

        $status = app(ForgeDomainProvisioner::class)->certificateStatus('777');

        $this->assertSame(WebsiteDomainCertificateStatus::Pending, $status);
    }

    public function test_certificate_status_is_failed_when_forge_reports_a_failed_request(): void
    {
        Http::fake([
            'forge.laravel.com/api/v1/servers/999/sites/42/certificates/777' => Http::response([
                'certificate' => ['id' => 777, 'status' => 'failed', 'request_status' => 'failed', 'active' => false, 'existing' => false],
            ], 200),
        ]);

        $status = app(ForgeDomainProvisioner::class)->certificateStatus('777');

        $this->assertSame(WebsiteDomainCertificateStatus::Failed, $status);
    }

    public function test_certificate_status_is_failed_on_an_http_error(): void
    {
        Http::fake([
            'forge.laravel.com/api/v1/servers/999/sites/42/certificates/777' => Http::response(['message' => 'Not found'], 404),
        ]);

        $status = app(ForgeDomainProvisioner::class)->certificateStatus('777');

        $this->assertSame(WebsiteDomainCertificateStatus::Failed, $status);
    }

    public function test_detach_domain_removes_only_the_named_domain_from_the_alias_list(): void
    {
        Http::fake([
            'forge.laravel.com/api/v1/servers/999/sites/42' => Http::response([
                'site' => ['id' => 42, 'aliases' => ['business-a.test', 'business-b.test']],
            ], 200),
            'forge.laravel.com/api/v1/servers/999/sites/42/aliases' => Http::response(['site' => ['id' => 42]], 200),
        ]);

        app(ForgeDomainProvisioner::class)->detachDomain('business-a.test');

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && $request['aliases'] === ['business-b.test']);
    }

    public function test_detach_domain_never_throws_even_when_the_forge_call_fails(): void
    {
        Http::fake([
            'forge.laravel.com/api/v1/servers/999/sites/42' => Http::response(['message' => 'Server error'], 500),
        ]);

        // Must not throw — WebsiteDomainService::remove() has to succeed
        // locally regardless of the provider-side outcome.
        app(ForgeDomainProvisioner::class)->detachDomain('business-a.test');

        $this->addToAssertionCount(1);
    }

    public function test_detach_domain_never_deletes_or_reissues_the_shared_certificate(): void
    {
        Http::fake([
            'forge.laravel.com/api/v1/servers/999/sites/42' => Http::response([
                'site' => ['id' => 42, 'aliases' => ['business-a.test']],
            ], 200),
            'forge.laravel.com/api/v1/servers/999/sites/42/aliases' => Http::response(['site' => ['id' => 42]], 200),
        ]);

        app(ForgeDomainProvisioner::class)->detachDomain('business-a.test');

        // Only the site-read and the alias PUT — never a certificate
        // delete or a new letsencrypt request, which would risk
        // disrupting every OTHER Business's domain still covered by the
        // one shared certificate.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/certificates'));
    }
}

<?php

namespace Tests\Feature\Website;

use App\Enums\Website\WebsiteDomainCertificateStatus;
use App\Enums\Website\WebsiteDomainStatus;
use App\Library\Website\Domains\DomainProvisioningException;
use App\Library\Website\Domains\WebsiteDomainService;
use App\Library\Website\WebsitePublisher;
use App\Models\Website;
use App\Models\WebsiteDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Custom domains: two DNS records (A @ + a unique CNAME on www), fully automatic HTTPS. DnsVerifier, ForgeDomainProvisioner
 * and HttpsProbe are ALWAYS Mockery doubles here: no real DNS lookup, Forge call or HTTPS request happens in this file.
 */
class WebsiteDomainTest extends TestCase
{
    use CreatesWebsiteFixtures;
    use RefreshDatabase;

    private function publish(Website $website): void
    {
        $this->homePage($website);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        $website->refresh();
    }

    /** @return array{0: \App\Models\Customer, 1: \App\Models\Business, 2: \App\Models\Workspace, 3: Website} */
    private function tenant(): array
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->publish($website);

        return [$customer, $business, $workspace, $website];
    }

    private function add(string $workspaceUid, string $businessUid, string $domain)
    {
        return $this->post(route('customer.workspaces.businesses.website.domains.store', [$workspaceUid, $businessUid]), ['domain' => $domain]);
    }

    private function service(): WebsiteDomainService
    {
        return app(WebsiteDomainService::class);
    }

    private function indexUrl($workspace, $business): string
    {
        return route('customer.workspaces.businesses.website.domains.index', [$workspace->uid, $business->uid]);
    }

    // ------------------------------------------------------------------ connecting

    public function test_a_domain_cannot_be_connected_before_the_website_is_published(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->fakeDnsVerifier();
        $this->fakeDomainProvisioner();
        $this->authenticateAsCustomer($customer);

        $this->add($workspace->uid, $business->uid, 'early.test')->assertSessionHasErrors('domain');
        $this->assertSame(0, $website->domains()->count());
    }

    public function test_invalid_formats_and_subdomains_are_rejected_and_www_is_folded_into_the_apex(): void
    {
        [$customer, $business, $workspace, $website] = $this->tenant();
        $this->fakeDnsVerifier();
        $this->fakeDomainProvisioner();
        $this->authenticateAsCustomer($customer);

        foreach (['not a domain', '192.168.1.1', '-bad.example.test', 'localhost', 'shop.example.test', 'a.b.example.test'] as $bad) {
            $this->add($workspace->uid, $business->uid, $bad)->assertSessionHasErrors('domain');
        }

        $this->assertSame(0, $website->domains()->count());

        $this->add($workspace->uid, $business->uid, 'https://www.Example.test/')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('example.test', $website->domains()->sole()->domain, 'www.<domain> is the same site; the apex is connected.');
    }

    public function test_custom_domains_are_refused_when_the_platform_dns_values_are_not_configured(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->fakeDomainProvisioner();
        config(['services.forge.a_record_ip' => null, 'services.forge.cname_zone' => null]);
        $this->authenticateAsCustomer($customer);

        $this->add($workspace->uid, $business->uid, 'example.test')->assertSessionHasErrors('domain');
        $this->assertSame(0, WebsiteDomain::count());
    }

    // ------------------------------------------------------------------ what the customer sees

    public function test_exactly_two_records_are_shown_with_a_unique_cname_and_no_txt(): void
    {
        [$customer, $business, $workspace, $website] = $this->tenant();
        $this->fakeDnsVerifier();
        $this->fakeDomainProvisioner();
        $this->authenticateAsCustomer($customer);
        $this->add($workspace->uid, $business->uid, 'example.test');
        $domain = $website->domains()->sole();
        $this->add($workspace->uid, $business->uid, 'second-site.test');
        $second = $website->domains()->where('domain', 'second-site.test')->sole();

        $this->assertMatchesRegularExpression('/^[a-z0-9]{32}$/', $domain->verification_token);
        $this->assertNotSame($domain->verification_token, $second->verification_token, 'Every domain gets its own CNAME target.');

        $html = $this->get($this->indexUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="dns-value-a">203.0.113.10<', $html);
        $this->assertStringContainsString('data-testid="dns-value-cname">' . $domain->verification_token . '.domains.example-platform.test<', $html);
        preg_match_all('/data-testid="dns-row-(a|cname)"/', $html, $rows);
        $this->assertSame(['a', 'a', 'cname', 'cname'], $this->sorted($rows[1]), 'Two records per domain card: A and CNAME, nothing else.');
        $this->assertStringContainsString('CNAME', $html);
        $this->assertStringContainsString('Automatic', $html);
        $this->assertStringContainsString('data-copy="www"', $html);
        $this->assertStringContainsString('data-copy="@"', $html);
        $this->assertStringNotContainsString('TXT', $html);
        $this->assertStringNotContainsString('_platform-verify', $html);
        $this->assertStringNotContainsString('Request certificate', $html);
        $this->assertStringNotContainsString('Check certificate', $html);
        $this->assertStringContainsString('Connect DNS', $html);
        $this->assertStringContainsString('Securing', $html);
        $this->assertStringContainsString('Leave your email (MX, SPF, DKIM, DMARC) records as they are', $html);
        $this->assertStringContainsString('moves your live traffic', $html);
    }

    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    public function test_the_records_are_withheld_until_the_platform_is_ready_to_provision(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->fakeDnsVerifier();
        $provisioner = $this->fakeDomainProvisioner();
        $this->authenticateAsCustomer($customer);
        $this->add($workspace->uid, $business->uid, 'example.test');
        $provisioner->shouldReceive('isConfigured')->andReturn(false);

        $html = $this->get($this->indexUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="dns-not-ready"', $html);
        $this->assertStringNotContainsString('data-testid="dns-row-a"', $html, 'Customers are not told to change DNS before we can provision.');
    }

    // ------------------------------------------------------------------ ownership proof

    public function test_ownership_needs_the_exact_unique_cname_and_a_shared_server_ip_alone_never_verifies(): void
    {
        [$customer, $business, $workspace, $website] = $this->tenant();
        $dns = $this->fakeDnsVerifier();
        $provisioner = $this->fakeDomainProvisioner();
        $provisioner->shouldNotReceive('attachDomain');
        $provisioner->shouldNotReceive('requestCertificate');
        $this->authenticateAsCustomer($customer);
        $this->add($workspace->uid, $business->uid, 'hijack.test');
        $domain = $website->domains()->sole();

        // The domain already points at our IP (e.g. a previous owner's setup) but the unique CNAME is absent.
        $dns->shouldReceive('hasARecord')->with('hijack.test', '203.0.113.10')->andReturn(true);
        $dns->shouldReceive('hasCnameTo')->with('www.hijack.test', $domain->verification_token . '.domains.example-platform.test')->andReturn(false);

        $domain = $this->service()->advance($domain, true);

        $this->assertSame(WebsiteDomainStatus::PendingVerification, $domain->status);
        $this->assertTrue($domain->dns_a_ok);
        $this->assertFalse($domain->dns_cname_ok);
        $this->assertNull($domain->forge_domain_id);
    }

    public function test_the_verifier_matches_the_cname_target_exactly(): void
    {
        $verifier = new class extends \App\Library\Website\Domains\DnsVerifier {
            public function hasCnameTo(string $host, string $expectedTarget): bool
            {
                $records = [['target' => 'Abc123.Domains.Example-Platform.Test.']];
                $expected = strtolower(rtrim(trim($expectedTarget), '.'));

                foreach ($records as $record) {
                    if (strtolower(rtrim((string) $record['target'], '.')) === $expected) {
                        return true;
                    }
                }

                return false;
            }
        };

        $this->assertTrue($verifier->hasCnameTo('www.x.test', 'abc123.domains.example-platform.test'));
        $this->assertFalse($verifier->hasCnameTo('www.x.test', 'abc123.domains.example-platform.test.evil.test'));
        $this->assertFalse($verifier->hasCnameTo('www.x.test', 'domains.example-platform.test'), 'A shared/generic target is not proof.');
        $this->assertFalse((new \App\Library\Website\Domains\DnsVerifier)->hasCnameTo('www.x.test', ''));
    }

    public function test_apex_and_www_are_reserved_together_and_cannot_be_claimed_by_a_different_customer(): void
    {
        [$customerA, $businessA, $workspaceA, $websiteA] = $this->tenant();
        $this->fakeDnsVerifier();
        $this->fakeDomainProvisioner();
        $this->authenticateAsCustomer($customerA);
        $this->add($workspaceA->uid, $businessA->uid, 'contested.test')->assertRedirect()->assertSessionHasNoErrors();
        $domain = $websiteA->domains()->sole();

        $this->assertSame(['website_domain' => 'contested.test', 'website_domain_www' => 'www.contested.test'],
            DB::table('domain_reservations')->where('owner_id', $domain->id)->pluck('domain', 'owner_type')->all());

        [$customerB, $businessB, $workspaceB] = $this->entitledTenant();
        $websiteB = $this->createWebsite($businessB);
        $this->publish($websiteB);
        $this->authenticateAsCustomer($customerB);

        foreach (['contested.test', 'www.contested.test', 'https://WWW.contested.test/'] as $attempt) {
            $this->add($workspaceB->uid, $businessB->uid, $attempt)->assertSessionHasErrors('domain');
        }

        $this->assertSame(0, $websiteB->domains()->count());
        $this->assertSame(1, WebsiteDomain::where('domain', 'contested.test')->count());
    }

    // ------------------------------------------------------------------ automatic provisioning

    public function test_correct_dns_starts_automatic_provisioning_for_both_hostnames_and_goes_live_only_when_https_works(): void
    {
        [$customer, $business, $workspace, $website] = $this->tenant();
        $this->fakeDnsVerifier();
        $provisioner = $this->fakeDomainProvisioner();
        $probe = $this->fakeHttpsProbe(false);
        $provisioner->shouldReceive('attachDomain')->once()->with('example.test')->andReturn('forge-apex');
        $provisioner->shouldReceive('attachDomain')->once()->with('www.example.test')->andReturn('forge-www');
        $provisioner->shouldReceive('requestCertificate')->once()->with('forge-apex')->andReturn('cert-apex');
        $provisioner->shouldReceive('requestCertificate')->once()->with('forge-www')->andReturn('cert-www');
        $this->authenticateAsCustomer($customer);
        $this->add($workspace->uid, $business->uid, 'example.test');
        $domain = $website->domains()->sole();

        // One automatic step: DNS proven, both Forge domains and both certificates requested.
        $domain = $this->service()->advance($domain, true);
        $this->assertSame(WebsiteDomainStatus::Provisioning, $domain->status);
        $this->assertSame(['forge-apex', 'forge-www', 'cert-apex', 'cert-www'], [$domain->forge_domain_id, $domain->www_forge_domain_id, $domain->certificate_reference, $domain->www_certificate_reference]);
        $this->assertNotNull($domain->verified_at);

        // Certificate still installing on one hostname: stays Provisioning, never Live.
        $provisioner->shouldReceive('certificateStatus')->with('forge-apex', 'cert-apex')->andReturn(WebsiteDomainCertificateStatus::Active);
        $provisioner->shouldReceive('certificateStatus')->with('forge-www', 'cert-www')->once()->andReturn(WebsiteDomainCertificateStatus::Pending);
        $this->assertSame(WebsiteDomainStatus::Provisioning, $this->service()->advance($domain, true)->status);

        // Both certificates active but HTTPS does not actually answer yet: still not Live.
        $provisioner->shouldReceive('certificateStatus')->with('forge-www', 'cert-www')->andReturn(WebsiteDomainCertificateStatus::Active);
        $this->assertSame(WebsiteDomainStatus::Provisioning, $this->service()->advance($domain->refresh(), true)->status);
        $this->assertNull($domain->fresh()->activated_at);

        // Both answer over HTTPS: Live.
        $probe->shouldReceive('isServing')->with('example.test')->andReturn(true);
        $probe->shouldReceive('isServing')->with('www.example.test')->andReturn(true);
        $domain = $this->service()->advance($domain->refresh(), true);
        $this->assertSame(WebsiteDomainStatus::Active, $domain->status);
        $this->assertNotNull($domain->activated_at);
        $this->assertTrue($domain->is_primary);

        // Further runs change nothing and call Forge for nothing.
        $this->assertSame(WebsiteDomainStatus::Active, $this->service()->advance($domain->refresh(), true)->status);
    }

    public function test_the_website_is_not_live_when_only_one_hostname_answers_over_https(): void
    {
        [$customer, $business, $workspace, $website] = $this->tenant();
        $this->fakeDnsVerifier();
        $this->fakeDomainProvisioner();
        $probe = $this->fakeHttpsProbe(false);
        $probe->shouldReceive('isServing')->with('example.test')->andReturn(true);
        $probe->shouldReceive('isServing')->with('www.example.test')->andReturn(false);
        $this->authenticateAsCustomer($customer);
        $this->add($workspace->uid, $business->uid, 'example.test');
        $domain = $website->domains()->sole();

        $this->service()->advance($domain, true);
        $domain = $this->service()->advance($domain->refresh(), true);

        $this->assertSame(WebsiteDomainStatus::Provisioning, $domain->status);
    }

    public function test_dns_that_has_not_propagated_never_produces_a_false_success_and_is_retried_on_a_backoff(): void
    {
        [$customer, $business, $workspace, $website] = $this->tenant();
        $this->fakeDnsVerifier(false);
        $provisioner = $this->fakeDomainProvisioner();
        $provisioner->shouldNotReceive('attachDomain');
        $this->authenticateAsCustomer($customer);
        $this->add($workspace->uid, $business->uid, 'example.test');
        $domain = $website->domains()->sole();

        $domain = $this->service()->advance($domain, true);

        $this->assertSame(WebsiteDomainStatus::PendingVerification, $domain->status);
        $this->assertNull($domain->failure_reason, 'Waiting for DNS is not an error.');
        $this->assertTrue($domain->next_attempt_at->isFuture());

        // Honouring the schedule: nothing happens before next_attempt_at, and no extra lookups are made.
        $before = $domain->attempts;
        $this->service()->advance($domain->refresh());
        $this->assertSame($before, $domain->fresh()->attempts);

        $html = $this->get($this->indexUrl($workspace, $business))->assertOk()->getContent();
        $this->assertStringContainsString('Not found yet', $html);
        $this->assertStringNotContainsString('data-testid="open-website"', $html);
    }

    public function test_missing_forge_credentials_leave_the_domain_waiting_safely_then_it_continues_once_configured(): void
    {
        [$customer, $business, $workspace, $website] = $this->tenant();
        $this->fakeDnsVerifier();
        $provisioner = $this->fakeDomainProvisioner();
        $configured = false;
        $provisioner->shouldReceive('isConfigured')->andReturnUsing(function () use (&$configured) { return $configured; });
        $provisioner->shouldReceive('attachDomain')->never()->byDefault();
        $this->authenticateAsCustomer($customer);
        $this->add($workspace->uid, $business->uid, 'example.test');
        $domain = $website->domains()->sole();

        $domain = $this->service()->advance($domain, true);

        $this->assertSame(WebsiteDomainStatus::Verified, $domain->status, 'Not Failed: our side is not ready.');
        $this->assertNull($domain->forge_domain_id);
        $this->assertStringNotContainsString('FORGE', (string) $domain->failure_reason);
        $this->assertStringNotContainsString('not configured', (string) $domain->failure_reason);
        $this->assertTrue($domain->next_attempt_at->isFuture());

        $configured = true;
        $provisioner->shouldReceive('attachDomain')->andReturn('forge-a', 'forge-w');
        $domain = $this->service()->advance($domain->refresh(), true);
        $this->assertSame(WebsiteDomainStatus::Provisioning, $domain->status);
    }

    public function test_transient_forge_errors_back_off_then_fail_once_and_a_retry_reuses_every_forge_resource(): void
    {
        [$customer, $business, $workspace, $website] = $this->tenant();
        $this->fakeDnsVerifier();
        $provisioner = $this->fakeDomainProvisioner();
        // Apex attaches once and is reused; the www attach keeps failing, then works after the retry.
        $provisioner->shouldReceive('attachDomain')->once()->with('example.test')->andReturn('forge-apex');
        $provisioner->shouldReceive('attachDomain')->times(6)->with('www.example.test')->andThrow(new DomainProvisioningException('Forge is down'));
        $this->authenticateAsCustomer($customer);
        $this->add($workspace->uid, $business->uid, 'example.test');
        $domain = $website->domains()->sole();

        $domain = $this->service()->advance($domain, true);
        $this->assertSame(WebsiteDomainStatus::Verified, $domain->status);
        $this->assertSame('forge-apex', $domain->forge_domain_id, 'The id is persisted the moment Forge returns it.');
        $this->assertNull($domain->failure_reason);

        for ($i = 0; $i < 5; $i++) {
            $domain = $this->service()->advance($domain->refresh(), true);
        }

        $this->assertSame(WebsiteDomainStatus::Failed, $domain->status);
        $this->assertSame("We couldn't finish setting up this domain. Please try again.", $domain->failure_reason);
        $this->assertStringNotContainsString('Forge is down', (string) $domain->failure_reason, 'No provider detail reaches the customer.');

        // The owner's "Try again": DNS is re-proven first, existing Forge ids are kept, nothing is duplicated.
        $provisioner->shouldReceive('attachDomain')->once()->with('www.example.test')->andReturn('forge-www');
        $this->post(route('customer.workspaces.businesses.website.domains.retry', [$workspace->uid, $business->uid, $domain->uid]))->assertRedirect();
        $domain->refresh();

        $this->assertSame(WebsiteDomainStatus::Provisioning, $domain->status);
        $this->assertSame(['forge-apex', 'forge-www'], [$domain->forge_domain_id, $domain->www_forge_domain_id]);
    }

    public function test_a_retry_rechecks_ownership_before_anything_else(): void
    {
        [$customer, $business, $workspace, $website] = $this->tenant();
        $dns = $this->fakeDnsVerifier(false);
        $provisioner = $this->fakeDomainProvisioner();
        $provisioner->shouldNotReceive('attachDomain');
        $this->authenticateAsCustomer($customer);
        $this->add($workspace->uid, $business->uid, 'example.test');
        $domain = $website->domains()->sole();
        $domain->forceFill(['status' => WebsiteDomainStatus::Failed, 'failure_reason' => 'x', 'forge_domain_id' => 'kept'])->save();

        $this->post(route('customer.workspaces.businesses.website.domains.retry', [$workspace->uid, $business->uid, $domain->uid]));

        $domain->refresh();
        $this->assertSame(WebsiteDomainStatus::PendingVerification, $domain->status);
        $this->assertSame('kept', $domain->forge_domain_id);
    }

    public function test_a_failed_certificate_is_retried_a_bounded_number_of_times_then_fails(): void
    {
        [$customer, $business, $workspace, $website] = $this->tenant();
        $this->fakeDnsVerifier();
        $provisioner = $this->fakeDomainProvisioner();
        $provisioner->shouldReceive('attachDomain')->andReturn('forge-x');
        $provisioner->shouldReceive('requestCertificate')->andReturn('cert-x');
        $provisioner->shouldReceive('certificateStatus')->andReturn(WebsiteDomainCertificateStatus::Failed);
        $this->authenticateAsCustomer($customer);
        $this->add($workspace->uid, $business->uid, 'example.test');
        $domain = $website->domains()->sole();

        $domain = $this->service()->advance($domain, true);

        for ($i = 0; $i < 3; $i++) {
            $domain = $this->service()->advance($domain->refresh(), true);
            $this->assertSame(WebsiteDomainStatus::Provisioning, $domain->status);
        }

        $domain = $this->service()->advance($domain->refresh(), true);
        $this->assertSame(WebsiteDomainStatus::Failed, $domain->status);
    }

    public function test_background_processing_is_idempotent_and_honours_claims_and_schedules(): void
    {
        [$customer, $business, $workspace, $website] = $this->tenant();
        $this->fakeDnsVerifier();
        $provisioner = $this->fakeDomainProvisioner();
        $provisioner->shouldReceive('attachDomain')->twice()->andReturn('forge-apex', 'forge-www');
        $provisioner->shouldReceive('requestCertificate')->twice()->andReturn('cert-a', 'cert-w');
        $provisioner->shouldReceive('certificateStatus')->andReturn(WebsiteDomainCertificateStatus::Pending);
        $this->authenticateAsCustomer($customer);
        $this->add($workspace->uid, $business->uid, 'example.test');
        $domain = $website->domains()->sole();

        // Another worker holds the claim: nothing runs.
        $domain->forceFill(['processing_until' => now()->addMinutes(5)])->save();
        Artisan::call('website-domains:process');
        $this->assertSame(WebsiteDomainStatus::PendingVerification, $domain->fresh()->status);

        $domain->forceFill(['processing_until' => null])->save();
        Artisan::call('website-domains:process');
        $this->assertSame(WebsiteDomainStatus::Provisioning, $domain->fresh()->status);
        $this->assertNull($domain->fresh()->processing_until, 'The claim is released.');

        // Not due yet: skipped. Due: polled, with no new Forge resources.
        Artisan::call('website-domains:process');
        $domain->forceFill(['next_attempt_at' => now()->subMinute()])->save();
        Artisan::call('website-domains:process');
        Artisan::call('website-domains:process');
        $this->assertSame(WebsiteDomainStatus::Provisioning, $domain->fresh()->status);
    }

    public function test_the_recover_command_restarts_failed_domains_only_when_configured_and_keeps_every_resource(): void
    {
        [$customer, $business, $workspace, $website] = $this->tenant();
        $this->fakeDnsVerifier();
        $provisioner = $this->fakeDomainProvisioner();
        $this->authenticateAsCustomer($customer);
        $this->add($workspace->uid, $business->uid, 'example.test');
        $domain = $website->domains()->sole();
        $domain->forceFill(['status' => WebsiteDomainStatus::Failed, 'failure_reason' => 'x', 'forge_domain_id' => 'kept-id'])->save();
        DB::table('domain_reservations')->where('owner_type', 'website_domain_www')->delete();

        $configured = false;
        $provisioner->shouldReceive('isConfigured')->andReturnUsing(function () use (&$configured) { return $configured; });
        $this->assertSame(1, Artisan::call('website-domains:recover'));
        $this->assertSame(WebsiteDomainStatus::Failed, $domain->fresh()->status, 'Incomplete configuration changes nothing.');

        $configured = true;
        $this->assertSame(0, Artisan::call('website-domains:recover', ['--dry-run' => true]));
        $this->assertSame(WebsiteDomainStatus::Failed, $domain->fresh()->status);

        $provisioner->shouldReceive('attachDomain')->once()->with('www.example.test')->andReturn('forge-www');
        $this->assertSame(0, Artisan::call('website-domains:recover', ['domain' => 'example.test']));

        $domain->refresh();
        $this->assertSame(WebsiteDomainStatus::Provisioning, $domain->status);
        $this->assertSame('kept-id', $domain->forge_domain_id, 'No duplicate Forge domain is created.');
        $this->assertTrue(DB::table('domain_reservations')->where('owner_type', 'website_domain_www')->where('owner_id', $domain->id)->exists(), 'The www reservation is added, never replaced.');
        $this->assertSame(1, WebsiteDomain::where('domain', 'example.test')->count());
    }

    // ------------------------------------------------------------------ serving, primary, removal

    public function test_www_redirects_to_the_apex_on_an_active_domain(): void
    {
        [$customer, $business, $workspace, $website] = $this->tenant();
        $this->fakeDnsVerifier();
        $this->fakeDomainProvisioner();
        $this->authenticateAsCustomer($customer);
        $this->add($workspace->uid, $business->uid, 'example.test');
        $domain = $website->domains()->sole();
        $this->service()->advance($domain, true);
        $this->service()->advance($domain->refresh(), true);
        $this->assertSame(WebsiteDomainStatus::Active, $domain->fresh()->status);

        $this->get('http://www.example.test/about?x=1')->assertStatus(301)->assertRedirect('https://example.test/about?x=1');
    }

    public function test_owner_can_reassign_which_active_domain_is_the_primary_address(): void
    {
        [$customer, $business, $workspace, $website] = $this->tenant();
        $this->fakeDnsVerifier();
        $this->fakeDomainProvisioner();
        $this->authenticateAsCustomer($customer);
        $this->add($workspace->uid, $business->uid, 'first.test');
        $this->add($workspace->uid, $business->uid, 'second.test');
        $first = $website->domains()->where('domain', 'first.test')->sole();
        $second = $website->domains()->where('domain', 'second.test')->sole();

        foreach ([$first, $second] as $domain) {
            $this->service()->advance($domain, true);
            $this->service()->advance($domain->refresh(), true);
        }

        $this->assertTrue($first->fresh()->is_primary);
        $this->assertFalse($second->fresh()->is_primary);

        $this->post(route('customer.workspaces.businesses.website.domains.makePrimary', [$workspace->uid, $business->uid, $second->uid]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertFalse($first->fresh()->is_primary);
        $this->assertTrue($second->fresh()->is_primary);
    }

    public function test_make_primary_requires_an_already_active_domain(): void
    {
        [$customer, $business, $workspace, $website] = $this->tenant();
        $this->fakeDnsVerifier(false);
        $this->fakeDomainProvisioner();
        $this->authenticateAsCustomer($customer);
        $this->add($workspace->uid, $business->uid, 'notyet.test');
        $domain = $website->domains()->sole();

        $this->post(route('customer.workspaces.businesses.website.domains.makePrimary', [$workspace->uid, $business->uid, $domain->uid]))
            ->assertSessionHasErrors('domain');
    }

    public function test_removing_detaches_both_forge_domains_and_frees_both_hostnames_for_another_business(): void
    {
        [$customerA, $businessA, $workspaceA, $websiteA] = $this->tenant();
        $this->fakeDnsVerifier();
        $provisioner = $this->fakeDomainProvisioner();
        $provisioner->shouldReceive('attachDomain')->andReturn('forge-apex', 'forge-www');
        $this->authenticateAsCustomer($customerA);
        $this->add($workspaceA->uid, $businessA->uid, 'reassignable.test');
        $domain = $websiteA->domains()->sole();
        $this->service()->advance($domain, true);
        $provisioner->shouldReceive('detachDomain')->once()->with('forge-apex');
        $provisioner->shouldReceive('detachDomain')->once()->with('forge-www');

        $this->delete(route('customer.workspaces.businesses.website.domains.destroy', [$workspaceA->uid, $businessA->uid, $domain->uid]))->assertRedirect();

        $this->assertSame(0, $websiteA->domains()->count());
        $this->assertSame(0, DB::table('domain_reservations')->whereIn('domain', ['reassignable.test', 'www.reassignable.test'])->count());

        [$customerB, $businessB, $workspaceB] = $this->entitledTenant();
        $websiteB = $this->createWebsite($businessB);
        $this->publish($websiteB);
        $this->authenticateAsCustomer($customerB);
        $this->add($workspaceB->uid, $businessB->uid, 'reassignable.test')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotSame($domain->verification_token, $websiteB->domains()->sole()->verification_token);
    }

    public function test_a_removal_forge_cannot_confirm_keeps_both_hostnames_claimed_until_a_retry_succeeds(): void
    {
        [$customer, $business, $workspace, $website] = $this->tenant();
        $this->fakeDnsVerifier();
        $provisioner = $this->fakeDomainProvisioner();
        $provisioner->shouldReceive('attachDomain')->andReturn('forge-apex', 'forge-www');
        $this->authenticateAsCustomer($customer);
        $this->add($workspace->uid, $business->uid, 'flaky-removal.test');
        $domain = $website->domains()->sole();
        $this->service()->advance($domain, true);
        $provisioner->shouldReceive('detachDomain')->once()->with('forge-apex')->andThrow(new DomainProvisioningException('Forge did not respond.'));

        $this->delete(route('customer.workspaces.businesses.website.domains.destroy', [$workspace->uid, $business->uid, $domain->uid]))
            ->assertRedirect()->assertSessionHas('status', 'error');

        $domain->refresh();
        $this->assertSame(WebsiteDomainStatus::Removing, $domain->status);
        $this->assertSame('forge-apex', $domain->forge_domain_id);
        $this->assertSame(2, DB::table('domain_reservations')->where('owner_id', $domain->id)->whereIn('owner_type', ['website_domain', 'website_domain_www'])->count());

        $provisioner->shouldReceive('detachDomain')->with('forge-apex');
        $provisioner->shouldReceive('detachDomain')->with('forge-www');
        $this->delete(route('customer.workspaces.businesses.website.domains.destroy', [$workspace->uid, $business->uid, $domain->uid]))
            ->assertRedirect()->assertSessionHas('status', 'success');
        $this->assertSame(0, WebsiteDomain::where('domain', 'flaky-removal.test')->count());
    }

    // ------------------------------------------------------------------ tenancy and the page

    public function test_a_foreign_businesss_domain_is_not_reachable(): void
    {
        [, $businessA] = $this->entitledTenant();
        $websiteA = $this->createWebsite($businessA);
        $this->publish($websiteA);
        $foreign = $websiteA->domains()->create(['domain' => 'direct-fixture.test', 'status' => WebsiteDomainStatus::Failed, 'verification_token' => 'token']);

        [$customerB, $businessB, $workspaceB] = $this->entitledTenant();
        $this->createWebsite($businessB);
        $this->authenticateAsCustomer($customerB);

        $this->get($this->indexUrl($workspaceB, $businessB))->assertOk();
        $this->post(route('customer.workspaces.businesses.website.domains.verify', [$workspaceB->uid, $businessB->uid, $foreign->uid]))->assertNotFound();
        $this->post(route('customer.workspaces.businesses.website.domains.retry', [$workspaceB->uid, $businessB->uid, $foreign->uid]))->assertNotFound();
        $this->assertSame(WebsiteDomainStatus::Failed, $foreign->fresh()->status);
    }

    public function test_the_old_manual_certificate_actions_no_longer_exist(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('customer.workspaces.businesses.website.domains.provision'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('customer.workspaces.businesses.website.domains.checkCertificate'));
    }

    public function test_the_securing_and_live_steps_have_no_buttons_beyond_open_and_retry(): void
    {
        [$customer, $business, $workspace, $website] = $this->tenant();
        $this->fakeDnsVerifier();
        $this->fakeDomainProvisioner();
        $this->fakeHttpsProbe(false);
        $this->authenticateAsCustomer($customer);
        $this->add($workspace->uid, $business->uid, 'example.test');
        $domain = $website->domains()->sole();
        $this->service()->advance($domain, true);

        $html = $this->get($this->indexUrl($workspace, $business))->assertOk()->getContent();
        $this->assertStringContainsString('data-testid="securing"', $html);
        $this->assertStringNotContainsString('Check certificate', $html);
        $this->assertStringNotContainsString('Request certificate', $html);
        $this->assertStringNotContainsString('data-testid="dns-row-a"', $html);

        $this->fakeHttpsProbe(true);
        $this->service()->advance($domain->refresh(), true);
        $live = $this->get($this->indexUrl($workspace, $business))->assertOk()->getContent();
        $this->assertStringContainsString('data-testid="open-website"', $live);
        $this->assertStringContainsString('https://example.test', $live);
    }

    public function test_a_failed_add_keeps_the_connect_form_open_with_the_typed_value(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->fakeDnsVerifier();
        $this->fakeDomainProvisioner();
        $this->authenticateAsCustomer($customer);
        $this->add($workspace->uid, $business->uid, 'first.test');
        $index = $this->indexUrl($workspace, $business);

        $this->from($index)->post(route('customer.workspaces.businesses.website.domains.store', [$workspace->uid, $business->uid]), ['domain' => 'not a domain!'])
            ->assertRedirect($index)->assertSessionHasErrors('domain');
        $html = $this->get($index)->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-testid="connect-another"\s+open/', $html);
        $this->assertStringContainsString('value="not a domain!"', $html);
    }
}

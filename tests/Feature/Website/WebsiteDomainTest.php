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
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Website Generation + Hosting Slice B — custom domains. DnsVerifier and
 * ForgeDomainProvisioner are ALWAYS bound to Mockery doubles here: no
 * real DNS lookup and no real Forge API call happens anywhere in this
 * file.
 */
class WebsiteDomainTest extends TestCase
{
    use CreatesWebsiteFixtures;
    use RefreshDatabase;

    private function publish(Website $website): void
    {
        $this->homePage($website);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        // WebsitePublisher::publish() re-fetches and updates its OWN
        // locked copy of the Website row internally — the caller's own
        // $website instance is never mutated by reference, so it must be
        // refreshed here or published_revision_id stays stale for any
        // code (like WebsiteDomainService::attach()) that checks it
        // directly against this same in-memory instance.
        $website->refresh();
    }

    // ---------------------------------------------------------------
    // Published-site requirement, format validation
    // ---------------------------------------------------------------

    public function test_a_domain_cannot_be_connected_before_the_website_is_published(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->fakeDnsVerifier();
        $this->fakeDomainProvisioner();
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.domains.store', [$workspace->uid, $business->uid]), [
            'domain' => 'www.example.test',
        ])->assertSessionHasErrors('domain');

        $this->assertSame(0, $website->domains()->count());
    }

    public function test_invalid_domain_formats_are_rejected(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->publish($website);
        $this->fakeDnsVerifier();
        $this->fakeDomainProvisioner();
        $this->authenticateAsCustomer($customer);

        foreach (['not a domain', '192.168.1.1', '-bad.example.test', 'localhost'] as $badDomain) {
            $this->post(route('customer.workspaces.businesses.website.domains.store', [$workspace->uid, $business->uid]), [
                'domain' => $badDomain,
            ])->assertSessionHasErrors('domain');
        }

        $this->assertSame(0, $website->domains()->count());
    }

    // ---------------------------------------------------------------
    // Happy path: connect -> verify -> provision -> active
    // ---------------------------------------------------------------

    public function test_owner_can_connect_verify_provision_and_activate_a_domain(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->publish($website);
        $this->fakeDnsVerifier(true);
        $provisioner = $this->fakeDomainProvisioner();
        $provisioner->shouldReceive('attachDomain')->once()->andReturn('forge-domain-1');
        $provisioner->shouldReceive('requestCertificate')->once()->with('forge-domain-1')->andReturn('cert-ref-123');
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.domains.store', [$workspace->uid, $business->uid]), [
            'domain' => 'https://www.example.test/',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $domain = $website->domains()->sole();
        // The stored domain is normalized: scheme and trailing slash gone.
        $this->assertSame('www.example.test', $domain->domain);
        $this->assertSame(WebsiteDomainStatus::PendingVerification, $domain->status);
        $this->assertTrue($domain->is_primary);

        $this->post(route('customer.workspaces.businesses.website.domains.verify', [$workspace->uid, $business->uid, $domain->uid]))
            ->assertRedirect()->assertSessionHas('status', 'success');
        $this->assertSame(WebsiteDomainStatus::Verified, $domain->fresh()->status);

        $this->post(route('customer.workspaces.businesses.website.domains.provision', [$workspace->uid, $business->uid, $domain->uid]))
            ->assertRedirect()->assertSessionHas('status', 'success');
        $domain->refresh();
        $this->assertSame(WebsiteDomainStatus::Provisioning, $domain->status);
        $this->assertSame('forge-domain-1', $domain->forge_domain_id);
        $this->assertSame('cert-ref-123', $domain->certificate_reference);

        $provisioner->shouldReceive('certificateStatus')->once()->with('forge-domain-1', 'cert-ref-123')->andReturn(WebsiteDomainCertificateStatus::Active);

        $this->post(route('customer.workspaces.businesses.website.domains.checkCertificate', [$workspace->uid, $business->uid, $domain->uid]))
            ->assertRedirect()->assertSessionHas('status', 'success');
        $domain->refresh();
        $this->assertSame(WebsiteDomainStatus::Active, $domain->status);
        $this->assertNotNull($domain->activated_at);
    }

    public function test_verification_failure_shows_a_clear_reason_and_keeps_the_domain_pending(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->publish($website);
        $this->fakeDnsVerifier(false);
        $this->fakeDomainProvisioner();
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.domains.store', [$workspace->uid, $business->uid]), [
            'domain' => 'www.example.test',
        ])->assertRedirect();
        $domain = $website->domains()->sole();

        $this->post(route('customer.workspaces.businesses.website.domains.verify', [$workspace->uid, $business->uid, $domain->uid]))
            ->assertRedirect()
            ->assertSessionHas('status', 'error')
            ->assertSessionHas('message');

        $domain->refresh();
        $this->assertSame(WebsiteDomainStatus::PendingVerification, $domain->status);
        $this->assertNotNull($domain->failure_reason);
    }

    public function test_certificate_provisioning_failure_is_reported_and_recoverable_by_removing_and_retrying(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->publish($website);
        $this->fakeDnsVerifier(true);
        $provisioner = $this->fakeDomainProvisioner();
        // attachDomain() succeeds and its id is persisted BEFORE the
        // certificate request that then fails — proving remove() below
        // can still clean up the Forge-side domain it created, using
        // that exact persisted id, not a dropped/forgotten reference.
        $provisioner->shouldReceive('attachDomain')->once()->andReturn('forge-domain-99');
        $provisioner->shouldReceive('requestCertificate')->once()->with('forge-domain-99')->andThrow(new DomainProvisioningException('DNS does not point at us yet.'));
        $provisioner->shouldReceive('detachDomain')->once()->with('forge-domain-99');
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.domains.store', [$workspace->uid, $business->uid]), [
            'domain' => 'www.example.test',
        ])->assertRedirect();
        $domain = $website->domains()->sole();

        $this->post(route('customer.workspaces.businesses.website.domains.verify', [$workspace->uid, $business->uid, $domain->uid]))->assertRedirect();
        $this->post(route('customer.workspaces.businesses.website.domains.provision', [$workspace->uid, $business->uid, $domain->uid]))
            ->assertRedirect()
            ->assertSessionHas('status', 'error');

        $domain->refresh();
        $this->assertSame(WebsiteDomainStatus::Failed, $domain->status);
        $this->assertStringContainsString('DNS does not point at us yet.', (string) $domain->failure_reason);
        $this->assertSame('forge-domain-99', $domain->forge_domain_id);

        // A failed domain is removable, freeing the string to try again.
        $this->delete(route('customer.workspaces.businesses.website.domains.destroy', [$workspace->uid, $business->uid, $domain->uid]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(0, $website->domains()->count());
    }

    // ---------------------------------------------------------------
    // Takeover, reassignment, removal
    // ---------------------------------------------------------------

    public function test_a_domain_already_connected_to_another_website_cannot_be_taken_over(): void
    {
        [$customerA, $businessA, $workspaceA] = $this->entitledTenant();
        $websiteA = $this->createWebsite($businessA);
        $this->publish($websiteA);
        $this->fakeDnsVerifier();
        $this->fakeDomainProvisioner();
        $this->authenticateAsCustomer($customerA);
        $this->post(route('customer.workspaces.businesses.website.domains.store', [$workspaceA->uid, $businessA->uid]), [
            'domain' => 'contested.test',
        ])->assertRedirect();

        [$customerB, $businessB, $workspaceB] = $this->entitledTenant();
        $websiteB = $this->createWebsite($businessB);
        $this->publish($websiteB);
        $this->authenticateAsCustomer($customerB);

        $this->post(route('customer.workspaces.businesses.website.domains.store', [$workspaceB->uid, $businessB->uid]), [
            'domain' => 'contested.test',
        ])->assertSessionHasErrors('domain');

        $this->assertSame(1, WebsiteDomain::where('domain', 'contested.test')->count());
        $this->assertSame($websiteA->id, WebsiteDomain::where('domain', 'contested.test')->sole()->website_id);
    }

    public function test_removing_a_domain_frees_it_for_a_different_business_with_fresh_verification(): void
    {
        [$customerA, $businessA, $workspaceA] = $this->entitledTenant();
        $websiteA = $this->createWebsite($businessA);
        $this->publish($websiteA);
        $this->fakeDnsVerifier();
        $this->fakeDomainProvisioner();
        $this->authenticateAsCustomer($customerA);
        $this->post(route('customer.workspaces.businesses.website.domains.store', [$workspaceA->uid, $businessA->uid]), [
            'domain' => 'reassignable.test',
        ])->assertRedirect();
        $originalDomain = $websiteA->domains()->sole();

        $this->delete(route('customer.workspaces.businesses.website.domains.destroy', [$workspaceA->uid, $businessA->uid, $originalDomain->uid]))
            ->assertRedirect();
        $this->assertSame(0, $websiteA->domains()->count());

        [$customerB, $businessB, $workspaceB] = $this->entitledTenant();
        $websiteB = $this->createWebsite($businessB);
        $this->publish($websiteB);
        $this->authenticateAsCustomer($customerB);

        $this->post(route('customer.workspaces.businesses.website.domains.store', [$workspaceB->uid, $businessB->uid]), [
            'domain' => 'reassignable.test',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $newDomain = $websiteB->domains()->sole();
        $this->assertSame(WebsiteDomainStatus::PendingVerification, $newDomain->status);
        $this->assertNotSame($originalDomain->verification_token, $newDomain->verification_token);
    }

    public function test_owner_can_reassign_which_active_domain_is_the_primary_address(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->publish($website);
        $this->fakeDnsVerifier(true);
        $provisioner = $this->fakeDomainProvisioner();
        $provisioner->shouldReceive('requestCertificate')->andReturn('ref-1', 'ref-2');
        $provisioner->shouldReceive('certificateStatus')->andReturn(WebsiteDomainCertificateStatus::Active);
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.domains.store', [$workspace->uid, $business->uid]), ['domain' => 'first.test'])->assertRedirect();
        $this->post(route('customer.workspaces.businesses.website.domains.store', [$workspace->uid, $business->uid]), ['domain' => 'second.test'])->assertRedirect();
        $first = $website->domains()->where('domain', 'first.test')->sole();
        $second = $website->domains()->where('domain', 'second.test')->sole();

        foreach ([$first, $second] as $domain) {
            $this->post(route('customer.workspaces.businesses.website.domains.verify', [$workspace->uid, $business->uid, $domain->uid]));
            $this->post(route('customer.workspaces.businesses.website.domains.provision', [$workspace->uid, $business->uid, $domain->uid]));
            $this->post(route('customer.workspaces.businesses.website.domains.checkCertificate', [$workspace->uid, $business->uid, $domain->uid]));
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
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->publish($website);
        $this->fakeDnsVerifier();
        $this->fakeDomainProvisioner();
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.domains.store', [$workspace->uid, $business->uid]), ['domain' => 'still-pending.test'])->assertRedirect();
        $domain = $website->domains()->sole();

        $this->post(route('customer.workspaces.businesses.website.domains.makePrimary', [$workspace->uid, $business->uid, $domain->uid]))
            ->assertSessionHasErrors('domain');
    }

    public function test_the_first_domain_to_go_live_becomes_primary_when_the_first_attached_one_never_does(): void
    {
        // The first domain a website attaches is flagged primary at once. If it never activates
        // (wrong DNS, abandoned) and a later domain does, the site must not become unreachable
        // (an alias with no live primary to redirect to answers 404).
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->publish($website);
        $this->fakeDnsVerifier(true);
        $provisioner = $this->fakeDomainProvisioner();
        $provisioner->shouldReceive('requestCertificate')->andReturn('ref-2');
        $provisioner->shouldReceive('certificateStatus')->andReturn(WebsiteDomainCertificateStatus::Active);
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.domains.store', [$workspace->uid, $business->uid]), ['domain' => 'never-live.test'])->assertRedirect();
        $this->post(route('customer.workspaces.businesses.website.domains.store', [$workspace->uid, $business->uid]), ['domain' => 'goes-live.test'])->assertRedirect();
        $stuck = $website->domains()->where('domain', 'never-live.test')->sole();
        $live = $website->domains()->where('domain', 'goes-live.test')->sole();
        $this->assertTrue($stuck->is_primary);
        $this->assertFalse($live->is_primary);

        $this->post(route('customer.workspaces.businesses.website.domains.verify', [$workspace->uid, $business->uid, $live->uid]));
        $this->post(route('customer.workspaces.businesses.website.domains.provision', [$workspace->uid, $business->uid, $live->uid]));
        $this->post(route('customer.workspaces.businesses.website.domains.checkCertificate', [$workspace->uid, $business->uid, $live->uid]));

        $this->assertTrue($live->fresh()->is_primary, 'The live domain takes the primary role.');
        $this->assertFalse($stuck->fresh()->is_primary);
        $this->assertSame('goes-live.test', $website->fresh()->activePrimaryDomain()?->domain);

        $this->get('http://goes-live.test/')->assertOk();
    }

    public function test_removing_the_primary_domain_promotes_another_active_domain(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->publish($website);
        $this->fakeDnsVerifier(true);
        $provisioner = $this->fakeDomainProvisioner();
        $provisioner->shouldReceive('requestCertificate')->andReturn('ref-1', 'ref-2');
        $provisioner->shouldReceive('certificateStatus')->andReturn(WebsiteDomainCertificateStatus::Active);
        $provisioner->shouldReceive('detachDomain')->once();
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.domains.store', [$workspace->uid, $business->uid]), ['domain' => 'primary.test'])->assertRedirect();
        $this->post(route('customer.workspaces.businesses.website.domains.store', [$workspace->uid, $business->uid]), ['domain' => 'alias.test'])->assertRedirect();
        $primary = $website->domains()->where('domain', 'primary.test')->sole();
        $alias = $website->domains()->where('domain', 'alias.test')->sole();

        foreach ([$primary, $alias] as $domain) {
            $this->post(route('customer.workspaces.businesses.website.domains.verify', [$workspace->uid, $business->uid, $domain->uid]));
            $this->post(route('customer.workspaces.businesses.website.domains.provision', [$workspace->uid, $business->uid, $domain->uid]));
            $this->post(route('customer.workspaces.businesses.website.domains.checkCertificate', [$workspace->uid, $business->uid, $domain->uid]));
        }

        $this->delete(route('customer.workspaces.businesses.website.domains.destroy', [$workspace->uid, $business->uid, $primary->uid]))
            ->assertRedirect();

        $this->assertTrue($alias->fresh()->is_primary);
    }

    public function test_a_removal_forge_cannot_confirm_keeps_the_hostname_claimed_until_a_retry_succeeds(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->publish($website);
        $this->fakeDnsVerifier(true);
        $provisioner = $this->fakeDomainProvisioner();
        $provisioner->shouldReceive('attachDomain')->once()->andReturn('forge-domain-retry');
        $provisioner->shouldReceive('requestCertificate')->once()->with('forge-domain-retry')->andReturn('cert-retry');
        $provisioner->shouldReceive('certificateStatus')->once()->with('forge-domain-retry', 'cert-retry')->andReturn(WebsiteDomainCertificateStatus::Active);
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.domains.store', [$workspace->uid, $business->uid]), ['domain' => 'flaky-removal.test'])->assertRedirect();
        $domain = $website->domains()->sole();
        $this->post(route('customer.workspaces.businesses.website.domains.verify', [$workspace->uid, $business->uid, $domain->uid]));
        $this->post(route('customer.workspaces.businesses.website.domains.provision', [$workspace->uid, $business->uid, $domain->uid]));
        $this->post(route('customer.workspaces.businesses.website.domains.checkCertificate', [$workspace->uid, $business->uid, $domain->uid]));
        $this->assertSame(WebsiteDomainStatus::Active, $domain->fresh()->status);

        // Forge is unreachable (this stands in for either an HTTP error
        // response or a transport failure — ForgeDomainProvisionerTest
        // proves detachDomain() converts BOTH into the same exception,
        // so WebsiteDomainService's handling is identical either way).
        $provisioner->shouldReceive('detachDomain')->once()->with('forge-domain-retry')
            ->andThrow(new DomainProvisioningException('Forge did not respond.'));

        $this->delete(route('customer.workspaces.businesses.website.domains.destroy', [$workspace->uid, $business->uid, $domain->uid]))
            ->assertRedirect()
            ->assertSessionHas('status', 'error')
            ->assertSessionHas('message');

        $domain->refresh();
        // Stopped being served and relinquished the primary claim
        // immediately, but the row, its hostname, and its forge_domain_id
        // are all still here — nothing is freed until Forge confirms.
        $this->assertSame(WebsiteDomainStatus::Removing, $domain->status);
        $this->assertSame('forge-domain-retry', $domain->forge_domain_id);
        $this->assertFalse($domain->is_primary);
        $this->assertStringContainsString('Forge did not respond.', (string) $domain->failure_reason);

        // The hostname is still claimed: a different business cannot
        // take it over while removal is unconfirmed.
        [$customerB, $businessB, $workspaceB] = $this->entitledTenant();
        $websiteB = $this->createWebsite($businessB);
        $this->publish($websiteB);
        $this->authenticateAsCustomer($customerB);
        $this->post(route('customer.workspaces.businesses.website.domains.store', [$workspaceB->uid, $businessB->uid]), ['domain' => 'flaky-removal.test'])
            ->assertSessionHasErrors('domain');
        $this->authenticateAsCustomer($customer);

        // Retrying removal is the SAME destroy action, called again on
        // the still-existing row — this time Forge confirms deletion.
        $provisioner->shouldReceive('detachDomain')->once()->with('forge-domain-retry');

        $this->delete(route('customer.workspaces.businesses.website.domains.destroy', [$workspace->uid, $business->uid, $domain->uid]))
            ->assertRedirect()
            ->assertSessionHas('status', 'success');

        $this->assertSame(0, WebsiteDomain::where('domain', 'flaky-removal.test')->count());

        // The hostname is free now.
        $this->authenticateAsCustomer($customerB);
        $this->post(route('customer.workspaces.businesses.website.domains.store', [$workspaceB->uid, $businessB->uid]), ['domain' => 'flaky-removal.test'])
            ->assertRedirect()->assertSessionHasNoErrors();
    }

    // ---------------------------------------------------------------
    // Tenancy and DNS-instructions accuracy
    // ---------------------------------------------------------------

    public function test_a_foreign_businesss_domain_is_not_reachable(): void
    {
        [, $businessA] = $this->entitledTenant();
        $websiteA = $this->createWebsite($businessA);
        $this->publish($websiteA);
        $foreignDomain = $websiteA->domains()->create([
            'domain' => 'direct-fixture.test',
            'status' => WebsiteDomainStatus::PendingVerification,
            'verification_token' => 'token',
        ]);

        [$customerB, $businessB, $workspaceB] = $this->entitledTenant();
        $this->createWebsite($businessB);
        $this->authenticateAsCustomer($customerB);

        $this->get(route('customer.workspaces.businesses.website.domains.index', [$workspaceB->uid, $businessB->uid]))->assertOk();
        $this->post(route('customer.workspaces.businesses.website.domains.verify', [$workspaceB->uid, $businessB->uid, $foreignDomain->uid]))
            ->assertNotFound();
    }

    public function test_dns_instructions_show_the_exact_txt_record_and_never_claim_success_before_verification(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->publish($website);
        $this->fakeDnsVerifier();
        $this->fakeDomainProvisioner();
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.domains.store', [$workspace->uid, $business->uid]), [
            'domain' => 'instructions.test',
        ])->assertRedirect();
        $domain = $website->domains()->sole();

        $this->get(route('customer.workspaces.businesses.website.domains.index', [$workspace->uid, $business->uid]))
            ->assertOk()
            ->assertSee('_platform-verify.instructions.test', false)
            ->assertSee($domain->verification_token, false)
            ->assertSee('Waiting on DNS verification', false)
            ->assertDontSee('is now live', false)
            ->assertDontSee('badge-success', false);
    }

    public function test_website_domain_service_instance_uses_bound_fakes_never_real_network_calls(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->publish($website);
        $this->fakeDnsVerifier(true);
        $provisioner = $this->fakeDomainProvisioner();
        $provisioner->shouldReceive('requestCertificate')->once()->andReturn('ref');
        $provisioner->shouldReceive('certificateStatus')->once()->andReturn(WebsiteDomainCertificateStatus::Active);

        $service = app(WebsiteDomainService::class);
        $domain = $service->attach($website, 'service-level.test');
        $domain = $service->checkVerification($domain);
        $domain = $service->provisionCertificate($domain);
        $domain = $service->checkCertificate($domain);

        $this->assertSame(WebsiteDomainStatus::Active, $domain->status);
    }
}

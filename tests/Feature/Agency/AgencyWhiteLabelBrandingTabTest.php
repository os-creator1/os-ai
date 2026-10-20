<?php

namespace Tests\Feature\Agency;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Exceptions\Branding\AgencyWhiteLabelException;
use App\Library\Branding\AgencyAppDomainService;
use App\Library\Branding\AgencyWhiteLabelManager;
use App\Library\Branding\ClientWorkspaceBrandResolver;
use App\Library\Support\RequestScopedCache;
use App\Library\Website\Domains\DnsVerifier;
use App\Models\AgencyWhiteLabelChange;
use App\Models\AgencyWhiteLabelSetting;
use App\Models\Customer;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * White Label — Branding tab: the extra identity fields and image slots, the
 * live-preview surface, the verified-but-not-live app domain, and the proof that
 * none of it crosses an Agency boundary or touches a direct platform customer.
 */
class AgencyWhiteLabelBrandingTabTest extends TestCase
{
    use CreatesCustomerContextFixtures;
    use RefreshDatabase;

    private bool $brandingDirExisted = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdminId();
        $this->ensureRequiredAppConfigRowsExist();
        $this->brandingDirExisted = is_dir(public_path('images/branding/agency'));
    }

    protected function tearDown(): void
    {
        if (! $this->brandingDirExisted && is_dir(public_path('images/branding/agency'))) {
            File::deleteDirectory(public_path('images/branding/agency'));
        }

        Mockery::close();
        parent::tearDown();
    }

    /** @return array{0: Workspace, 1: Customer} */
    private function agency(string $name = 'Northwind Agency'): array
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, $name . ' Business', $name);

        return [$workspace, $owner];
    }

    private function url(string $name, Workspace $agency, array $query = []): string
    {
        return route('customer.workspaces.agency.white-label.' . $name, ['workspaceUid' => $agency->uid] + $query);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'display_name' => 'Northwind Digital',
            'tagline' => 'Growth, handled.',
            'accent_color' => '#1a73e8',
            'secondary_color' => '#0f172a',
            'support_email' => 'help@northwind.example',
            'support_url' => 'https://help.northwind.example',
            'legal_name' => 'Northwind Digital LLC',
            'social' => ['linkedin' => 'https://www.linkedin.com/company/northwind'],
            'is_enabled' => '1',
        ], $overrides);
    }

    private function saved(Workspace $agency, Customer $owner, array $overrides = []): AgencyWhiteLabelSetting
    {
        return app(AgencyWhiteLabelManager::class)->save((int) $owner->user_id, $agency, $this->payload($overrides) + ['social_links' => $this->payload($overrides)['social']]);
    }

    private bool $dnsFound = false;

    /** One mock for the whole test; flip $this->dnsFound to change what DNS "contains" (routes cache their controller). */
    private function fakeDns(bool $found): void
    {
        $this->dnsFound = $found;

        if (! $this->app->bound('wl.dns.mocked')) {
            $dns = Mockery::mock(DnsVerifier::class);
            $dns->shouldReceive('hasTxtRecord')->andReturnUsing(fn () => $this->dnsFound);
            $this->app->instance(DnsVerifier::class, $dns);
            $this->app->instance('wl.dns.mocked', true);
        }
    }

    public function test_the_full_identity_is_saved_through_the_form_and_restored_on_the_page(): void
    {
        [$agency, $owner] = $this->agency();
        $this->authenticateAs($owner);

        $this->post($this->url('update', $agency), $this->payload() + [
            'logo_dark' => UploadedFile::fake()->image('d.png', 120, 40),
            'logo_compact' => UploadedFile::fake()->image('c.png', 64, 64),
            'favicon' => UploadedFile::fake()->image('f.png', 32, 32),
        ])->assertRedirect($this->url('show', $agency))->assertSessionHas('status', 'success');

        $s = AgencyWhiteLabelSetting::query()->sole();
        $this->assertSame('#0f172a', $s->secondary_color);
        $this->assertSame('https://help.northwind.example', $s->support_url);
        $this->assertSame('Northwind Digital LLC', $s->legal_name);
        $this->assertSame(['linkedin' => 'https://www.linkedin.com/company/northwind'], $s->social_links);

        $dir = AgencyWhiteLabelManager::directoryFor($agency);
        foreach (['logo_dark_path', 'logo_compact_path', 'favicon_path'] as $column) {
            $this->assertStringStartsWith($dir . '/', $s->{$column});
            $this->assertFileExists(public_path($s->{$column}));
        }

        $html = $this->get($this->url('show', $agency))->assertOk()->getContent();
        $this->assertStringContainsString('data-role="white-label-preview"', $html);
        $this->assertStringContainsString('value="#0f172a"', $html);
        $this->assertStringContainsString('Northwind Digital LLC', $html);
    }

    public function test_invalid_colours_links_and_images_are_rejected_and_write_nothing(): void
    {
        [$agency, $owner] = $this->agency();
        $this->authenticateAs($owner);

        foreach ([
            ['secondary_color' => 'red'],
            ['accent_color' => '#12'],
            ['support_url' => 'http://insecure.example'],
            ['support_url' => 'javascript:alert(1)'],
            ['legal_name' => '<b>x</b>'],
            ['social' => ['linkedin' => 'https://evil.example/in/x']],
            ['social' => ['x' => 'https://x.com.evil.example/a']],
        ] as $bad) {
            $this->post($this->url('update', $agency), $this->payload($bad));
            $this->assertTrue(session('status') === 'error' || (session('errors')?->any() ?? false), 'accepted: ' . json_encode($bad));
            $this->assertNotSame('success', session('status'));
        }

        $this->post($this->url('update', $agency), $this->payload() + ['favicon' => UploadedFile::fake()->image('big.png', 2000, 2000)])
            ->assertSessionHasErrors('favicon');
        $this->post($this->url('update', $agency), $this->payload() + ['favicon' => UploadedFile::fake()->createWithContent('f.png', '<svg xmlns="http://www.w3.org/2000/svg"/>')])
            ->assertSessionHasErrors('favicon');

        $this->assertSame(0, AgencyWhiteLabelSetting::query()->count());
        $this->assertFalse(is_dir(public_path(AgencyWhiteLabelManager::directoryFor($agency))));
    }

    public function test_a_save_that_omits_the_new_fields_never_clears_them(): void
    {
        [$agency, $owner] = $this->agency();
        $this->saved($agency, $owner);
        $this->authenticateAs($owner);

        $this->post($this->url('update', $agency), ['display_name' => 'Northwind Digital', 'is_enabled' => '1', 'accent_color' => '#1a73e8', 'tagline' => 'Growth, handled.', 'support_email' => 'help@northwind.example']);

        $s = AgencyWhiteLabelSetting::query()->sole();
        $this->assertSame('#0f172a', $s->secondary_color);
        $this->assertSame('Northwind Digital LLC', $s->legal_name);
    }

    public function test_images_are_replaced_removed_and_a_shared_file_survives_while_still_in_use(): void
    {
        [$agency, $owner] = $this->agency();
        $m = app(AgencyWhiteLabelManager::class);
        $img = imagecreatetruecolor(64, 64);
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();

        $s = $m->save((int) $owner->user_id, $agency, $this->payload(), null, false, [
            'logo_compact' => UploadedFile::fake()->createWithContent('a.png', $bytes),
            'favicon' => UploadedFile::fake()->createWithContent('b.png', $bytes),
        ]);
        $this->assertNotSame($s->logo_compact_path, $s->favicon_path);

        $m->save((int) $owner->user_id, $agency, $this->payload(), null, false, [], ['favicon']);
        $s->refresh();
        $this->assertNull($s->favicon_path);
        $this->assertFileExists(public_path($s->logo_compact_path));

        $m->save((int) $owner->user_id, $agency, $this->payload(), null, false, [], ['logo_compact']);
        $this->assertNull($s->refresh()->logo_compact_path);
    }

    public function test_a_non_owner_cannot_edit_brand_or_domain(): void
    {
        [$agency, $owner] = $this->agency();
        $this->saved($agency, $owner);
        $member = $this->createCustomer();
        $this->member($agency, $member->user, \App\Enums\Workspace\WorkspaceMembershipRole::Admin);
        $this->authenticateAs($member);

        $this->post($this->url('update', $agency), $this->payload(['display_name' => 'Hijack']))->assertSessionHas('status', 'error');
        $this->post($this->url('domain.attach', $agency), ['app_domain' => 'app.hijack.example'])->assertSessionHas('status', 'error');
        $this->assertSame('Northwind Digital', AgencyWhiteLabelSetting::query()->sole()->display_name);
        $this->assertNull(AgencyWhiteLabelSetting::query()->sole()->app_domain);
    }

    public function test_agency_a_cannot_read_or_write_agency_b(): void
    {
        [$a, $ownerA] = $this->agency('Agency A');
        [$b, $ownerB] = $this->agency('Agency B');
        $this->saved($b, $ownerB, ['display_name' => 'Brand B']);
        $this->authenticateAs($ownerA);

        $this->get($this->url('show', $b))->assertNotFound();
        $this->post($this->url('update', $b), $this->payload(['display_name' => 'Taken']))->assertNotFound();
        $this->post($this->url('domain.attach', $b), ['app_domain' => 'app.b.example'])->assertNotFound();
        $this->assertSame('Brand B', AgencyWhiteLabelSetting::query()->sole()->display_name);
    }

    public function test_the_landing_tab_opens_a_draft_editor_for_the_owner_and_a_status_card_for_the_team(): void
    {
        [$agency, $owner] = $this->agency();
        $this->authenticateAs($owner);

        $html = $this->get($this->url('show', $agency, ['tab' => 'landing']))->assertOk()->getContent();
        $this->assertStringContainsString('data-role="landing-editor"', $html);
        $this->assertStringContainsString('>Draft<', $html);
        $this->assertStringNotContainsString('>Published<', $html);
        $this->assertStringNotContainsString('data-role="white-label-form"', $html);

        // A team member sees the same site read-only; before any site exists they only get honest status.
        [$other, $otherOwner] = $this->agency('Agency B');
        $member = $this->createCustomer();
        $this->member($other, $member->user, \App\Enums\Workspace\WorkspaceMembershipRole::Admin);
        $this->authenticateAs($member);
        $status = $this->get($this->url('show', $other, ['tab' => 'landing']))->assertOk()->getContent();
        $this->assertStringContainsString('data-role="landing-status"', $status);
        $this->assertStringContainsString('Not published', $status);
        $this->assertStringContainsString('Stripe account for selling to customers: not connected', $status);
    }

    public function test_app_domain_is_pending_until_dns_proves_ownership_and_is_never_reported_live(): void
    {
        [$agency, $owner] = $this->agency();
        $this->saved($agency, $owner);
        $this->fakeDns(false);
        $this->authenticateAs($owner);

        $this->post($this->url('domain.attach', $agency), ['app_domain' => 'App.Northwind.Example'])->assertSessionHas('status', 'success');
        $s = AgencyWhiteLabelSetting::query()->sole();
        $this->assertSame('app.northwind.example', $s->app_domain);
        $this->assertSame('pending_verification', $s->app_domain_status);
        $this->assertSame(40, strlen($s->app_domain_token));

        $this->fakeDns(false);
        $this->post($this->url('domain.check', $agency))->assertSessionHas('status', 'error');
        $this->assertSame('pending_verification', $s->refresh()->app_domain_status);

        $this->fakeDns(true);
        $this->post($this->url('domain.check', $agency))->assertSessionHas('status', 'success');
        $this->assertSame('verified', $s->refresh()->app_domain_status);
        $this->assertNotNull($s->app_domain_verified_at);

        $html = $this->get($this->url('show', $agency))->getContent();
        $this->assertStringContainsString('Ownership verified', $html);
        $this->assertStringContainsString('data-role="domain-not-live"', $html);

        // Verification is not activation: the brand lookup knows the host, but the platform's trusted-host list does not.
        $this->assertSame('Northwind Digital', app(\App\Library\Branding\AgencyBrandResolver::class)->resolve(\Illuminate\Http\Request::create('https://app.northwind.example/login'))?->displayName);
        $this->assertStringNotContainsString('northwind', implode('|', array_map('strval', app(\App\Http\Middleware\TrustHosts::class)->hosts())));
    }

    public function test_invalid_reserved_and_duplicate_domains_are_refused(): void
    {
        [$a, $ownerA] = $this->agency('Agency A');
        [$b, $ownerB] = $this->agency('Agency B');
        $this->saved($a, $ownerA);
        $this->saved($b, $ownerB, ['display_name' => 'B']);
        $svc = app(AgencyAppDomainService::class);
        $platform = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        foreach (['https://app.x.example', 'app.x.example/path', 'localhost', '10.0.0.1', 'nodot', 'bad_host.example', $platform, 'login.' . $platform] as $bad) {
            try {
                $svc->attach((int) $ownerA->user_id, $a, $bad);
                $this->fail("accepted {$bad}");
            } catch (AgencyWhiteLabelException $e) {
                $this->assertContains($e->reason, ['invalid_domain', 'domain_reserved']);
            }
        }

        $svc->attach((int) $ownerA->user_id, $a, 'app.shared.example');
        $this->expectException(AgencyWhiteLabelException::class);
        $svc->attach((int) $ownerB->user_id, $b, 'app.shared.example');
    }

    public function test_removing_a_domain_clears_it_and_changing_it_resets_verification(): void
    {
        [$agency, $owner] = $this->agency();
        $this->saved($agency, $owner);
        $this->fakeDns(true);
        $svc = app(AgencyAppDomainService::class);

        $svc->attach((int) $owner->user_id, $agency, 'app.one.example');
        $svc->check((int) $owner->user_id, $agency);
        $this->assertSame('verified', AgencyWhiteLabelSetting::query()->sole()->app_domain_status);

        $svc->attach((int) $owner->user_id, $agency, 'app.two.example');
        $s = AgencyWhiteLabelSetting::query()->sole();
        $this->assertSame('pending_verification', $s->app_domain_status);
        $this->assertNull($s->app_domain_verified_at);

        $svc->remove((int) $owner->user_id, $agency);
        $this->assertNull($s->refresh()->app_domain);
        $this->assertSame('none', $s->app_domain_status);
    }

    public function test_a_managed_clients_tab_gets_the_agencys_favicon_and_a_direct_customer_does_not(): void
    {
        [$agency, $owner] = $this->agency();
        $fixture = $this->createAgencyManagedClient($agency, 'Alpha Business', 'Alpha');
        $this->assignTier($fixture['clientWorkspace'], WorkspacePlanTier::Growth);
        $s = app(AgencyWhiteLabelManager::class)->save((int) $owner->user_id, $agency, $this->payload(), null, false, [
            'favicon' => UploadedFile::fake()->image('f.png', 32, 32),
        ]);
        [$directOwner] = $this->tenant(WorkspacePlanTier::Core, 'Solo Business', 'Solo');

        $this->authenticateAs($fixture['clientOwner']);
        app(RequestScopedCache::class)->flush();
        $this->assertStringContainsString($s->favicon_path, $this->get(route('user.home'))->assertOk()->getContent());

        $this->authenticateAs($directOwner);
        app(RequestScopedCache::class)->flush();
        $direct = $this->get(route('user.home'))->assertOk()->getContent();
        $this->assertStringNotContainsString($s->favicon_path, $direct);
        $this->assertStringNotContainsString('Northwind Digital', $direct);

        $brand = app(ClientWorkspaceBrandResolver::class)->forClientWorkspaceId((int) $fixture['clientWorkspace']->id);
        $this->assertSame('#0f172a', $brand->secondaryColor);
    }

    public function test_every_new_field_change_is_audited(): void
    {
        [$agency, $owner] = $this->agency();
        $this->saved($agency, $owner);
        $this->saved($agency, $owner, ['secondary_color' => '#112233']);

        $last = AgencyWhiteLabelChange::query()->orderByDesc('id')->first();
        $this->assertSame(['secondary_color'], array_keys($last->changes));
        $this->assertSame(['#0f172a', '#112233'], $last->changes['secondary_color']);
    }
}

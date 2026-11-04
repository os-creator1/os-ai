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

        $svc->attach((int) $owner->user_id, $agency, 'one.example');
        $svc->check((int) $owner->user_id, $agency);
        $this->assertSame('verified', AgencyWhiteLabelSetting::query()->sole()->app_domain_status);

        $svc->attach((int) $owner->user_id, $agency, 'two.example');
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

    // ------------------------------------------------- one hostname, one owner (website domains + app domains)

    private function publishedWebsiteOf(Workspace $workspace): \App\Models\Website
    {
        $business = \App\Models\Business::query()->where('workspace_id', $workspace->id)->firstOrFail();
        $website = \App\Models\Website::create(['business_id' => $business->id, 'name' => 'Site', 'status' => \App\Enums\Website\WebsiteStatus::Draft]);
        $website->published_revision_id = 1; // attach() only checks that the site has been published once

        return $website;
    }

    private function attachWebsiteDomain(\App\Models\Website $website, string $domain): ?string
    {
        config(['services.forge.a_record_ip' => '203.0.113.10', 'services.forge.cname_zone' => 'domains.example-platform.test']);

        try {
            app(\App\Library\Website\Domains\WebsiteDomainService::class)->attach($website, $domain);

            return null;
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $e->errors()['domain'][0];
        }
    }

    private function attachAppDomain(Workspace $agency, Customer $owner, string $domain): ?string
    {
        try {
            app(AgencyAppDomainService::class)->attach((int) $owner->user_id, $agency, $domain);

            return null;
        } catch (AgencyWhiteLabelException $e) {
            return $e->reason;
        }
    }

    public function test_a_hostname_cannot_be_claimed_by_a_website_domain_and_an_agency_app_domain_in_either_direction(): void
    {
        [$a, $ownerA] = $this->agency('Agency A');
        [$b, $ownerB] = $this->agency('Agency B');
        $this->saved($a, $ownerA);
        $this->saved($b, $ownerB, ['display_name' => 'B']);
        $siteA = $this->publishedWebsiteOf($a);
        $siteB = $this->publishedWebsiteOf($b);

        // Agency app domain first → a website cannot take it (any workspace).
        $this->assertNull($this->attachAppDomain($a, $ownerA, 'first.example'));
        $this->assertNotNull($this->attachWebsiteDomain($siteA, 'first.example'));
        $this->assertNotNull($this->attachWebsiteDomain($siteB, 'first.example'));
        $this->assertSame('domain_taken', $this->attachAppDomain($b, $ownerB, 'first.example'));

        // Website domain first → no Agency may take it as an app domain, including the owner's own website's workspace.
        $this->assertNull($this->attachWebsiteDomain($siteA, 'second.example'));
        $this->assertSame('domain_taken', $this->attachAppDomain($b, $ownerB, 'second.example'));
        $this->assertSame('domain_taken', $this->attachAppDomain($a, $ownerA, 'second.example'));
        $this->assertNotNull($this->attachWebsiteDomain($siteB, 'second.example'));

        // Each hostname has exactly one reservation and one owner row.
        $this->assertSame(3, \Illuminate\Support\Facades\DB::table('domain_reservations')->count(), 'the app domain, plus a website domain and its www');
        $this->assertSame(1, \App\Models\WebsiteDomain::query()->count());
        $this->assertSame(1, AgencyWhiteLabelSetting::query()->whereNotNull('app_domain')->count());
    }

    public function test_the_reservation_itself_decides_a_simultaneous_claim(): void
    {
        [$a, $ownerA] = $this->agency('Agency A');
        $this->saved($a, $ownerA);
        $site = $this->publishedWebsiteOf($a);
        $reservations = app(\App\Library\Domains\DomainReservations::class);

        $one = $site->domains()->create(['domain' => 'race.example', 'is_primary' => true, 'status' => \App\Enums\Website\WebsiteDomainStatus::PendingVerification, 'verification_token' => 'a']);
        $two = $site->domains()->create(['domain' => 'race-2.example', 'is_primary' => false, 'status' => \App\Enums\Website\WebsiteDomainStatus::PendingVerification, 'verification_token' => 'b']);

        // Both requests passed their "is it free?" look-ups; only the first insert can win, whichever service it is.
        $reservations->claim('race.example', \App\Library\Domains\DomainReservations::WEBSITE, (int) $one->id);

        try {
            $reservations->claim('race.example', \App\Library\Domains\DomainReservations::WEBSITE, (int) $two->id);
            $this->fail('the second simultaneous claim must lose');
        } catch (\App\Library\Domains\DomainUnavailableException $e) {
            $this->assertSame('domain_taken', $e->reason);
        }

        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('domain_reservations')->where('domain', 'race.example')->count());
    }

    public function test_released_hostnames_can_be_claimed_again(): void
    {
        [$a, $ownerA] = $this->agency('Agency A');
        [$b, $ownerB] = $this->agency('Agency B');
        $this->saved($a, $ownerA);
        $this->saved($b, $ownerB, ['display_name' => 'B']);
        $siteB = $this->publishedWebsiteOf($b);

        // Removing an app domain frees it; re-pointing frees the OLD name.
        $this->assertNull($this->attachAppDomain($a, $ownerA, 'one.example'));
        $this->assertNull($this->attachAppDomain($a, $ownerA, 'two.example'));
        $this->assertNull($this->attachWebsiteDomain($siteB, 'one.example'));
        app(AgencyAppDomainService::class)->remove((int) $ownerA->user_id, $a);
        $this->assertNull($this->attachWebsiteDomain($siteB, 'two.example'));

        // Deleting a website domain frees it for an Agency.
        \App\Models\WebsiteDomain::query()->where('domain', 'one.example')->firstOrFail()->delete();
        $this->assertNull($this->attachAppDomain($a, $ownerA, 'one.example'));

        // A reservation orphaned by a database cascade (no model event) never blocks the name for ever.
        \Illuminate\Support\Facades\DB::table('website_domains')->where('domain', 'two.example')->delete();
        $this->assertNull($this->attachAppDomain($b, $ownerB, 'two.example'));
    }

    public function test_platform_hostnames_cannot_be_claimed_as_website_domains_either(): void
    {
        [$a, $ownerA] = $this->agency('Agency A');
        $this->saved($a, $ownerA);
        $site = $this->publishedWebsiteOf($a);
        $platform = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        foreach ([$platform, 'login.'.$platform] as $host) {
            $this->assertNotNull($this->attachWebsiteDomain($site, $host), $host);
        }

        $this->assertSame(0, \App\Models\WebsiteDomain::query()->count());
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('domain_reservations')->count());
    }

    // ------------------------------------------------- the back-fill respects what already exists

    private function migration(): object
    {
        return require base_path('database/migrations/2026_11_27_100000_create_domain_reservations_table.php');
    }

    public function test_the_backfill_reserves_every_existing_domain_for_its_current_owner_without_displacing_anyone(): void
    {
        [$a, $ownerA] = $this->agency('Agency A');
        [$b, $ownerB] = $this->agency('Agency B');
        $this->saved($a, $ownerA);
        $this->saved($b, $ownerB, ['display_name' => 'B']);
        $site = $this->publishedWebsiteOf($a);

        // Records as they were BEFORE the reservation system: rows only, no reservations.
        $web = $site->domains()->create(['domain' => 'WWW.Legacy.example', 'is_primary' => true, 'status' => \App\Enums\Website\WebsiteDomainStatus::PendingVerification, 'verification_token' => 't']);
        $settingA = AgencyWhiteLabelSetting::query()->where('agency_workspace_id', $a->id)->firstOrFail();
        $settingB = AgencyWhiteLabelSetting::query()->where('agency_workspace_id', $b->id)->firstOrFail();
        $settingA->forceFill(['app_domain' => 'app.legacy.example'])->save();
        // A legacy DUPLICATE: Agency B's app domain is already a website domain's name.
        $settingB->forceFill(['app_domain' => 'www.legacy.example'])->save();
        \Illuminate\Support\Facades\DB::table('domain_reservations')->delete();

        \Illuminate\Support\Facades\Log::spy();
        $this->migration()->backfill();
        $this->migration()->backfill(); // idempotent: running it twice changes nothing

        $rows = \Illuminate\Support\Facades\DB::table('domain_reservations')->orderBy('domain')->get(['domain', 'owner_type', 'owner_id'])->map(fn ($r) => (array) $r)->all();
        $this->assertSame([
            ['domain' => 'app.legacy.example', 'owner_type' => 'agency_app_domain', 'owner_id' => $settingA->id],
            ['domain' => 'www.legacy.example', 'owner_type' => 'website_domain', 'owner_id' => $web->id],
        ], $rows, 'the website domain keeps the name; the duplicate is not given or taken');
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->with(\Mockery::pattern('/already reserved/'), \Mockery::type('array'));

        // Nobody's own record was touched, and the names are now protected from new claims.
        $this->assertSame('www.legacy.example', $settingB->refresh()->app_domain);
        $this->assertSame(1, \App\Models\WebsiteDomain::query()->count());
        $this->assertSame('domain_taken', $this->attachAppDomain($b, $ownerB, 'app.legacy.example'));
        $this->assertNotNull($this->attachWebsiteDomain($this->publishedWebsiteOf($b), 'app.legacy.example'));

        // Uniqueness holds at the database for a second owner of an existing name.
        try {
            \Illuminate\Support\Facades\DB::table('domain_reservations')->insert(['domain' => 'app.legacy.example', 'owner_type' => 'website_domain', 'owner_id' => 999999, 'created_at' => now(), 'updated_at' => now()]);
            $this->fail('the unique index must refuse a second owner');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertTrue(true);
        }

        // Back-filled reservations are released like any other.
        $web->delete();
        $this->assertFalse(app(\App\Library\Domains\DomainReservations::class)->isReserved('www.legacy.example'));
        app(AgencyAppDomainService::class)->remove((int) $ownerA->user_id, $a);
        $this->assertFalse(app(\App\Library\Domains\DomainReservations::class)->isReserved('app.legacy.example'));
    }
}

<?php

namespace Tests\Feature\Legal;

use App\Library\Legal\LegalCompany;
use App\Models\AppConfig;
use App\Models\LegalCompanyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Settings\Concerns\SettingsTestHelpers;
use Tests\TestCase;

/** Platform Owner → Settings → Legal Company Information. */
class LegalCompanySettingsTest extends TestCase
{
    use RefreshDatabase;
    use SettingsTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        config(['marketing.enabled' => true, 'marketing.hosts' => []]);
        $this->consumeSuperAdminId();
        $this->ensureRequiredAppConfigRowsExist();
    }

    private function payload(array $overrides = []): array
    {
        $current = app(LegalCompany::class)->current();

        return array_merge([
            'tab' => 'legal',
            'base_revision' => $current['revision'],
            'brand_name' => 'MotionGrove',
            'legal_entity' => 'Jazmin medija, MB',
            'company_number' => '306304073',
            'registered_address' => 'Virtual Office 5, Gedimino pr. 1, LT-01103 Vilnius, Lithuania',
            'country' => 'Lithuania',
            'legal_email' => 'legal@getmotiongrove.com',
        ], $overrides);
    }

    private function revisions(): int
    {
        return LegalCompanyProfile::query()->count();
    }

    private function customer(bool $agency = false): User
    {
        $user = User::create([
            'first_name' => $agency ? 'Agency' : 'Customer', 'last_name' => 'User',
            'email' => 'c'.uniqid('', true).'@example.test', 'status' => true,
            'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $this->withSession(['permissions' => collect(['access backend'])]);
        $this->actingAs($user);

        return $user;
    }

    public function test_initial_values_are_the_verified_company_details(): void
    {
        $this->assertSame(1, $this->revisions());
        $this->assertSame([
            'brand_name' => 'MotionGrove', 'legal_entity' => 'Jazmin medija, MB', 'company_number' => '306304073',
            'registered_address' => 'Bičių g. 23, LT-11111 Vilnius, Lithuania', 'country' => 'Lithuania',
            'legal_email' => 'legal@getmotiongrove.com',
        ], array_intersect_key(app(LegalCompany::class)->current(), array_flip(LegalCompany::FIELDS)));
    }

    public function test_platform_owner_edits_the_registered_address_and_it_persists_and_goes_public(): void
    {
        $this->actingAsAdmin(['access backend']);

        $this->get('/admin/settings?tab=legal')->assertOk()
            ->assertSee('Legal Company Information')->assertSee('Bičių g. 23, LT-11111 Vilnius, Lithuania');

        $this->post(route('admin.platform-owner.legal-company.save'), $this->payload())
            ->assertRedirect(route('admin.settings.general', ['tab' => 'legal']))
            ->assertSessionHas('status', 'success');

        $this->get('/admin/settings?tab=legal')->assertOk()->assertSee('Virtual Office 5, Gedimino pr. 1');

        foreach (['terms-of-service', 'privacy-policy', 'agency-terms', 'security'] as $slug) {
            $this->get('/'.$slug)->assertOk()
                ->assertSee('Virtual Office 5, Gedimino pr. 1, LT-01103 Vilnius, Lithuania')
                ->assertDontSee('Bičių g. 23');
        }
        $this->assertSame(2, $this->revisions());
        $this->assertDatabaseHas('legal_company_profiles', ['revision' => 1, 'registered_address' => 'Bičių g. 23, LT-11111 Vilnius, Lithuania']);
        $this->assertDatabaseHas('platform_admin_actions', ['action' => 'legal_company.updated', 'subject_ref' => '2']);
    }

    public function test_customer_and_agency_accounts_cannot_change_it_and_nothing_is_written(): void
    {
        foreach ([false, true] as $agency) {
            $this->customer($agency);
            $this->post(route('admin.platform-owner.legal-company.save'), $this->payload())->assertStatus(401);
        }
        $this->assertSame(1, $this->revisions());
        $this->assertSame('Bičių g. 23, LT-11111 Vilnius, Lithuania', app(LegalCompany::class)->current()['registered_address']);
    }

    public function test_guest_cannot_change_it(): void
    {
        $this->post(route('admin.platform-owner.legal-company.save'), $this->payload())->assertStatus(401);
        $this->assertSame(1, $this->revisions());
    }

    public function test_settings_tab_is_not_shown_to_a_non_owner_settings_admin(): void
    {
        $this->customer();
        $this->get('/admin/settings')->assertStatus(401);
    }

    public function test_service_refuses_a_non_administrator_actor_directly(): void
    {
        $customer = $this->customer();

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        try {
            app(LegalCompany::class)->update($customer->id, $this->payload(), 1);
        } finally {
            $this->assertSame(1, $this->revisions());
        }
    }

    public function test_invalid_submissions_persist_nothing(): void
    {
        $this->actingAsAdmin(['access backend']);

        foreach ([
            ['registered_address' => ''],
            ['registered_address' => str_repeat('a', 501)],
            ['legal_email' => 'not-an-email'],
            ['legal_entity' => ''],
            ['company_number' => ''],
            ['brand_name' => str_repeat('b', 121)],
        ] as $bad) {
            $field = array_key_first($bad);
            $this->post(route('admin.platform-owner.legal-company.save'), $this->payload($bad))->assertSessionHasErrors($field);
        }
        $this->assertSame(1, $this->revisions());
    }

    public function test_international_addresses_are_not_rejected_for_format(): void
    {
        $this->actingAsAdmin(['access backend']);

        $this->post(route('admin.platform-owner.legal-company.save'), $this->payload([
            'registered_address' => "PO Box 12, 1 Rue de l'Église\nGenève 1204, Suisse",
        ]))->assertSessionHasNoErrors();

        $this->assertStringContainsString('Genève 1204', app(LegalCompany::class)->current()['registered_address']);
    }

    public function test_a_stale_form_cannot_overwrite_a_newer_save(): void
    {
        $this->actingAsAdmin(['access backend']);
        $stale = $this->payload();

        $this->post(route('admin.platform-owner.legal-company.save'), $this->payload(['registered_address' => 'Newer Address 1, Vilnius']))
            ->assertSessionHasNoErrors();
        $this->post(route('admin.platform-owner.legal-company.save'), $stale + ['registered_address' => 'Stale Address 9'])
            ->assertSessionHasErrors('registered_address');

        $this->assertSame('Newer Address 1, Vilnius', app(LegalCompany::class)->current()['registered_address']);
        $this->assertSame(2, $this->revisions());
    }

    public function test_an_unchanged_save_creates_no_revision(): void
    {
        $this->actingAsAdmin(['access backend']);

        $this->post(route('admin.platform-owner.legal-company.save'), $this->payload([
            'registered_address' => 'Bičių g. 23, LT-11111 Vilnius, Lithuania',
        ]))->assertSessionHas('status', 'success');

        $this->assertSame(1, $this->revisions());
    }

    public function test_saving_touches_only_the_legal_company_tables_and_no_tenant_data(): void
    {
        $admin = $this->actingAsAdmin(['access backend']);
        $before = AppConfig::query()->where('setting', 'company_address')->value('value');
        $tables = [];
        DB::listen(function ($q) use (&$tables) {
            if (preg_match('/^\s*(insert into|update|delete from)\s+[`"]?(\w+)/i', $q->sql, $m)) {
                $tables[] = $m[2];
            }
        });

        app(LegalCompany::class)->update($admin->id, $this->payload(), 1);

        $this->assertEqualsCanonicalizing(['legal_company_profiles', 'platform_admin_actions'], array_values(array_unique($tables)));
        $this->assertSame($before, AppConfig::query()->where('setting', 'company_address')->value('value'));
    }
}

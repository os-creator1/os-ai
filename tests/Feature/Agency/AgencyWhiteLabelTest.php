<?php

namespace Tests\Feature\Agency;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Exceptions\Branding\AgencyWhiteLabelException;
use App\Library\Branding\AgencyWhiteLabelManager;
use App\Library\Branding\ClientWorkspaceBrandResolver;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Support\RequestScopedCache;
use App\Library\Workspace\AgencyClientRelationshipManager;
use App\Models\AgencyWhiteLabelChange;
use App\Models\AgencyWhiteLabelSetting;
use App\Models\Customer;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Agency V1 completion, acceptance items 7, 11 and 12 — White Label.
 *
 * Branding belongs to the Agency; a client renders the managing Agency's brand
 * only through the PERSISTED relationship; another Agency can never read or
 * use it; ending the relationship (or the Agency losing eligibility or the
 * entitlement) returns the client to platform branding; and an upload is
 * validated and bounded.
 */
class AgencyWhiteLabelTest extends TestCase
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
        // Test uploads are real files under public/ — never leave them behind.
        if (! $this->brandingDirExisted && is_dir(public_path('images/branding/agency'))) {
            File::deleteDirectory(public_path('images/branding/agency'));
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------- fixtures

    /** @return array{0: Workspace, 1: Customer} */
    private function agency(string $name = 'Northwind Agency'): array
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, $name . ' Business', $name);

        return [$workspace, $owner];
    }

    /** @return array{0: Workspace, 1: Customer} a managed client and its owner */
    private function client(Workspace $agency, string $name = 'Alpha Dental'): array
    {
        $fixture = $this->createAgencyManagedClient($agency, $name . ' Business', $name);
        $this->assignTier($fixture['clientWorkspace'], WorkspacePlanTier::Growth);

        return [$fixture['clientWorkspace']->fresh(), $fixture['clientOwner']];
    }

    private function showUrl(Workspace $agency): string
    {
        return route('customer.workspaces.agency.white-label.show', $agency->uid);
    }

    private function updateUrl(Workspace $agency): string
    {
        return route('customer.workspaces.agency.white-label.update', $agency->uid);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'display_name' => 'Northwind Digital',
            'tagline' => 'Growth, handled.',
            'accent_color' => '#1a73e8',
            'support_email' => 'help@northwind.example',
            'is_enabled' => '1',
        ], $overrides);
    }

    private function manager(): AgencyWhiteLabelManager
    {
        return app(AgencyWhiteLabelManager::class);
    }

    private function flushCaches(): void
    {
        app(RequestScopedCache::class)->flush();
    }

    private function enable(Workspace $agency, Customer $owner, array $overrides = [], ?UploadedFile $logo = null): AgencyWhiteLabelSetting
    {
        return $this->manager()->save((int) $owner->user_id, $agency, $this->payload($overrides), $logo);
    }

    private function homeHtmlFor(Customer $customer): string
    {
        $this->authenticateAs($customer);
        $this->flushCaches();

        return $this->get(route('user.home'))->assertOk()->getContent();
    }

    // ------------------------------------------------------------ owner writes

    public function test_the_owner_saves_a_brand_and_the_change_is_audited_with_the_actor(): void
    {
        [$agency, $owner] = $this->agency();
        $this->authenticateAs($owner);

        $this->post($this->updateUrl($agency), $this->payload())
            ->assertRedirect($this->showUrl($agency))
            ->assertSessionHas('status', 'success');

        $setting = AgencyWhiteLabelSetting::query()->where('agency_workspace_id', $agency->id)->sole();
        $this->assertSame('Northwind Digital', $setting->display_name);
        $this->assertSame('#1a73e8', $setting->accent_color);
        $this->assertTrue($setting->is_enabled);
        $this->assertSame((int) $owner->user_id, (int) $setting->updated_by_user_id);

        $audit = AgencyWhiteLabelChange::query()->where('agency_workspace_id', $agency->id)->sole();
        $this->assertSame('created', $audit->change_type);
        $this->assertSame((int) $owner->user_id, (int) $audit->changed_by_user_id);
        $this->assertSame([null, 'Northwind Digital'], $audit->changes['display_name']);

        $this->get($this->showUrl($agency))->assertOk()->assertSee('Northwind Digital');
    }

    public function test_resubmitting_identical_values_is_a_no_op_with_no_new_audit_row(): void
    {
        [$agency, $owner] = $this->agency();
        $this->authenticateAs($owner);

        $this->post($this->updateUrl($agency), $this->payload());
        $updatedAt = AgencyWhiteLabelSetting::query()->sole()->updated_at;

        // A double click / browser retry.
        $this->post($this->updateUrl($agency), $this->payload());
        $this->post($this->updateUrl($agency), $this->payload());

        $this->assertSame(1, AgencyWhiteLabelSetting::query()->count());
        $this->assertSame(1, AgencyWhiteLabelChange::query()->count());
        $this->assertTrue($updatedAt->equalTo(AgencyWhiteLabelSetting::query()->sole()->updated_at));
    }

    public function test_audit_rows_name_what_actually_changed(): void
    {
        [$agency, $owner] = $this->agency();
        $this->enable($agency, $owner);

        $this->enable($agency, $owner, ['display_name' => 'Northwind Studio']);
        $this->enable($agency, $owner, ['display_name' => 'Northwind Studio', 'is_enabled' => '0']);
        $this->enable($agency, $owner, ['display_name' => 'Northwind Studio', 'is_enabled' => '1']);

        $types = AgencyWhiteLabelChange::query()->orderBy('id')->pluck('change_type')->all();
        $this->assertSame(['created', 'updated', 'disabled', 'enabled'], $types);

        $updated = AgencyWhiteLabelChange::query()->where('change_type', 'updated')->sole();
        $this->assertSame(['Northwind Digital', 'Northwind Studio'], $updated->changes['display_name']);
        $this->assertSame(['display_name'], array_keys($updated->changes));
    }

    public function test_a_save_is_refused_for_a_non_owner_even_with_agency_authority(): void
    {
        [$agency, $owner] = $this->agency();

        foreach ([WorkspaceMembershipRole::Admin, WorkspaceMembershipRole::Staff] as $role) {
            $member = $this->createCustomer();
            $this->member($agency, $member->user, $role);
            $this->authenticateAs($member);

            // They can SEE the surface (Agency team work)...
            $this->get($this->showUrl($agency))->assertOk();

            // ...and the form is not offered.
            $this->assertStringNotContainsString('data-role="white-label-form"', $this->get($this->showUrl($agency))->getContent());

            // A hand-crafted POST is refused by the manager, not just hidden.
            $this->post($this->updateUrl($agency), $this->payload())->assertSessionHas('status', 'error');
            $this->assertSame(0, AgencyWhiteLabelSetting::query()->count(), $role->value);

            try {
                $this->manager()->save((int) $member->user_id, $agency, $this->payload());
                $this->fail('A non-owner saved a brand.');
            } catch (AgencyWhiteLabelException $e) {
                $this->assertSame(AgencyWhiteLabelException::NOT_OWNER, $e->reason);
            }
        }

        $this->assertSame(0, AgencyWhiteLabelChange::query()->count());
        $this->assertNotNull($owner);
    }

    public function test_only_agency_authority_over_this_exact_workspace_opens_the_surface(): void
    {
        [$agencyA, $ownerA] = $this->agency('Agency A');
        [$agencyB, $ownerB] = $this->agency('Agency B');
        [, $clientOwner] = $this->client($agencyA);
        $stranger = $this->createCustomer();

        $this->enable($agencyA, $ownerA);

        foreach ([$ownerB, $clientOwner, $stranger] as $actor) {
            $this->authenticateAs($actor);
            $this->get($this->showUrl($agencyA))->assertNotFound();
            $this->post($this->updateUrl($agencyA), $this->payload(['display_name' => 'Hijacked']))->assertNotFound();
        }

        $this->assertSame('Northwind Digital', AgencyWhiteLabelSetting::query()->where('agency_workspace_id', $agencyA->id)->value('display_name'));
        $this->assertSame(0, AgencyWhiteLabelSetting::query()->where('agency_workspace_id', $agencyB->id)->count());

        // Agency B's owner reaching for A through the manager is refused too.
        try {
            $this->manager()->save((int) $ownerB->user_id, $agencyA, $this->payload(['display_name' => 'Hijacked']));
            $this->fail('Agency B wrote Agency A\'s brand.');
        } catch (AgencyWhiteLabelException $e) {
            $this->assertSame(AgencyWhiteLabelException::NOT_OWNER, $e->reason);
        }
    }

    public function test_a_non_agency_workspace_and_a_locked_agency_cannot_open_or_save(): void
    {
        [, , $core] = $this->tenant(WorkspacePlanTier::Core, 'Core Business', 'Core Account');
        $coreOwner = Customer::query()->where('user_id', $core->owner_user_id)->firstOrFail();
        $this->authenticateAs($coreOwner);
        $this->get($this->showUrl($core))->assertNotFound();
        $this->post($this->updateUrl($core), $this->payload())->assertNotFound();

        [$agency, $owner] = $this->agency();
        $entitlements = app(EntitlementManager::class);
        $entitlements->enterGracePeriod($agency);
        $entitlements->lockForNonPayment($agency);
        $this->flushCaches();

        // A locked account is stopped by the account-access gate itself (a redirect to the
        // billing recovery page) before the controller runs; either way nothing is served or saved.
        $this->authenticateAs($owner);
        $this->get($this->showUrl($agency))->assertRedirect();
        $this->post($this->updateUrl($agency), $this->payload())->assertRedirect();
        $this->assertSame(0, AgencyWhiteLabelSetting::query()->count());

        try {
            $this->manager()->save((int) $owner->user_id, $agency, $this->payload());
            $this->fail('A locked Agency saved a brand.');
        } catch (AgencyWhiteLabelException $e) {
            $this->assertSame(AgencyWhiteLabelException::NOT_ELIGIBLE, $e->reason);
        }
    }

    public function test_without_the_white_label_entitlement_nothing_can_be_saved_and_the_page_says_so(): void
    {
        [$agency, $owner] = $this->agency();

        app(EntitlementManager::class)->createOrChangeOverride(
            $agency,
            PlatformFeature::WhiteLabel,
            WorkspaceEntitlementOverrideState::Deny,
            $this->platformAdminId(),
            'Test: white label denied.',
        );
        $this->flushCaches();

        $this->authenticateAs($owner);
        $html = $this->get($this->showUrl($agency))->assertOk()->getContent();
        $this->assertStringContainsString('data-role="white-label-not-entitled"', $html);
        $this->assertStringNotContainsString('data-role="white-label-form"', $html);

        try {
            $this->manager()->save((int) $owner->user_id, $agency, $this->payload());
            $this->fail('An unentitled Agency saved a brand.');
        } catch (AgencyWhiteLabelException $e) {
            $this->assertSame(AgencyWhiteLabelException::NOT_ENTITLED, $e->reason);
        }
    }

    public function test_white_label_is_not_reachable_through_view_as(): void
    {
        [$agency, $owner] = $this->agency();
        [$client] = $this->client($agency);

        $this->authenticateAs($owner);
        $this->post(route('customer.workspaces.clients.view-as', [$agency->uid, $client->uid]))->assertRedirect();

        // Inside a View As session the page and the save are refused outright.
        $this->get($this->showUrl($agency))->assertRedirect(route('user.home'));
        $this->post($this->updateUrl($agency), $this->payload())->assertRedirect(route('user.home'));
        $this->assertSame(0, AgencyWhiteLabelSetting::query()->count());
    }

    // ------------------------------------------------------------- validation

    public function test_every_text_field_is_validated_and_html_is_refused_not_stripped(): void
    {
        [$agency, $owner] = $this->agency();

        $bad = [
            'display_name' => ['<b>Bold</b>', str_repeat('n', 81), '   ', "Name\x07"],
            'tagline' => [str_repeat('t', 161), '<script>x</script>'],
            'accent_color' => ['red', '#12345', '#1234567', 'url(javascript:1)'],
            'support_email' => ['not-an-email', str_repeat('a', 200) . '@example.com'],
        ];

        foreach ($bad as $field => $values) {
            foreach ($values as $value) {
                try {
                    $this->manager()->save((int) $owner->user_id, $agency, $this->payload([$field => $value]));
                    $this->fail("Accepted {$field} = " . json_encode($value));
                } catch (AgencyWhiteLabelException $e) {
                    $this->assertContains($e->reason, [
                        AgencyWhiteLabelException::INVALID_NAME,
                        AgencyWhiteLabelException::INVALID_TAGLINE,
                        AgencyWhiteLabelException::INVALID_ACCENT,
                        AgencyWhiteLabelException::INVALID_SUPPORT_EMAIL,
                    ]);
                }
            }
        }

        $this->assertSame(0, AgencyWhiteLabelSetting::query()->count());

        // Over HTTP too: validation errors, nothing stored.
        $this->authenticateAs($owner);
        $this->post($this->updateUrl($agency), $this->payload(['accent_color' => 'red']))->assertSessionHasErrors('accent_color');
        $this->post($this->updateUrl($agency), $this->payload(['display_name' => '']))->assertSessionHasErrors('display_name');
        $this->assertSame(0, AgencyWhiteLabelSetting::query()->count());
    }

    public function test_a_valid_logo_is_stored_under_the_agencys_own_directory_and_replaced_cleanly(): void
    {
        [$agency, $owner] = $this->agency();

        $first = $this->enable($agency, $owner, [], UploadedFile::fake()->image('logo.png', 120, 40));

        $directory = AgencyWhiteLabelManager::directoryFor($agency);
        $this->assertStringStartsWith($directory . '/', $first->logo_path);
        $this->assertMatchesRegularExpression('#^' . preg_quote($directory, '#') . '/[0-9a-f]{64}\.png$#', $first->logo_path);
        $this->assertFileExists(public_path($first->logo_path));

        // A different image replaces it: the old file is deleted, the new one stored.
        $second = $this->manager()->save((int) $owner->user_id, $agency, $this->payload(), UploadedFile::fake()->image('other.jpg', 100, 50));
        $this->assertNotSame($first->logo_path, $second->logo_path);
        $this->assertFileExists(public_path($second->logo_path));
        $this->assertFileDoesNotExist(public_path($first->logo_path));

        $types = AgencyWhiteLabelChange::query()->orderBy('id')->pluck('change_type')->all();
        $this->assertSame(['created', 'logo_replaced'], $types);

        // Removing it deletes the file and the pointer.
        $removed = $this->manager()->save((int) $owner->user_id, $agency, $this->payload(), null, true);
        $this->assertNull($removed->logo_path);
        $this->assertFileDoesNotExist(public_path($second->logo_path));
        $this->assertSame('logo_removed', AgencyWhiteLabelChange::query()->orderByDesc('id')->value('change_type'));
    }

    public function test_an_unsafe_or_oversized_logo_is_refused_and_leaves_no_file_behind(): void
    {
        [$agency, $owner] = $this->agency();

        $unsafe = [
            'svg' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'php disguised as png' => UploadedFile::fake()->createWithContent('logo.png', '<?php echo 1; ?>'),
            'too many pixels' => UploadedFile::fake()->image('big.png', 900, 300),
            'empty' => UploadedFile::fake()->createWithContent('empty.png', ''),
        ];

        foreach ($unsafe as $label => $file) {
            try {
                $this->manager()->save((int) $owner->user_id, $agency, $this->payload(), $file);
                $this->fail("Accepted an unsafe logo: {$label}");
            } catch (AgencyWhiteLabelException $e) {
                $this->assertSame(AgencyWhiteLabelException::INVALID_LOGO, $e->reason, $label);
            }
        }

        $this->assertSame(0, AgencyWhiteLabelSetting::query()->count());
        $this->assertDirectoryDoesNotExist(public_path(AgencyWhiteLabelManager::directoryFor($agency)));

        // And through the HTTP validation layer.
        $this->authenticateAs($owner);
        $this->post($this->updateUrl($agency), $this->payload() + ['logo' => UploadedFile::fake()->createWithContent('logo.svg', '<svg/>')])
            ->assertSessionHasErrors('logo');
        $this->assertSame(0, AgencyWhiteLabelSetting::query()->count());
    }

    public function test_a_non_owner_upload_writes_no_file_and_never_touches_the_live_logo(): void
    {
        [$agency, $owner] = $this->agency();
        $live = $this->enable($agency, $owner, [], UploadedFile::fake()->image('logo.png', 120, 40));

        $member = $this->createCustomer();
        $this->member($agency, $member->user, WorkspaceMembershipRole::Admin);

        // The identical bytes, uploaded by someone who may not save: refused,
        // and the live file (same content hash) must survive the refusal.
        $same = UploadedFile::fake()->createWithContent('logo.png', (string) file_get_contents(public_path($live->logo_path)));

        try {
            $this->manager()->save((int) $member->user_id, $agency, $this->payload(), $same);
            $this->fail('A non-owner saved.');
        } catch (AgencyWhiteLabelException $e) {
            $this->assertSame(AgencyWhiteLabelException::NOT_OWNER, $e->reason);
        }

        $this->assertFileExists(public_path($live->logo_path));
        $this->assertSame($live->logo_path, AgencyWhiteLabelSetting::query()->sole()->logo_path);
    }

    // --------------------------------------------------------- client rendering

    public function test_a_managed_clients_chrome_shows_the_managing_agencys_brand_not_the_platforms(): void
    {
        [$agency, $owner] = $this->agency();
        [, $clientOwner] = $this->client($agency);

        // Off by default: nothing is branded until the owner turns it on.
        $before = $this->homeHtmlFor($clientOwner);
        $this->assertStringNotContainsString('Northwind Digital', $before);
        $this->assertStringNotContainsString('data-role="agency-brand-mark"', $before);

        $this->enable($agency, $owner);

        $html = $this->homeHtmlFor($clientOwner);
        $this->assertStringContainsString('Northwind Digital', $html);
        $this->assertStringContainsString('data-role="agency-brand-mark"', $html);
        $this->assertMatchesRegularExpression('#<title>[^<]*- Northwind Digital</title>#', $html);
        $this->assertStringContainsString('help@northwind.example', $html);
        // The accent is the validated colour, applied to the text mark.
        $this->assertStringContainsString('background-color:#1a73e8', $html);

        // Switching it off returns the client to the platform at once.
        $this->enable($agency, $owner, ['is_enabled' => '0']);
        $this->assertStringNotContainsString('Northwind Digital', $this->homeHtmlFor($clientOwner));
    }

    public function test_an_uploaded_logo_is_rendered_as_the_clients_mark(): void
    {
        [$agency, $owner] = $this->agency();
        [, $clientOwner] = $this->client($agency);
        $setting = $this->enable($agency, $owner, [], UploadedFile::fake()->image('logo.png', 120, 40));

        $html = $this->homeHtmlFor($clientOwner);

        $this->assertStringContainsString('src="' . asset($setting->logo_path) . '"', $html);
        $this->assertStringContainsString('alt="Northwind Digital"', $html);
    }

    public function test_the_agencys_own_chrome_is_never_branded_with_its_own_white_label(): void
    {
        [$agency, $owner] = $this->agency();
        $this->enable($agency, $owner);

        $this->assertStringNotContainsString('data-role="agency-brand-mark"', $this->homeHtmlFor($owner));
    }

    public function test_one_agencys_brand_never_reaches_another_agencys_client(): void
    {
        [$agencyA, $ownerA] = $this->agency('Agency A');
        [$agencyB, $ownerB] = $this->agency('Agency B');
        [$clientOfA, $clientOwnerA] = $this->client($agencyA, 'Client of A');
        [$clientOfB] = $this->client($agencyB, 'Client of B');
        [$unmanagedOwner] = $this->tenant(WorkspacePlanTier::Core, 'Solo Business', 'Solo');

        $this->enable($agencyA, $ownerA, ['display_name' => 'Brand A']);
        $this->enable($agencyB, $ownerB, ['display_name' => 'Brand B']);

        $resolver = app(ClientWorkspaceBrandResolver::class);

        $this->assertSame('Brand A', $resolver->forClientWorkspaceId((int) $clientOfA->id)?->displayName);
        $this->assertSame('Brand B', $resolver->forClientWorkspaceId((int) $clientOfB->id)?->displayName);

        $html = $this->homeHtmlFor($clientOwnerA);
        $this->assertStringContainsString('Brand A', $html);
        $this->assertStringNotContainsString('Brand B', $html);

        // A Workspace with no managing Agency is never branded — not by "the first
        // Agency", not by a submitted uid or query parameter.
        $this->authenticateAs($unmanagedOwner);
        $this->flushCaches();
        $unmanaged = $this->get(route('user.home', ['agency' => $agencyA->uid, 'workspaceUid' => $agencyA->uid, 'brand' => 'Brand A']))->assertOk()->getContent();
        $this->assertStringNotContainsString('Brand A', $unmanaged);
        $this->assertStringNotContainsString('Brand B', $unmanaged);
    }

    public function test_ending_the_relationship_returns_the_client_to_platform_branding(): void
    {
        [$agency, $owner] = $this->agency();
        $fixture = $this->createAgencyManagedClient($agency, 'Ended Business', 'Ended Client');
        $this->assignTier($fixture['clientWorkspace'], WorkspacePlanTier::Growth);
        $this->enable($agency, $owner);

        $this->assertStringContainsString('Northwind Digital', $this->homeHtmlFor($fixture['clientOwner']));

        app(AgencyClientRelationshipManager::class)->terminate((int) $owner->user_id, $fixture['relationship'], 'Test: ended.');

        $html = $this->homeHtmlFor($fixture['clientOwner']);
        $this->assertStringNotContainsString('Northwind Digital', $html);
        $this->assertStringNotContainsString('data-role="agency-brand-mark"', $html);

        // The Agency's own brand is untouched by the termination.
        $this->assertTrue(AgencyWhiteLabelSetting::query()->where('agency_workspace_id', $agency->id)->value('is_enabled'));
    }

    public function test_an_agency_that_loses_eligibility_or_the_entitlement_stops_branding_its_clients(): void
    {
        [$agency, $owner] = $this->agency();
        [$client] = $this->client($agency);
        $this->enable($agency, $owner);
        $resolver = app(ClientWorkspaceBrandResolver::class);

        $this->assertNotNull($resolver->forClientWorkspaceId((int) $client->id));

        // Entitlement removed: no row edited, branding stops.
        $entitlements = app(EntitlementManager::class);
        $entitlements->createOrChangeOverride($agency, PlatformFeature::WhiteLabel, WorkspaceEntitlementOverrideState::Deny, $this->platformAdminId(), 'Test: denied.');
        $this->flushCaches();
        $this->assertNull($resolver->forClientWorkspaceId((int) $client->id));

        $entitlements->revertOverride($agency, PlatformFeature::WhiteLabel, $this->platformAdminId());
        $this->flushCaches();
        $this->assertNotNull($resolver->forClientWorkspaceId((int) $client->id));

        // Agency account locked: no branding either.
        $entitlements->enterGracePeriod($agency);
        $entitlements->lockForNonPayment($agency);
        $this->flushCaches();
        $this->assertNull($resolver->forClientWorkspaceId((int) $client->id));
    }

    public function test_a_tampered_row_can_never_inject_markup_a_foreign_path_or_a_bad_colour(): void
    {
        [$agency, $owner] = $this->agency();
        [, $clientOwner] = $this->client($agency);
        $this->enable($agency, $owner);

        DB::table('agency_white_label_settings')->where('agency_workspace_id', $agency->id)->update([
            'display_name' => '<script>alert(1)</script>Evil',
            'accent_color' => 'red;}</style><script>x</script>',
            'logo_path' => '../../.env',
            'support_email' => 'not an email"><script>',
        ]);

        $html = $this->homeHtmlFor($clientOwner);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('</style><script>x</script>', $html);
        $this->assertStringNotContainsString('.env', $html);
        $this->assertStringNotContainsString('not an email', $html);
    }

    public function test_the_brand_lookup_is_bounded_and_memoized_per_request(): void
    {
        [$agency, $owner] = $this->agency();
        [$client, $clientOwner] = $this->client($agency);
        $this->enable($agency, $owner);

        $this->authenticateAs($clientOwner);
        $this->flushCaches();

        $request = \Illuminate\Http\Request::create('/');
        $request->attributes->set('customerContext', app(\App\Library\Navigation\CustomerContextResolver::class)->resolve(
            $clientOwner->user,
            $request,
            null,
        ));

        $resolver = app(ClientWorkspaceBrandResolver::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $first = $resolver->forRequest($request);
        $afterFirst = count(DB::getQueryLog());
        $second = $resolver->forRequest($request);
        $third = $resolver->forRequest($request);
        $afterAll = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertNotNull($first);
        $this->assertSame($first, $second);
        $this->assertSame($first, $third);
        $this->assertSame($afterFirst, $afterAll, 'The brand was looked up again within the same request.');
        $this->assertNotNull($client);
    }

    public function test_the_brand_lookup_reuses_the_menus_per_request_relationship_read(): void
    {
        [$agency, $owner] = $this->agency();
        [$client, $clientOwner] = $this->client($agency);
        $this->enable($agency, $owner);

        $this->authenticateAs($clientOwner);
        $this->flushCaches();

        $request = \Illuminate\Http\Request::create('/');
        $request->attributes->set('customerContext', app(\App\Library\Navigation\CustomerContextResolver::class)->resolve(
            $clientOwner->user,
            $request,
            null,
        ));

        // The customer menu has already asked "who manages this Workspace?" in this
        // request (it caches the answer under the shared key)...
        app(RequestScopedCache::class)->remember(
            RequestScopedCache::ACTIVE_AGENCY_RELATIONSHIP_PREFIX . $client->id,
            fn () => app(\App\Repositories\Contracts\AgencyClientWorkspaceRelationshipRepository::class)->findActiveForClientWorkspace((int) $client->id),
        );

        // ...so the chrome's brand lookup must not ask the database again.
        DB::flushQueryLog();
        DB::enableQueryLog();
        $brand = app(ClientWorkspaceBrandResolver::class)->forRequest($request);
        $relationshipReads = collect(DB::getQueryLog())
            // Only reads for the CLIENT Workspace: the Agency's own eligibility check
            // legitimately looks up the Agency Workspace's relationship, a different row.
            ->filter(fn (array $q) => str_contains($q['query'], 'agency_client_workspace_relationships')
                && (int) ($q['bindings'][0] ?? 0) === (int) $client->id)
            ->count();
        DB::disableQueryLog();

        $this->assertNotNull($brand);
        $this->assertSame(0, $relationshipReads, 'The brand lookup issued its own relationship query.');
    }

    public function test_white_label_is_workspace_scoped_and_never_listed_as_a_business_feature(): void
    {
        [$owner, $agencyBusiness, $agency] = $this->tenant(WorkspacePlanTier::Agency, 'Agency Biz', 'Agency Ws');

        $decisions = app(EntitlementManager::class)->decideAvailableFeaturesForBusiness($agency, $agencyBusiness, (int) $owner->user_id);

        $this->assertArrayNotHasKey(PlatformFeature::WhiteLabel->value, $decisions);
        $this->assertTrue(app(EntitlementManager::class)->decideForWorkspace($agency, PlatformFeature::WhiteLabel->value)->allowed);

        // A Core Workspace is simply not entitled (Agency-tier packaging only).
        [, , $core] = $this->tenant(WorkspacePlanTier::Core, 'Core Biz', 'Core Ws');
        $denied = app(EntitlementManager::class)->decideForWorkspace($core, PlatformFeature::WhiteLabel->value);
        $this->assertFalse($denied->allowed);
        $this->assertSame('not_entitled_by_plan', $denied->reason);
    }
}

<?php

namespace Tests\Feature\Workspace;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Enums\Workspace\WorkspaceMemberModule;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Growth\GrowthHomeBand;
use App\Library\Growth\GrowthOpportunityReader;
use App\Library\Growth\GrowthViewer;
use App\Library\Navigation\MenuEntitlements;
use App\Library\Workspace\LocationAccessGuard;
use App\Library\Workspace\MemberModuleVisibility;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Per-user module visibility inside one business Workspace: the owner switches
 * modules off for ONE team member (Settings → Team → Edit member → Visible
 * modules). It is a narrowing layer over the existing permissions and
 * entitlements, stored on the membership row, never a grant.
 */
class MemberModuleVisibilityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;
    use CreatesOpportunityTestData;

    private const SEO_RULE = 'seo.technical_findings:v1';

    private const CRM_RULE = 'crm.stale_opportunities:v1';

    private Customer $owner;

    private Business $business;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->owner, $this->business, $this->workspace] = $this->tenant(WorkspacePlanTier::Growth);
    }

    /** @return array{0: Customer, 1: WorkspaceMembership} */
    private function staff(WorkspaceMembershipRole $role = WorkspaceMembershipRole::Staff, ?array $visible = null): array
    {
        $customer = $this->createCustomer();
        $membership = $this->member($this->workspace, $customer->user, $role);

        if ($visible !== null) {
            $this->saveModules($membership, $visible);
        }

        return [$customer, $membership->fresh()];
    }

    private function saveModules(WorkspaceMembership $membership, array $visible, ?Customer $as = null): \Illuminate\Testing\TestResponse
    {
        $this->authenticateAs($as ?? $this->owner);

        return $this->post(route('customer.workspaces.members.modules', [$this->workspace->uid, $membership->user->uid]), ['visible_modules' => $visible]);
    }

    private function url(string $name, array $extra = []): string
    {
        return route($name, array_merge([$this->workspace->uid, $this->business->uid], $extra));
    }

    // ── Settings → Team → Edit member ────────────────────────────────────

    public function test_the_team_editor_lists_every_module_as_a_switch_with_a_save_button(): void
    {
        $this->staff();
        $this->authenticateAs($this->owner);

        $html = $this->get(route('customer.workspaces.team.show', $this->workspace->uid))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="visible-modules"', $html);

        foreach (WorkspaceMemberModule::cases() as $module) {
            $this->assertStringContainsString('name="visible_modules[]" value="' . $module->value . '"', $html);
        }
    }

    public function test_owner_saves_a_members_modules_and_the_complement_is_stored_on_the_membership(): void
    {
        [, $membership] = $this->staff();

        $this->saveModules($membership, ['crm', 'contacts'])->assertRedirect(route('customer.workspaces.team.show', $this->workspace->uid));

        $stored = $membership->fresh()->hidden_modules;
        $this->assertEqualsCanonicalizing(['conversations', 'calendar', 'automations', 'website', 'seo', 'ads', 'growth'], $stored);
        $this->assertEqualsCanonicalizing(['crm', 'contacts'], array_map(fn ($m) => $m->value, MemberModuleVisibility::visibleFor($membership->fresh())));
    }

    public function test_everyone_keeps_their_existing_access_by_default(): void
    {
        [, $membership] = $this->staff();

        $this->assertNull($membership->hidden_modules);
        $this->assertSame([], app(MemberModuleVisibility::class)->hiddenFor((int) $membership->user_id, (int) $this->workspace->id));
    }

    public function test_switching_everything_back_on_clears_the_restriction(): void
    {
        [, $membership] = $this->staff(visible: ['crm']);

        $this->saveModules($membership, WorkspaceMemberModule::values())->assertRedirect();

        $this->assertNull($membership->fresh()->hidden_modules);
    }

    public function test_unknown_module_values_are_refused(): void
    {
        [, $membership] = $this->staff();

        $this->saveModules($membership, ['crm', 'billing'])->assertSessionHasErrors('visible_modules.1');
        $this->assertNull($membership->fresh()->hidden_modules);
    }

    public function test_staff_cannot_change_anyones_modules_and_strangers_get_a_404(): void
    {
        [$staffer] = $this->staff();
        [, $target] = $this->staff();

        $this->saveModules($target, ['crm'], $staffer)->assertNotFound();
        $this->assertNull($target->fresh()->hidden_modules);

        $stranger = $this->createIndependentWorkspaceBusiness()['customer'];
        $this->saveModules($target, ['crm'], $stranger)->assertNotFound();
        $this->assertNull($target->fresh()->hidden_modules);
    }

    public function test_an_admin_may_configure_staff_but_not_another_admin_and_never_the_owner(): void
    {
        [$admin] = $this->staff(WorkspaceMembershipRole::Admin);
        [, $otherAdmin] = $this->staff(WorkspaceMembershipRole::Admin);
        [, $staffer] = $this->staff();

        $this->saveModules($staffer, ['crm'], $admin)->assertRedirect();
        $this->assertNotNull($staffer->fresh()->hidden_modules);

        $this->saveModules($otherAdmin, ['crm'], $admin)->assertNotFound();
        $this->assertNull($otherAdmin->fresh()->hidden_modules);

        $this->authenticateAs($admin);
        $this->post(route('customer.workspaces.members.modules', [$this->workspace->uid, $this->owner->user->uid]), ['visible_modules' => ['crm']])->assertNotFound();
    }

    public function test_the_preference_is_per_workspace_membership_not_global(): void
    {
        [$customer, $membership] = $this->staff(visible: ['crm']);

        $other = $this->createIndependentWorkspaceBusiness();
        $otherMembership = $this->member($other['workspace'], $customer->user, WorkspaceMembershipRole::Staff);

        $this->assertNotNull($membership->fresh()->hidden_modules);
        $this->assertNull($otherMembership->fresh()->hidden_modules);

        $visibility = app(MemberModuleVisibility::class);
        $this->assertNotSame([], $visibility->hiddenFor((int) $customer->user->id, (int) $this->workspace->id));
        $this->assertSame([], $visibility->hiddenFor((int) $customer->user->id, (int) $other['workspace']->id));
    }

    // ── A CRM-only member ────────────────────────────────────────────────

    public function test_a_crm_only_member_sees_crm_but_no_seo_ads_or_growth_in_the_sidebar(): void
    {
        [$crmOnly] = $this->staff(visible: ['crm']);
        $this->authenticateAs($crmOnly);

        $keys = $this->menuKeys($this->home()->assertOk()->getContent());

        $this->assertContains('opportunities', $keys);

        foreach (['seo', 'seo-keywords', 'gbp', 'seo-citations', 'seo-reviews', 'ads', 'ads-google', 'ads-meta', 'website', 'automations', 'conversations', 'calendar', 'contacts'] as $gone) {
            $this->assertNotContains($gone, $keys, "A CRM-only member must not see [{$gone}].");
        }
    }

    public function test_a_crm_only_member_is_refused_seo_ads_and_growth_by_direct_url(): void
    {
        [$crmOnly] = $this->staff(visible: ['crm']);
        $this->authenticateAs($crmOnly);

        foreach ([
            $this->url('customer.workspaces.businesses.seo.keywords.index'),
            $this->url('customer.workspaces.businesses.seo.index'),
            $this->url('customer.workspaces.businesses.gbp.index'),
            $this->url('customer.workspaces.businesses.ads.overview'),
            $this->url('customer.workspaces.businesses.ads.meta.index'),
            $this->url('customer.workspaces.businesses.growth.index'),
            $this->url('customer.workspaces.businesses.growth.opportunities.index'),
            $this->url('customer.workspaces.businesses.website.show'),
            $this->url('customer.workspaces.businesses.people.index'),
        ] as $url) {
            $this->get($url)->assertNotFound();
        }

        // …and for JSON/AJAX callers: the same 404, never a 403 that confirms the module exists.
        $this->getJson($this->url('customer.workspaces.businesses.seo.keywords.index'))->assertNotFound();
        $this->postJson($this->url('customer.workspaces.businesses.growth.refresh'))->assertNotFound();

        // CRM itself keeps working.
        $this->get($this->url('customer.workspaces.businesses.crm.board'))->assertOk();
    }

    public function test_the_owner_sees_everything_and_other_members_are_unaffected(): void
    {
        [, $restricted] = $this->staff(visible: ['crm']);
        [$other] = $this->staff();

        foreach ([$this->owner, $other] as $viewer) {
            $this->authenticateAs($viewer);
            $keys = $this->menuKeys($this->home()->assertOk()->getContent());

            foreach (['opportunities', 'contacts', 'conversations', 'automations', 'website', 'seo', 'ads'] as $expected) {
                $this->assertContains($expected, $keys, "[{$expected}] must stay visible.");
            }

            // Reachable, i.e. never the module-visibility 404 (some entries redirect to their landing page).
            foreach (['seo.keywords.index', 'ads.overview', 'growth.index'] as $name) {
                $this->assertNotSame(404, $this->get($this->url('customer.workspaces.businesses.' . $name))->getStatusCode(), "{$name} must stay reachable.");
            }
        }

        $this->assertNotNull($restricted->fresh()->hidden_modules);
    }

    public function test_an_owner_cannot_be_narrowed_even_by_an_anomalous_membership_row(): void
    {
        $this->createMembership($this->workspace, $this->owner->user, ['hidden_modules' => ['seo', 'ads', 'growth']]);

        $this->assertSame([], app(MemberModuleVisibility::class)->hiddenFor((int) $this->owner->user->id, (int) $this->workspace->id));

        $this->authenticateAs($this->owner);
        $this->assertNotSame(404, $this->get($this->url('customer.workspaces.businesses.seo.keywords.index'))->getStatusCode());
    }

    public function test_an_inactive_membership_is_never_narrowed_by_this_layer(): void
    {
        $customer = $this->createCustomer();
        $this->member($this->workspace, $customer->user, WorkspaceMembershipRole::Staff, active: false)
            ->update(['hidden_modules' => ['seo']]);

        $this->assertSame([], app(MemberModuleVisibility::class)->hiddenFor((int) $customer->user->id, (int) $this->workspace->id));
    }

    public function test_changes_persist_after_logout_and_login(): void
    {
        [$member, $membership] = $this->staff();
        $this->saveModules($membership, ['crm'])->assertRedirect();

        $this->post(route('logout'));
        $this->assertGuest();

        // A brand-new session for the same person.
        $this->flushSession();
        $this->authenticateAs($member);

        $keys = $this->menuKeys($this->home()->assertOk()->getContent());
        $this->assertContains('opportunities', $keys);
        $this->assertNotContains('seo', $keys);
        $this->assertNotContains('ads', $keys);
        $this->get($this->url('customer.workspaces.businesses.seo.keywords.index'))->assertNotFound();
    }

    public function test_a_hidden_module_never_authorises_beyond_the_existing_permissions(): void
    {
        // Visible SEO, but the member holds no SEO permission: still refused (narrowing only).
        [$member] = $this->staff(visible: ['seo']);
        $this->authenticateAs($member, ['view_contact']);

        $this->get($this->url('customer.workspaces.businesses.seo.keywords.index'))->assertUnauthorized();
    }

    // ── No leakage through Home, Next Best Move, the assistant or search ─

    private function seedRecommendations(): void
    {
        config(['opportunity.enabled' => true]);

        $this->createOpportunity($this->business, ['type' => self::SEO_RULE, 'worker_key' => OpportunityWorkerKey::Seo->value, 'location_id' => null, 'priority_score' => 95, 'title' => 'Fix technical SEO findings']);
        $this->createOpportunity($this->business, ['type' => self::CRM_RULE, 'worker_key' => OpportunityWorkerKey::Sales->value, 'location_id' => null, 'priority_score' => 40, 'title' => 'Follow up stale deals']);
    }

    private function band(int $userId): array
    {
        return app(GrowthHomeBand::class)->build($this->business->fresh(), $userId, $this->workspace->uid, $this->business->uid, true);
    }

    public function test_hiding_seo_keeps_seo_recommendations_out_of_the_growth_band_but_not_out_of_the_owners(): void
    {
        $this->seedRecommendations();
        [$crmOnly] = $this->staff(visible: ['crm', 'growth']);

        $ownerBand = $this->band((int) $this->owner->user->id);
        $this->assertSame(self::SEO_RULE, $ownerBand['head']->type, 'The owner still gets the SEO recommendation.');

        $memberBand = $this->band((int) $crmOnly->user->id);
        $this->assertSame(self::CRM_RULE, $memberBand['head']->type);
        $this->assertSame(1, $memberBand['open_count']);
        $this->assertNotContains('Fix technical SEO findings', array_column($memberBand['items'], 'title'));
    }

    public function test_hiding_growth_returns_no_recommendations_at_all(): void
    {
        $this->seedRecommendations();
        [$noGrowth] = $this->staff(visible: ['crm', 'seo', 'ads']);

        $band = $this->band((int) $noGrowth->user->id);

        $this->assertSame([], $band['items']);
        $this->assertNull($band['head']);
    }

    public function test_the_menu_snapshot_for_a_crm_only_member_answers_no_for_seo_ads_and_growth(): void
    {
        [$crmOnly] = $this->staff(visible: ['crm']);
        $this->authenticateAs($crmOnly);
        $this->home()->assertOk();

        $snapshot = app(\App\Library\Navigation\CustomerShellComposer::class)->currentMenuEntitlements(
            app(\App\Library\Navigation\CustomerContext::class),
        );

        $this->assertInstanceOf(MenuEntitlements::class, $snapshot);

        foreach (['seo_basic_visibility', 'seo_module', 'google_business_profile_module', 'ads_basic_visibility', 'ads_module', 'google_ads_module', 'ai_coo_basic', 'conversations', 'automations', 'website_generation', 'calendar'] as $feature) {
            $this->assertFalse($snapshot->allows($feature), "[{$feature}] must be narrowed away.");
        }

        $this->assertTrue($snapshot->allows('crm'));
    }

    public function test_home_for_a_crm_only_member_carries_no_seo_ads_or_growth_links_or_recommendations(): void
    {
        $this->seedRecommendations();
        [$crmOnly] = $this->staff(visible: ['crm']);
        $this->authenticateAs($crmOnly);

        $html = $this->home()->assertOk()->getContent();

        $this->assertStringNotContainsString('Fix technical SEO findings', $html);

        foreach (['/seo', '/ads', '/growth', '/gbp'] as $needle) {
            $this->assertStringNotContainsString($this->workspace->uid . '/businesses/' . $this->business->uid . $needle, $html, "Home must not link to {$needle}.");
        }
    }

    public function test_global_search_skips_domains_the_member_cannot_see(): void
    {
        [$crmOnly] = $this->staff(visible: ['crm']);
        $this->authenticateAs($crmOnly);

        $results = app(\App\Library\Search\GlobalSearchCoordinator::class)->search($this->business->fresh(), 'anything', $crmOnly->user);

        $this->assertSame([], $results['contacts']);
        $this->assertSame([], $results['conversations']);
    }

    // ── Background work and View As are untouched ────────────────────────

    public function test_hiding_seo_does_not_change_what_background_work_decides(): void
    {
        [, $membership] = $this->staff(visible: ['crm']);
        $this->assertNotNull($membership->fresh()->hidden_modules);

        // Background jobs ask EntitlementManager with no viewer: SEO stays entitled.
        $decision = app(\App\Library\Entitlement\EntitlementManager::class)
            ->decide($this->workspace, $this->business, 'seo_module', 0);

        $this->assertTrue($decision->allowed);

        // …and generation still produces SEO rows: the eligibility used by producers ignores viewers.
        $types = app(\App\Library\Growth\GrowthOpportunityEligibility::class)->ineligibleTypes($this->business);
        $this->assertNotContains(self::SEO_RULE, $types);
    }

    public function test_view_as_agency_actor_is_never_narrowed_by_a_clients_staff_preferences(): void
    {
        $fixture = $this->createAgencyManagedClient();
        $staffer = $this->createCustomer();
        $this->member($fixture['clientWorkspace'], $staffer->user, WorkspaceMembershipRole::Staff)
            ->update(['hidden_modules' => ['seo', 'ads', 'growth']]);

        $this->assertSame([], app(MemberModuleVisibility::class)->hiddenFor((int) $fixture['agencyOwner']->user->id, (int) $fixture['clientWorkspace']->id));
    }
}

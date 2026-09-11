<?php

namespace Tests\Feature\Workspace;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Http\Middleware\VerifyCsrfToken;
use App\Library\Entitlement\BusinessFeatureSettings;
use App\Library\Entitlement\EntitlementManager;
use App\Models\BusinessFeatureToggle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * The account page's Business features: human names and one-line
 * descriptions, an Enabled/Disabled switch, only the features the server
 * says this customer can switch, and each change saved without leaving the
 * page through the existing enable/disable routes (same authority).
 */
class WorkspaceBusinessFeatureSettingsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    private const CANNOT_CHANGE_NOW = 'This feature can\'t be changed right now. Refresh the page to see its current setting.';

    private const IMPLEMENTATION_WORDING = [
        'Platform feature preference',
        'Effective entitlement',
        'Record disable preference',
        'Remove disable preference',
        'Disable preference recorded',
        'No disable preference recorded',
        'Runtime enforcement pending',
        'Not included',
        'Disabled by plan',
        'Upgrade',
    ];

    public function test_core_lists_its_entitled_switchable_features_by_customer_name(): void
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->authenticateAs($owner);

        $region = $this->featureRegion($this->get(route('customer.workspaces.show', $workspace->uid))->assertOk()->getContent());

        $this->assertSame(['Inbox & Conversations', 'Automations', 'Website'], $this->featureNames($region));
        // Each switch is named after its feature for assistive technology.
        $this->assertSame(3, preg_match_all('/<label class="form-check-label" for="[^"]+"><span class="visually-hidden">(Inbox &amp; Conversations|Automations|Website)<\/span>/', $region));
        $this->assertStringContainsString('Read and reply to customer messages from one place.', $region);
        $this->assertStringContainsString('Automatically follow up and perform repetitive tasks.', $region);
        $this->assertStringContainsString('Create and manage your business website.', $region);
    }

    public function test_growth_also_lists_google_business_profile(): void
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($owner);

        $region = $this->featureRegion($this->get(route('customer.workspaces.show', $workspace->uid))->assertOk()->getContent());

        $this->assertSame(['Inbox & Conversations', 'Automations', 'Website', 'Google Business Profile'], $this->featureNames($region));
        $this->assertStringContainsString('Manage how your business appears on Google.', $region);
    }

    /**
     * Rule 1 + Rule 6: a feature the plan does not include is never sent to
     * the page at all — not hidden, not "locked", no upgrade prompt.
     */
    public function test_a_plan_locked_feature_is_not_sent_to_the_page(): void
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->authenticateAs($owner);

        $html = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk()->getContent();

        $this->assertStringNotContainsString('google_business_profile_module', $html);
        $this->assertStringNotContainsString('Google Business Profile', $html);
        $this->assertSame(3, preg_match_all('/<input [^>]*data-business-feature-switch/', $html));
    }

    /**
     * Client Management is included in every plan, but nothing in the
     * product reads its Business switch yet, so a switch would do nothing:
     * it is not offered (BusinessFeatureSettings::CUSTOMER_TOGGLEABLE).
     */
    public function test_a_feature_whose_switch_nothing_honours_is_not_offered(): void
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($owner);

        $region = $this->featureRegion($this->get(route('customer.workspaces.show', $workspace->uid))->assertOk()->getContent());

        $this->assertNotContains('Client Management', $this->featureNames($region));
        $this->assertNotContains(PlatformFeature::Crm->value, BusinessFeatureSettings::CUSTOMER_TOGGLEABLE);
    }

    public function test_no_machine_keys_or_implementation_wording_are_shown(): void
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($owner);

        $region = $this->featureRegion($this->get(route('customer.workspaces.show', $workspace->uid))->assertOk()->getContent());
        $visibleText = html_entity_decode(strip_tags($region));

        foreach (['crm', 'conversations', 'automations', 'website_generation', 'google_business_profile_module'] as $machineKey) {
            $this->assertDoesNotMatchRegularExpression('/\b' . preg_quote($machineKey, '/') . '\b/', $visibleText, "Machine key [{$machineKey}] must not be visible.");
        }

        foreach (self::IMPLEMENTATION_WORDING as $wording) {
            $this->assertStringNotContainsString($wording, $region);
        }
    }

    public function test_each_switch_shows_its_saved_state(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);
        app(EntitlementManager::class)->disableBusinessFeature($business, PlatformFeature::Automations, (int) $owner->user_id);
        $this->authenticateAs($owner);

        $region = $this->featureRegion($this->get(route('customer.workspaces.show', $workspace->uid))->assertOk()->getContent());

        $this->assertSame([
            'Inbox & Conversations' => true,
            'Automations' => false,
            'Website' => true,
        ], $this->switchStates($region));
    }

    /**
     * A Workspace-level deny (support override) means the Business is not
     * entitled, so the feature leaves the list even with a switch recorded.
     */
    public function test_a_feature_denied_for_the_whole_account_is_not_listed_even_with_a_recorded_switch(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);
        app(EntitlementManager::class)->disableBusinessFeature($business, PlatformFeature::Automations, (int) $owner->user_id);
        app(EntitlementManager::class)->createOrChangeOverride($workspace, PlatformFeature::Automations, WorkspaceEntitlementOverrideState::Deny, $this->platformAdminId(), 'Fixture deny.');
        $this->authenticateAs($owner);

        $region = $this->featureRegion($this->get(route('customer.workspaces.show', $workspace->uid))->assertOk()->getContent());

        $this->assertSame(['Inbox & Conversations', 'Website'], $this->featureNames($region));
    }

    public function test_an_account_without_a_plan_shows_no_features_section(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $owner = $this->createCustomer();
        $workspace = $this->createWorkspace($owner->user);
        $this->addBusiness($owner, $workspace, 'Unassigned Co');
        $this->authenticateAs($owner);

        $html = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk()->getContent();

        $this->assertStringNotContainsString('id="business-feature-settings"', $html);
        $this->assertSame(0, preg_match_all('/<input [^>]*data-business-feature-switch/', $html));
    }

    // --- Saving without a reload -------------------------------------------

    public function test_turning_a_feature_off_and_on_returns_the_saved_state_as_json(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->authenticateAs($owner);

        $this->postJson(route('customer.workspaces.businesses.features.disable', [$workspace->uid, $business->uid, 'automations']))
            ->assertOk()
            ->assertExactJson(['status' => 'success', 'enabled' => false]);
        $this->assertDatabaseHas('business_feature_toggles', ['business_id' => $business->id, 'feature_key' => 'automations']);
        $this->assertFalse(app(EntitlementManager::class)->decide($workspace, $business, 'automations', (int) $owner->user_id)->allowed, 'Off really means off.');

        $this->postJson(route('customer.workspaces.businesses.features.enable', [$workspace->uid, $business->uid, 'automations']))
            ->assertOk()
            ->assertExactJson(['status' => 'success', 'enabled' => true]);
        $this->assertSame(0, BusinessFeatureToggle::query()->where('business_id', $business->id)->count());
        $this->assertTrue(app(EntitlementManager::class)->decide($workspace, $business, 'automations', (int) $owner->user_id)->allowed);
    }

    /**
     * A second "turn off" (another tab still showing Enabled) is refused by
     * the manager, which only turns off a feature the Business can use now;
     * nothing is duplicated and the message tells the customer to refresh.
     */
    public function test_repeating_a_change_never_duplicates_it(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->authenticateAs($owner);
        $url = route('customer.workspaces.businesses.features.disable', [$workspace->uid, $business->uid, 'website_generation']);

        $this->postJson($url)->assertExactJson(['status' => 'success', 'enabled' => false]);
        $this->postJson($url)->assertStatus(409)->assertExactJson(['status' => 'error', 'customer_message' => self::CANNOT_CHANGE_NOW]);

        $this->assertSame(1, BusinessFeatureToggle::query()->where('business_id', $business->id)->count());

        $enable = route('customer.workspaces.businesses.features.enable', [$workspace->uid, $business->uid, 'website_generation']);
        $this->postJson($enable)->assertExactJson(['status' => 'success', 'enabled' => true]);
        $this->postJson($enable)->assertExactJson(['status' => 'success', 'enabled' => true]);
        $this->assertSame(0, BusinessFeatureToggle::query()->where('business_id', $business->id)->count());
    }

    public function test_the_manager_still_refuses_a_feature_the_plan_does_not_include(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->authenticateAs($owner);

        $this->postJson(route('customer.workspaces.businesses.features.disable', [$workspace->uid, $business->uid, 'google_business_profile_module']))
            ->assertStatus(409)
            ->assertExactJson(['status' => 'error', 'customer_message' => self::CANNOT_CHANGE_NOW]);

        $this->assertSame(0, BusinessFeatureToggle::query()->where('business_id', $business->id)->count());
    }

    public function test_staff_are_refused_by_the_same_authority_and_nothing_changes(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $staff = $this->createCustomer();
        $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff);
        $this->authenticateAs($staff);

        $this->postJson(route('customer.workspaces.businesses.features.disable', [$workspace->uid, $business->uid, 'automations']))
            ->assertStatus(403)
            ->assertExactJson(['status' => 'error', 'customer_message' => 'You don\'t have permission to change this Business\'s features.']);

        $this->assertSame(0, BusinessFeatureToggle::query()->where('business_id', $business->id)->count());
    }

    public function test_an_inactive_account_refuses_the_change_with_customer_wording(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);
        DB::table('workspaces')->where('id', $workspace->id)->update(['is_active' => false]);
        $this->authenticateAs($owner);

        $this->postJson(route('customer.workspaces.businesses.features.disable', [$workspace->uid, $business->uid, 'automations']))
            ->assertStatus(409)
            ->assertExactJson(['status' => 'error', 'customer_message' => 'This account is inactive, so its features can\'t be changed.']);
    }

    /**
     * A stranger, an unknown account and another account's Business all
     * fail closed exactly as before — no customer message, nothing saved,
     * and the stranger's answer is the unknown account's answer.
     */
    public function test_inaccessible_accounts_and_businesses_fail_closed_without_a_customer_message(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);
        [$otherOwner, $otherBusiness] = $this->tenant(WorkspacePlanTier::Core, 'Other Studio', 'Other');
        $stranger = $this->createCustomer();

        $this->authenticateAs($stranger);
        $strangerResponse = $this->postJson(route('customer.workspaces.businesses.features.disable', [$workspace->uid, $business->uid, 'automations']));
        $unknownResponse = $this->postJson(route('customer.workspaces.businesses.features.disable', ['no-such-account', $business->uid, 'automations']));

        $this->assertSame($unknownResponse->getContent(), $strangerResponse->getContent());
        $strangerResponse->assertJsonMissing(['status' => 'success'])->assertJsonMissingPath('customer_message');

        $this->authenticateAs($owner);
        $this->postJson(route('customer.workspaces.businesses.features.disable', [$workspace->uid, $otherBusiness->uid, 'automations']))
            ->assertJsonMissing(['status' => 'success'])
            ->assertJsonMissingPath('customer_message');

        $this->assertSame(0, BusinessFeatureToggle::query()->count());
        $this->assertNotNull($otherOwner);
    }

    public function test_a_plain_form_post_still_redirects_with_customer_wording(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->authenticateAs($owner);

        $this->post(route('customer.workspaces.businesses.features.disable', [$workspace->uid, $business->uid, 'automations']))
            ->assertRedirect(route('customer.workspaces.show', $workspace->uid))
            ->assertSessionHas('flash_success', 'Saved.');
    }

    /**
     * CSRF is not relaxed for the switches: the routes stay in the web group
     * behind VerifyCsrfToken with no exception, and the page sends the token.
     */
    public function test_the_switches_keep_csrf_protection_and_send_the_token(): void
    {
        foreach (['customer.workspaces.businesses.features.disable', 'customer.workspaces.businesses.features.enable'] as $name) {
            $this->assertContains('web', Route::getRoutes()->getByName($name)->gatherMiddleware());
        }

        $except = (fn () => $this->except)->call(app(VerifyCsrfToken::class));
        foreach ($except as $pattern) {
            $this->assertFalse(str_contains($pattern, 'workspaces') || str_contains($pattern, 'features'), "CSRF exception [{$pattern}] must not cover the feature switches.");
        }

        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->authenticateAs($owner);
        $html = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk()->getContent();

        $this->assertStringContainsString("'X-CSRF-TOKEN': csrfMeta ? csrfMeta.getAttribute('content') : ''", $html);
        $this->assertStringContainsString("'Accept': 'application/json'", $html);
    }

    /**
     * The switch only ever shows what the server confirmed, reverts on any
     * refusal, and ignores clicks while a change is saving.
     */
    public function test_the_switch_script_shows_only_confirmed_state_and_ignores_clicks_while_saving(): void
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->authenticateAs($owner);
        $html = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk()->getContent();

        $this->assertStringContainsString("result.body.status === 'success' && typeof result.body.enabled === 'boolean'", $html);
        $this->assertStringContainsString('show(result.body.enabled);', $html);
        $this->assertSame(2, substr_count($html, 'show(previous);'), 'Both a refusal and a network failure put the switch back.');
        $this->assertMatchesRegularExpression('/if \(saving\) \{\s*event\.preventDefault\(\);/', $html);
    }

    // -----------------------------------------------------------------

    private function featureRegion(string $html): string
    {
        $start = strpos($html, 'id="business-feature-settings"');
        $this->assertNotFalse($start, 'The Business features section must render.');
        $end = strpos($html, '</ul>', $start);

        return substr($html, $start, $end - $start);
    }

    /**
     * @return list<string>
     */
    private function featureNames(string $region): array
    {
        preg_match_all('/<div class="fw-bolder" id="[^"]+-name">([^<]+)<\/div>/', $region, $matches);

        return array_map('html_entity_decode', $matches[1]);
    }

    /**
     * @return array<string, bool> name => switch checked
     */
    private function switchStates(string $region): array
    {
        preg_match_all('/<li class="list-group-item[^"]*" data-role="business-feature">(.*?)<\/li>/s', $region, $rows);
        $states = [];

        foreach ($rows[1] as $row) {
            preg_match('/id="[^"]+-name">([^<]+)</', $row, $name);
            preg_match('/<input [^>]*data-business-feature-switch[^>]*>/', $row, $input);
            preg_match('/data-role="business-feature-state">([^<]+)</', $row, $label);

            $checked = str_contains($input[0], ' checked');
            $this->assertSame($checked ? 'Enabled' : 'Disabled', $label[1], 'The label matches the switch.');
            $states[html_entity_decode($name[1])] = $checked;
        }

        return $states;
    }
}

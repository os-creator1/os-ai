<?php

namespace Tests\Feature\Dashboards;

use App\DTO\Analytics\AutomationKpis;
use App\Enums\Business\BusinessStatus;
use App\Enums\Dashboard\AttentionSeverity;
use App\Enums\Dashboard\AttentionType;
use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Analytics\AnalyticsDateRange;
use App\Library\Analytics\BusinessAnalyticsQueries;
use App\Library\Dashboard\AttentionItem;
use App\Library\Dashboard\DashboardSnapshot;
use App\Models\Business;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\OpportunityRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 4 §18 — the Business Home: frame-driven, one
 * Business, five bands, attention and Advisor kept apart, entitlement and
 * permission both required, view-as showing the viewed client, and a band
 * failure staying local.
 */
class BusinessHomeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
        config(['opportunity.enabled' => true]);
    }

    // =================================================================
    // #1, #2 — Core and Growth Business Home
    // =================================================================

    public function test_core_business_home_renders_the_five_bands_in_order_for_the_selected_business(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core, 'Main Street Bakery', 'Main Street');
        $this->populateAllFiveBands($business);
        $this->authenticateAs($customer);

        $html = $this->home()->assertOk()->getContent();

        $this->assertSame(['attention', 'recommendations', 'headlines', 'spend', 'actions'], $this->bandOrder($html));
        $this->assertStringContainsString('data-kind="business"', $html);
        $this->assertMatchesRegularExpression('#<h1[^>]*>.*Business home.*Main Street Bakery.*</h1>#s', $html);

        // Every link the page offers is scoped to this Business.
        foreach ($this->mainLinks($html) as $href) {
            if (str_contains($href, '/workspaces/')) {
                $this->assertStringContainsString($workspace->uid, $href);
                $this->assertStringContainsString($business->uid, $href);
            }
        }

        foreach ($this->quickActionLinks($html) as $href) {
            $this->assertStringContainsString($business->uid, $href, 'Quick actions use only Business-scoped routes.');
        }
    }

    public function test_growth_business_home_differs_from_core_only_where_entitlement_differs(): void
    {
        $pages = [];
        $gbpEntitled = [];

        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth] as $tier) {
            [$customer, $business] = $this->tenant($tier, 'Venue ' . $tier->value, 'Account ' . $tier->value);
            $this->populateAllFiveBands($business);
            $this->googleConnection($business, GoogleConnectionState::Revoked);
            $this->website($business, 'draft');
            $this->authenticateAs($customer);

            $context = $this->resolvedContext($customer->user);
            $gbpEntitled[$tier->value] = app(\App\Library\Navigation\CustomerShellComposer::class)
                ->currentMenuEntitlements($context)->allows('google_business_profile_module');

            $html = $this->home()->assertOk()->getContent();
            $pages[$tier->value] = [
                'bands' => $this->bandOrder($html),
                'attention' => $this->attentionTypes($html),
                'headlines' => $this->headlineKeys($html),
                'actions' => $this->quickActionKeys($html),
            ];
        }

        $this->assertFalse($gbpEntitled['core'], 'Precondition: Core is not entitled to Google Business Profile.');
        $this->assertTrue($gbpEntitled['growth']);

        $this->assertSame($pages['core']['bands'], $pages['growth']['bands']);
        $this->assertSame($pages['core']['headlines'], $pages['growth']['headlines']);
        $this->assertSame($pages['core']['actions'], $pages['growth']['actions']);

        // The ONE difference: the Google item, whose feature only Growth has.
        $this->assertSame(
            [AttentionType::GoogleConnectionLost->value],
            array_values(array_diff($pages['growth']['attention'], $pages['core']['attention'])),
        );
        $this->assertSame([], array_values(array_diff($pages['core']['attention'], $pages['growth']['attention'])));
    }

    // =================================================================
    // #4 — Agency inside a selected client account
    // =================================================================

    public function test_an_agency_inside_a_selected_client_sees_that_clients_business_home_and_name(): void
    {
        [$agency, $clientA, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client Alpha', 'Northwind Agency');
        $clientB = $this->addBusiness($agency, $workspace, 'Client Bravo');
        $this->sent($clientA, 7, '2026-09-01');
        $this->sent($clientB, 4, '2026-09-01');
        $this->authenticateAs($agency);

        $this->switchTo($workspace, $clientB)->assertRedirect(route('user.home'));
        $html = $this->home()->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<h1[^>]*>.*Client account home.*Client Bravo.*</h1>#s', $html);
        $this->assertSame('4', $this->headlineFigure($html, 'messages_sent'));
        $this->assertStringNotContainsString('Client Alpha', $this->mainText($html));
    }

    // =================================================================
    // #5 — Restricted staff
    // =================================================================

    public function test_restricted_staff_see_only_their_scoped_business_and_never_an_account_frame(): void
    {
        [$owner, $scoped, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Scoped Client', 'Northwind Agency');
        $other = $this->addBusiness($owner, $workspace, 'Other Client');
        $this->sent($scoped, 2, '2026-09-01');
        $this->sent($other, 9, '2026-09-01');
        $this->wallet($scoped, ['billing_status' => 'suspended']);

        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected);
        $this->assign($membership, $scoped);
        $this->authenticateAs($staff);

        $html = $this->home()->assertOk()->getContent();

        $this->assertStringContainsString('data-kind="business"', $html);
        $this->assertMatchesRegularExpression('#<h1[^>]*>.*Scoped Client.*</h1>#s', $html);
        $this->assertSame('2', $this->headlineFigure($html, 'messages_sent'));
        $this->assertStringNotContainsString('Other Client', $this->mainText($html));
        $this->assertNotContains('spend', $this->bandOrder($html), 'Staff are never the payer side.');
        $this->assertNotContains(AttentionType::WalletSuspended->value, $this->attentionTypes($html), 'No billing fix is reachable for staff, so no billing item.');

        // Two scoped Businesses: the chooser — never the Agency account bands.
        $this->assign($membership, $other);
        $html = $this->home()->assertOk()->getContent();

        $this->assertStringContainsString('data-kind="chooser"', $html);
        foreach (['clients', 'capacity', 'prospecting', 'account'] as $agencyBand) {
            $this->assertNotContains($agencyBand, $this->bandOrder($html));
        }
    }

    // =================================================================
    // #6 — zero / one / many
    // =================================================================

    public function test_zero_one_and_many_businesses_render_empty_state_business_home_and_chooser(): void
    {
        // Zero: no Workspace, no Business.
        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();
        $nobody = $this->createCustomer();
        $this->authenticateAs($nobody);

        $html = $this->home()->assertOk()->getContent();

        $this->assertStringContainsString('data-kind="zero"', $html);
        $this->assertStringContainsString('data-role="empty-state" data-state="empty"', $html);
        $this->assertSame(1, substr_count($html, 'data-role="empty-state-primary"'), 'Exactly one primary action.');
        $this->assertStringContainsString('Create your first business', $html);
        $this->assertSame([], $this->headlineKeys($html), 'No zeroed tiles: a zero would be a false claim.');

        // One.
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Only Venue', 'Only Account');
        $this->authenticateAs($customer);
        $this->assertStringContainsString('data-kind="business"', $this->home()->assertOk()->getContent());

        // Many, none chosen.
        $second = $this->addBusiness($customer, $workspace, 'Second Venue');
        session()->forget(array_keys(session()->all()));
        $this->authenticateAs($customer);
        $html = $this->home()->assertOk()->getContent();

        $this->assertStringContainsString('data-kind="chooser"', $html);
        $this->assertSame(2, substr_count($html, 'data-role="chooser-option"'));
        $this->assertStringContainsString($business->uid, $html);
        $this->assertStringContainsString($second->uid, $html);
    }

    // =================================================================
    // #7 — View-as-client
    // =================================================================

    public function test_view_as_renders_the_viewed_clients_data_and_name_with_the_banner_and_no_agent_figure(): void
    {
        [$agency, $ownClient, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Agency Own Client', 'Northwind Agency');
        $viewed = $this->addBusiness($agency, $workspace, 'Viewed Client');
        $this->sent($ownClient, 7, '2026-09-01');
        $this->sent($viewed, 2, '2026-09-01');
        $this->wallet($viewed, ['available_balance_micro' => 3000000, 'billing_status' => 'suspended']);
        $this->website($viewed, 'draft');
        $this->authenticateAs($agency);

        $this->startViewAs($workspace, $viewed)->assertRedirect(route('user.home'));
        $html = $this->home()->assertOk()->getContent();

        $this->assertStringContainsString('data-role="view-as-banner"', $html);
        $this->assertMatchesRegularExpression('#<h1[^>]*>.*Viewed Client.*</h1>#s', $html);
        $this->assertSame('2', $this->headlineFigure($html, 'messages_sent'));
        $this->assertStringNotContainsString('Agency Own Client', $this->mainText($html));

        // No cost, funding, provider or identity action while viewing.
        $this->assertSame([], array_values(array_intersect($this->quickActionKeys($html), ['send', 'add_funds', 'reconnect_google', 'login_as_parent'])));

        // The viewed client's own state and wallet — never the agent's. Usage &
        // Billing is an allowed read while viewing, so its fix stays reachable.
        $this->assertContains(AttentionType::WalletSuspended->value, $this->attentionTypes($html));
        $this->assertContains(AttentionType::WebsiteUnpublished->value, $this->attentionTypes($html));
        $this->assertStringContainsString('USD 3.00', $this->bandHtml($html, 'spend'));

        foreach ($this->mainLinks($html) as $href) {
            if (str_contains($href, '/businesses/')) {
                $this->assertStringContainsString($viewed->uid, $href, 'Every Business link targets the viewed client.');
            }
        }
    }

    // =================================================================
    // #8 — Foreign-Business isolation, per band
    // =================================================================

    public function test_a_foreign_businesss_data_never_appears_in_any_band(): void
    {
        [$customerA, $businessA] = $this->tenant(WorkspacePlanTier::Growth, 'Home Venue', 'Home Account');
        $this->wallet($businessA, ['available_balance_micro' => 5000000]);

        [, $businessB] = $this->tenant(WorkspacePlanTier::Growth, 'Stranger Venue', 'Stranger Account');
        $this->wallet($businessB, ['available_balance_micro' => 777000000, 'billing_status' => 'suspended', 'debt_balance_micro' => 1]);
        $this->website($businessB, 'draft');
        $this->googleConnection($businessB, GoogleConnectionState::Revoked);
        $this->googleLocation($businessB, 'suspended');
        $this->recommendation($businessB, ['title' => 'Stranger recommendation']);
        $this->sent($businessB, 9, '2026-09-01');
        $this->contactsAdded($businessB, 6, '2026-09-01');
        $this->conversationsStarted($businessB, 5, '2026-09-01');
        $this->automationRuns($businessB, 4, '2026-09-01', 'failed');

        $this->authenticateAs($customerA);
        $html = $this->home()->assertOk()->getContent();
        $main = $this->mainText($html);

        $this->assertNotContains('attention', $this->bandOrder($html), 'None of B\'s conditions raises an item for A.');
        $this->assertNotContains('recommendations', $this->bandOrder($html));
        $this->assertStringNotContainsString('Stranger', $main);
        $this->assertStringNotContainsString('777', $main);
        $this->assertStringContainsString('USD 5.00', $main);

        foreach (['messages_sent', 'new_contacts', 'conversations_started', 'automation_runs'] as $headline) {
            $this->assertSame('0', $this->headlineFigure($html, $headline), "{$headline} must count only the selected Business.");
        }
    }

    // =================================================================
    // #11, #12 — entitlement
    // =================================================================

    public function test_an_unentitled_feature_is_absent_even_with_the_permission_granted(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Entitled Venue', 'Entitled Account');
        $this->conversationsStarted($business, 3, '2026-09-01');
        $this->automationRuns($business, 2, '2026-09-01', 'failed');
        $this->googleConnection($business, GoogleConnectionState::Revoked);
        $this->authenticateAs($customer); // every customer permission

        $html = $this->home()->assertOk()->getContent();
        $this->assertContains('inbox', $this->quickActionKeys($html));
        $this->assertContains('conversations_started', $this->headlineKeys($html));
        $this->assertContains('automation_runs', $this->headlineKeys($html));
        $this->assertContains(AttentionType::AutomationFailing->value, $this->attentionTypes($html));
        $this->assertContains(AttentionType::GoogleConnectionLost->value, $this->attentionTypes($html));

        foreach (['conversations', 'automations', 'google_business_profile_module'] as $feature) {
            app(\App\Library\Entitlement\EntitlementManager::class)->disableBusinessFeature($business, PlatformFeature::from($feature), (int) $customer->user_id, 'Dashboard entitlement test.');
        }
        Cache::flush();

        $html = $this->home()->assertOk()->getContent();

        $this->assertNotContains('inbox', $this->quickActionKeys($html), 'Permission alone never exposes an unentitled feature.');
        $this->assertNotContains('conversations_started', $this->headlineKeys($html));
        $this->assertNotContains('automation_runs', $this->headlineKeys($html));
        $this->assertNotContains(AttentionType::AutomationFailing->value, $this->attentionTypes($html));
        $this->assertNotContains(AttentionType::GoogleConnectionLost->value, $this->attentionTypes($html));
        $this->assertStringNotContainsString('disabled', $this->mainHtml($html), 'Absent, never disabled.');
        $this->assertStringNotContainsString('data-state="locked"', $html, 'The dashboard is not an upsell wall.');
    }

    public function test_a_permission_the_actor_lacks_hides_the_tile_even_when_entitled(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Narrow Venue', 'Narrow Account');
        $this->conversationsStarted($business, 3, '2026-09-01');
        $this->authenticateAs($customer, ['view_reports']);

        $html = $this->home()->assertOk()->getContent();

        $this->assertNotContains('inbox', $this->quickActionKeys($html));
        $this->assertNotContains('send', $this->quickActionKeys($html));
        $this->assertNotContains('conversations_started', $this->headlineKeys($html));
        $this->assertContains('messages_sent', $this->headlineKeys($html));

        $this->authenticateAs($customer, []);
        $this->assertSame([], $this->headlineKeys($this->home()->assertOk()->getContent()), 'Without view_reports there are no Results figures.');
    }

    // =================================================================
    // #13, #14, #15 — Attention
    // =================================================================

    public function test_each_of_the_nine_attention_types_appears_exactly_on_its_condition_and_clears_with_it(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Condition Venue', 'Condition Account');
        $this->authenticateAs($customer);
        $this->clearAllConditions($business);

        $this->assertSame([], $this->attentionTypeValues($customer->user), 'Precondition: a clean Business raises nothing.');

        foreach (AttentionType::cases() as $type) {
            $this->raise($business, $type);
            Cache::flush();
            $this->assertSame([$type->value], $this->attentionTypeValues($customer->user), "{$type->value} must appear on its condition, alone.");

            $this->clearAllConditions($business);
            Cache::flush();
            $this->assertSame([], $this->attentionTypeValues($customer->user), "{$type->value} must disappear when its condition clears.");
        }

        $this->assertSame(9, count(AttentionType::cases()), 'BusinessPhoneMissing is deliberately not a case (Correction 1, decision B).');
        $this->assertFalse(Schema::hasTable('dashboard_attention_dismissals'), 'No dismissal is persisted anywhere.');
    }

    public function test_google_disconnected_is_the_same_lost_connection_and_pending_or_verified_raise_nothing(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Google Venue', 'Google Account');
        $this->authenticateAs($customer);

        $this->googleConnection($business, GoogleConnectionState::Disconnected);
        $this->assertSame([AttentionType::GoogleConnectionLost->value], $this->attentionTypeValues($customer->user));

        $this->googleConnection($business, GoogleConnectionState::Pending);
        foreach (['verified', 'verification_pending', 'awaiting_review', 'unknown'] as $healthy) {
            $this->googleLocation($business, $healthy);
        }
        $this->assertSame([], $this->attentionTypeValues($customer->user));

        foreach (['ownership_conflict', 'disabled', 'duplicate', 'unverified'] as $unhealthy) {
            DB::table('business_google_locations')->where('business_id', $business->id)->delete();
            $this->googleLocation($business, $unhealthy);
            $this->assertSame([AttentionType::GoogleLocationUnhealthy->value], $this->attentionTypeValues($customer->user), $unhealthy);
        }
    }

    public function test_every_attention_item_carries_type_severity_word_scope_plain_text_and_a_reachable_route(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Shape Venue', 'Shape Account');
        foreach (AttentionType::cases() as $type) {
            $this->raise($business, $type);
        }
        $this->authenticateAs($customer);

        $snapshot = $this->dashboardFor($customer->user);
        $items = $snapshot->band(DashboardSnapshot::BAND_ATTENTION);

        $this->assertCount(9, $items);
        $ranks = array_map(fn (AttentionItem $item) => $item->severity->rank(), $items);
        $sorted = $ranks;
        sort($sorted);
        $this->assertSame($sorted, $ranks, 'Ordered by severity.');

        foreach ($items as $item) {
            $this->assertInstanceOf(AttentionType::class, $item->type);
            $this->assertInstanceOf(AttentionSeverity::class, $item->severity);
            $this->assertSame('Shape Venue', $item->scope);
            $this->assertDoesNotMatchRegularExpression('/_|locale\.|twilio|stripe|google_business|' . preg_quote($item->type->value, '/') . '/i', $item->text, 'Plain customer sentence only.');
            $this->assertUrlMatchesARegisteredRoute($item->url);
        }

        $html = $this->home()->assertOk()->getContent();

        foreach (AttentionSeverity::cases() as $severity) {
            if (preg_match('/data-severity="' . $severity->value . '"/', $html)) {
                $this->assertMatchesRegularExpression('#data-severity="' . $severity->value . '".*?<span[^>]*>\s*' . $severity->word() . '\s*</span>#s', $html, 'Severity is rendered as a word.');
            }
        }

        // Each remediation opens for this actor.
        foreach (array_unique(array_map(fn (AttentionItem $item) => $item->url, $items)) as $url) {
            $this->get($url)->assertOk();
        }
    }

    // =================================================================
    // #16, #17, #18 — Advisor
    // =================================================================

    public function test_a_deterministic_failure_is_attention_and_never_a_recommendation(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Separate Venue', 'Separate Account');
        $this->wallet($business, ['billing_status' => 'suspended']);
        $this->website($business, 'draft');
        $this->authenticateAs($customer);

        $html = $this->home()->assertOk()->getContent();

        $this->assertContains('attention', $this->bandOrder($html));
        $this->assertNotContains('recommendations', $this->bandOrder($html), 'No recommendation exists, so the band is absent — prerequisite failures are never promoted into it.');

        $this->recommendation($business, ['title' => 'Grow repeat bookings with a follow-up']);
        $html = $this->home()->assertOk()->getContent();
        $recommendations = $this->bandHtml($html, 'recommendations');

        $this->assertStringContainsString('Grow repeat bookings with a follow-up', $recommendations);
        foreach ([AttentionType::WalletSuspended, AttentionType::WebsiteUnpublished] as $type) {
            $this->assertStringNotContainsString($type->sentence(), $recommendations);
        }
        $this->assertStringNotContainsString('Grow repeat bookings', $this->bandHtml($html, 'attention'));
    }

    public function test_only_open_and_current_recommendations_render_at_most_five_and_never_from_the_query_string(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Advisor Venue', 'Advisor Account');

        for ($i = 0; $i < 8; $i++) {
            $this->recommendation($business, ['title' => 'Open Current ' . $i, 'priority_score' => 90 - $i]);
        }
        $this->recommendation($business, ['title' => 'Stale Item', 'freshness' => 'stale', 'priority_score' => 99]);
        foreach (['snoozed', 'awaiting_approval', 'in_progress', 'completed', 'dismissed'] as $status) {
            $this->recommendation($business, ['title' => 'Status ' . $status, 'status' => $status, 'priority_score' => 99]);
        }
        $this->authenticateAs($customer);

        foreach ([route('user.home'), route('user.home', ['page' => 2])] as $url) {
            $band = $this->bandHtml($this->get($url)->assertOk()->getContent(), 'recommendations');

            $this->assertSame(5, substr_count($band, 'data-role="recommendation"'));
            foreach (range(0, 4) as $i) {
                $this->assertStringContainsString('Open Current ' . $i, $band);
            }
            foreach (range(5, 7) as $i) {
                $this->assertStringNotContainsString('Open Current ' . $i, $band);
            }
            $this->assertStringNotContainsString('Stale Item', $band);
            $this->assertStringNotContainsString('Status ', $band);
        }
    }

    public function test_the_recommendations_band_is_absent_not_empty_when_there_is_nothing_to_recommend(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Quiet Venue', 'Quiet Account');
        $this->recommendation($business, ['title' => 'Stale Only', 'freshness' => 'stale']);
        $this->authenticateAs($customer);

        $html = $this->home()->assertOk()->getContent();

        $this->assertNotContains('recommendations', $this->bandOrder($html));
        $this->assertStringNotContainsString('Recommended next steps', $html);
        $this->assertStringNotContainsString('No opportunities', $html);

        config(['opportunity.enabled' => false]);
        $this->recommendation($business, ['title' => 'Hidden When Disabled']);
        $html = $this->home()->assertOk()->getContent();
        $this->assertStringNotContainsString('Hidden When Disabled', $html);
    }

    // =================================================================
    // #21 — automationKpis() null
    // =================================================================

    public function test_the_automation_headline_is_absent_for_both_periods_when_either_period_is_null(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Null Venue', 'Null Account');
        $this->automationRuns($business, 3, '2026-09-01', 'failed');
        $this->authenticateAs($customer);

        $this->assertContains('automation_runs', $this->headlineKeys($this->home()->assertOk()->getContent()));

        foreach ([AnalyticsDateRange::PRESET_CUSTOM, AnalyticsDateRange::PRESET_LAST_30_DAYS] as $nullPreset) {
            Cache::flush();
            $this->partialMock(BusinessAnalyticsQueries::class, function ($mock) use ($nullPreset) {
                $mock->shouldReceive('automationKpis')->andReturnUsing(
                    fn (Business $b, AnalyticsDateRange $range) => $range->preset === $nullPreset ? null : new AutomationKpis(3, ['failed' => 3], ['contact_created' => 3]),
                );
            });

            $html = $this->home()->assertOk()->getContent();

            $this->assertNotContains('automation_runs', $this->headlineKeys($html), "Absent when the {$nullPreset} period is null — never zeroed on one side.");
            $this->assertContains('messages_sent', $this->headlineKeys($html), 'Every other headline still renders.');
        }
    }

    // =================================================================
    // #22, #23, #28, #29, #36 — vocabulary, no invented metric, no chart
    // =================================================================

    public function test_the_page_says_provider_accepted_and_never_labels_a_metric_delivered_or_invents_one(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Words Venue', 'Words Account');
        $this->populateAllFiveBands($business);
        $this->sent($business, 2, '2026-09-03', 'Undelivered');
        $this->authenticateAs($customer);

        $html = $this->home()->assertOk()->getContent();
        $main = $this->mainText($html);

        $this->assertStringContainsString('Provider accepted', $main);
        $this->assertStringContainsString('provider accepted', $main);
        $this->assertDoesNotMatchRegularExpression('/\bdelivered\b/i', $main);
        $this->assertDoesNotMatchRegularExpression('/\b(revenue|roi|reply rate|handset delivery|pipeline value|bookings?|conversions?)\b/i', $main);
        $this->assertStringNotContainsString('locale.', $main);
        $this->assertStringNotContainsString('locale.', $this->titleOf($html));

        $this->assertStringNotContainsString('apexcharts', $html);
        $this->assertStringNotContainsString('<canvas', $html);
        $this->assertStringNotContainsString('id="sms-reports"', $html);
        $this->assertStringNotContainsString('range', strtolower(implode(' ', $this->formFieldNames($html))), 'No range picker.');

        foreach (['customer.sms.quick_send', 'customer.sms.campaign_builder', 'customer.chatbox.index'] as $legacy) {
            if (Route::has($legacy)) {
                $this->assertStringNotContainsString(route($legacy), $html, "{$legacy} is never linked from the dashboard.");
            }
        }
    }

    // =================================================================
    // #30, #47 — quick actions
    // =================================================================

    public function test_quick_actions_are_at_most_four_business_scoped_and_inbox_is_the_canonical_route(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Action Venue', 'Action Account');
        $this->wallet($business, ['available_balance_micro' => 9000000]);
        $this->website($business, 'draft');
        $this->googleConnection($business, GoogleConnectionState::Revoked);
        $this->authenticateAs($customer);

        $html = $this->home()->assertOk()->getContent();
        $actions = $this->quickActionKeys($html);

        $this->assertLessThanOrEqual(4, count($actions));
        $this->assertSame(['send', 'inbox', 'add_contact', 'add_funds'], $actions, 'The payer\'s Add funds takes the fourth place before a setup action.');
        $this->assertStringContainsString(
            'href="' . route('customer.workspaces.businesses.conversations.index', [$workspace->uid, $business->uid]) . '"',
            $this->bandHtml($html, 'actions'),
        );
    }

    public function test_a_setup_action_takes_the_fourth_place_only_when_its_status_column_proves_it(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Setup Venue', 'Setup Account');
        $this->authenticateAs($customer);

        $this->assertSame(['send', 'inbox', 'add_contact'], $this->quickActionKeys($this->home()->assertOk()->getContent()), 'No wallet, nothing to set up: three actions.');

        $this->website($business, 'draft');
        $html = $this->home()->assertOk()->getContent();
        $this->assertSame(['send', 'inbox', 'add_contact', 'publish_website'], $this->quickActionKeys($html));

        $this->website($business, 'published');
        $this->googleConnection($business, GoogleConnectionState::Revoked);
        $this->assertSame(['send', 'inbox', 'add_contact', 'reconnect_google'], $this->quickActionKeys($this->home()->assertOk()->getContent()));
    }

    // =================================================================
    // #34 — band-level degradation
    // =================================================================

    public function test_one_failing_band_degrades_locally_and_the_rest_of_the_page_renders(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Fragile Venue', 'Fragile Account');
        $this->populateAllFiveBands($business);
        $this->authenticateAs($customer);

        $failing = Mockery::mock(OpportunityRepository::class);
        $failing->shouldReceive('paginateForCustomer')->andThrow(new RuntimeException('Advisor store unavailable'));
        $this->app->instance(OpportunityRepository::class, $failing);

        $html = $this->home()->assertOk()->getContent();

        $this->assertSame(['attention', 'recommendations', 'headlines', 'spend', 'actions'], $this->bandOrder($html));
        $this->assertStringContainsString('data-band="recommendations" data-band-state="failed"', $html);
        $this->assertStringContainsString('This section could not be loaded just now.', $this->bandHtml($html, 'recommendations'));
        $this->assertStringNotContainsString('data-band-state="failed"', $this->bandHtml($html, 'headlines'));
        $this->assertStringNotContainsString('Advisor store unavailable', $html);

        $this->app->forgetInstance(OpportunityRepository::class);
        $this->app->bind(OpportunityRepository::class, \App\Repositories\Eloquent\EloquentOpportunityRepository::class);
        Cache::flush();
        $this->partialMock(BusinessAnalyticsQueries::class, function ($mock) {
            $mock->shouldReceive('messageKpis')->andThrow(new RuntimeException('Results store unavailable'));
        });

        $html = $this->home()->assertOk()->getContent();

        $this->assertStringContainsString('data-band="headlines" data-band-state="failed"', $html);
        $this->assertStringNotContainsString('data-band-state="failed"', $this->bandHtml($html, 'recommendations'));
        $this->assertContains('attention', $this->bandOrder($html));
        $this->assertContains('actions', $this->bandOrder($html));
    }

    // =================================================================
    // #35 — empty and locked states
    // =================================================================

    public function test_the_zero_business_state_offers_one_create_action_and_no_band_is_ever_a_locked_upsell_or_404(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();
        $customer = $this->createCustomer();
        $this->authenticateAs($customer);

        $html = $this->home()->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-role="empty-state-primary"'));
        $this->assertStringContainsString('href="' . route('customer.workspaces.index') . '"', $html);
        $this->assertStringNotContainsString('data-band=', $this->mainHtml($html), 'Nothing else.');

        // Core is not entitled to Google: no Google band, no locked upsell, a 200 page.
        [$core, $business] = $this->tenant(WorkspacePlanTier::Core, 'Core Venue', 'Core Account');
        $this->googleConnection($business, GoogleConnectionState::Revoked);
        $this->authenticateAs($core);

        $html = $this->home()->assertOk()->getContent();
        $this->assertStringNotContainsString('data-state="locked"', $html);
        $this->assertNotContains(AttentionType::GoogleConnectionLost->value, $this->attentionTypes($html));
    }

    public function test_staff_without_a_business_get_an_owner_hint_instead_of_a_create_action(): void
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Unassigned Client', 'Northwind Agency');
        $staff = $this->createCustomer();
        $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected);
        $this->authenticateAs($staff);

        $html = $this->home()->assertOk()->getContent();

        $this->assertStringContainsString('data-kind="zero"', $html);
        $this->assertStringNotContainsString('data-role="empty-state-primary"', $html);
        $this->assertStringContainsString('data-role="empty-state-owner"', $html);
    }

    // =================================================================
    // Correction 1, decision D — Login as Parent
    // =================================================================

    public function test_a_team_member_keeps_login_as_parent_as_a_quick_action_and_never_while_viewing(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Parent Venue', 'Parent Account');
        $member = $this->createCustomer();
        DB::table('users')->where('id', $member->user_id)->update(['parent_id' => $owner->user_id]);
        $membership = $this->member($workspace, $member->user, WorkspaceMembershipRole::Admin);
        $this->authenticateAs($member);

        $html = $this->home()->assertOk()->getContent();

        $this->assertContains('login_as_parent', $this->quickActionKeys($html));
        $this->assertLessThanOrEqual(4, count($this->quickActionKeys($html)));
        $this->assertStringContainsString(route('user.account.login_as', $owner->user->uid), $html);
        $this->assertStringContainsString('You are currently logged in as a team member.', $html);

        // Never for an ordinary owner.
        $this->authenticateAs($owner);
        $this->assertNotContains('login_as_parent', $this->quickActionKeys($this->home()->assertOk()->getContent()));
    }

    public function test_login_as_parent_is_absent_while_a_team_member_views_a_client(): void
    {
        [$owner, $client, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Parent Client', 'Parent Agency');
        $this->addBusiness($owner, $workspace, 'Other Parent Client');
        $member = $this->createCustomer();
        DB::table('users')->where('id', $member->user_id)->update(['parent_id' => $owner->user_id]);
        $this->member($workspace, $member->user, WorkspaceMembershipRole::Admin);
        $this->authenticateAs($member);

        $this->startViewAs($workspace, $client)->assertRedirect(route('user.home'));
        $html = $this->home()->assertOk()->getContent();

        $this->assertStringContainsString('data-role="view-as-banner"', $html);
        $this->assertNotContains('login_as_parent', $this->quickActionKeys($html));
        $this->assertStringNotContainsString(route('user.account.login_as', $owner->user->uid), $this->mainHtml($html));
    }

    // -----------------------------------------------------------------
    // Fixture helpers
    // -----------------------------------------------------------------

    private function populateAllFiveBands(Business $business): void
    {
        $this->wallet($business, ['available_balance_micro' => 1000000, 'auto_recharge_threshold_micro' => 5000000]);
        $this->recommendation($business, ['title' => 'Ask happy customers for a review']);
        $this->sent($business, 3, '2026-09-01');
        $this->sent($business, 2, '2026-08-01');
        $this->contactsAdded($business, 2, '2026-09-02');
        $this->conversationsStarted($business, 1, '2026-09-02');
    }

    private function clearAllConditions(Business $business): void
    {
        $this->wallet($business, [
            'billing_status' => 'active',
            'debt_balance_micro' => 0,
            'paid_activity_paused_at' => null,
            'available_balance_micro' => 10000000,
            'auto_recharge_threshold_micro' => 1000000,
            'consecutive_recharge_failures' => 0,
        ]);
        $this->website($business, 'published');
        $this->googleConnection($business, GoogleConnectionState::Active);
        DB::table('business_google_locations')->where('business_id', $business->id)->update(['verification_state' => 'verified']);
        DB::table('automation_executions')->where('business_id', $business->id)->update(['status' => 'succeeded']);
    }

    private function raise(Business $business, AttentionType $type): void
    {
        match ($type) {
            AttentionType::WalletSuspended => $this->wallet($business, ['billing_status' => 'suspended']),
            AttentionType::OutstandingDebt => $this->wallet($business, ['debt_balance_micro' => 1]),
            AttentionType::PaidActivityPaused => $this->wallet($business, ['paid_activity_paused_at' => now()]),
            AttentionType::LowBalance => $this->wallet($business, ['available_balance_micro' => 999999, 'auto_recharge_threshold_micro' => 1000000]),
            AttentionType::AutoRechargeFailing => $this->wallet($business, ['consecutive_recharge_failures' => 2]),
            AttentionType::WebsiteUnpublished => $this->website($business, 'draft'),
            AttentionType::GoogleConnectionLost => $this->googleConnection($business, GoogleConnectionState::Revoked),
            AttentionType::GoogleLocationUnhealthy => $this->googleLocation($business, 'suspended'),
            AttentionType::AutomationFailing => $this->automationRuns($business, 1, '2026-09-05', 'failed'),
        };
    }

    /** @return array<int, string> */
    private function attentionTypeValues(User $user): array
    {
        $items = $this->dashboardFor($user)->band(DashboardSnapshot::BAND_ATTENTION) ?? [];
        $values = array_map(fn (AttentionItem $item) => $item->type->value, $items);
        sort($values);

        return $values;
    }

    /** @return array<int, string> */
    private function bandOrder(string $html): array
    {
        preg_match_all('/data-band="([a-z_]+)"/', $this->mainHtml($html), $matches);

        return $matches[1];
    }

    private function bandHtml(string $html, string $band): string
    {
        $main = $this->mainHtml($html);
        $start = strpos($main, 'data-band="' . $band . '"');

        if ($start === false) {
            return '';
        }

        $end = strpos($main, '</section>', $start);

        return substr($main, $start, ($end === false ? strlen($main) : $end) - $start);
    }

    /** @return array<int, string> */
    private function attentionTypes(string $html): array
    {
        preg_match_all('/data-attention-type="([a-z_]+)"/', $this->bandHtml($html, 'attention'), $matches);

        return $matches[1];
    }

    /** @return array<int, string> */
    private function headlineKeys(string $html): array
    {
        preg_match_all('/data-headline="([a-z_]+)"/', $this->mainHtml($html), $matches);

        return $matches[1];
    }

    private function headlineFigure(string $html, string $key): ?string
    {
        $pattern = '#data-headline="' . preg_quote($key, '#') . '".*?data-role="headline-figure">([^<]*)<#s';

        return preg_match($pattern, $this->mainHtml($html), $match) === 1 ? trim($match[1]) : null;
    }

    /** @return array<int, string> */
    private function quickActionKeys(string $html): array
    {
        preg_match_all('/data-action="([a-z_]+)"/', $this->bandHtml($html, 'actions'), $matches);

        return $matches[1];
    }

    /** @return array<int, string> */
    private function quickActionLinks(string $html): array
    {
        preg_match_all('/<a\s+href="([^"]+)"[^>]*data-role="quick-action"/', $this->bandHtml($html, 'actions'), $matches);

        return $matches[1];
    }

    /** @return array<int, string> */
    private function mainLinks(string $html): array
    {
        preg_match_all('/href="([^"]+)"/', $this->mainHtml($html), $matches);

        return array_map('html_entity_decode', $matches[1]);
    }

    /** @return array<int, string> */
    private function formFieldNames(string $html): array
    {
        preg_match_all('/<(?:input|select)[^>]*name="([^"]+)"/', $this->mainHtml($html), $matches);

        return $matches[1];
    }

    private function titleOf(string $html): string
    {
        return preg_match('#<title>(.*?)</title>#s', $html, $match) === 1 ? $match[1] : '';
    }
}

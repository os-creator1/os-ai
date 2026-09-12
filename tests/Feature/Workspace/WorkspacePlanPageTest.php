<?php

namespace Tests\Feature\Workspace;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Currency;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Settings → Plan & subscription is the account's own AI Business OS plan
 * (workspace_plan_assignments / _catalog / _features) — Core, Growth or
 * Agency — never the inherited Ultimate SMS plans/subscriptions pages.
 */
class WorkspacePlanPageTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    private const MACHINE_KEYS = ['crm', 'conversations', 'automations', 'website_generation', 'google_business_profile_module', 'prospect_outreach', 'seo_module', 'google_ads_module', 'meta_ads_module'];

    public function test_growth_resolves_to_the_growth_assignment_with_human_feature_names(): void
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Harbor Lane Studios', 'Harbor Lane');
        $this->authenticateAs($owner);

        $page = $this->planPage($workspace);

        $this->assertSame('Growth', $this->text($page, 'plan-name'));
        $page->assertSee('Active');
        $this->assertSame(['Client Management', 'Inbox & Conversations', 'Automations', 'Website', 'Google Business Profile'], $this->includedNames($page));
        $page->assertSee('Manage how your business appears on Google.');
    }

    public function test_core_resolves_to_core(): void
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->authenticateAs($owner);

        $page = $this->planPage($workspace);

        $this->assertSame('Core', $this->text($page, 'plan-name'));
        $this->assertSame(['Client Management', 'Inbox & Conversations', 'Automations', 'Website'], $this->includedNames($page));
    }

    public function test_agency_resolves_to_the_agency_accounts_own_plan(): void
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $this->addBusiness($owner, $workspace, 'Client Two');
        $this->authenticateAs($owner);

        $page = $this->planPage($workspace);

        $this->assertSame('Agency', $this->text($page, 'plan-name'));
        $page->assertSee('Northwind Agency');
        $this->assertContains('Prospecting', $this->includedNames($page));
    }

    /**
     * The corrected capacity model (RFC-004 §33): Core = 1 Business,
     * Growth = 1 Business, Agency = unlimited Businesses, with the
     * 3-included / 4-and-5-by-allocation / 6+-requires-Agency rule governing
     * PHYSICAL locations. Core and Growth still carry Milestone 1's
     * superseded Business-slot data (3 included, 5 max) until §33's additive
     * migration lands, so the page shows no Business figure for them rather
     * than the withdrawn limit — and never restates the rule itself.
     */
    public function test_core_and_growth_never_show_the_superseded_business_slot_numbers(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth] as $tier) {
            [$owner, , $workspace] = $this->tenant($tier, 'Tier ' . $tier->value, 'Own Account');
            $this->authenticateAs($owner);
            $decision = app(EntitlementManager::class)->decideBusinessSlotCapacity($workspace);

            $section = $this->section($this->planPage($workspace));

            // The catalog still says 3 included / 5 max for this tier — proof
            // the page is not simply echoing what it reads.
            $this->assertFalse($decision->unlimited);
            $this->assertSame(3, $decision->includedSlots);
            $this->assertStringNotContainsString('of ' . $decision->effectiveCapacity . ' in use', $section);
            $this->assertStringNotContainsString('data-role="plan-capacity"', $section);

            foreach (['1 of 3 Businesses', 'Businesses', 'Client accounts', 'Included slots', 'Additional slots', 'Effective capacity'] as $absent) {
                $this->assertStringNotContainsString($absent, $section, $tier->value . ' must not state a Business limit.');
            }
        }
    }

    /**
     * Unlimited is the one Business figure the catalog states and §33 agrees
     * with, so Agency shows it — read from the decision, never written here.
     */
    public function test_agency_shows_unlimited_businesses_from_the_canonical_decision(): void
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $this->addBusiness($owner, $workspace, 'Client Two');
        $this->authenticateAs($owner);
        $decision = app(EntitlementManager::class)->decideBusinessSlotCapacity($workspace);

        $page = $this->planPage($workspace);

        $this->assertTrue($decision->unlimited);
        $this->assertMatchesRegularExpression(
            '/<dt[^>]*>Client accounts<\/dt>\s*<dd[^>]*>' . $decision->currentBusinessCount . ' in use · no limit<\/dd>/',
            $page->getContent()
        );
        $this->assertStringNotContainsString(' of ', $this->between($page->getContent(), 'data-role="plan-capacity"', '</dl>'));
    }

    /**
     * Physical-location capacity is not readable on main yet (§33 contracts
     * the migration and the decision over it), so the page states nothing
     * about locations and invents no location price.
     */
    public function test_no_location_capacity_or_location_price_is_invented(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth, WorkspacePlanTier::Agency] as $tier) {
            [$owner, , $workspace] = $this->tenant($tier, 'Tier ' . $tier->value, 'Own Account');
            $this->authenticateAs($owner);

            $section = $this->visibleText($this->section($this->planPage($workspace)));

            foreach (['Location', 'location', 'Included locations', 'branch', 'storefront'] as $absent) {
                $this->assertStringNotContainsString($absent, $section, $tier->value . ' must say nothing about physical locations yet.');
            }
        }
    }

    public function test_features_that_do_not_exist_yet_are_not_listed_and_no_machine_key_is_shown(): void
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Agency);
        $this->authenticateAs($owner);

        $visible = $this->visibleText($this->section($this->planPage($workspace)));

        foreach (self::MACHINE_KEYS as $key) {
            $this->assertDoesNotMatchRegularExpression('/\b' . preg_quote($key, '/') . '\b/', $visible, "Machine key [{$key}] must not be visible.");
        }

        foreach (['SEO', 'Google Ads', 'Meta Ads', 'White label'] as $planned) {
            $this->assertStringNotContainsString($planned, $visible, "{$planned} is packaged for later but not available yet.");
        }
    }

    public function test_the_page_never_reads_or_renders_the_legacy_sms_plans(): void
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($owner);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = strtolower($query->sql);
        });

        // What the page itself reads: the Workspace plan domain only.
        app(\App\Library\Entitlement\WorkspacePlanPresenter::class)->present($workspace);
        foreach ($queries as $sql) {
            $this->assertDoesNotMatchRegularExpression('/\b(from|join)\s+`?(plans|subscriptions|plans_coverage_countries)`?\b/', $sql, 'The plan page must not read the legacy SMS plan domain.');
        }

        // The whole response never touches legacy plan pricing either (the
        // shared shell's pre-existing active-subscription lookup aside).
        $queries = [];
        $page = $this->planPage($workspace);

        foreach ($queries as $sql) {
            $this->assertDoesNotMatchRegularExpression('/\b(from|join)\s+`?(plans|plans_coverage_countries)`?\b/', $sql, 'The plan page must not read legacy SMS plan pricing.');
        }

        $page->assertDontSee('Pricing Plans');
        $page->assertDontSee('Pricing plans');
        $page->assertDontSee(route('customer.subscriptions.index'), false);
    }

    public function test_no_price_is_guessed_when_the_catalog_has_none(): void
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->update(['is_complimentary' => false]);
        DB::table('workspace_plan_catalog')->where('tier', 'growth')->update(['price' => null]);
        $this->authenticateAs($owner);

        $page = $this->planPage($workspace);

        $section = $this->section($page);
        $this->assertStringNotContainsString('data-role="plan-billing"', $section);
        foreach (['$0', '0.00', 'Free'] as $guess) {
            $this->assertStringNotContainsString($guess, $section);
        }
    }

    public function test_a_configured_price_and_a_complimentary_plan_are_shown_as_they_are(): void
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($owner);

        $this->assertSame('Complimentary', $this->billingValue($this->planPage($workspace)));

        $euro = Currency::create(['name' => 'Euro', 'code' => 'EUR', 'format' => '€{PRICE}', 'status' => true]);
        DB::table('workspace_plan_catalog')->where('tier', 'growth')->update(['price' => '49.00', 'currency_id' => $euro->id, 'billing_cycle' => 'monthly']);
        DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->update(['is_complimentary' => false]);

        $this->assertSame('€49.00 per month', $this->billingValue($this->planPage($workspace)));
    }

    public function test_an_unassigned_account_says_so_without_inventing_a_plan(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $owner = $this->createCustomer();
        $workspace = $this->createWorkspace($owner->user, ['name' => 'Fresh Account']);
        $this->authenticateAs($owner);

        $page = $this->planPage($workspace);

        $page->assertSee('No plan is set up for Fresh Account yet.');
        $page->assertDontSee('data-role="plan-name"', false);
        $page->assertDontSee('data-role="plan-included"', false);
    }

    /**
     * Owner and Agency-wide Admin manage the plan. Everyone else gets the
     * same 404 as an unknown account.
     */
    public function test_an_unauthorized_or_inaccessible_account_fails_closed(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $url = route('customer.workspaces.plan.show', $workspace->uid);

        $admin = $this->createCustomer();
        $this->member($workspace, $admin->user, WorkspaceMembershipRole::Admin);
        $this->authenticateAs($admin);
        $this->get($url)->assertOk();

        $unknown = null;
        foreach ([
            'stranger' => fn () => $this->createCustomer(),
            'staff' => function () use ($workspace) {
                $staff = $this->createCustomer();
                $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff);

                return $staff;
            },
            'client admin' => function () use ($workspace, $business) {
                $client = $this->createCustomer();
                $this->assign($this->member($workspace, $client->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::Selected), $business);

                return $client;
            },
            'former admin' => function () use ($workspace) {
                $former = $this->createCustomer();
                $this->member($workspace, $former->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::All, false);

                return $former;
            },
        ] as $who => $make) {
            $this->authenticateAs($make());
            $denied = $this->get($url)->assertNotFound();
            $unknown ??= $this->get(route('customer.workspaces.plan.show', 'no-such-account'))->assertNotFound()->getContent();

            $this->assertSame($unknown, $denied->getContent(), "{$who} must get the unknown-account 404.");
        }

        $this->assertNotNull($owner);
    }

    public function test_customer_navigation_opens_this_plan_page_not_the_legacy_subscriptions(): void
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($owner);

        $home = $this->home()->assertOk()->getContent();

        $this->assertContains(route('customer.workspaces.plan.show', $workspace->uid), $this->menuLinks($home));
        $this->assertStringNotContainsString('href="' . route('customer.subscriptions.index') . '"', $home, 'Neither the sidebar nor the profile menu links the legacy subscriptions page.');
        $this->assertStringNotContainsString('href="' . route('user.account.pricing') . '"', $home, 'Nor the legacy pricing plans page.');
    }

    public function test_agency_account_frame_links_the_agency_accounts_plan(): void
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $this->addBusiness($owner, $workspace, 'Client Two');
        $this->authenticateAs($owner);

        $html = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk()->getContent();

        $this->assertContains(route('customer.workspaces.plan.show', $workspace->uid), $this->menuLinks($html));
        $this->assertStringNotContainsString('href="' . route('customer.subscriptions.index') . '"', $html);
    }

    public function test_the_plan_menu_entry_is_active_on_the_plan_page(): void
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($owner);

        $this->assertContains('plan', $this->activeMenuKeys($this->planPage($workspace)->getContent()));
    }

    // -----------------------------------------------------------------

    private function planPage(Workspace $workspace): TestResponse
    {
        return $this->get(route('customer.workspaces.plan.show', $workspace->uid))->assertOk();
    }

    private function text(TestResponse $page, string $role): string
    {
        $this->assertSame(1, preg_match('/data-role="' . $role . '"[^>]*>(.*?)</s', $page->getContent(), $match), "[{$role}] not rendered.");

        return trim(html_entity_decode($match[1]));
    }

    /**
     * @return list<string>
     */
    private function includedNames(TestResponse $page): array
    {
        $this->assertSame(1, preg_match('/data-role="plan-included">(.*?)<\/ul>/s', $page->getContent(), $list));
        preg_match_all('/<div class="fw-bolder">([^<]+)<\/div>/', $list[1], $names);

        return array_map('html_entity_decode', $names[1]);
    }

    private function billingValue(TestResponse $page): string
    {
        $this->assertSame(1, preg_match('/data-role="plan-billing">.*?<dd[^>]*>([^<]+)<\/dd>/s', $page->getContent(), $match), 'Billing not rendered.');

        return trim(html_entity_decode($match[1]));
    }

    private function between(string $haystack, string $start, string $end): string
    {
        $from = strpos($haystack, $start);
        $this->assertNotFalse($from, "Missing [{$start}].");
        $from += strlen($start);
        $to = strpos($haystack, $end, $from);
        $this->assertNotFalse($to, "Missing [{$end}].");

        return substr($haystack, $from, $to - $from);
    }

    /**
     * The page body: the plan section only, not the shared shell around it.
     */
    private function section(TestResponse $page): string
    {
        $this->assertSame(1, preg_match('/<section id="workspace-plan">.*?<\/section>/s', $page->getContent(), $match));

        return $match[0];
    }

    private function visibleText(string $html): string
    {
        $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html) ?? '';

        return html_entity_decode(strip_tags(preg_replace('/\s[a-zA-Z-]+="[^"]*"/', '', $html) ?? ''));
    }
}

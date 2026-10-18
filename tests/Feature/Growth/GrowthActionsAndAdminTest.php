<?php

namespace Tests\Feature\Growth;

use App\Enums\Growth\GrowthActionSafetyClass;
use App\Library\Growth\GrowthRuleRegistry;
use App\Library\Opportunity\Exceptions\OpportunityActionNotExecutableException;
use App\Library\Opportunity\OpportunityActionRegistry;
use App\Library\Opportunity\OpportunityManager;
use App\Models\Customer;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Growth\Concerns\CreatesGrowthFixtures;
use Tests\TestCase;

/**
 * Stage D — actions: handoffs, safety classes, "resolved only by evidence",
 * snooze wake-up — plus the Platform Owner's existing admin view reading the
 * new worker keys without breaking.
 */
class GrowthActionsAndAdminTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGrowthFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGrowthBusiness();
    }

    // ── Safety classes ───────────────────────────────────────────────────

    public function test_every_v1_action_is_read_only_and_needs_no_confirmation(): void
    {
        foreach (GrowthRuleRegistry::all() as $key => $rule) {
            $this->assertSame(GrowthActionSafetyClass::ReadOnly, $rule->definition()->safetyClass, "{$key}: V1 hands off, it never writes");
        }
    }

    public function test_the_confirmation_policy_is_decided_by_the_class_not_the_screen(): void
    {
        $this->assertFalse(GrowthActionSafetyClass::ReadOnly->requiresConfirmation());

        foreach ([GrowthActionSafetyClass::SafeLocalWrite, GrowthActionSafetyClass::ExternalMessage, GrowthActionSafetyClass::ExternalProviderMutation, GrowthActionSafetyClass::Financial, GrowthActionSafetyClass::Publication] as $class) {
            $this->assertTrue($class->requiresConfirmation(), $class->value . ' always confirms');
        }

        foreach ([GrowthActionSafetyClass::Financial, GrowthActionSafetyClass::Publication, GrowthActionSafetyClass::ExternalProviderMutation, GrowthActionSafetyClass::ExternalMessage] as $class) {
            $this->assertTrue($class->alwaysExplicit(), $class->value . ' can never be pre-approved or batched');
        }

        $this->assertFalse(GrowthActionSafetyClass::SafeLocalWrite->alwaysExplicit());
    }

    // ── The engine never executes a Growth handoff ───────────────────────

    public function test_a_growth_action_cannot_be_approved_or_executed_through_the_engine(): void
    {
        $this->unansweredDeal();
        $this->evaluateGrowth();
        $o = Opportunity::where('type', 'crm.unanswered_new_leads:v1')->firstOrFail();
        $customer = Customer::query()->where('user_id', $this->business->customer_id)->firstOrFail();

        $this->expectException(OpportunityActionNotExecutableException::class);
        app(OpportunityManager::class)->requestApproval($o, $customer);
    }

    public function test_opening_the_fix_writes_nothing_to_the_opportunity_or_the_module(): void
    {
        $this->authenticateAs($this->owner(), ['business_advisor']);
        $deal = $this->unansweredDeal();
        $this->evaluateGrowth();
        $o = Opportunity::where('type', 'crm.unanswered_new_leads:v1')->firstOrFail();
        $before = [$o->fresh()->updated_at->toIso8601String(), $o->fresh()->status->value, DB::table('crm_opportunities')->where('id', $deal->id)->value('contact_status')];

        Log::spy();
        $this->get(route('customer.workspaces.businesses.growth.opportunities.go', [$this->workspace->uid, $this->business->uid, $o->uid]))->assertRedirect();

        $this->assertSame($before, [$o->fresh()->updated_at->toIso8601String(), $o->fresh()->status->value, DB::table('crm_opportunities')->where('id', $deal->id)->value('contact_status')]);
        Log::shouldHaveReceived('info')->withArgs(fn ($message) => $message === 'growth.action_opened')->once();
    }

    public function test_clicking_a_button_never_resolves_an_opportunity_only_the_evidence_does(): void
    {
        $this->authenticateAs($this->owner(), ['business_advisor']);
        $this->unansweredDeal();
        $this->evaluateGrowth();
        $o = Opportunity::where('type', 'crm.unanswered_new_leads:v1')->firstOrFail();

        $this->get(route('customer.workspaces.businesses.growth.opportunities.go', [$this->workspace->uid, $this->business->uid, $o->uid]));
        $this->evaluateGrowth();

        $this->assertSame('current', $o->fresh()->freshness->value);
        $this->assertSame('open', $o->fresh()->status->value);
        $this->assertNull($o->fresh()->completed_at);
    }

    public function test_the_automation_handoff_is_offered_for_lead_response_only(): void
    {
        $this->authenticateAs($this->owner(), ['business_advisor']);
        $this->unansweredDeal();
        $this->document('sent', ['sent_at' => now()->subDays(5)], [], 1000);
        $this->evaluateGrowth();
        $leads = Opportunity::where('type', 'crm.unanswered_new_leads:v1')->firstOrFail();
        $proposal = Opportunity::where('type', 'documents.proposal_unsigned:v1')->firstOrFail();

        $show = fn ($o) => $this->get(route('customer.workspaces.businesses.growth.opportunities.show', [$this->workspace->uid, $this->business->uid, $o->uid]));

        $show($leads)->assertSee('data-role="secondary-action"', false)->assertSee('Build a follow-up automation');
        $show($proposal)->assertDontSee('data-role="secondary-action"', false);
    }

    // ── Snooze wake-up ───────────────────────────────────────────────────

    public function test_a_snoozed_opportunity_wakes_at_its_time_and_is_re_judged_by_evidence(): void
    {
        $deal = $this->unansweredDeal();
        $this->evaluateGrowth();
        $o = Opportunity::where('type', 'crm.unanswered_new_leads:v1')->firstOrFail();
        $customer = Customer::query()->where('user_id', $this->business->customer_id)->firstOrFail();

        app(OpportunityManager::class)->snooze($o, $customer, now()->addDays(3));
        DB::table('opportunities')->where('id', $o->id)->update(['snoozed_until' => now()->subMinute()]);
        Artisan::call('opportunity:sweep-expired-snoozes');
        $this->assertSame('open', $o->fresh()->status->value, 'the sweep reopens it at its wake time');

        $this->markDealContacted($deal);                    // meanwhile the problem was fixed
        $this->evaluateGrowth();
        $this->assertSame('stale', $o->fresh()->freshness->value, 'and evidence, not the timer, decides whether it still counts');
    }

    // ── Platform Owner / admin ───────────────────────────────────────────

    public function test_the_platform_owner_admin_views_read_growth_runs_and_opportunities(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        \App\Models\AppConfig::updateOrCreate(['setting' => 'license'], ['value' => 'test-license-key']);
        $this->unansweredDeal();
        $this->evaluateGrowth();
        $o = Opportunity::where('type', 'crm.unanswered_new_leads:v1')->firstOrFail();
        $run = \App\Models\OpportunityRun::where('worker_key', 'sales')->firstOrFail();

        $admin = User::create([
            'first_name' => 'Plat', 'last_name' => 'Form', 'email' => 'plat' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
        $this->withSession(['permissions' => collect(['access backend', 'view opportunities'])]);
        $this->actingAs($admin);

        $statuses = [
            'index' => $this->get(route('admin.opportunities.index'))->status(),
            'show' => $this->get(route('admin.opportunities.show', $o->id))->status(),
            'runs' => $this->get(route('admin.opportunities.runs.index', ['business_id' => $this->business->id]))->status(),
            'run' => $this->get(route('admin.opportunities.runs.show', $run->id))->status(),
        ];

        $this->assertSame(['index' => 200, 'show' => 200, 'runs' => 200, 'run' => 200], $statuses);
    }

    private function owner(): Customer
    {
        return Customer::query()->where('user_id', $this->business->customer_id)->firstOrFail();
    }
}

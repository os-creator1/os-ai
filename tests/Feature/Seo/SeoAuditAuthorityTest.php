<?php

namespace Tests\Feature\Seo;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Http\Controllers\Customer\Business\SeoAuditController;
use App\Jobs\Seo\RunSeoAuditForRevision;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Seo\SeoAuditPageReader;
use App\Library\Seo\SeoAuditRunner;
use App\Models\Business;
use App\Models\Customer;
use App\Models\SeoAuditRun;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Seo\Concerns\CreatesSeoFixtures;
use Tests\Support\Seo\EntitlementAllowedSeoAuditJob;
use Tests\Support\Seo\EntitlementBypassSeoAuditController;
use Tests\TestCase;

/**
 * Contract 18 Sub-slice 18G — the three authority properties of the audit
 * that are not about SEO rules at all:
 *
 *  1. the Business -> Website -> revision ownership chain is proved BEFORE
 *     any idempotency short circuit, so an existing run can never be handed
 *     to a caller who has not earned it (§10.1);
 *  2. the queued job re-checks SeoModule entitlement at EXECUTION time, so a
 *     publish cannot quietly accumulate audit data for a Business that may
 *     not have the feature (§10.3);
 *  3. the manual re-run is genuinely throttled, per actor per Business
 *     (§8.7).
 */
class SeoAuditAuthorityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesSeoFixtures;

    private function runner(): SeoAuditRunner
    {
        return app(SeoAuditRunner::class);
    }

    /**
     * A job whose FEATURE DECISION is allowed, with every other gate
     * (Business active, Workspace active) still real production code.
     */
    private function allowedJob(Business $business, $website): EntitlementAllowedSeoAuditJob
    {
        return new EntitlementAllowedSeoAuditJob(
            (int) $business->id,
            (int) $website->id,
            (int) $website->published_revision_id,
        );
    }

    private function page(string $uid = 'p1'): array
    {
        return $this->snapshotPage($uid, 'Home', ['seo_title' => null, 'meta_description' => null]);
    }

    // =================================================================
    // 1. Ownership chain before idempotency.
    // =================================================================

    public function test_a_valid_business_website_and_revision_creates_the_run(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $website = $this->publishWebsite($business, [$this->page()]);

        $run = $this->runner()->runForRevision(
            (int) $business->id,
            (int) $website->id,
            (int) $website->published_revision_id,
        );

        $this->assertNotNull($run);
        $this->assertSame(1, SeoAuditRun::query()->count());
    }

    public function test_a_foreign_business_with_a_valid_website_and_revision_returns_null(): void
    {
        [, $owner] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $website = $this->publishWebsite($owner, [$this->page()]);

        [, $stranger] = $this->entitledTenant(WorkspacePlanTier::Growth);

        $run = $this->runner()->runForRevision(
            (int) $stranger->id,
            (int) $website->id,
            (int) $website->published_revision_id,
        );

        $this->assertNull($run);
        $this->assertSame(0, SeoAuditRun::query()->count(), 'Nothing may be written for a Business that does not own the Website.');
    }

    public function test_a_valid_business_with_a_foreign_website_returns_null(): void
    {
        [, $mine] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->publishWebsite($mine, [$this->page('mine')]);

        [, $theirs] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $theirWebsite = $this->publishWebsite($theirs, [$this->page('theirs')]);

        $run = $this->runner()->runForRevision(
            (int) $mine->id,
            (int) $theirWebsite->id,
            (int) $theirWebsite->published_revision_id,
        );

        $this->assertNull($run);
        $this->assertSame(0, SeoAuditRun::query()->count());
    }

    public function test_a_revision_belonging_to_another_website_returns_null(): void
    {
        [, $mine] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $myWebsite = $this->publishWebsite($mine, [$this->page('mine')]);

        [, $theirs] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $theirWebsite = $this->publishWebsite($theirs, [$this->page('theirs')]);

        // My own Business and my own Website, but someone else's revision id.
        $run = $this->runner()->runForRevision(
            (int) $mine->id,
            (int) $myWebsite->id,
            (int) $theirWebsite->published_revision_id,
        );

        $this->assertNull($run);
        $this->assertSame(0, SeoAuditRun::query()->count());
    }

    /**
     * THE REGRESSION THIS CORRECTION EXISTS FOR. An audit row already exists
     * for the victim's revision. A caller that names its own Business but the
     * victim's revision must NOT be handed that row: the ownership chain runs
     * before the `(website_revision_id, rule_set_version)` lookup, and the
     * row's own stored business_id/website_id are never treated as
     * authorization.
     */
    public function test_an_existing_foreign_run_cannot_be_reached_through_the_idempotency_lookup(): void
    {
        [, $victim] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $victimWebsite = $this->publishWebsite($victim, [$this->page('victim')]);
        $victimRevisionId = (int) $victimWebsite->published_revision_id;

        $legitimate = $this->runner()->runForRevision((int) $victim->id, (int) $victimWebsite->id, $victimRevisionId);
        $this->assertNotNull($legitimate, 'Test premise: the victim has a real audit run.');

        [, $attacker] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $attackerWebsite = $this->publishWebsite($attacker, [$this->page('attacker')]);

        // Three shapes of the same attack, all naming the victim's revision.
        $attempts = [
            [(int) $attacker->id, (int) $attackerWebsite->id, $victimRevisionId],
            [(int) $attacker->id, (int) $victimWebsite->id, $victimRevisionId],
            [(int) $victim->id, (int) $attackerWebsite->id, $victimRevisionId],
        ];

        foreach ($attempts as [$businessId, $websiteId, $revisionId]) {
            $this->assertNull(
                $this->runner()->runForRevision($businessId, $websiteId, $revisionId),
                "Business [{$businessId}] must not reach the run for revision [{$revisionId}]."
            );
        }

        $this->assertSame(1, SeoAuditRun::query()->count(), 'No extra run was written either.');
    }

    public function test_the_read_surface_never_renders_another_businesses_run(): void
    {
        [, $victim] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $victimWebsite = $this->publishWebsite($victim, [$this->page('victim')]);
        $this->runner()->runForRevision((int) $victim->id, (int) $victimWebsite->id, (int) $victimWebsite->published_revision_id);

        [, $attacker] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $attackerWebsite = $this->publishWebsite($attacker, [$this->page('attacker')]);

        $reader = app(SeoAuditPageReader::class);

        $this->assertFalse($reader->read($attacker)->hasRun(), 'The attacker sees no run of their own.');
        $this->assertTrue($reader->read($victim)->hasRun(), 'The victim still sees theirs.');

        // Even a run row that LIES about which Business it belongs to cannot
        // be rendered: authorization is Business AND Website, never the row.
        SeoAuditRun::query()->update(['business_id' => $attacker->id]);

        $page = $reader->read($attacker);

        $this->assertFalse(
            $page->hasRun(),
            'A run claiming the attacker\'s business_id but sitting under the victim\'s Website must not render.'
        );
    }

    public function test_ownership_is_checked_before_the_existing_run_lookup_in_source_order(): void
    {
        $source = str_replace("\r\n", "\n", (string) file_get_contents(
            dirname(__DIR__, 3) . '/app/Library/Seo/SeoAuditRunner.php'
        ));

        $method = substr($source, (int) strpos($source, 'public function runForRevision('));
        $method = substr($method, 0, (int) strpos($method, "\n    }\n") + 7);

        $websiteCheck = strpos($method, "->where('business_id', \$businessId)");
        $revisionCheck = strpos($method, "->where('website_id', \$websiteId)");
        $existingLookup = strpos($method, 'existingRun($revisionId)');

        $this->assertNotFalse($websiteCheck);
        $this->assertNotFalse($revisionCheck);
        $this->assertNotFalse($existingLookup);
        $this->assertLessThan($existingLookup, $websiteCheck, 'The Website ownership check must run before the idempotency lookup.');
        $this->assertLessThan($existingLookup, $revisionCheck, 'The revision ownership check must run before the idempotency lookup.');
    }

    // =================================================================
    // 2. Execution-time entitlement (§10.3).
    // =================================================================

    public function test_while_seo_module_is_planned_an_automatic_publish_creates_no_audit_data(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $website = $this->publishWebsite($business, [$this->page()]);

        // The REAL entitlement manager: SeoModule is Planned, so not allowed.
        (new RunSeoAuditForRevision((int) $business->id, (int) $website->id, (int) $website->published_revision_id))
            ->handle($this->runner(), app(EntitlementManager::class));

        $this->assertSame(0, SeoAuditRun::query()->count(), 'A Planned feature must not accumulate audit data.');
    }

    public function test_an_allowed_entitlement_lets_the_same_job_path_run(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $website = $this->publishWebsite($business, [$this->page()]);

        $this->allowedJob($business, $website)->handle($this->runner(), app(EntitlementManager::class));

        $this->assertSame(1, SeoAuditRun::query()->count());
    }

    public function test_an_inactive_business_produces_no_audit_run(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $website = $this->publishWebsite($business, [$this->page()]);

        // Entitlement allowed; the Business-active gate must still refuse.
        $job = $this->allowedJob($business, $website);
        DB::table('businesses')->where('id', $business->id)->update(['status' => BusinessStatus::Inactive->value]);

        $job->handle($this->runner(), app(EntitlementManager::class));

        $this->assertSame(0, SeoAuditRun::query()->count());
    }

    public function test_an_inactive_workspace_produces_no_audit_run(): void
    {
        [, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $website = $this->publishWebsite($business, [$this->page()]);

        // Entitlement allowed; the Workspace-active gate must still refuse.
        $job = $this->allowedJob($business, $website);
        DB::table('workspaces')->where('id', $workspace->id)->update(['is_active' => false]);

        $job->handle($this->runner(), app(EntitlementManager::class));

        $this->assertSame(0, SeoAuditRun::query()->count());
    }

    /**
     * The whole point of re-checking at EXECUTION time rather than at
     * dispatch: the entitlement was fine when the publish queued the job and
     * is gone by the time the worker picks it up.
     */
    public function test_entitlement_lost_between_dispatch_and_execution_makes_the_job_a_no_op(): void
    {
        Queue::fake();

        [, $business] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $website = $this->publishWebsite($business, [$this->page()]);

        // Dispatched while the publish was happening...
        RunSeoAuditForRevision::dispatch((int) $business->id, (int) $website->id, (int) $website->published_revision_id);
        Queue::assertPushed(RunSeoAuditForRevision::class);

        // ...and by the time the worker runs it the feature is not allowed
        // (SeoModule is Planned — the real decision, no stubbing).
        (new RunSeoAuditForRevision((int) $business->id, (int) $website->id, (int) $website->published_revision_id))
            ->handle($this->runner(), app(EntitlementManager::class));

        $this->assertSame(0, SeoAuditRun::query()->count(), 'A queued job must re-check, not trust dispatch-time state.');
    }

    public function test_the_job_rechecks_entitlement_before_touching_the_runner(): void
    {
        $source = str_replace("\r\n", "\n", (string) file_get_contents(
            dirname(__DIR__, 3) . '/app/Jobs/Seo/RunSeoAuditForRevision.php'
        ));

        $handle = substr($source, (int) strpos($source, 'public function handle('));
        $handle = substr($handle, 0, (int) strpos($handle, "\n    }\n") + 7);

        $gate = strpos($handle, 'stillEntitled(');
        $work = strpos($handle, 'runForRevision(');

        $this->assertNotFalse($gate, 'The job must re-check entitlement.');
        $this->assertNotFalse($work);
        $this->assertLessThan($work, $gate, 'The entitlement gate must run before the audit.');
        $this->assertStringContainsString('PlatformFeature::SeoModule', $source);
    }

    // =================================================================
    // 3. The manual re-run throttle (§8.7).
    // =================================================================

    /** A controller whose entitlement step passes, so the throttle is reachable. */
    private function reachableAuditController(): void
    {
        $this->app->bind(SeoAuditController::class, EntitlementBypassSeoAuditController::class);
    }

    private function rerun(string $workspaceUid, string $businessUid): TestResponse
    {
        return $this->post(route('customer.workspaces.businesses.seo.audit.rerun', [$workspaceUid, $businessUid]));
    }

    public function test_the_first_click_queues_exactly_one_job_and_a_second_inside_the_cooldown_queues_none(): void
    {
        Queue::fake();
        $this->reachableAuditController();

        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->publishWebsite($business, [$this->page()]);
        $this->authenticateAsSeoCustomer($customer);

        $this->rerun($workspace->uid, $business->uid)->assertRedirect();
        Queue::assertPushed(RunSeoAuditForRevision::class, 1);

        $this->rerun($workspace->uid, $business->uid)->assertRedirect();

        Queue::assertPushed(RunSeoAuditForRevision::class, 1, 'A repeat click inside the cooldown must queue nothing more.');
    }

    public function test_a_different_business_has_its_own_cooldown(): void
    {
        Queue::fake();
        $this->reachableAuditController();

        [$customer, $first, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->publishWebsite($first, [$this->page('a')]);

        [$second, $secondWorkspace] = $this->secondBusinessFor($customer);
        $this->publishWebsite($second, [$this->page('b')]);

        $this->authenticateAsSeoCustomer($customer);

        $this->rerun($workspace->uid, $first->uid)->assertRedirect();
        $this->rerun($secondWorkspace->uid, $second->uid)->assertRedirect();

        Queue::assertPushed(RunSeoAuditForRevision::class, 2, 'One Business must never block another.');
    }

    public function test_a_different_actor_has_their_own_cooldown(): void
    {
        Queue::fake();
        $this->reachableAuditController();

        [$owner, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->publishWebsite($business, [$this->page()]);

        $this->authenticateAsSeoCustomer($owner);
        $this->rerun($workspace->uid, $business->uid)->assertRedirect();

        $colleague = $this->selectedScopeMember($workspace, []);
        $this->authenticateAsSeoCustomer($colleague);
        $this->rerun($workspace->uid, $business->uid)->assertRedirect();

        Queue::assertPushed(RunSeoAuditForRevision::class, 2, 'The cooldown is per actor, not shared.');
    }

    public function test_after_the_cooldown_the_same_actor_may_request_again(): void
    {
        Queue::fake();
        $this->reachableAuditController();

        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->publishWebsite($business, [$this->page()]);
        $this->authenticateAsSeoCustomer($customer);

        $this->rerun($workspace->uid, $business->uid)->assertRedirect();
        Queue::assertPushed(RunSeoAuditForRevision::class, 1);

        $this->travel(61)->seconds();

        $this->rerun($workspace->uid, $business->uid)->assertRedirect();

        Queue::assertPushed(RunSeoAuditForRevision::class, 2, 'The cooldown must expire.');

        $this->travelBack();
    }

    public function test_an_unentitled_request_is_refused_without_consuming_the_cooldown(): void
    {
        Queue::fake();

        // No bypass binding: SeoModule is Planned, so the real chain 404s.
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->publishWebsite($business, [$this->page()]);
        $this->authenticateAsSeoCustomer($customer);

        $this->rerun($workspace->uid, $business->uid)->assertNotFound();

        Queue::assertNotPushed(RunSeoAuditForRevision::class);
        $this->assertSame(
            0,
            RateLimiter::attempts(SeoAuditController::rerunLimiterKey((int) $customer->user_id, (int) $business->id)),
            'A refused request must not burn the real customer\'s cooldown.'
        );
    }

    public function test_a_request_without_manage_seo_is_refused_without_consuming_the_cooldown(): void
    {
        Queue::fake();
        $this->reachableAuditController();

        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->publishWebsite($business, [$this->page()]);

        // Read-only capability set.
        $this->authenticateAsSeoCustomer($customer, ['view_seo']);

        $this->rerun($workspace->uid, $business->uid)->assertUnauthorized();

        Queue::assertNotPushed(RunSeoAuditForRevision::class);
        $this->assertSame(
            0,
            RateLimiter::attempts(SeoAuditController::rerunLimiterKey((int) $customer->user_id, (int) $business->id)),
            'The capability gate runs before the throttle.'
        );
    }

    public function test_the_cooldown_is_config_backed_and_fails_closed(): void
    {
        $config = app(\App\Library\Seo\SeoConfig::class);

        $this->assertSame(60, $config->auditManualRerunCooldownSeconds(), 'The documented default.');

        config()->set('seo.audit.manual_rerun_cooldown_seconds', 0);
        $this->assertSame(60, $config->auditManualRerunCooldownSeconds(), 'Zero must not disable the cooldown.');

        config()->set('seo.audit.manual_rerun_cooldown_seconds', 999999);
        $this->assertSame(60, $config->auditManualRerunCooldownSeconds(), 'An absurd value must not lock a customer out.');

        config()->set('seo.audit.manual_rerun_cooldown_seconds', 120);
        $this->assertSame(120, $config->auditManualRerunCooldownSeconds());
    }

    /**
     * A second entitled, ACTIVE Business for the SAME customer.
     *
     * It necessarily brings its own Workspace: `businesses.workspace_id` is
     * UNIQUE in this schema, so one Workspace holds exactly one Business. The
     * actor is unchanged, which is what makes this a clean "different
     * Business" control for the throttle key.
     *
     * @return array{0: Business, 1: Workspace}
     */
    private function secondBusinessFor(Customer $customer): array
    {
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        DB::table('businesses')->where('id', $business->id)->update([
            'status' => BusinessStatus::Active->value,
        ]);

        $workspace = Workspace::query()->findOrFail($business->workspace_id);

        app(EntitlementManager::class)->assignFirstPlan(
            $workspace,
            WorkspacePlanTier::Growth,
            $this->platformAdminId(),
            'SEO audit throttle fixture.',
            true,
            0,
        );

        return [Business::query()->findOrFail($business->id), $workspace->fresh()];
    }
}

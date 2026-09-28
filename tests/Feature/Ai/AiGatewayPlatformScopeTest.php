<?php

namespace Tests\Feature\Ai;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Ai\AiCompletionResult;
use App\Library\Ai\AiGateway;
use App\Library\Ai\AiModelRouter;
use App\Library\Ai\AiRequest;
use App\Library\Ai\AiUsageLedgerManager;
use App\Library\Ai\Contracts\AiCompletionClient;
use App\Library\Ai\Enums\AiLane;
use App\Library\Ai\Enums\AiRefusalReason;
use App\Library\Ai\Enums\AiRefusalScope;
use App\Library\Ai\Enums\AiUsageCategory;
use App\Library\Ai\Enums\AiUsageEntryStatus;
use App\Library\Ai\Exceptions\AiPlatformBudgetMisconfiguredException;
use App\Library\Ai\Providers\FakeAiCompletionClient;
use App\Models\AiUsageLedgerEntry;
use App\Models\AiUsagePeriod;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 19 §5.7a, §6.7, §8, §12.19.H0, §13.7 — the
 * platform-foundation battery run end to end through the SAME
 * `AiGateway::complete()` every Workspace call already goes through: one
 * gateway, one router, one reservation protocol, one ledger, one settle
 * path.
 */
class AiGatewayPlatformScopeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    private FakeAiCompletionClient $fakeClient;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.openai.active' => true]);
        config(['ai.platform.monthly_cap_microusd' => 20_000_000]);
        config(['ai.platform.interactive_share_bps' => 3000]);

        $this->fakeClient = new FakeAiCompletionClient();
        $this->app->instance(AiCompletionClient::class, $this->fakeClient);
    }

    private function gateway(): AiGateway
    {
        return app(AiGateway::class);
    }

    // =================================================================
    // A. Authority — the four executable proofs (§5.7a I, §13.7 item 7)
    // =================================================================

    public function test_a_admin_actor_succeeds_and_the_ledger_carries_no_fabricated_tenant(): void
    {
        $admin = $this->adminUser();

        $result = $this->gateway()->complete($this->platformRequest((int) $admin->id));

        $this->assertTrue($result->ok);
        $entry = $result->ledgerEntry;
        $this->assertNotNull($entry);
        $this->assertNull($entry->workspace_id, 'A Platform ledger entry carries no Workspace id at all — never a magic id (R-19).');
        $this->assertNull($entry->business_id);
        $this->assertSame('platform', $entry->scope_type);
        $this->assertSame(AiUsageLedgerManager::platformScopeId(), $entry->scope_id);
        $this->assertSame((int) $admin->id, $entry->actor_user_id);

        $period = AiUsagePeriod::query()->where('scope_type', AiUsagePeriod::SCOPE_PLATFORM)->first();
        $this->assertNotNull($period);
        $this->assertNull($period->workspace_id, 'A Platform period row carries no Workspace id at all (R-19).');
    }

    public function test_b_a_non_admin_actor_is_refused_before_any_reservation_and_before_any_provider_call(): void
    {
        $nonAdmin = $this->nonAdminUser();

        $result = $this->gateway()->complete($this->platformRequest((int) $nonAdmin->id));

        $this->assertFalse($result->ok);
        $this->assertSame(AiRefusalReason::PlatformAuthorityDenied, $result->refusalReason);
        $this->assertSame(0, AiUsageLedgerEntry::query()->count(), 'Nothing may be reserved for a non-admin actor.');
        $this->assertSame(0, AiUsagePeriod::query()->count(), 'No period may even open.');
        $this->assertSame(0, $this->fakeClient->callCount(), 'The provider must never be called.');
    }

    public function test_c_an_admin_demoted_between_construction_and_execution_is_refused_before_reserve(): void
    {
        $admin = $this->adminUser();
        // The moment a caller would have built the request — e.g. at
        // enqueue time.
        $request = $this->platformRequest((int) $admin->id);

        $admin->forceFill(['is_admin' => false])->save();

        $result = $this->gateway()->complete($request);

        $this->assertFalse($result->ok);
        $this->assertSame(AiRefusalReason::PlatformAuthorityDenied, $result->refusalReason);
        $this->assertSame(0, AiUsageLedgerEntry::query()->count());
        $this->assertSame(0, $this->fakeClient->callCount());
    }

    public function test_c_an_admin_deleted_between_construction_and_execution_is_refused_before_reserve(): void
    {
        $admin = $this->adminUser();
        $request = $this->platformRequest((int) $admin->id);

        $admin->delete();

        $result = $this->gateway()->complete($request);

        $this->assertFalse($result->ok);
        $this->assertSame(AiRefusalReason::PlatformAuthorityDenied, $result->refusalReason);
        $this->assertSame(0, AiUsageLedgerEntry::query()->count());
    }

    /**
     * D — no platform job payload carries an authority boolean, role
     * snapshot or permission claim. `AiRequest` itself is the payload a
     * queued platform job would carry; it holds only a plain integer actor
     * id, and the gateway derives the whole authority decision from a
     * fresh row read at execution time, never from anything on the
     * request.
     */
    public function test_d_the_request_the_gateway_authorizes_from_carries_only_a_plain_actor_id(): void
    {
        $admin = $this->adminUser();
        $request = $this->platformRequest((int) $admin->id);

        $this->assertIsInt($request->actorUserId);
        $this->assertNull($request->workspace);
        $this->assertNull($request->business);

        // Demoting the SAME id after the request was built proves the
        // gateway is not honouring anything cached on the request — it can
        // only be re-deriving authority from a fresh row.
        $admin->forceFill(['is_admin' => false])->save();
        $this->assertFalse($this->gateway()->complete($request)->ok);
    }

    // =================================================================
    // Budget — both dimensions fail closed (§5.7a D, §13.7 item 7)
    // =================================================================

    public function test_a_finite_platform_cap_is_enforced(): void
    {
        config(['ai.platform.monthly_cap_microusd' => 1]);
        $admin = $this->adminUser();

        $result = $this->gateway()->complete($this->platformRequest((int) $admin->id));

        $this->assertFalse($result->ok);
        $this->assertSame(AiRefusalReason::BudgetExhausted, $result->refusalReason);
    }

    public function test_an_absent_platform_cap_refuses_every_call_before_any_reservation(): void
    {
        config(['ai.platform.monthly_cap_microusd' => null]);
        $admin = $this->adminUser();

        $result = $this->gateway()->complete($this->platformRequest((int) $admin->id));

        $this->assertFalse($result->ok);
        $this->assertSame(AiRefusalReason::BudgetExhausted, $result->refusalReason);
        $this->assertSame(0, AiUsageLedgerEntry::query()->count());
    }

    public function test_a_non_positive_platform_cap_refuses_every_call(): void
    {
        config(['ai.platform.monthly_cap_microusd' => 0]);
        $admin = $this->adminUser();

        $result = $this->gateway()->complete($this->platformRequest((int) $admin->id));

        $this->assertFalse($result->ok);
        $this->assertSame(AiRefusalReason::BudgetExhausted, $result->refusalReason);
    }

    public function test_an_out_of_range_interactive_share_fails_configuration_rather_than_becoming_unbounded(): void
    {
        config(['ai.platform.interactive_share_bps' => 10_001]);
        $admin = $this->adminUser();

        $this->expectException(AiPlatformBudgetMisconfiguredException::class);

        $this->gateway()->complete($this->platformRequest((int) $admin->id));
    }

    public function test_the_interactive_share_is_enforced_against_the_platform_cap(): void
    {
        config(['ai.platform.monthly_cap_microusd' => 10_000]);
        config(['ai.platform.interactive_share_bps' => 1000]); // 10% of 10,000 = 1,000
        $this->seedPlatformPeriod(capMicrousd: 10_000, committedMicrousd: 100, interactiveCommittedMicrousd: 1_000);

        $admin = $this->adminUser();
        $result = $this->gateway()->complete($this->platformRequest((int) $admin->id, lane: AiLane::Interactive));

        $this->assertFalse($result->ok);
        $this->assertSame(AiRefusalReason::InteractiveShareExhausted, $result->refusalReason);
        $this->assertSame(AiRefusalScope::InteractiveShare, $result->ledgerEntry->refusal_scope);
    }

    public function test_a_platform_product_lane_call_does_not_consume_the_interactive_counter(): void
    {
        $admin = $this->adminUser();

        $result = $this->gateway()->complete($this->platformRequest((int) $admin->id, lane: AiLane::Product));

        $this->assertTrue($result->ok);
        $period = AiUsagePeriod::query()->where('scope_type', AiUsagePeriod::SCOPE_PLATFORM)->firstOrFail();
        $this->assertSame(0, $period->interactive_committed_microusd, 'Product-lane spend must never touch the interactive counter.');
        $this->assertGreaterThan(0, $period->committed_microusd);
    }

    public function test_a_platform_budget_refusal_is_recorded_with_the_platform_refusal_scope(): void
    {
        config(['ai.platform.monthly_cap_microusd' => 1]);
        $admin = $this->adminUser();

        $result = $this->gateway()->complete($this->platformRequest((int) $admin->id));

        $this->assertFalse($result->ok);
        $this->assertSame(AiRefusalScope::Platform, $result->ledgerEntry->refusal_scope);
    }

    // =================================================================
    // Never collides with Workspace accounting (§5.7a E/F, §13.7 item 7)
    // =================================================================

    public function test_platform_and_workspace_period_rows_at_the_same_numeric_scope_id_never_collide(): void
    {
        $periodKey = Carbon::now('UTC')->format('Y-m');
        $sharedNumericId = AiUsageLedgerManager::platformScopeId();

        AiUsagePeriod::create([
            'scope_type' => AiUsagePeriod::SCOPE_WORKSPACE,
            'scope_id' => $sharedNumericId,
            'workspace_id' => $sharedNumericId,
            'period_key' => $periodKey,
            'policy_key' => 'core',
            'policy_version' => 1,
            'cap_microusd' => 5_000_000,
            'committed_microusd' => 4_000_000,
        ]);

        $this->seedPlatformPeriod(capMicrousd: 20_000_000, committedMicrousd: 0, periodKey: $periodKey);

        $this->assertSame(2, AiUsagePeriod::query()->where('scope_id', $sharedNumericId)->where('period_key', $periodKey)->count());

        $workspaceRow = AiUsagePeriod::query()->where('scope_type', AiUsagePeriod::SCOPE_WORKSPACE)->where('scope_id', $sharedNumericId)->where('period_key', $periodKey)->firstOrFail();
        $platformRow = AiUsagePeriod::query()->where('scope_type', AiUsagePeriod::SCOPE_PLATFORM)->where('scope_id', $sharedNumericId)->where('period_key', $periodKey)->firstOrFail();

        $this->assertSame(4_000_000, $workspaceRow->committed_microusd);
        $this->assertSame(0, $platformRow->committed_microusd);
    }

    public function test_idempotency_key_stays_globally_unique_across_workspace_and_platform_scopes(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $admin = $this->adminUser();
        $sharedKey = 'coincidentally-shared-key-' . Str::uuid();

        $workspaceResult = $this->gateway()->complete(AiRequest::forWorkspace(
            workspace: $workspace,
            business: null,
            category: AiUsageCategory::WebsiteGeneration,
            lane: AiLane::Product,
            route: app(AiModelRouter::class)->defaultRouteFor(AiUsageCategory::WebsiteGeneration),
            messages: [['role' => 'user', 'content' => 'hi']],
            maxOutputTokens: 50,
            idempotencyKey: $sharedKey,
            actorUserId: null,
        ));
        $this->assertTrue($workspaceResult->ok);

        try {
            $this->gateway()->complete($this->platformRequest((int) $admin->id, idempotencyKey: $sharedKey));
            $this->fail('A Platform call reusing a Workspace call\'s idempotency key must be rejected — the key stays globally unique across scopes.');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(UniqueConstraintViolationException::class, $e);
        }

        $this->assertSame(1, AiUsageLedgerEntry::query()->where('idempotency_key', $sharedKey)->count(), 'Only the first, Workspace-scoped row exists.');
    }

    // =================================================================
    // Provider failure settles identically at platform scope
    // =================================================================

    public function test_provider_failure_releases_the_reservation_identically_at_platform_scope(): void
    {
        $admin = $this->adminUser();
        $this->fakeClient->setDefaultResult(AiCompletionResult::failure());

        $result = $this->gateway()->complete($this->platformRequest((int) $admin->id));

        $this->assertFalse($result->ok);
        $this->assertNotNull($result->ledgerEntry);
        $this->assertSame(AiUsageEntryStatus::Failed, $result->ledgerEntry->status);
        $this->assertSame(0, $result->ledgerEntry->actual_cost_microusd);

        $period = AiUsagePeriod::query()->where('scope_type', AiUsagePeriod::SCOPE_PLATFORM)->firstOrFail();
        $this->assertSame(0, $period->reserved_microusd, 'A failure with no billable usage releases the whole reservation.');
        $this->assertSame(0, $period->committed_microusd);
    }

    public function test_a_stale_platform_reservation_expires_exactly_like_a_workspace_one(): void
    {
        $admin = $this->adminUser();
        $this->seedPlatformPeriod(capMicrousd: 20_000_000, committedMicrousd: 0, reservedMicrousd: 1_000);

        $entry = AiUsageLedgerEntry::create([
            'workspace_id' => null,
            'business_id' => null,
            'scope_type' => AiUsagePeriod::SCOPE_PLATFORM,
            'scope_id' => AiUsageLedgerManager::platformScopeId(),
            'category' => AiUsageCategory::CooDiagnosis,
            'lane' => AiLane::Product,
            'model_route' => 'routine',
            'provider' => 'openai',
            'price_version' => 1,
            'status' => AiUsageEntryStatus::Reserved,
            'estimated_cost_microusd' => 1_000,
            'period_key' => Carbon::now('UTC')->format('Y-m'),
            'idempotency_key' => 'stale-platform:' . Str::uuid(),
            'actor_user_id' => (int) $admin->id,
            'created_at' => Carbon::now()->subMinutes((int) config('ai.reservation_expiry_minutes') + 1),
        ]);

        app(AiUsageLedgerManager::class)->expireStaleReservations();

        $this->assertSame(AiUsageEntryStatus::Released, $entry->fresh()->status);
        $period = AiUsagePeriod::query()->where('scope_type', AiUsagePeriod::SCOPE_PLATFORM)->firstOrFail();
        $this->assertSame(0, $period->reserved_microusd);
    }

    // -----------------------------------------------------------------

    private function platformRequest(
        int $actorUserId,
        AiUsageCategory $category = AiUsageCategory::CooDiagnosis,
        AiLane $lane = AiLane::Product,
        ?string $idempotencyKey = null,
    ): AiRequest {
        return AiRequest::forPlatform(
            actorUserId: $actorUserId,
            category: $category,
            lane: $lane,
            route: app(AiModelRouter::class)->defaultRouteFor($category),
            messages: [['role' => 'user', 'content' => 'platform question']],
            maxOutputTokens: 50,
            idempotencyKey: $idempotencyKey ?? (string) Str::uuid(),
        );
    }

    private function seedPlatformPeriod(
        int $capMicrousd,
        int $committedMicrousd = 0,
        int $reservedMicrousd = 0,
        int $interactiveCommittedMicrousd = 0,
        ?string $periodKey = null,
    ): void {
        AiUsagePeriod::create([
            'scope_type' => AiUsagePeriod::SCOPE_PLATFORM,
            'scope_id' => AiUsageLedgerManager::platformScopeId(),
            'workspace_id' => null,
            'period_key' => $periodKey ?? Carbon::now('UTC')->format('Y-m'),
            'policy_key' => 'platform',
            'policy_version' => (int) config('ai.policy_version'),
            'cap_microusd' => $capMicrousd,
            'committed_microusd' => $committedMicrousd,
            'reserved_microusd' => $reservedMicrousd,
            'interactive_committed_microusd' => $interactiveCommittedMicrousd,
        ]);
    }

    private function adminUser(): User
    {
        return User::create([
            'first_name' => 'Platform',
            'last_name' => 'Admin',
            'email' => 'platform-h0-admin-' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);
    }

    private function nonAdminUser(): User
    {
        return User::create([
            'first_name' => 'Not',
            'last_name' => 'Admin',
            'email' => 'platform-h0-nonadmin-' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);
    }
}

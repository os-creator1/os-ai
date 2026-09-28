<?php

namespace Tests\Feature\Ai;

use App\Library\Ai\AiBudgetPolicyResolver;
use App\Library\Ai\Exceptions\AiPlatformBudgetMisconfiguredException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Implementation Contract 19 §5.7a D, sub-slice 19.H0 —
 * `AiBudgetPolicyResolver::resolveForPlatform()` in isolation: the complete
 * six-field policy, and fail-closed behaviour in both dimensions.
 */
class AiBudgetPolicyResolverPlatformTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_for_platform_returns_the_complete_six_field_policy(): void
    {
        config(['ai.policy_version' => 7]);
        config(['ai.platform.monthly_cap_microusd' => 20_000_000]);
        config(['ai.platform.interactive_share_bps' => 3000]);

        $policy = app(AiBudgetPolicyResolver::class)->resolveForPlatform();

        $this->assertSame('platform', $policy->policyKey);
        $this->assertSame(7, $policy->policyVersion, 'The SAME canonical policy version every Workspace policy already uses.');
        $this->assertSame(Carbon::now('UTC')->format('Y-m'), $policy->periodKey);
        $this->assertSame(20_000_000, $policy->workspaceCapMicrousd);
        $this->assertNull($policy->businessCapMicrousd, 'There is no Business at platform scope.');
        $this->assertSame(3000, $policy->interactiveShareBps);
    }

    public function test_an_absent_cap_resolves_to_zero_never_unlimited(): void
    {
        config(['ai.platform.monthly_cap_microusd' => null]);

        $policy = app(AiBudgetPolicyResolver::class)->resolveForPlatform();

        $this->assertSame(0, $policy->workspaceCapMicrousd);
    }

    public function test_a_non_positive_cap_resolves_to_zero(): void
    {
        config(['ai.platform.monthly_cap_microusd' => -5]);

        $policy = app(AiBudgetPolicyResolver::class)->resolveForPlatform();

        $this->assertSame(0, $policy->workspaceCapMicrousd);
    }

    public function test_a_non_numeric_cap_resolves_to_zero(): void
    {
        config(['ai.platform.monthly_cap_microusd' => 'not-a-number']);

        $policy = app(AiBudgetPolicyResolver::class)->resolveForPlatform();

        $this->assertSame(0, $policy->workspaceCapMicrousd);
    }

    public function test_an_out_of_range_interactive_share_raises_a_configuration_exception_rather_than_clamping(): void
    {
        config(['ai.platform.interactive_share_bps' => 10_001]);

        $this->expectException(AiPlatformBudgetMisconfiguredException::class);

        app(AiBudgetPolicyResolver::class)->resolveForPlatform();
    }

    public function test_a_negative_interactive_share_raises_a_configuration_exception(): void
    {
        config(['ai.platform.interactive_share_bps' => -1]);

        $this->expectException(AiPlatformBudgetMisconfiguredException::class);

        app(AiBudgetPolicyResolver::class)->resolveForPlatform();
    }

    public function test_a_missing_interactive_share_raises_a_configuration_exception(): void
    {
        config(['ai.platform.interactive_share_bps' => null]);

        $this->expectException(AiPlatformBudgetMisconfiguredException::class);

        app(AiBudgetPolicyResolver::class)->resolveForPlatform();
    }

    public function test_no_customer_plan_lookup_occurs_for_platform_scope(): void
    {
        // No Workspace, no EntitlementManager call is even possible here —
        // resolveForPlatform() takes no arguments at all. This is the
        // structural half of "EntitlementManager is not consulted at
        // platform scope": the method signature itself admits no tenant.
        $method = new \ReflectionMethod(AiBudgetPolicyResolver::class, 'resolveForPlatform');
        $this->assertCount(0, $method->getParameters());
    }
}

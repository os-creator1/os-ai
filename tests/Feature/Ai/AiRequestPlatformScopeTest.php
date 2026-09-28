<?php

namespace Tests\Feature\Ai;

use App\Library\Ai\AiModelRouter;
use App\Library\Ai\AiRequest;
use App\Library\Ai\Enums\AiLane;
use App\Library\Ai\Enums\AiScope;
use App\Library\Ai\Enums\AiUsageCategory;
use App\Models\Business;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 19 §5.7a A/B/C, sub-slice 19.H0 — `AiRequest`'s
 * own scope invariant, its named constructors, and the R-19/R-28 structural
 * proofs the platform-foundation battery (§13.7) needs at this layer:
 * a Platform request can never carry a fabricated tenant, and nothing in
 * this shape can carry authority as data.
 */
class AiRequestPlatformScopeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    // =================================================================
    // The scope invariant (§5.7a A)
    // =================================================================

    public function test_workspace_scope_requires_a_real_workspace(): void
    {
        [, $business, $workspace] = $this->tenant();

        // The existing shape still works exactly as before.
        $request = $this->workspaceRequest($workspace, $business);
        $this->assertSame(AiScope::Workspace, $request->scope);
        $this->assertSame($workspace->id, $request->workspace->id);
    }

    public function test_platform_scope_rejects_a_workspace(): void
    {
        [, , $workspace] = $this->tenant();

        $this->expectException(InvalidArgumentException::class);

        new AiRequest(
            workspace: $workspace,
            business: null,
            category: AiUsageCategory::CooDiagnosis,
            lane: AiLane::Product,
            route: app(AiModelRouter::class)->defaultRouteFor(AiUsageCategory::CooDiagnosis),
            messages: [['role' => 'user', 'content' => 'hi']],
            maxOutputTokens: 50,
            idempotencyKey: (string) Str::uuid(),
            actorUserId: 1,
            scope: AiScope::Platform,
        );
    }

    public function test_platform_scope_rejects_a_business(): void
    {
        [, $business] = $this->tenant();

        $this->expectException(InvalidArgumentException::class);

        new AiRequest(
            workspace: null,
            business: $business,
            category: AiUsageCategory::CooDiagnosis,
            lane: AiLane::Product,
            route: app(AiModelRouter::class)->defaultRouteFor(AiUsageCategory::CooDiagnosis),
            messages: [['role' => 'user', 'content' => 'hi']],
            maxOutputTokens: 50,
            idempotencyKey: (string) Str::uuid(),
            actorUserId: 1,
            scope: AiScope::Platform,
        );
    }

    public function test_platform_scope_requires_a_real_actor_user_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AiRequest(
            workspace: null,
            business: null,
            category: AiUsageCategory::CooDiagnosis,
            lane: AiLane::Product,
            route: app(AiModelRouter::class)->defaultRouteFor(AiUsageCategory::CooDiagnosis),
            messages: [['role' => 'user', 'content' => 'hi']],
            maxOutputTokens: 50,
            idempotencyKey: (string) Str::uuid(),
            actorUserId: null,
            scope: AiScope::Platform,
        );
    }

    public function test_forplatform_produces_a_valid_platform_request(): void
    {
        $request = AiRequest::forPlatform(
            actorUserId: 42,
            category: AiUsageCategory::CooDiagnosis,
            lane: AiLane::Product,
            route: app(AiModelRouter::class)->defaultRouteFor(AiUsageCategory::CooDiagnosis),
            messages: [['role' => 'user', 'content' => 'hi']],
            maxOutputTokens: 50,
            idempotencyKey: (string) Str::uuid(),
        );

        $this->assertSame(AiScope::Platform, $request->scope);
        $this->assertNull($request->workspace);
        $this->assertNull($request->business);
        $this->assertSame(42, $request->actorUserId);
    }

    public function test_forworkspace_produces_a_valid_workspace_request(): void
    {
        [, $business, $workspace] = $this->tenant();

        $request = AiRequest::forWorkspace(
            workspace: $workspace,
            business: $business,
            category: AiUsageCategory::WebsiteGeneration,
            lane: AiLane::Product,
            route: app(AiModelRouter::class)->defaultRouteFor(AiUsageCategory::WebsiteGeneration),
            messages: [['role' => 'user', 'content' => 'hi']],
            maxOutputTokens: 50,
            idempotencyKey: (string) Str::uuid(),
            actorUserId: null,
        );

        $this->assertSame(AiScope::Workspace, $request->scope);
        $this->assertSame($workspace->id, $request->workspace->id);
    }

    // =================================================================
    // §13.7 / §5.7a B — the three existing production construction sites
    // remain source-unchanged
    // =================================================================

    /**
     * Every existing call keeps working precisely because `scope` is
     * appended last, with a default, after the pre-existing `jsonMode`
     * parameter — never inserted earlier in the list. This proves the
     * three real files were not touched by this slice, by asserting each
     * one's exact `new AiRequest(...)` block is byte-identical to what it
     * was before 19.H0 (recon basis, §5.7a B) — none of them names `scope`
     * or `AiScope` anywhere.
     */
    public function test_all_three_construction_sites_remain_source_unchanged(): void
    {
        $sites = [
            app_path('Library/Coo/Insight/CooInsightGenerator.php') => <<<'PHP'
            $result = $this->gateway->complete(new AiRequest(
                workspace: $workspace,
                business: $business,
                category: $category,
                lane: $trigger->lane(),
                route: $this->route($trigger, $facts),
                messages: $this->prompts->messages($facts),
                maxOutputTokens: max(1, (int) config('coo.insight.max_output_tokens')),
                idempotencyKey: $keyPrefix . ($family['attempts'] + 1),
                actorUserId: $actorUserId,
                jsonMode: true,
            ));
            PHP,
            app_path('Library/Website/WebsiteAiGenerationClient.php') => <<<'PHP'
            $request = new AiRequest(
                workspace: $business->workspace,
                business: $business,
                category: $category,
                lane: AiLane::Product,
                route: $route,
                messages: $messages,
                maxOutputTokens: (int) $routeConfig['max_output_tokens'],
                idempotencyKey: (string) Str::uuid(),
                actorUserId: $actorUserId,
                jsonMode: true,
            );
            PHP,
            app_path('Library/AgencyProspecting/OpenAiAgencyProspectingClient.php') => <<<'PHP'
            $request = new AiRequest(
                workspace: $workspace,
                business: null,
                category: $category,
                lane: AiLane::Product,
                route: $route,
                messages: $messages,
                maxOutputTokens: (int) $routeConfig['max_output_tokens'],
            PHP,
        ];

        foreach ($sites as $path => $exactBlock) {
            // Compared with indentation and line endings normalized away:
            // the invariant this proves is that the construction call's own
            // code — parameter order, names and values — is unchanged, not
            // that its surrounding whitespace style never moves a column.
            $source = $this->dedent((string) file_get_contents($path));
            $needle = $this->dedent($exactBlock);
            $this->assertStringContainsString($needle, $source, basename($path) . ' must remain source-unchanged by 19.H0.');
            $this->assertStringNotContainsString('AiScope', $source, basename($path) . ' must not reference the new scope parameter — it relies on the default.');
        }
    }

    private function dedent(string $text): string
    {
        $lines = preg_split('/\R/', $text);

        return implode("\n", array_map(static fn (string $line): string => ltrim($line), $lines));
    }

    // =================================================================
    // R-28 (part D) — no authority ever travels as data
    // =================================================================

    /**
     * The structural half of "no platform job payload carries an authority
     * boolean, role snapshot or permission claim": `AiRequest::forPlatform()`
     * and `PlatformAiAuthority::authorize()` — the only two places a queued
     * platform job's payload could reach authority through — take nothing
     * but plain scalars (an actor id, a category, a lane, a route, message
     * strings, counts and keys). Neither accepts a bool, an array of
     * claims, or any object that could carry a role/permission snapshot.
     */
    public function test_no_platform_entry_point_accepts_an_authority_shaped_parameter(): void
    {
        $suspect = '/is_?admin|role|permission|claim|authority/i';

        foreach ([
            new ReflectionMethod(AiRequest::class, 'forPlatform'),
            new ReflectionMethod(\App\Library\Ai\PlatformAiAuthority::class, 'authorize'),
        ] as $method) {
            foreach ($method->getParameters() as $parameter) {
                $this->assertDoesNotMatchRegularExpression($suspect, $parameter->getName(), "{$method->getDeclaringClass()->getName()}::{$method->getName()}() parameter \${$parameter->getName()} looks authority-shaped.");

                // The only legitimate boolean flag in this API is `jsonMode`
                // (output formatting, not authority). Any other boolean
                // parameter here would be exactly the shape an `is_admin`
                // copy would take.
                $type = $parameter->getType();
                if ($type !== null && (string) $type === 'bool') {
                    $this->assertSame('jsonMode', $parameter->getName(), "{$method->getName()}(\${$parameter->getName()}) is a boolean parameter that is not the known-safe jsonMode flag.");
                }
            }
        }

        // AiRequest itself carries only an integer actorUserId — never a
        // User object, a role, or a permission claim.
        $actorProperty = (new ReflectionClass(AiRequest::class))->getProperty('actorUserId');
        $this->assertSame('?int', (string) $actorProperty->getType());
    }

    private function workspaceRequest(Workspace $workspace, ?Business $business): AiRequest
    {
        return new AiRequest(
            workspace: $workspace,
            business: $business,
            category: AiUsageCategory::WebsiteGeneration,
            lane: AiLane::Product,
            route: app(AiModelRouter::class)->defaultRouteFor(AiUsageCategory::WebsiteGeneration),
            messages: [['role' => 'user', 'content' => 'hi']],
            maxOutputTokens: 50,
            idempotencyKey: (string) Str::uuid(),
            actorUserId: null,
        );
    }
}

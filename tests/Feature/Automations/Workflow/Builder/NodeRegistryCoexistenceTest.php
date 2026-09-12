<?php

namespace Tests\Feature\Automations\Workflow\Builder;

use App\Enums\Automation\Workflow\WorkflowNodeType;
use App\Library\Automation\Workflow\NodeTypeRegistry;
use App\Library\Automation\Workflow\Runtime\NodeExecutorRegistry;
use Tests\TestCase;

/**
 * Automations V2 (contract §5.2, §16, V2-D ↔ #270/V2-B coexistence, PR #274) —
 * proves this slice's builder vocabulary has not drifted from the real,
 * current NodeTypeRegistry now that V2-B's action executors (send_sms,
 * update_contact_field, internal_notification) are merged alongside the
 * pre-existing logic executors (trigger, end). Wait and If/Else remain
 * builder-supported SCHEMA node types even though their own runtime
 * executors are not yet merged (Lane D) — this slice never removes a
 * registered node type merely because its executor lags.
 */
class NodeRegistryCoexistenceTest extends TestCase
{
    private const LAUNCH_NODE_TYPES = [
        'trigger',
        'send_sms',
        'update_contact_field',
        'internal_notification',
        'wait',
        'if_else',
        'end',
    ];

    /** #270 proof: the executor registry actually resolves the three new action executors. */
    public function test_the_v2b_action_executor_registry_still_resolves(): void
    {
        $registry = $this->app->make(NodeExecutorRegistry::class);

        foreach (['send_sms', 'update_contact_field', 'internal_notification', 'trigger', 'end'] as $type) {
            $executor = $registry->for(WorkflowNodeType::from($type));
            $this->assertNotNull($executor, "NodeExecutorRegistry must resolve an executor for [{$type}].");
        }
    }

    /** The builder's JS vocabulary is exactly WorkflowNodeType::cases() — no more, no less. */
    public function test_builder_node_vocabulary_matches_canonical_registry(): void
    {
        $registry = new NodeTypeRegistry();
        $canonical = array_map(static fn (WorkflowNodeType $type) => $type->value, $registry->all());

        sort($canonical);
        $launch = self::LAUNCH_NODE_TYPES;
        sort($launch);

        $this->assertSame($canonical, $launch, 'The builder\'s node vocabulary must track WorkflowNodeType::cases() exactly.');

        $constants = file_get_contents(base_path('resources/js/automations/workflow-builder/constants.js'));

        foreach (self::LAUNCH_NODE_TYPES as $type) {
            $this->assertStringContainsString("'{$type}'", $constants, "constants.js must declare the canonical node type [{$type}].");
        }
    }

    /** Wait and If/Else stay builder-supported even though their own executors have not merged yet. */
    public function test_wait_and_if_else_remain_builder_supported_pending_their_own_executors(): void
    {
        $registry = new NodeTypeRegistry();

        $this->assertTrue($registry->isRegistered('wait'));
        $this->assertTrue($registry->isRegistered('if_else'));

        $constants = file_get_contents(base_path('resources/js/automations/workflow-builder/constants.js'));
        $this->assertStringContainsString("WAIT: 'wait'", $constants);
        $this->assertStringContainsString("IF_ELSE: 'if_else'", $constants);

        // Their own executors are Lane D's, not yet merged — this is a fact
        // about the runtime directory, not a reason to drop them from the
        // schema-level builder.
        $this->assertFileDoesNotExist(base_path('app/Library/Automation/Workflow/Executors/WaitNodeExecutor.php'));
        $this->assertFileDoesNotExist(base_path('app/Library/Automation/Workflow/Executors/IfElseNodeExecutor.php'));
    }

    /** No unsupported node type was added alongside the real V2-B merge. */
    public function test_no_extra_node_type_was_introduced(): void
    {
        $registry = new NodeTypeRegistry();
        $canonical = array_map(static fn (WorkflowNodeType $type) => $type->value, $registry->all());

        $this->assertCount(7, $canonical, 'Exactly the launch vocabulary — v2 adds no node type outside this contract.');
    }
}

<?php

namespace Tests\Feature\Automations\Workflow\Builder;

use App\Enums\Automation\Workflow\WorkflowNodeType;
use App\Library\Automation\Workflow\NodeTypeRegistry;
use App\Library\Automation\Workflow\Runtime\NodeExecutorRegistry;
use Tests\TestCase;

/**
 * Automations V2 (contract §5.2, §16, V2-D ↔ #270/V2-B ↔ #277/V2-A
 * coexistence, PR #274) — proves this slice's builder vocabulary has not
 * drifted from the real, current NodeTypeRegistry now that BOTH V2-B's
 * action executors (send_sms, update_contact_field, internal_notification)
 * AND V2-A's logic executors (wait, if_else) are merged alongside the
 * pre-existing structural executors (trigger, end). The registry is now
 * COMPLETE — every builder-supported node type resolves a real executor —
 * so this slice never has to special-case a type whose executor "hasn't
 * shipped yet".
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

    /** The executor registry resolves every one of the seven launch node types. */
    public function test_the_full_executor_registry_resolves_every_launch_node_type(): void
    {
        $registry = $this->app->make(NodeExecutorRegistry::class);

        foreach (self::LAUNCH_NODE_TYPES as $type) {
            $executor = $registry->for(WorkflowNodeType::from($type));
            $this->assertNotNull($executor, "NodeExecutorRegistry must resolve an executor for [{$type}].");
        }

        $this->assertEqualsCanonicalizing(
            self::LAUNCH_NODE_TYPES,
            $registry->registeredTypes(),
            'The registry must hold exactly the seven launch executors — no gaps, no strays.',
        );
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

    /**
     * Wait and If/Else are builder-supported schema node types AND now have
     * real runtime executors, since #277/V2-A merged.
     *
     * This test previously asserted the opposite — that
     * WaitNodeExecutor.php/IfElseNodeExecutor.php did not exist yet, because
     * the builder (V2-D) shipped the schema-level node types ahead of their
     * own runtime slice. That gap is closed now: the assertion is inverted
     * rather than deleted, so a future regression that silently drops one of
     * these executors from the container is still caught here, from the
     * builder's own vantage point.
     */
    public function test_wait_and_if_else_are_builder_supported_and_now_have_real_executors(): void
    {
        $registry = new NodeTypeRegistry();

        $this->assertTrue($registry->isRegistered('wait'));
        $this->assertTrue($registry->isRegistered('if_else'));

        $constants = file_get_contents(base_path('resources/js/automations/workflow-builder/constants.js'));
        $this->assertStringContainsString("WAIT: 'wait'", $constants);
        $this->assertStringContainsString("IF_ELSE: 'if_else'", $constants);

        $this->assertFileExists(base_path('app/Library/Automation/Workflow/Executors/WaitNodeExecutor.php'));
        $this->assertFileExists(base_path('app/Library/Automation/Workflow/Executors/IfElseNodeExecutor.php'));

        $executorRegistry = $this->app->make(NodeExecutorRegistry::class);
        $this->assertInstanceOf(
            \App\Library\Automation\Workflow\Executors\WaitNodeExecutor::class,
            $executorRegistry->for(WorkflowNodeType::Wait),
        );
        $this->assertInstanceOf(
            \App\Library\Automation\Workflow\Executors\IfElseNodeExecutor::class,
            $executorRegistry->for(WorkflowNodeType::IfElse),
        );
    }

    /** No unsupported node type was added alongside the real V2-B merge. */
    public function test_no_extra_node_type_was_introduced(): void
    {
        $registry = new NodeTypeRegistry();
        $canonical = array_map(static fn (WorkflowNodeType $type) => $type->value, $registry->all());

        $this->assertCount(7, $canonical, 'Exactly the launch vocabulary — v2 adds no node type outside this contract.');
    }
}

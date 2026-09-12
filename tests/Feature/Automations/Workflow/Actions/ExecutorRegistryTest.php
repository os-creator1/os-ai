<?php

namespace Tests\Feature\Automations\Workflow\Actions;

use App\Enums\Automation\Workflow\WorkflowNodeType;
use App\Library\Automation\Workflow\Contracts\NodeExecutor;
use App\Library\Automation\Workflow\Executors\EndNodeExecutor;
use App\Library\Automation\Workflow\Executors\InternalNotificationNodeExecutor;
use App\Library\Automation\Workflow\Executors\SendSmsNodeExecutor;
use App\Library\Automation\Workflow\Executors\TriggerNodeExecutor;
use App\Library\Automation\Workflow\Executors\UpdateContactFieldNodeExecutor;
use App\Library\Automation\Workflow\Runtime\NodeExecutorRegistry;
use Tests\TestCase;

/**
 * Automations V2-B — the registry after the action executors are added.
 *
 * The wiring is additive, and "additive" is a claim worth checking rather than
 * asserting: the registry is keyed by node type, so a careless registration
 * REPLACES an executor instead of adding one, and the symptom would be a
 * structural step silently doing an action's work. So the two V2-A executors are
 * checked for by identity here, not just for presence.
 */
class ExecutorRegistryTest extends TestCase
{
    private function registry(): NodeExecutorRegistry
    {
        return app(NodeExecutorRegistry::class);
    }

    /** 17. Trigger and End survive, and the three action types are added. */
    public function test_the_registry_holds_the_structural_and_action_executors(): void
    {
        $expected = [
            WorkflowNodeType::Trigger->value => TriggerNodeExecutor::class,
            WorkflowNodeType::End->value => EndNodeExecutor::class,
            WorkflowNodeType::SendSms->value => SendSmsNodeExecutor::class,
            WorkflowNodeType::UpdateContactField->value => UpdateContactFieldNodeExecutor::class,
            WorkflowNodeType::InternalNotification->value => InternalNotificationNodeExecutor::class,
        ];

        foreach ($expected as $type => $class) {
            $executor = $this->registry()->for(WorkflowNodeType::from($type));

            $this->assertInstanceOf($class, $executor, "The registry must resolve {$class} for '{$type}'.");
            $this->assertSame(
                $type,
                $executor->handles()->value,
                'An executor must be registered under the type it claims to handle.',
            );
        }

        $this->assertEqualsCanonicalizing(array_keys($expected), $this->registry()->registeredTypes());
    }

    /**
     * The types this slice does NOT own must still have no executor, because the
     * advancer's "hold, do not skip" behaviour for a missing one is what keeps a
     * journey intact until its slice ships. Registering a placeholder here would
     * silently drop those steps out of customers' workflows.
     */
    public function test_wait_and_if_else_are_still_deliberately_unregistered(): void
    {
        foreach ([WorkflowNodeType::Wait, WorkflowNodeType::IfElse] as $type) {
            $this->assertNull(
                $this->registry()->for($type),
                "'{$type->value}' belongs to a later slice and must have no executor yet.",
            );
            $this->assertFalse($this->registry()->has($type));
        }
    }

    /** Every registered executor honours the contract the advancer relies on. */
    public function test_every_registered_executor_implements_the_contract(): void
    {
        $types = $this->registry()->registeredTypes();

        $this->assertNotEmpty($types);

        foreach ($types as $type) {
            $this->assertInstanceOf(
                NodeExecutor::class,
                $this->registry()->for(WorkflowNodeType::from($type)),
            );
        }
    }

    /** The registry is a singleton: every resolution sees the same registrations. */
    public function test_the_registry_is_a_singleton(): void
    {
        $this->assertSame(app(NodeExecutorRegistry::class), app(NodeExecutorRegistry::class));
    }
}

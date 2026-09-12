<?php

namespace Tests\Feature\Automations\Workflow\Actions;

use App\Enums\Automation\Workflow\WorkflowNodeType;
use App\Library\Automation\Workflow\Runtime\NodeExecutorRegistry;
use App\Repositories\Contracts\OpportunityActionExecutionRepository;
use App\Repositories\Contracts\OpportunityProducerDispatchRepository;
use App\Repositories\Contracts\OpportunityRepository;
use App\Repositories\Contracts\OpportunityRunCandidateRepository;
use App\Repositories\Contracts\OpportunityRunRepository;
use App\Repositories\Contracts\OpportunityTransitionRepository;
use App\Repositories\Eloquent\EloquentOpportunityActionExecutionRepository;
use App\Repositories\Eloquent\EloquentOpportunityProducerDispatchRepository;
use App\Repositories\Eloquent\EloquentOpportunityRepository;
use App\Repositories\Eloquent\EloquentOpportunityRunCandidateRepository;
use App\Repositories\Eloquent\EloquentOpportunityRunRepository;
use App\Repositories\Eloquent\EloquentOpportunityTransitionRepository;
use Tests\TestCase;

/**
 * AppServiceProvider coexistence, after merging current main into V2-B.
 *
 * The COO C-1 slice and this one both edit AppServiceProvider::register(), in
 * different regions of the same method. Git merged them without a conflict, but
 * "no conflict" only means the two hunks did not overlap textually — it says
 * nothing about whether the resulting container actually serves both families.
 * A botched resolution that dropped one `$bindings` entry, or replaced the
 * registry closure with main's version, would merge just as cleanly and fail
 * only at runtime, in a queue worker, in production.
 *
 * So this asserts the thing that matters: ONE booted container hands back both
 * the Opportunity/COO repositories and the fully-populated workflow executor
 * registry.
 *
 * Slice AI-1 makes it three families, in a third region of the same method:
 * the AI provider seam. Merging newest main into the AI-1 branch is the same
 * hazard again from the other direction, so the gateway's binding is asserted
 * from the same container here rather than only in the AI suites.
 */
class ContainerBindingCoexistenceTest extends TestCase
{
    /** Current main's Opportunity/COO bindings all resolve to their concretes. */
    public function test_the_opportunity_bindings_from_current_main_resolve(): void
    {
        $expected = [
            OpportunityRepository::class => EloquentOpportunityRepository::class,
            OpportunityRunRepository::class => EloquentOpportunityRunRepository::class,
            OpportunityRunCandidateRepository::class => EloquentOpportunityRunCandidateRepository::class,
            OpportunityActionExecutionRepository::class => EloquentOpportunityActionExecutionRepository::class,
            OpportunityTransitionRepository::class => EloquentOpportunityTransitionRepository::class,
            OpportunityProducerDispatchRepository::class => EloquentOpportunityProducerDispatchRepository::class,
        ];

        foreach ($expected as $contract => $concrete) {
            $this->assertInstanceOf(
                $concrete,
                app($contract),
                "Merging V2-B must not have displaced main's binding for {$contract}.",
            );
        }
    }

    /**
     * And the SAME container still serves a complete executor registry. Both
     * assertions in one test would pass individually even if the provider ran
     * two different register() paths, so the registry is re-resolved here from
     * the identical application instance the block above used.
     */
    public function test_the_same_container_serves_both_families(): void
    {
        $opportunities = app(OpportunityRepository::class);
        $registry = app(NodeExecutorRegistry::class);

        $this->assertInstanceOf(EloquentOpportunityRepository::class, $opportunities);

        $this->assertEqualsCanonicalizing(
            ['trigger', 'end', 'send_sms', 'update_contact_field', 'internal_notification'],
            $registry->registeredTypes(),
            'The merge must leave the executor registry complete.',
        );

        // Still a singleton, and still the same one the advancer would be given.
        $this->assertSame($registry, app(NodeExecutorRegistry::class));
    }

    /**
     * All three families, resolved from one booted container: the
     * Opportunity/COO repositories, the workflow executor registry, and the
     * AI provider seam every AiGateway call depends on.
     *
     * The gateway is deliberately resolved as a whole rather than only its
     * seam: it takes the policy resolver, the router, the ledger manager and
     * the completion client, so a construction failure in any of them — the
     * realistic outcome of a hunk resolved badly — surfaces here instead of
     * inside a queue worker.
     */
    public function test_the_ai_gateway_family_resolves_from_the_same_container_as_the_other_two(): void
    {
        $opportunities = app(OpportunityRepository::class);
        $registry = app(NodeExecutorRegistry::class);
        $gateway = app(\App\Library\Ai\AiGateway::class);
        $provider = app(\App\Library\Ai\Contracts\AiCompletionClient::class);

        $this->assertInstanceOf(EloquentOpportunityRepository::class, $opportunities);
        $this->assertContains('internal_notification', $registry->registeredTypes());
        $this->assertInstanceOf(\App\Library\Ai\AiGateway::class, $gateway);

        // The seam binds to the real adapter by default; only a test may
        // swap the fake in, and nothing else in app/ may reach a provider.
        $this->assertInstanceOf(\App\Library\Ai\Providers\OpenAiCompletionClient::class, $provider);

        // The AI expiry job the Kernel schedules is constructible too: a
        // scheduled job that cannot be built fails silently until it runs.
        $this->assertInstanceOf(\App\Jobs\Ai\ExpireStaleAiReservations::class, app(\App\Jobs\Ai\ExpireStaleAiReservations::class));
    }

    /** The types this slice does not own are still deliberately unserved. */
    public function test_wait_and_if_else_remain_without_an_executor_after_the_merge(): void
    {
        $registry = app(NodeExecutorRegistry::class);

        foreach ([WorkflowNodeType::Wait, WorkflowNodeType::IfElse] as $type) {
            $this->assertFalse(
                $registry->has($type),
                "'{$type->value}' must still have no executor, so the advancer holds those journeys.",
            );
        }
    }
}

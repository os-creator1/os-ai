<?php

namespace Tests\Unit\Coo;

use App\Enums\Coo\CooScope;
use App\Enums\Dashboard\AttentionType;
use App\Library\Coo\Context\CooContextEnvelope;
use App\Library\Coo\Insight\CooFactAuthorization;
use PHPUnit\Framework\TestCase;

/**
 * Implementation Contract 19 §6.2 / R-7, sub-slice 19.B — the classifier that
 * decides which fact sources an actor may have composed for them.
 *
 * The coverage test at the bottom is the one that matters most over time: it
 * fails the moment someone adds an Attention type without deciding whether it
 * is safe, so a new fact can never reach a Location-restricted actor by
 * default.
 */
class CooFactAuthorizationTest extends TestCase
{
    public function test_an_actor_who_can_read_every_location_may_compose_location_bound_facts(): void
    {
        $authorization = CooFactAuthorization::resolve($this->envelope([1, 2, 3]), [1, 2, 3]);

        $this->assertTrue($authorization->coversEveryLocation);
        $this->assertTrue($authorization->mayComposeLocationBoundFacts());
    }

    public function test_a_strict_subset_may_not_compose_location_bound_facts(): void
    {
        $authorization = CooFactAuthorization::resolve($this->envelope([1, 2]), [1, 2, 3]);

        $this->assertFalse($authorization->mayComposeLocationBoundFacts());
    }

    public function test_an_actor_with_no_authorized_location_may_not_compose_location_bound_facts(): void
    {
        $this->assertFalse(CooFactAuthorization::resolve($this->envelope([]), [1, 2])->mayComposeLocationBoundFacts());
    }

    /**
     * A Business with no Locations has nothing Location-bound to be restricted
     * from, so covering the empty set is genuinely complete — not a loophole
     * that lets an unauthorized actor through.
     */
    public function test_a_business_with_no_locations_is_complete_coverage(): void
    {
        $this->assertTrue(CooFactAuthorization::resolve($this->envelope([]), [])->mayComposeLocationBoundFacts());
    }

    public function test_ordering_and_duplicates_in_either_set_do_not_change_the_answer(): void
    {
        $this->assertTrue(CooFactAuthorization::resolve($this->envelope([3, 1, 2, 3]), [2, 1, 3])->mayComposeLocationBoundFacts());
    }

    // =================================================================
    // Attention filtering
    // =================================================================

    public function test_complete_coverage_keeps_every_raised_attention_type(): void
    {
        $raised = [AttentionType::LowBalance, AttentionType::ConversationsAwaitingReply, AttentionType::GoogleLocationUnhealthy];

        $this->assertSame($raised, CooFactAuthorization::resolve($this->envelope([1]), [1])->permittedAttention($raised));
    }

    public function test_partial_coverage_drops_every_location_bound_attention_type(): void
    {
        $authorization = CooFactAuthorization::resolve($this->envelope([1]), [1, 2]);

        $permitted = $authorization->permittedAttention([
            AttentionType::LowBalance,
            AttentionType::ConversationsAwaitingReply,
            AttentionType::GoogleLocationUnhealthy,
            AttentionType::AutomationFailing,
            AttentionType::WebsiteUnpublished,
        ]);

        $this->assertSame([AttentionType::LowBalance, AttentionType::WebsiteUnpublished], $permitted);
    }

    /**
     * Fail closed: safety is an allowlist. A type that is neither proven safe
     * nor named Location-bound must be dropped for a restricted actor, because
     * an unclassified fact is an unproven fact.
     */
    public function test_partial_coverage_drops_a_type_that_is_not_on_the_safe_allowlist(): void
    {
        $classified = array_merge(
            CooFactAuthorization::businessWideSafeAttention(),
            CooFactAuthorization::locationBoundAttention(),
        );

        $unclassified = array_values(array_filter(
            AttentionType::cases(),
            static fn (AttentionType $type): bool => ! in_array($type, $classified, true),
        ));

        // If this ever has members, the coverage test below already fails; the
        // assertion here is that whatever they are, they do not survive.
        $permitted = CooFactAuthorization::resolve($this->envelope([1]), [1, 2])->permittedAttention($unclassified);

        $this->assertSame([], $permitted);
    }

    /**
     * THE GUARD THAT MATTERS OVER TIME. Adding an AttentionType without
     * classifying it fails here, so nobody can widen what a Location-restricted
     * actor is told simply by forgetting.
     */
    public function test_every_attention_type_is_explicitly_classified(): void
    {
        $safe = CooFactAuthorization::businessWideSafeAttention();
        $bound = CooFactAuthorization::locationBoundAttention();

        $values = static fn (array $types): array => array_map(static fn (AttentionType $t): string => $t->value, $types);
        $safeValues = $values($safe);
        $boundValues = $values($bound);

        $unclassifiedValues = array_values(array_diff($values(AttentionType::cases()), $safeValues, $boundValues));
        $bothValues = array_values(array_intersect($safeValues, $boundValues));

        $this->assertSame([], $unclassifiedValues, 'Classify every new AttentionType in CooFactAuthorization as Business-wide-safe or Location-bound.');
        $this->assertSame([], $bothValues, 'A type cannot be both safe and Location-bound.');
        $this->assertCount(count(AttentionType::cases()), array_merge($safe, $bound));
    }

    /**
     * The three types whose Location-boundness is NOT visible as a column on
     * their own table, pinned by name so a future reader cannot "simplify" the
     * classification back to a column check.
     */
    public function test_the_substantively_location_bound_types_are_classified_as_such(): void
    {
        $bound = CooFactAuthorization::locationBoundAttention();

        $this->assertContains(AttentionType::ConversationsAwaitingReply, $bound);
        $this->assertContains(AttentionType::GoogleLocationUnhealthy, $bound);
        $this->assertContains(AttentionType::AutomationFailing, $bound, 'automation_executions.contact_id is NOT NULL and contacts.location_id is Location-bound.');
    }

    /** @param array<int, int> $authorized */
    private function envelope(array $authorized): CooContextEnvelope
    {
        return new CooContextEnvelope(
            scope: CooScope::Business,
            workspaceId: 5,
            businessId: 7,
            authorizedLocationIds: $authorized,
            capabilityKeys: [],
            actorUserId: 11,
        );
    }
}

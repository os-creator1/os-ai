<?php

namespace Tests\Feature\Coo\Insight;

use App\Enums\Dashboard\AttentionType;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Coo\Insight\CooInsightFacts;
use App\Library\Coo\Insight\CooInsightFactsReader;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\User;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Coo\Insight\Concerns\CreatesCooInsightFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 19 §6.2 / R-7, sub-slice 19.B — the COO never
 * narrates, implies, counts or summarises a fact the actor may not see.
 *
 * R-7 is the acceptance bar and it is stricter than "don't print it": an
 * excluded fact must not appear as a value, as a zero, as a count, as a
 * citable fact ref, or as an explanation of its own absence. These tests
 * therefore assert on the FACT SNAPSHOT THAT IS HANDED TO THE MODEL, not on
 * rendered output — the model never receives the information in the first
 * place, rather than being trusted to ignore it.
 */
class CooAuthorizedFactCompositionTest extends TestCase
{
    use CreatesCooInsightFixtures;
    use RefreshDatabase;

    /** Tables that exist to hold, or are attributed to, Location-bound data. */
    private const LOCATION_BOUND_TABLES = ['contacts', 'chat_boxes', 'chat_box_messages', 'reports', 'automation_executions', 'opportunities'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCooInsights();
    }

    // =================================================================
    // Complete coverage — nothing changes for an actor who may read it all
    // =================================================================

    public function test_an_actor_who_may_read_every_location_still_gets_the_full_fact_set(): void
    {
        [$owner, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $this->locations($business, 2);
        $business = $business->fresh();
        $this->materialPeriod($business);

        $facts = $this->factsFor($business, $owner->user);

        $this->assertSame(
            ['conversations_started', 'messages_received', 'new_contacts'],
            $this->metricKeys($facts),
            'Complete coverage composes exactly the pre-19.B metric set.',
        );
        $this->assertSame(20, $facts->metrics['new_contacts']['current']);
        $this->assertContains('metric.new_contacts', $facts->factRefs());
    }

    public function test_a_business_with_no_locations_is_complete_coverage_for_its_owner(): void
    {
        [$owner, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $this->materialPeriod($business);

        $this->assertSame(
            ['conversations_started', 'messages_received', 'new_contacts'],
            $this->metricKeys($this->factsFor($business->fresh(), $owner->user)),
        );
    }

    // =================================================================
    // Partial coverage — the leak surface
    // =================================================================

    /**
     * §13.3 / proof 3 and proof 14 together: a Selected-scope actor is told
     * NOTHING about activity, and in particular no count that could reveal a
     * Location they may not read. The keys are absent, not zeroed — a zero is
     * itself a claim about the Business as a whole.
     */
    public function test_a_selected_scope_actor_receives_no_activity_metric_at_all(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $locations = $this->locations($business, 2);
        $business = $business->fresh();
        $this->materialPeriod($business);

        $staff = $this->staffWithLocations($workspace, [$locations[0]]);

        $facts = $this->factsFor($business, $staff);

        $this->assertSame([], $this->metricKeys($facts), 'No metric key exists, so no count can be read or inferred.');
        $this->assertSame([], $facts->materialMetricKeys());

        foreach (['metric.new_contacts', 'metric.conversations_started', 'metric.messages_received'] as $ref) {
            $this->assertNotContains($ref, $facts->factRefs(), $ref . ' must not even be citable.');
        }
    }

    /**
     * The decisive leakage proof: the same Business, the same window, two
     * actors. The owner is told the numbers; the restricted actor's snapshot
     * contains no trace of them anywhere — not the value, not a bucket label,
     * not a key, not the word.
     */
    public function test_the_restricted_actors_prompt_snapshot_contains_no_trace_of_the_excluded_activity(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $locations = $this->locations($business, 2);
        $business = $business->fresh();
        $this->materialPeriod($business);

        $staff = $this->staffWithLocations($workspace, [$locations[0]]);

        $ownerSnapshot = json_encode($this->factsFor($business, $owner->user)->forPrompt());
        $staffSnapshot = json_encode($this->factsFor($business, $staff)->forPrompt());

        $this->assertStringContainsString('new_contacts', (string) $ownerSnapshot, 'Control: the owner really is told this.');

        foreach (['new_contacts', 'conversations_started', 'messages_received', 'material_increase', 'material_decrease'] as $needle) {
            $this->assertStringNotContainsString($needle, (string) $staffSnapshot, 'The model must never receive "' . $needle . '" for a restricted actor.');
        }
    }

    /**
     * R-7 forbids explaining the absence as much as stating the fact. Nothing
     * in the snapshot may announce that anything was withheld.
     */
    public function test_nothing_in_the_snapshot_announces_that_facts_were_withheld(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $locations = $this->locations($business, 2);
        $business = $business->fresh();
        $this->materialPeriod($business);

        $staff = $this->staffWithLocations($workspace, [$locations[0]]);
        $snapshot = strtolower((string) json_encode($this->factsFor($business, $staff)->forPrompt()));

        foreach (['restrict', 'withheld', 'hidden', 'not available', 'unavailable', 'permission', 'excluded', 'location'] as $needle) {
            $this->assertStringNotContainsString($needle, $snapshot, 'The snapshot must not hint that anything was left out.');
        }
    }

    /**
     * "Filter BEFORE aggregation" proven the strongest way available: for a
     * restricted actor the excluded sources are not queried at all, so the
     * inaccessible Location is unreadable and uncountable rather than
     * aggregated and then discarded.
     */
    public function test_a_restricted_actor_composes_facts_without_querying_any_location_bound_source(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $locations = $this->locations($business, 3);
        $business = $business->fresh();
        $this->materialPeriod($business);

        $staff = $this->staffWithLocations($workspace, [$locations[0]]);

        $sql = $this->sqlDuring(fn () => $this->factsFor($business, $staff));

        foreach (self::LOCATION_BOUND_TABLES as $table) {
            $touched = array_values(array_filter($sql, fn (string $statement): bool => preg_match('/\b' . preg_quote($table, '/') . '\b/i', $statement) === 1));

            $this->assertSame([], $touched, $table . ' must not be read at all for a Location-restricted actor.');
        }
    }

    /** Control: the same sources ARE read for an actor who may read them. */
    public function test_the_same_sources_are_read_for_an_actor_with_complete_coverage(): void
    {
        [$owner, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $this->locations($business, 2);
        $business = $business->fresh();
        $this->materialPeriod($business);

        $sql = implode(' | ', $this->sqlDuring(fn () => $this->factsFor($business, $owner->user)));

        $this->assertMatchesRegularExpression('/\bcontacts\b/i', $sql, 'Control: complete coverage really does read the Location-bound sources.');
    }

    // =================================================================
    // Attention: the substantively Location-bound types
    // =================================================================

    public function test_a_restricted_actor_is_never_told_a_conversation_is_waiting(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $locations = $this->locations($business, 2);
        $business = $business->fresh();

        // A real waiting conversation exists for the Business: one inbound
        // message, older than the awaiting-reply grace window.
        $this->conversationWith($business, [['incoming', Carbon::now()->subHours(3)->format('Y-m-d H:i:s')]]);

        $staff = $this->staffWithLocations($workspace, [$locations[0]]);
        $facts = $this->factsFor($business, $staff);

        $this->assertNotContains(AttentionType::ConversationsAwaitingReply->value, $facts->attention);
        $this->assertNotContains('attention.' . AttentionType::ConversationsAwaitingReply->value, $facts->factRefs());
    }

    public function test_a_restricted_actor_is_never_told_an_automation_is_failing(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $locations = $this->locations($business, 2);
        $business = $business->fresh();
        $this->automationRuns($business, 3, '2026-09-04', 'failed');

        $staff = $this->staffWithLocations($workspace, [$locations[0]]);
        $facts = $this->factsFor($business, $staff);

        $this->assertNotContains(AttentionType::AutomationFailing->value, $facts->attention, 'automation_executions.contact_id -> contacts.location_id makes this Location-bound in substance.');
    }

    /** The per-Business facts an actor may always have are still composed. */
    public function test_a_restricted_actor_still_receives_the_provably_business_wide_facts(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $locations = $this->locations($business, 2);
        $business = $business->fresh();
        $this->website($business, 'draft');

        $staff = $this->staffWithLocations($workspace, [$locations[0]]);
        $facts = $this->factsFor($business, $staff);

        $this->assertContains(AttentionType::WebsiteUnpublished->value, $facts->attention, '`websites` is one row per Business with no location column.');
        $this->assertSame('draft', $facts->visibility['website']);
    }

    // =================================================================
    // Query budget — flat in the number of Locations
    // =================================================================

    /**
     * Fact composition happens only inside the queued generator, never on a
     * Home render, so Home's own pinned budget is untouched by this slice.
     * What must stay flat here is the generator's own read: adding Locations
     * must not add queries, or a per-Location permission check has crept in.
     */
    public function test_composing_facts_costs_the_same_for_one_location_and_for_many(): void
    {
        [$ownerA, $businessA] = $this->tenant(WorkspacePlanTier::Growth, 'One Location Co', 'One Location Account');
        $this->locations($businessA, 1);
        $businessA = $businessA->fresh();
        $this->materialPeriod($businessA);

        [$ownerB, $businessB] = $this->tenant(WorkspacePlanTier::Growth, 'Many Location Co', 'Many Location Account');
        $this->locations($businessB, 8);
        $businessB = $businessB->fresh();
        $this->materialPeriod($businessB);

        $one = count($this->sqlDuring(fn () => $this->factsFor($businessA, $ownerA->user)));
        $many = count($this->sqlDuring(fn () => $this->factsFor($businessB, $ownerB->user)));

        $this->assertSame($one, $many, 'Fact composition must not grow with the number of Locations (no per-Location check, no per-Location query).');
    }

    public function test_a_restricted_actor_costs_no_more_than_a_complete_one(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $locations = $this->locations($business, 4);
        $business = $business->fresh();
        $this->materialPeriod($business);

        $staff = $this->staffWithLocations($workspace, [$locations[0]]);

        $complete = count($this->sqlDuring(fn () => $this->factsFor($business, $owner->user)));
        $restricted = count($this->sqlDuring(fn () => $this->factsFor($business, $staff)));

        $this->assertLessThanOrEqual($complete, $restricted, 'Excluding sources must cost fewer reads, never more.');
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function factsFor(Business $business, User $actor): CooInsightFacts
    {
        return app(CooInsightFactsReader::class)->read(
            $business,
            $this->thisMonth($business),
            $this->actorEnvelope($business, $actor),
        );
    }

    /** @return array<int, string> */
    private function metricKeys(CooInsightFacts $facts): array
    {
        $keys = array_keys($facts->metrics);
        sort($keys);

        return $keys;
    }

    /** @return array<int, BusinessLocation> */
    private function locations(Business $business, int $count): array
    {
        $created = [];

        for ($i = 0; $i < $count; $i++) {
            $created[] = BusinessLocation::create([
                'business_id' => $business->id,
                'name' => 'Location ' . ($i + 1),
                'service_mode' => 'storefront',
                'country_code' => 'US',
            ]);
        }

        return $created;
    }

    /** @param array<int, BusinessLocation> $granted */
    private function staffWithLocations(mixed $workspace, array $granted): User
    {
        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();

        foreach ($granted as $location) {
            app(WorkspaceMembershipLocationRepository::class)->assign($membership, $location);
        }

        return $staff->user->fresh();
    }
}

<?php

namespace Tests\Feature\Coo\Context;

use App\Enums\Coo\CooInsightInvalidationReason;
use App\Enums\Coo\CooInsightOrigin;
use App\Enums\Coo\CooScope;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Coo\Context\AuthorizationScopeFingerprint;
use App\Library\Coo\Context\CooContextEnvelopeFactory;
use App\Library\Coo\Insight\CooInsightDisplayReader;
use App\Library\ViewAs\ViewAsContext;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\CooInsight;
use App\Models\Customer;
use App\Models\User;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Coo\Insight\Concerns\CreatesCooInsightFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 19 §5.8, §5.9, §5.9b, sub-slice 19.A — the display
 * safety the authorization-scope fingerprint exists to provide.
 *
 * The rule under test is short: a cached answer is readable only by an actor
 * whose INDEPENDENTLY RECOMPUTED live authorization scope is EXACTLY equal to
 * the one it was generated for (R-31), and when nothing matches there is no
 * fallback to anything broader (R-32).
 */
class CooAuthorizationScopeCacheTest extends TestCase
{
    use CreatesCooInsightFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCooInsights();
    }

    // =================================================================
    // Who may read a background row
    // =================================================================

    public function test_a_background_row_is_generated_for_the_workspace_owner_audience_and_names_no_actor(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $this->locations($business, 2);

        $insight = $this->cachedInsight($business->fresh());

        $this->assertSame(CooInsightOrigin::System, $insight->origin);
        $this->assertNull($insight->actor_user_id, 'Nobody asked for a background generation, so nothing is attributed.');
        $this->assertSame((int) $customer->user_id, $insight->audience_user_id, 'The declared audience is the canonical Workspace owner.');
    }

    public function test_the_owner_reads_the_background_row_written_for_their_own_scope(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $this->locations($business, 2);
        $business = $business->fresh();
        $this->cachedInsight($business);

        $this->assertNotNull($this->read($business, $customer->user));
    }

    /**
     * §5.9b — a second All-scope actor whose consulted capability set is
     * exactly equal reuses the row, correctly, because their authorization is
     * identical. Nothing is re-attributed: the row still names no actor.
     */
    public function test_a_second_actor_with_an_exactly_equal_scope_may_reuse_the_background_row(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->locations($business, 2);
        $business = $business->fresh();
        $this->cachedInsight($business);

        $peer = $this->createCustomer();
        $this->member($workspace, $peer->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);

        $this->assertNotNull($this->read($business, $peer->user));
        $this->assertNull(CooInsight::query()->sole()->actor_user_id, 'Reuse never rewrites attribution.');
        $this->assertSame((int) $customer->user_id, CooInsight::query()->sole()->audience_user_id);
    }

    /**
     * R-23/R-31, the case the whole mechanism exists for: a Selected-scope
     * staff member reads a strict subset of the owner's Locations, so the
     * owner's answer is not theirs to see.
     */
    public function test_the_owners_full_scope_row_is_unavailable_to_selected_scope_staff(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $locations = $this->locations($business, 3);
        $business = $business->fresh();
        $this->cachedInsight($business);

        $staff = $this->staffWithLocations($workspace, $business, [$locations[0], $locations[1]]);

        $this->assertNull($this->read($business, $staff), 'A subset of Locations is a different authorization scope.');
    }

    /** Two different subsets, in both directions: neither may read the other's. */
    public function test_two_staff_with_different_location_subsets_never_share_a_cached_answer(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $locations = $this->locations($business, 3);
        $business = $business->fresh();

        $a = $this->staffWithLocations($workspace, $business, [$locations[0], $locations[1]]);
        $b = $this->staffWithLocations($workspace, $business, [$locations[1], $locations[2]]);

        $this->cachedInsight($business, $this->scopeOf($business, $a));

        $this->assertNotNull($this->read($business, $a));
        $this->assertNull($this->read($business, $b), '[1,2] and [2,3] are different authorization sets.');
    }

    /** R-24 — removing a consulted capability strands the richer answer at once. */
    public function test_removing_a_consulted_capability_immediately_strands_the_cached_answer(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $this->locations($business, 2);
        $business = $business->fresh();

        $this->grant($customer, ['view_reports']);
        $this->cachedInsight($business, $this->scopeOf($business, $customer->user->fresh()));

        $this->assertNotNull($this->read($business, $customer->user->fresh()));

        $this->grant($customer, []);

        $this->assertNull($this->read($business, $customer->user->fresh()));
    }

    /** A Location grant added or removed makes the prior row unselectable, with no sweep. */
    public function test_changing_a_location_grant_immediately_strands_the_cached_answer(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $locations = $this->locations($business, 3);
        $business = $business->fresh();

        $staff = $this->staffWithLocations($workspace, $business, [$locations[0]], $membership);
        $this->cachedInsight($business, $this->scopeOf($business, $staff));

        $this->assertNotNull($this->read($business, $staff));

        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $locations[1]);

        $this->assertNull($this->read($business, $staff->fresh()), 'A widened grant is a different scope, not a licence to read the old row.');
    }

    // =================================================================
    // Who may read a human's own row
    // =================================================================

    public function test_an_on_demand_row_is_never_served_to_another_actor(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->locations($business, 2);
        $business = $business->fresh();

        $peer = $this->createCustomer();
        $this->member($workspace, $peer->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);

        $this->cachedInsight($business, $this->scopeOf($business, $customer->user) + [
            'origin' => CooInsightOrigin::OnDemand->value,
            'actor_user_id' => (int) $customer->user_id,
            'audience_user_id' => null,
        ]);

        $this->assertNotNull($this->read($business, $customer->user), "A human reads their own answer.");
        $this->assertNull($this->read($business, $peer->user), 'Even an identical authorization scope does not inherit somebody else\'s answer.');
    }

    // =================================================================
    // View As
    // =================================================================

    public function test_a_view_as_read_never_reuses_an_ordinary_reads_cached_answer(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $this->locations($business, 2);
        $business = $business->fresh();
        $this->cachedInsight($business);

        $this->assertNotNull($this->read($business, $customer->user));
        $this->assertNull(
            $this->read($business, $customer->user, $this->viewAs($business, $customer->user)),
            'Viewing as a client is a different authorization context, so it is a different cache entry.',
        );
    }

    // =================================================================
    // Retirement, and the absence of any fallback
    // =================================================================

    public function test_a_retired_legacy_row_is_unreachable_by_every_reader(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $this->locations($business, 2);
        $business = $business->fresh();

        $insight = $this->cachedInsight($business);

        // Exactly what the 19.A migration does to a row generated before
        // authorization scope existed.
        DB::table('coo_insights')->where('id', $insight->id)->update([
            'authorization_scope_fingerprint' => AuthorizationScopeFingerprint::RETIRED,
            'invalidated_at' => Carbon::now(),
            'invalidation_reason' => CooInsightInvalidationReason::AuthorizationScopeIntroduced->value,
        ]);

        $this->assertNull($this->read($business, $customer->user));
        $this->assertFalse(AuthorizationScopeFingerprint::looksComputed(AuthorizationScopeFingerprint::RETIRED));
    }

    public function test_a_reader_with_no_exact_match_is_never_given_a_broader_row(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $locations = $this->locations($business, 3);
        $business = $business->fresh();

        $this->cachedInsight($business);

        $staff = $this->staffWithLocations($workspace, $business, [$locations[0]]);

        $this->assertNull($this->read($business, $staff));
        $this->assertSame(1, CooInsight::query()->count(), 'The row still exists; it is simply not theirs to read.');
    }

    // =================================================================
    // Home stays usable either way
    // =================================================================

    public function test_home_renders_its_deterministic_bands_for_an_actor_with_no_matching_ai_row(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $locations = $this->locations($business, 3);
        $business = $business->fresh();
        $this->cachedInsight($business);

        $staffCustomer = $this->createCustomer();
        $membership = $this->member($workspace, $staffCustomer->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();
        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $locations[0]);

        $this->authenticateAs($staffCustomer);

        $html = $this->get(route('user.home'))->assertOk()->getContent();

        $this->assertStringContainsString('data-band="headlines"', $html, 'Home is fully usable without an AI line.');
        $this->assertStringNotContainsString('data-role="what-we-notice"', $html, 'And it shows no answer written for somebody else.');
    }

    // =================================================================
    // Sub-slice 19.B — the remaining §13.3 selection proofs
    // =================================================================

    /**
     * Restoring a capability does not "un-strand" the old row: selection
     * follows the CURRENT exact fingerprint and nothing else, so the row
     * written while the capability was held becomes readable again only
     * because the live fingerprint is once more exactly equal to it — never
     * because the reader remembered anything.
     */
    public function test_selection_follows_only_the_current_exact_fingerprint_when_a_capability_is_restored(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $this->locations($business, 2);
        $business = $business->fresh();

        $this->grant($customer, ['view_reports']);
        $this->cachedInsight($business, $this->scopeOf($business, $customer->user->fresh()));

        $this->assertNotNull($this->read($business, $customer->user->fresh()), 'Readable while the capability is held.');

        $this->grant($customer, []);
        $this->assertNull($this->read($business, $customer->user->fresh()), 'Stranded the moment it is removed.');

        $this->grant($customer, ['view_reports']);
        $this->assertNotNull($this->read($business, $customer->user->fresh()), 'Readable again only because the live fingerprint matches exactly once more.');
    }

    /** A foreign Business's insight is unreadable, however well the actor is authorized in their own. */
    public function test_an_insight_belonging_to_another_business_is_never_readable(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $this->locations($business, 1);
        $business = $business->fresh();

        [$foreignOwner, $foreign] = $this->tenant(WorkspacePlanTier::Growth, 'Foreign Venue', 'Foreign Account');
        $this->locations($foreign, 1);
        $foreign = $foreign->fresh();
        $this->cachedInsight($foreign);

        $this->assertNotNull($this->read($foreign, $foreignOwner->user), 'Control: its own owner can read it.');
        $this->assertNull($this->read($business, $customer->user), 'A different Business has no insight of its own.');

        // And the foreign row is not reachable by asking about it with this
        // actor's own envelope either.
        $this->assertNull(
            app(CooInsightDisplayReader::class)->forHome($foreign, $this->thisMonth($foreign), $this->actorEnvelope($business, $customer->user)),
            'An envelope for one Business can never select another Business\'s row.',
        );
    }

    // =================================================================
    // Helpers
    // =================================================================

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
    private function staffWithLocations(mixed $workspace, Business $business, array $granted, mixed &$membership = null): User
    {
        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();

        foreach ($granted as $location) {
            app(WorkspaceMembershipLocationRepository::class)->assign($membership, $location);
        }

        return $staff->user->fresh();
    }

    /** The scope columns a cached row would carry if this actor had asked for it. */
    private function scopeOf(Business $business, User $actor): array
    {
        $envelope = $this->actorEnvelope($business, $actor);

        return [
            'authorization_scope_fingerprint' => $envelope->authorizationScopeFingerprint,
            'audience_user_id' => null,
        ];
    }

    /** @param array<int, string> $permissions */
    private function grant(Customer $customer, array $permissions): void
    {
        DB::table('customers')->where('id', $customer->id)->update(['permissions' => json_encode($permissions)]);
    }

    private function viewAs(Business $business, User $actor): ViewAsContext
    {
        return new ViewAsContext(
            sessionId: 4242,
            uid: 'view-as-uid',
            actorUserId: (int) $actor->id,
            actorDisplayName: 'Agency Actor',
            workspaceId: (int) $business->workspace_id,
            workspaceUid: 'workspace-uid',
            businessId: (int) $business->id,
            businessUid: (string) $business->uid,
            businessName: (string) $business->name,
            startedAt: Carbon::now()->toImmutable(),
            expiresAt: Carbon::now()->addHour()->toImmutable(),
        );
    }

    private function read(Business $business, User $actor, ?ViewAsContext $viewAs = null): ?array
    {
        $envelope = app(CooContextEnvelopeFactory::class)->forActor($business, $actor, $viewAs);

        $this->assertNotNull($envelope);
        $this->assertSame(CooScope::Business, $envelope->scope);

        return app(CooInsightDisplayReader::class)->forHome($business, $this->thisMonth($business), $envelope);
    }
}

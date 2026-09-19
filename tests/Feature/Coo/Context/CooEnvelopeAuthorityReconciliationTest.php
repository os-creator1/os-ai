<?php

namespace Tests\Feature\Coo\Context;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Coo\Context\AuthorizationScopeFingerprint;
use App\Library\Coo\Context\CooCapabilityKeys;
use App\Library\Coo\Context\CooContextEnvelopeFactory;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Customer;
use App\Models\User;
use App\Repositories\Contracts\AccountRepository;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Session;
use Tests\Feature\Coo\Insight\Concerns\CreatesCooInsightFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 19 sub-slice 19.A, reconciled with current `main`.
 *
 * Two seams this slice does NOT own, and must therefore be pinned to rather
 * than re-implemented:
 *
 *  1. LOCATION AUTHORITY. `main` owns the canonical bulk primitive,
 *     LocationAccessGuard::accessibleLocationIdsForBusiness() (Slice 18A),
 *     whose equivalence to the per-Location predicate is already proven for
 *     every actor class by LocationAccessGuardBulkTest. The COO must consume
 *     that answer verbatim — never widen it, never narrow it, and never grow
 *     a second ACL (Contract 19 R-0). The tests below assert the envelope's
 *     Location set against the guard's own output, so if either side ever
 *     changes, this fails rather than silently drifting.
 *
 *  2. PERMISSION AUTHORITY. The application's ordinary permission check
 *     prefers a session copy. A background job has no session, so the COO
 *     reads the DURABLE set instead — and the whole cache depends on a job
 *     and a request agreeing. The tests below prove they agree, prove a
 *     session's transient state cannot create an identity a job could never
 *     reproduce, and prove ordinary request semantics are untouched.
 */
class CooEnvelopeAuthorityReconciliationTest extends TestCase
{
    use CreatesCooInsightFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCooInsights();
    }

    // =================================================================
    // 1. Location authority — the envelope mirrors the canonical primitive
    // =================================================================

    public function test_the_envelope_location_set_is_exactly_the_canonical_guard_answer_for_every_actor_class(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $locations = $this->locations($business, 3);
        $business = $business->fresh();

        $allScope = $this->createCustomer();
        $this->member($workspace, $allScope->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);

        $selected = $this->createCustomer();
        $membership = $this->member($workspace, $selected->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();
        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $locations[1]);

        $stranger = $this->createCustomer();

        foreach ([$owner, $allScope, $selected, $stranger] as $actor) {
            $this->assertEnvelopeMirrorsGuard($business, $actor->user->fresh());
        }
    }

    public function test_an_inactive_workspace_gives_the_envelope_the_guards_empty_answer(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->locations($business, 2);
        $workspace->forceFill(['is_active' => false])->save();

        $this->assertEnvelopeMirrorsGuard($business->fresh(), $owner->user);
    }

    /**
     * The canonical primitive returns ids in repository order, not sorted.
     * That is fine here — and this proves it, rather than assuming it: the
     * cache identity must depend on WHICH Locations are authorized, never on
     * the order they happened to be listed in.
     */
    public function test_the_cache_identity_depends_on_the_location_set_not_the_order_the_guard_returned_it_in(): void
    {
        [$owner, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $locations = $this->locations($business, 3);
        $business = $business->fresh();

        $envelope = $this->actorEnvelope($business, $owner->user);
        $ids = array_map(static fn (BusinessLocation $location): int => (int) $location->id, $locations);

        $sorted = $ids;
        sort($sorted, SORT_NUMERIC);
        $shuffled = array_reverse($ids);

        $this->assertSame($sorted, $envelope->authorizedLocationIds, 'The envelope normalises to a sorted, de-duplicated set.');

        $this->assertSame(
            $envelope->authorizationScopeFingerprint,
            AuthorizationScopeFingerprint::compute(
                $envelope->scope,
                $envelope->workspaceId,
                $envelope->businessId,
                $shuffled,
                $envelope->capabilityKeys,
            ),
            'A different listing order of the same authorized Locations is the same identity.',
        );
    }

    /** R-0 — the COO never reaches past the one authority to build its own answer. */
    public function test_the_envelope_never_widens_beyond_the_guard(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $locations = $this->locations($business, 3);
        $business = $business->fresh();

        $selected = $this->createCustomer();
        $membership = $this->member($workspace, $selected->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();
        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $locations[0]);

        $envelope = $this->actorEnvelope($business, $selected->user->fresh());

        $this->assertSame([(int) $locations[0]->id], $envelope->authorizedLocationIds);
        $this->assertNotContains((int) $locations[1]->id, $envelope->authorizedLocationIds);
        $this->assertNotContains((int) $locations[2]->id, $envelope->authorizedLocationIds);
    }

    // =================================================================
    // 2. Permission authority — a job and a request agree
    // =================================================================

    /**
     * The invariant the whole cache rests on: the identity a background job
     * computes with NO session is byte-identical to the one an authenticated
     * request computes for the same user.
     */
    public function test_a_request_and_a_background_job_derive_the_same_capability_identity_for_the_same_user(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $this->locations($business, 2);
        $business = $business->fresh();
        $this->grant($customer, CooCapabilityKeys::CONSULTED);

        // A job: no session at all.
        Session::flush();
        $inJob = $this->actorEnvelope($business, $customer->user->fresh());

        // A request: the session carries its own permission copy.
        $this->authenticateAs($customer);
        $inRequest = $this->actorEnvelope($business, $customer->user->fresh());

        $this->assertSame($inJob->capabilityKeys, $inRequest->capabilityKeys);
        $this->assertSame($inJob->authorizationScopeFingerprint, $inRequest->authorizationScopeFingerprint);
    }

    /**
     * A session may carry transient permissions the stored record does not.
     * If those reached the fingerprint, the request would mint an identity no
     * background job could ever reproduce, and the cache would never hit.
     */
    public function test_transient_session_permissions_never_enter_the_cache_identity(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $this->locations($business, 2);
        $business = $business->fresh();

        // Durably holds nothing; the session claims everything.
        $this->grant($customer, []);
        $this->withSession(['permissions' => collect(array_merge(['access_backend'], CooCapabilityKeys::CONSULTED))]);
        $this->actingAs($customer->user);

        $withBroadSession = $this->actorEnvelope($business, $customer->user->fresh());

        Session::flush();
        $withNoSession = $this->actorEnvelope($business, $customer->user->fresh());

        $this->assertSame([], $withBroadSession->capabilityKeys, 'Only the durable set counts.');
        $this->assertSame($withNoSession->authorizationScopeFingerprint, $withBroadSession->authorizationScopeFingerprint);
    }

    /** And ordinary permission semantics are untouched: the session still wins for a request's own checks. */
    public function test_ordinary_has_permission_still_prefers_the_session_copy(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        $this->grant($customer, []);

        $accounts = app(AccountRepository::class);
        $user = $customer->user->fresh();

        $this->withSession(['permissions' => collect(['view_reports'])]);
        $this->actingAs($user);

        $this->assertTrue($accounts->hasPermission($user, 'view_reports'), 'Request semantics are unchanged.');
        $this->assertTrue(Gate::forUser($user)->allows('view_reports'));

        $this->assertSame([], $accounts->durablePermissions($user)->all(), 'The durable view is a separate question, and answers it honestly.');
    }

    /**
     * The `fresh` flag has to actually read the database, not the relation the
     * model happens to be carrying — otherwise a long-lived model inside a
     * worker would keep minting an identity from a stale permission set.
     */
    public function test_the_durable_view_reads_the_database_and_not_a_stale_loaded_relation(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $this->locations($business, 1);
        $business = $business->fresh();
        $this->grant($customer, ['view_reports']);

        $accounts = app(AccountRepository::class);

        // A model that loaded the relation while the permission was still held.
        $warm = $customer->user->fresh();
        $warm->load('customer');
        $this->assertSame(['view_reports'], $accounts->durablePermissions($warm, fresh: true)->all());

        // The permission is revoked underneath it.
        $this->grant($customer, []);

        $this->assertSame(
            ['view_reports'],
            collect(json_decode((string) $warm->customer->permissions, true) ?: [])->all(),
            'The cached relation is genuinely stale, so this test is testing something.',
        );

        $this->assertSame([], $accounts->durablePermissions($warm, fresh: true)->all(), 'fresh: true must see the revocation.');
        $this->assertSame([], $this->actorEnvelope($business, $warm)->capabilityKeys, 'And the envelope built from that model carries the revocation too.');
    }

    /**
     * The one case the deleted branch test covered that main's bulk suite does
     * not: a Location grant must never outlive the Business grant it depends
     * on. Restored here against the CANONICAL primitive, so it pins main's
     * method rather than resurrecting a parallel one.
     */
    public function test_a_business_grant_revoked_after_a_location_grant_collapses_the_canonical_set(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $locations = $this->locations($business, 2);
        $business = $business->fresh();

        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();
        app(\App\Repositories\Contracts\WorkspaceMembershipBusinessRepository::class)->assign($membership, $business);
        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $locations[0]);

        $guard = app(LocationAccessGuard::class);
        $actor = $staff->user->fresh();

        $this->assertSame([(int) $locations[0]->id], $guard->accessibleLocationIdsForBusiness((int) $actor->id, $business));
        $this->assertSame([(int) $locations[0]->id], $this->actorEnvelope($business, $actor)->authorizedLocationIds);

        app(\App\Repositories\Contracts\WorkspaceMembershipBusinessRepository::class)->unassign($membership, (int) $business->id);

        $this->assertSame([], $guard->accessibleLocationIdsForBusiness((int) $actor->id, $business), 'A stale Location grant never outlives the Business grant.');
        $this->assertEnvelopeMirrorsGuard($business, $actor->fresh());
    }

    // =================================================================
    // 3. Broader never satisfies narrower
    // =================================================================

    public function test_a_broader_capability_set_never_satisfies_a_narrower_fingerprint(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $this->locations($business, 2);
        $business = $business->fresh();

        $this->grant($customer, []);
        $narrow = $this->actorEnvelope($business, $customer->user->fresh());

        $this->grant($customer, CooCapabilityKeys::CONSULTED);
        $broad = $this->actorEnvelope($business, $customer->user->fresh());

        $this->assertNotSame($narrow->authorizationScopeFingerprint, $broad->authorizationScopeFingerprint);

        // The row the narrower actor's scope produced is not readable once the
        // actor is broader, and vice versa: an exact match, never a subset or
        // superset match.
        $this->cachedInsight($business, ['authorization_scope_fingerprint' => $narrow->authorizationScopeFingerprint, 'audience_user_id' => null]);

        $this->assertNotNull($this->readWith($business, $narrow));
        $this->assertNull($this->readWith($business, $broad), 'Holding MORE permission does not inherit an answer written for less.');
    }

    public function test_a_wider_location_set_never_satisfies_a_narrower_fingerprint(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $locations = $this->locations($business, 3);
        $business = $business->fresh();

        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();
        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $locations[0]);

        $narrow = $this->actorEnvelope($business, $staff->user->fresh());
        $this->cachedInsight($business, ['authorization_scope_fingerprint' => $narrow->authorizationScopeFingerprint, 'audience_user_id' => null]);

        $this->assertNotNull($this->readWith($business, $narrow));

        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $locations[1]);
        $wider = $this->actorEnvelope($business, $staff->user->fresh());

        $this->assertNull($this->readWith($business, $wider), 'A widened Location grant is a new identity, not a licence to read the old row.');
    }

    // =================================================================
    // 4. Job writes, request reads — across the session boundary
    // =================================================================

    public function test_an_insight_written_with_no_session_is_readable_by_the_same_actor_inside_a_real_request(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $this->locations($business, 2);
        $business = $business->fresh();
        $this->grant($customer, CooCapabilityKeys::CONSULTED);

        // Exactly a background job's conditions.
        Session::flush();
        $this->cachedInsight($business);

        $this->authenticateAs($customer);

        $html = $this->get(route('user.home'))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="what-we-notice"', $html, 'What a job wrote, the request can read: one identity, two contexts.');
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function assertEnvelopeMirrorsGuard(Business $business, User $actor): void
    {
        $guardAnswer = app(LocationAccessGuard::class)->accessibleLocationIdsForBusiness((int) $actor->id, $business);
        $expected = array_values(array_unique($guardAnswer));
        sort($expected, SORT_NUMERIC);

        $envelope = app(CooContextEnvelopeFactory::class)->forActor($business, $actor);

        $this->assertNotNull($envelope);
        $this->assertSame(
            $expected,
            $envelope->authorizedLocationIds,
            'The envelope must carry the canonical guard answer, normalised — never its own computation.',
        );
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

    /** @param array<int, string> $permissions */
    private function grant(Customer $customer, array $permissions): void
    {
        DB::table('customers')->where('id', $customer->id)->update(['permissions' => json_encode($permissions)]);
    }

    private function readWith(Business $business, \App\Library\Coo\Context\CooContextEnvelope $envelope): ?array
    {
        return app(\App\Library\Coo\Insight\CooInsightDisplayReader::class)
            ->forHome($business, $this->thisMonth($business), $envelope);
    }
}

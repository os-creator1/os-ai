<?php

namespace Tests\Feature\NicheBlueprint;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\NicheBlueprint\NicheBlueprintVersionState;
use App\Exceptions\NicheBlueprint\BlueprintVersionMismatchException;
use App\Exceptions\NicheBlueprint\DraftVersionAlreadyExistsException;
use App\Exceptions\NicheBlueprint\DuplicateComponentKeyException;
use App\Exceptions\NicheBlueprint\EmptyDraftVersionException;
use App\Exceptions\NicheBlueprint\InvalidComponentDescriptorException;
use App\Exceptions\NicheBlueprint\MissingRequiredFeatureKeyException;
use App\Exceptions\NicheBlueprint\NotADraftVersionException;
use App\Exceptions\NicheBlueprint\UnknownBlueprintComponentTypeException;
use App\Exceptions\NicheBlueprint\UnknownPlatformFeatureKeyException;
use App\Exceptions\NicheBlueprint\WrongFeatureScopeException;
use App\Library\Entitlement\PlatformFeatureRegistry;
use App\Library\NicheBlueprint\Adapters\BlueprintComponentAdapter;
use App\Library\NicheBlueprint\Adapters\BlueprintComponentAdapterRegistry;
use App\Library\NicheBlueprint\Adapters\InstalledComponentReference;
use App\Library\NicheBlueprint\NicheBlueprintPublisher;
use App\Models\Business;
use App\Models\NicheBlueprint;
use App\Models\NicheBlueprintComponent;
use App\Models\NicheBlueprintVersion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * Implementation Contract 20 §6.1/§6.2, §12.B — the platform-side publishing
 * authority.
 *
 * `publishVersion()` is the fail-closed gate of the whole Blueprint domain:
 * a published version is immutable and is the thing every installation is
 * made from, so anything wrong that survives publish becomes permanent and
 * reaches live Businesses. Every one of §6.2's six checks is proven here, in
 * both directions.
 */
class NicheBlueprintPublisherTest extends TestCase
{
    use RefreshDatabase;

    private const TYPE = 'crm_pipeline';

    private NicheBlueprintPublisher $publisher;

    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerAdapter();
        $this->publisher = app(NicheBlueprintPublisher::class);
        $this->adminId = $this->createUser(true);
    }

    // ------------------------------------------------------------- fixtures

    /**
     * @param  callable(array): void|null  $validator
     */
    private function registerAdapter(string $type = self::TYPE, ?callable $validator = null): void
    {
        $adapter = new class($type, $validator) implements BlueprintComponentAdapter
        {
            /** @param callable(array): void|null $validator */
            public function __construct(private readonly string $type, private $validator)
            {
            }

            public function componentType(): string
            {
                return $this->type;
            }

            public function validateDescriptor(array $payload): void
            {
                if ($this->validator !== null) {
                    ($this->validator)($payload);
                }
            }

            public function install(Business $business, array $payload, ?int $actorUserId): InstalledComponentReference
            {
                return new InstalledComponentReference('crm_pipeline', 1);
            }
        };

        app(BlueprintComponentAdapterRegistry::class)->register($adapter);
    }

    private function createUser(bool $isAdmin): int
    {
        return DB::table('users')->insertGetId([
            'uid' => (string) Str::uuid(),
            'first_name' => $isAdmin ? 'Admin' : 'Plain',
            'last_name' => 'User',
            'email' => ($isAdmin ? 'admin' : 'plain') . uniqid() . '@example.test',
            'status' => true,
            'is_admin' => $isAdmin,
            'is_customer' => ! $isAdmin,
            'active_portal' => $isAdmin ? 'admin' : 'customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function blueprint(string $key = 'photo_booth'): NicheBlueprint
    {
        return $this->publisher->createBlueprint($this->adminId, $key, 'Photo Booth', null, 'photo_booth_service');
    }

    private function draftWithComponent(
        NicheBlueprint $blueprint,
        string $featureKey = 'crm',
        string $type = self::TYPE,
        array $payload = ['pipeline_key' => 'sales'],
    ): NicheBlueprintVersion {
        $draft = $this->publisher->createDraftVersion($this->adminId, $blueprint);
        $this->publisher->addDraftComponent(
            $this->adminId,
            $draft,
            'photo_booth_default_pipeline',
            $type,
            $featureKey,
            $payload
        );

        return $draft;
    }

    // ============================================================ authority

    /**
     * §6.1 — Platform Administrator on EVERY public operation. A non-admin
     * must never reach any authoring or publishing path.
     */
    public function test_every_public_operation_refuses_a_non_administrator(): void
    {
        $plainUserId = $this->createUser(false);
        $blueprint = $this->blueprint();
        $draft = $this->draftWithComponent($blueprint);
        $component = $draft->components()->first();
        $published = $this->publisher->publishVersion($this->adminId, $draft);

        $operations = [
            'createBlueprint' => fn () => $this->publisher->createBlueprint($plainUserId, 'other_niche', 'Other'),
            'updateBlueprintIdentity' => fn () => $this->publisher->updateBlueprintIdentity($plainUserId, $blueprint, ['display_name' => 'X']),
            'activateBlueprint' => fn () => $this->publisher->activateBlueprint($plainUserId, $blueprint),
            'deactivateBlueprint' => fn () => $this->publisher->deactivateBlueprint($plainUserId, $blueprint),
            'createDraftVersion' => fn () => $this->publisher->createDraftVersion($plainUserId, $blueprint),
            'updateDraftNotes' => fn () => $this->publisher->updateDraftNotes($plainUserId, $draft, 'x'),
            'deleteDraftVersion' => fn () => $this->publisher->deleteDraftVersion($plainUserId, $draft),
            'addDraftComponent' => fn () => $this->publisher->addDraftComponent($plainUserId, $draft, 'k', self::TYPE, 'crm', []),
            'updateDraftComponent' => fn () => $this->publisher->updateDraftComponent($plainUserId, $component, ['position' => 3]),
            'removeDraftComponent' => fn () => $this->publisher->removeDraftComponent($plainUserId, $component),
            'publishVersion' => fn () => $this->publisher->publishVersion($plainUserId, $draft),
            'supersede' => fn () => $this->publisher->supersede($plainUserId, $published),
        ];

        foreach ($operations as $name => $operation) {
            try {
                $operation();
                $this->fail($name . '() must refuse a non-administrator.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Authority is re-derived from persistence on every call, so revoking
     * `is_admin` takes effect immediately — a stale in-memory actor cannot
     * keep publishing.
     */
    public function test_authority_is_reread_from_persistence_on_every_call(): void
    {
        $blueprint = $this->blueprint();

        DB::table('users')->where('id', $this->adminId)->update(['is_admin' => false]);

        $this->expectException(AuthorizationException::class);
        $this->publisher->createDraftVersion($this->adminId, $blueprint);
    }

    public function test_a_customer_workspace_owner_is_not_a_platform_administrator(): void
    {
        // is_customer, not is_admin: no customer or Agency path exists (§15).
        $customerUserId = $this->createUser(false);

        $this->expectException(AuthorizationException::class);
        $this->publisher->createBlueprint($customerUserId, 'salon', 'Salon');
    }

    // ==================================================== draft version flow

    public function test_version_numbers_are_sequential_per_blueprint(): void
    {
        $blueprint = $this->blueprint();
        $other = $this->blueprint('salon');

        $v1 = $this->draftWithComponent($blueprint);
        $this->assertSame(1, $v1->version_number);
        $this->publisher->publishVersion($this->adminId, $v1);

        $v2 = $this->draftWithComponent($blueprint);
        $this->assertSame(2, $v2->version_number);

        // Numbering is per Blueprint, not global.
        $otherV1 = $this->publisher->createDraftVersion($this->adminId, $other);
        $this->assertSame(1, $otherV1->version_number);
    }

    public function test_a_second_draft_is_refused_by_the_domain_not_by_sql(): void
    {
        $blueprint = $this->blueprint();
        $first = $this->publisher->createDraftVersion($this->adminId, $blueprint);

        try {
            $this->publisher->createDraftVersion($this->adminId, $blueprint);
            $this->fail('A second draft must be refused.');
        } catch (DraftVersionAlreadyExistsException $e) {
            $this->assertSame((int) $first->id, $e->existingDraftVersionId);
            // A domain refusal, never a leaked integrity error.
            $this->assertStringNotContainsStringIgnoringCase('sql', $e->getMessage());
        }

        $this->assertSame(1, NicheBlueprintVersion::where('blueprint_id', $blueprint->id)->count());
    }

    public function test_a_draft_may_be_deleted_and_its_components_go_with_it(): void
    {
        $blueprint = $this->blueprint();
        $draft = $this->draftWithComponent($blueprint);
        $draftId = $draft->id;

        $this->publisher->deleteDraftVersion($this->adminId, $draft);

        $this->assertSame(0, NicheBlueprintVersion::whereKey($draftId)->count());
        $this->assertSame(0, NicheBlueprintComponent::where('blueprint_version_id', $draftId)->count());
    }

    // ================================================= immutability of issued

    /**
     * §5.2 — every authoring write refuses on a published version. This is
     * what stops a platform edit ever reaching an already-installed Business.
     */
    public function test_no_authoring_operation_can_mutate_a_published_version(): void
    {
        $blueprint = $this->blueprint();
        $draft = $this->draftWithComponent($blueprint);
        $component = $draft->components()->first();
        $published = $this->publisher->publishVersion($this->adminId, $draft);

        $this->assertRefusedAsNonDraft([
            'updateDraftNotes' => fn () => $this->publisher->updateDraftNotes($this->adminId, $published, 'edited'),
            'deleteDraftVersion' => fn () => $this->publisher->deleteDraftVersion($this->adminId, $published),
            'addDraftComponent' => fn () => $this->publisher->addDraftComponent($this->adminId, $published, 'another', self::TYPE, 'crm', []),
            'updateDraftComponent' => fn () => $this->publisher->updateDraftComponent($this->adminId, $component, ['payload' => ['tampered' => true]]),
            'removeDraftComponent' => fn () => $this->publisher->removeDraftComponent($this->adminId, $component),
            'publishVersion' => fn () => $this->publisher->publishVersion($this->adminId, $published),
        ]);
    }

    public function test_no_authoring_operation_can_mutate_a_superseded_version(): void
    {
        $blueprint = $this->blueprint();
        $v1 = $this->draftWithComponent($blueprint);
        $component = $v1->components()->first();
        $this->publisher->publishVersion($this->adminId, $v1);

        $v2 = $this->draftWithComponent($blueprint);
        $this->publisher->publishVersion($this->adminId, $v2);

        $superseded = $v1->refresh();
        $this->assertSame(NicheBlueprintVersionState::Superseded, $superseded->state);

        $this->assertRefusedAsNonDraft([
            'updateDraftNotes' => fn () => $this->publisher->updateDraftNotes($this->adminId, $superseded, 'edited'),
            'deleteDraftVersion' => fn () => $this->publisher->deleteDraftVersion($this->adminId, $superseded),
            'addDraftComponent' => fn () => $this->publisher->addDraftComponent($this->adminId, $superseded, 'another', self::TYPE, 'crm', []),
            'updateDraftComponent' => fn () => $this->publisher->updateDraftComponent($this->adminId, $component, ['payload' => ['tampered' => true]]),
            'removeDraftComponent' => fn () => $this->publisher->removeDraftComponent($this->adminId, $component),
            'publishVersion' => fn () => $this->publisher->publishVersion($this->adminId, $superseded),
        ]);
    }

    private function assertRefusedAsNonDraft(array $operations): void
    {
        foreach ($operations as $name => $operation) {
            try {
                $operation();
                $this->fail($name . '() must refuse on an issued version.');
            } catch (NotADraftVersionException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ===================================================== §6.2 publish gates

    /** Gate 1 — no registered adapter for the component_type. */
    public function test_publish_refuses_an_unregistered_component_type(): void
    {
        $blueprint = $this->blueprint();
        $draft = $this->draftWithComponent($blueprint, 'crm', 'calendar_booking_defaults');

        $this->expectException(UnknownBlueprintComponentTypeException::class);
        $this->publisher->publishVersion($this->adminId, $draft);
    }

    /** Gate 2 — a blank required_feature_key, caught at authoring AND at publish. */
    public function test_a_blank_required_feature_key_is_refused_at_authoring(): void
    {
        $blueprint = $this->blueprint();
        $draft = $this->publisher->createDraftVersion($this->adminId, $blueprint);

        $this->expectException(MissingRequiredFeatureKeyException::class);
        $this->publisher->addDraftComponent($this->adminId, $draft, 'k', self::TYPE, '   ', []);
    }

    public function test_publish_refuses_a_blank_required_feature_key_written_around_the_service(): void
    {
        $blueprint = $this->blueprint();
        $draft = $this->draftWithComponent($blueprint);

        // The column is NOT NULL, so the only way to reach the publish gate is
        // an empty string written directly — exactly the "no default, ever"
        // case gate 2 exists for.
        DB::table('niche_blueprint_components')
            ->where('blueprint_version_id', $draft->id)
            ->update(['required_feature_key' => '']);

        $this->expectException(MissingRequiredFeatureKeyException::class);
        $this->publisher->publishVersion($this->adminId, $draft);
    }

    /** Gate 3 — the key names no known PlatformFeature. */
    public function test_publish_refuses_an_unknown_platform_feature_key(): void
    {
        $blueprint = $this->blueprint();
        $draft = $this->draftWithComponent($blueprint, 'not_a_real_feature');

        try {
            $this->publisher->publishVersion($this->adminId, $draft);
            $this->fail('An unknown PlatformFeature key must be refused.');
        } catch (UnknownPlatformFeatureKeyException $e) {
            $this->assertSame('not_a_real_feature', $e->featureKey);
        }
    }

    /** Gate 4 — the key is Workspace-scoped, and components are Business-scoped. */
    public function test_publish_refuses_a_workspace_scoped_platform_feature_key(): void
    {
        $workspaceScoped = PlatformFeature::ProspectOutreach->value;

        // Guard the premise: if this ever becomes Business-scoped, this test
        // must be re-pointed rather than silently passing for the wrong reason.
        $this->assertTrue(PlatformFeatureRegistry::isKnown($workspaceScoped));
        $this->assertFalse(PlatformFeatureRegistry::isBusinessScoped($workspaceScoped));

        $blueprint = $this->blueprint();
        $draft = $this->draftWithComponent($blueprint, $workspaceScoped);

        try {
            $this->publisher->publishVersion($this->adminId, $draft);
            $this->fail('A Workspace-scoped feature key must be refused.');
        } catch (WrongFeatureScopeException $e) {
            $this->assertSame($workspaceScoped, $e->featureKey);
        }
    }

    /** Gate 5 — the adapter rejects its own descriptor. */
    public function test_publish_refuses_a_payload_its_adapter_rejects(): void
    {
        $this->registerAdapter('picky_type', function (array $payload): void {
            if (! isset($payload['required_field'])) {
                throw new RuntimeException('required_field is missing.');
            }
        });

        $blueprint = $this->blueprint();
        $draft = $this->draftWithComponent($blueprint, 'crm', 'picky_type', ['wrong' => true]);

        try {
            $this->publisher->publishVersion($this->adminId, $draft);
            $this->fail('An adapter-rejected payload must be refused.');
        } catch (InvalidComponentDescriptorException $e) {
            $this->assertSame('picky_type', $e->componentType);
            // The adapter's own reason is preserved, not swallowed.
            $this->assertStringContainsString('required_field is missing', $e->getMessage());
        }
    }

    /**
     * Gate 6 — duplicate component_key. Unreachable at publish by
     * construction, because the version's own unique index refuses the second
     * row; the service therefore refuses it at authoring, which is where a
     * real operator meets it. The publish-time loop check remains as defence
     * in depth for any future path that bypasses this service.
     */
    public function test_a_duplicate_component_key_is_refused_at_authoring(): void
    {
        $blueprint = $this->blueprint();
        $draft = $this->draftWithComponent($blueprint);

        $this->expectException(DuplicateComponentKeyException::class);
        $this->publisher->addDraftComponent(
            $this->adminId,
            $draft,
            'photo_booth_default_pipeline',
            self::TYPE,
            'crm',
            []
        );
    }

    public function test_a_duplicate_component_key_is_also_refused_on_rename(): void
    {
        $blueprint = $this->blueprint();
        $draft = $this->draftWithComponent($blueprint);
        $second = $this->publisher->addDraftComponent($this->adminId, $draft, 'second_component', self::TYPE, 'crm', []);

        $this->expectException(DuplicateComponentKeyException::class);
        $this->publisher->updateDraftComponent($this->adminId, $second, [
            'component_key' => 'photo_booth_default_pipeline',
        ]);
    }

    /** Wrong Blueprint/version pairing — a stale or tampered model. */
    public function test_publish_refuses_a_version_that_belongs_to_another_blueprint(): void
    {
        $blueprintA = $this->blueprint('photo_booth');
        $blueprintB = $this->blueprint('salon');
        $draftOfA = $this->draftWithComponent($blueprintA);

        // A stale/mismatched model: version of A, claiming Blueprint B.
        $mismatched = new NicheBlueprintVersion();
        $mismatched->forceFill(['id' => $draftOfA->id, 'blueprint_id' => $blueprintB->id]);
        $mismatched->exists = true;

        try {
            $this->publisher->publishVersion($this->adminId, $mismatched);
            $this->fail('A mismatched Blueprint/version pair must be refused.');
        } catch (BlueprintVersionMismatchException $e) {
            $this->assertSame((int) $blueprintB->id, $e->expectedBlueprintId);
            $this->assertSame((int) $blueprintA->id, $e->actualBlueprintId);
        }

        $this->assertSame(NicheBlueprintVersionState::Draft, $draftOfA->refresh()->state);
    }

    public function test_publish_refuses_an_empty_draft(): void
    {
        $blueprint = $this->blueprint();
        $draft = $this->publisher->createDraftVersion($this->adminId, $blueprint);

        $this->expectException(EmptyDraftVersionException::class);
        $this->publisher->publishVersion($this->adminId, $draft);
    }

    /** A refusal must leave the draft exactly as it was — no partial publish. */
    public function test_a_refused_publish_leaves_the_draft_untouched(): void
    {
        $blueprint = $this->blueprint();
        $draft = $this->draftWithComponent($blueprint, 'not_a_real_feature');
        $before = DB::table('niche_blueprint_versions')->where('id', $draft->id)->first();

        try {
            $this->publisher->publishVersion($this->adminId, $draft);
        } catch (UnknownPlatformFeatureKeyException) {
            // expected
        }

        $after = DB::table('niche_blueprint_versions')->where('id', $draft->id)->first();
        $this->assertEquals($before, $after, 'A refused publish must write nothing at all.');
        $this->assertNull($after->published_at);
        $this->assertNull($after->published_by_user_id);
    }

    // ================================================ the Planned-feature rule

    /**
     * §6.2's explicit carve-out: `isAvailable()` is NOT a publish gate. A
     * component whose feature is still `Planned` is legitimately publishable —
     * it is skipped at install until the feature ships. This is how one
     * canonical Blueprint stays complete while modules arrive over time.
     */
    public function test_a_planned_business_scoped_feature_may_be_published(): void
    {
        $planned = PlatformFeature::Calendar->value;

        // Guard the premise explicitly, so this test cannot silently pass for
        // the wrong reason once Calendar ships.
        $this->assertTrue(PlatformFeatureRegistry::isKnown($planned));
        $this->assertTrue(PlatformFeatureRegistry::isBusinessScoped($planned));
        $this->assertFalse(
            PlatformFeatureRegistry::isAvailable($planned),
            'This test needs a Planned feature; re-point it if Calendar has shipped.'
        );

        $blueprint = $this->blueprint();
        $draft = $this->draftWithComponent($blueprint, $planned);

        $published = $this->publisher->publishVersion($this->adminId, $draft);

        $this->assertSame(NicheBlueprintVersionState::Published, $published->state);
        $this->assertSame($planned, $published->components()->first()->required_feature_key);
    }

    // ========================================================= publish success

    public function test_publishing_records_the_real_administrator_and_a_timestamp(): void
    {
        $blueprint = $this->blueprint();
        $draft = $this->draftWithComponent($blueprint);

        $published = $this->publisher->publishVersion($this->adminId, $draft);

        $this->assertSame(NicheBlueprintVersionState::Published, $published->state);
        $this->assertSame($this->adminId, $published->published_by_user_id);
        $this->assertNotNull($published->published_at);
    }

    /**
     * §5.2 — publishing v2 supersedes v1, and v1's components stay
     * byte-identical. The whole "a platform change never reaches a running
     * Business" guarantee rests on this.
     */
    public function test_publishing_v2_supersedes_v1_and_leaves_its_components_byte_identical(): void
    {
        $blueprint = $this->blueprint();

        $v1 = $this->draftWithComponent($blueprint);
        $this->publisher->publishVersion($this->adminId, $v1);

        $v1ComponentsBefore = DB::table('niche_blueprint_components')
            ->where('blueprint_version_id', $v1->id)->orderBy('id')->get()->toArray();
        $v1RowBefore = DB::table('niche_blueprint_versions')->where('id', $v1->id)->first();

        $v2 = $this->draftWithComponent($blueprint, 'crm', self::TYPE, ['pipeline_key' => 'completely_different']);
        $this->publisher->publishVersion($this->adminId, $v2);

        $this->assertSame(NicheBlueprintVersionState::Superseded, $v1->refresh()->state);
        $this->assertSame(NicheBlueprintVersionState::Published, $v2->refresh()->state);

        $v1ComponentsAfter = DB::table('niche_blueprint_components')
            ->where('blueprint_version_id', $v1->id)->orderBy('id')->get()->toArray();

        $this->assertEquals($v1ComponentsBefore, $v1ComponentsAfter, 'A superseded version keeps its components byte-identical.');

        // Only `state` moved; the publication provenance is retained.
        $v1RowAfter = DB::table('niche_blueprint_versions')->where('id', $v1->id)->first();
        $this->assertEquals($v1RowBefore->published_at, $v1RowAfter->published_at);
        $this->assertEquals($v1RowBefore->published_by_user_id, $v1RowAfter->published_by_user_id);

        $this->assertSame(1, NicheBlueprintVersion::where('blueprint_id', $blueprint->id)
            ->where('state', NicheBlueprintVersionState::Published->value)->count());
    }

    public function test_supersede_retires_the_published_version_without_a_replacement(): void
    {
        $blueprint = $this->blueprint();
        $draft = $this->draftWithComponent($blueprint);
        $published = $this->publisher->publishVersion($this->adminId, $draft);

        $retired = $this->publisher->supersede($this->adminId, $published);

        $this->assertSame(NicheBlueprintVersionState::Superseded, $retired->state);
        $this->assertSame(0, NicheBlueprintVersion::where('blueprint_id', $blueprint->id)
            ->where('state', NicheBlueprintVersionState::Published->value)->count());

        // Provenance survives retirement.
        $this->assertNotNull($retired->published_at);
        $this->assertSame($this->adminId, $retired->published_by_user_id);
    }

    public function test_supersede_refuses_a_draft_and_refuses_twice(): void
    {
        $blueprint = $this->blueprint();
        $draft = $this->draftWithComponent($blueprint);

        try {
            $this->publisher->supersede($this->adminId, $draft);
            $this->fail('supersede() must refuse a draft.');
        } catch (NotADraftVersionException) {
            $this->addToAssertionCount(1);
        }

        $published = $this->publisher->publishVersion($this->adminId, $draft);
        $this->publisher->supersede($this->adminId, $published);

        $this->expectException(NotADraftVersionException::class);
        $this->publisher->supersede($this->adminId, $published->refresh());
    }

    // ============================================== Blueprint identity writes

    public function test_activation_moves_only_through_the_service(): void
    {
        $blueprint = $this->blueprint();
        $this->assertTrue($blueprint->is_active);

        $this->publisher->deactivateBlueprint($this->adminId, $blueprint);
        $this->assertFalse($blueprint->refresh()->is_active);

        $this->publisher->activateBlueprint($this->adminId, $blueprint);
        $this->assertTrue($blueprint->refresh()->is_active);
    }

    public function test_blueprint_identity_is_validated(): void
    {
        foreach ([
            'blank key' => fn () => $this->publisher->createBlueprint($this->adminId, '   ', 'Name'),
            'bad key shape' => fn () => $this->publisher->createBlueprint($this->adminId, 'Photo Booth!', 'Name'),
            'blank display name' => fn () => $this->publisher->createBlueprint($this->adminId, 'ok_key', '  '),
            'unknown industry' => fn () => $this->publisher->createBlueprint($this->adminId, 'ok_key', 'Name', null, 'not_an_industry'),
            'unknown vertical' => fn () => $this->publisher->createBlueprint($this->adminId, 'ok_key', 'Name', 'not_a_vertical'),
        ] as $case => $operation) {
            try {
                $operation();
                $this->fail($case . ' must be refused.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_blueprint_key_is_not_editable_after_creation(): void
    {
        $blueprint = $this->blueprint();

        $this->publisher->updateBlueprintIdentity($this->adminId, $blueprint, [
            'display_name' => 'Renamed',
            'key' => 'a_different_key',
        ]);

        $fresh = $blueprint->refresh();
        $this->assertSame('Renamed', $fresh->display_name);
        $this->assertSame('photo_booth', $fresh->key, 'The Blueprint key is stable identity and is never rewritten here.');
    }
}

<?php

namespace App\Library\NicheBlueprint;

use App\Enums\Business\BusinessIndustry;
use App\Enums\NicheBlueprint\NicheBlueprintVersionState;
use App\Exceptions\NicheBlueprint\BlueprintVersionMismatchException;
use App\Exceptions\NicheBlueprint\DraftVersionAlreadyExistsException;
use App\Exceptions\NicheBlueprint\DuplicateComponentKeyException;
use App\Exceptions\NicheBlueprint\InvalidComponentDescriptorException;
use App\Exceptions\NicheBlueprint\MissingRequiredFeatureKeyException;
use App\Exceptions\NicheBlueprint\NotADraftVersionException;
use App\Exceptions\NicheBlueprint\UnknownBlueprintComponentTypeException;
use App\Exceptions\NicheBlueprint\UnknownPlatformFeatureKeyException;
use App\Exceptions\NicheBlueprint\WrongFeatureScopeException;
use App\Library\Entitlement\PlatformFeatureRegistry;
use App\Library\NicheBlueprint\Adapters\BlueprintComponentAdapterRegistry;
use App\Models\BusinessVertical;
use App\Models\NicheBlueprint;
use App\Models\NicheBlueprintComponent;
use App\Models\NicheBlueprintVersion;
use App\Repositories\Contracts\UserRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Contract 20 §6.1/§6.2, Sub-slice B — the platform-side authoring and
 * publishing authority for niche Blueprints.
 *
 * THE SOLE PRODUCTION WRITE SEAM for `niche_blueprints`,
 * `niche_blueprint_versions` and `niche_blueprint_components`. Slice 20A
 * deliberately removed the lifecycle fields from ordinary mass assignment
 * precisely so this class could be the only place they are set; it uses
 * `forceFill()` for exactly those fields and nothing else does.
 *
 * PLATFORM ADMINISTRATOR ONLY, ON EVERY PUBLIC METHOD. Authority is re-derived
 * from persistence (`users.is_admin`) on every call, reproducing
 * `EntitlementManager::assertPlatformAdministrator()`'s shape and exception in
 * this domain rather than calling into EntitlementManager for an authorization
 * concern that is not its own. Nothing here trusts a caller-supplied role, a
 * serialized flag, Workspace membership, an Agency relationship, or a customer
 * capability. There is no Agency path and no customer path into this service
 * at all (§15).
 *
 * PUBLISHING TOUCHES ZERO BUSINESS-OWNED STATE. It writes to the three
 * Blueprint tables and nothing else: no installation record, no
 * `crm_pipelines`, no target-module table, no job dispatch, no entitlement
 * operation. That is the Acceptance Matrix's "A new Blueprint version is
 * published without touching any live Business until it opts in", and it is
 * asserted directly by a query-log boundary test.
 *
 * WHAT PUBLISH DELIBERATELY DOES NOT CHECK: `isAvailable()`. A component whose
 * PlatformFeature is still `Planned` is legitimately publishable (§6.2) — it
 * is simply skipped at every installation until the feature ships. That is how
 * one canonical Blueprint stays complete while the platform's modules arrive
 * over time, instead of being rewritten each time one lands. EntitlementManager
 * is likewise never consulted here: entitlement is an INSTALL-time, per-Business
 * question (§6.3), and asking it at publish would invent a second authority.
 */
class NicheBlueprintPublisher
{
    /** `question_packs`' own key shape, reused for Blueprint and component keys. */
    private const KEY_PATTERN = '/^[a-z0-9]+(?:[_-][a-z0-9]+)*$/';

    private const MAX_BLUEPRINT_KEY = 40;

    private const MAX_DISPLAY_NAME = 80;

    private const MAX_COMPONENT_KEY = 64;

    private const MAX_COMPONENT_TYPE = 40;

    private const MAX_FEATURE_KEY = 64;

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly BlueprintComponentAdapterRegistry $adapters,
    ) {
    }

    // =====================================================================
    // Blueprint identity
    // =====================================================================

    public function createBlueprint(
        int $actorUserId,
        string $key,
        string $displayName,
        ?string $verticalKey = null,
        ?string $broadIndustry = null,
    ): NicheBlueprint {
        $this->assertPlatformAdministrator($actorUserId);

        $key = $this->assertBlueprintKey($key);
        $displayName = $this->assertDisplayName($displayName);
        $this->assertVerticalKey($verticalKey);
        $this->assertBroadIndustry($broadIndustry);

        $blueprint = NicheBlueprint::create([
            'key' => $key,
            'display_name' => $displayName,
            'vertical_key' => $verticalKey,
            'broad_industry' => $broadIndustry,
        ]);

        // `is_active` is not mass-assignable (Slice 20A), so the model that
        // comes back from create() carries no value for it at all while the
        // row already has the database default. Refresh so callers read the
        // persisted truth rather than a null that looks like "inactive".
        return $blueprint->refresh();
    }

    /**
     * Identity/metadata only. `key` is the Blueprint's stable identity and is
     * deliberately NOT editable here: installation records cite the Blueprint
     * by id, but operators, seeds and future resolution logic address it by
     * key, and silently renaming it would strand all three.
     *
     * @param  array{display_name?: string, vertical_key?: ?string, broad_industry?: ?string}  $attributes
     */
    public function updateBlueprintIdentity(int $actorUserId, NicheBlueprint $blueprint, array $attributes): NicheBlueprint
    {
        $this->assertPlatformAdministrator($actorUserId);

        $changes = [];

        if (array_key_exists('display_name', $attributes)) {
            $changes['display_name'] = $this->assertDisplayName((string) $attributes['display_name']);
        }

        if (array_key_exists('vertical_key', $attributes)) {
            $this->assertVerticalKey($attributes['vertical_key']);
            $changes['vertical_key'] = $attributes['vertical_key'];
        }

        if (array_key_exists('broad_industry', $attributes)) {
            $this->assertBroadIndustry($attributes['broad_industry']);
            $changes['broad_industry'] = $attributes['broad_industry'];
        }

        if ($changes !== []) {
            $blueprint->forceFill($changes)->save();
        }

        return $blueprint->refresh();
    }

    /**
     * Whether a Blueprint is live. Slice 20A removed `is_active` from ordinary
     * mass assignment specifically so this canonical boundary is the only way
     * it moves.
     */
    public function activateBlueprint(int $actorUserId, NicheBlueprint $blueprint): NicheBlueprint
    {
        return $this->setBlueprintActive($actorUserId, $blueprint, true);
    }

    public function deactivateBlueprint(int $actorUserId, NicheBlueprint $blueprint): NicheBlueprint
    {
        return $this->setBlueprintActive($actorUserId, $blueprint, false);
    }

    private function setBlueprintActive(int $actorUserId, NicheBlueprint $blueprint, bool $isActive): NicheBlueprint
    {
        $this->assertPlatformAdministrator($actorUserId);

        $blueprint->forceFill(['is_active' => $isActive])->save();

        return $blueprint->refresh();
    }

    // =====================================================================
    // Draft version lifecycle
    // =====================================================================

    /**
     * Create the Blueprint's single draft.
     *
     * SERIALIZED ON THE BLUEPRINT ROW. The transaction locks the exact
     * `niche_blueprints` row first, then re-reads the existing versions inside
     * that boundary to decide both "is there already a draft?" and "what is the
     * next version_number?". Two concurrent callers therefore cannot produce
     * two drafts or duplicate version numbers: the second one blocks, and on
     * acquiring the lock sees the first one's committed draft and refuses with
     * a domain exception rather than a raw `draft_guard` integrity error.
     *
     * The `draft_guard` unique index remains the final backstop, not the
     * primary mechanism.
     */
    public function createDraftVersion(int $actorUserId, NicheBlueprint $blueprint, ?string $notes = null): NicheBlueprintVersion
    {
        $this->assertPlatformAdministrator($actorUserId);

        return DB::transaction(function () use ($blueprint, $notes): NicheBlueprintVersion {
            $locked = $this->lockBlueprint($blueprint->id);

            $existingDraft = NicheBlueprintVersion::query()
                ->where('blueprint_id', $locked->id)
                ->where('state', NicheBlueprintVersionState::Draft->value)
                ->orderBy('id')
                ->first();

            if ($existingDraft !== null) {
                throw new DraftVersionAlreadyExistsException($locked->id, (int) $existingDraft->id);
            }

            $highest = NicheBlueprintVersion::query()
                ->where('blueprint_id', $locked->id)
                ->max('version_number');

            $version = new NicheBlueprintVersion();
            $version->fill(['blueprint_id' => $locked->id, 'notes' => $notes]);
            $version->forceFill([
                'blueprint_id' => $locked->id,
                'version_number' => $highest === null ? 1 : ((int) $highest) + 1,
                'state' => NicheBlueprintVersionState::Draft->value,
            ])->save();

            return $version;
        });
    }

    public function updateDraftNotes(int $actorUserId, NicheBlueprintVersion $version, ?string $notes): NicheBlueprintVersion
    {
        $this->assertPlatformAdministrator($actorUserId);

        return DB::transaction(function () use ($version, $notes): NicheBlueprintVersion {
            $this->lockBlueprint((int) $version->blueprint_id);
            $draft = $this->reloadAsDraft($version);

            $draft->forceFill(['notes' => $notes])->save();

            return $draft;
        });
    }

    /**
     * §5.3 — a DRAFT may be deleted outright, and its components cascade with
     * it. A published or superseded version is never deleted by this service,
     * because installation records cite its `version_number` as provenance.
     */
    public function deleteDraftVersion(int $actorUserId, NicheBlueprintVersion $version): void
    {
        $this->assertPlatformAdministrator($actorUserId);

        DB::transaction(function () use ($version): void {
            $this->lockBlueprint((int) $version->blueprint_id);
            $draft = $this->reloadAsDraft($version);

            $draft->delete();
        });
    }

    // =====================================================================
    // Draft components
    // =====================================================================

    /**
     * @param  array<string, mixed>  $payload
     */
    public function addDraftComponent(
        int $actorUserId,
        NicheBlueprintVersion $version,
        string $componentKey,
        string $componentType,
        string $requiredFeatureKey,
        array $payload,
        ?int $position = null,
    ): NicheBlueprintComponent {
        $this->assertPlatformAdministrator($actorUserId);

        $componentKey = $this->assertComponentKey($componentKey);
        $componentType = $this->assertComponentType($componentType);
        $requiredFeatureKey = $this->assertFeatureKeyShape($componentKey, $requiredFeatureKey);

        return DB::transaction(function () use (
            $version, $componentKey, $componentType, $requiredFeatureKey, $payload, $position
        ): NicheBlueprintComponent {
            $this->lockBlueprint((int) $version->blueprint_id);
            $draft = $this->reloadAsDraft($version);

            $alreadyUsed = NicheBlueprintComponent::query()
                ->where('blueprint_version_id', $draft->id)
                ->where('component_key', $componentKey)
                ->exists();

            if ($alreadyUsed) {
                throw new DuplicateComponentKeyException((int) $draft->id, $componentKey);
            }

            if ($position === null) {
                $highest = NicheBlueprintComponent::query()
                    ->where('blueprint_version_id', $draft->id)
                    ->max('position');

                $position = $highest === null ? 0 : ((int) $highest) + 1;
            }

            return NicheBlueprintComponent::create([
                'blueprint_version_id' => $draft->id,
                'blueprint_id' => $draft->blueprint_id,
                'component_key' => $componentKey,
                'component_type' => $componentType,
                'required_feature_key' => $requiredFeatureKey,
                'payload' => $payload,
                'position' => $position,
            ]);
        });
    }

    /**
     * @param  array{component_key?: string, component_type?: string, required_feature_key?: string, payload?: array<string, mixed>, position?: int}  $attributes
     */
    public function updateDraftComponent(int $actorUserId, NicheBlueprintComponent $component, array $attributes): NicheBlueprintComponent
    {
        $this->assertPlatformAdministrator($actorUserId);

        return DB::transaction(function () use ($component, $attributes): NicheBlueprintComponent {
            $this->lockBlueprint((int) $component->blueprint_id);

            $fresh = NicheBlueprintComponent::query()->whereKey($component->id)->first();

            if ($fresh === null) {
                throw new InvalidArgumentException("Blueprint component [{$component->id}] no longer exists.");
            }

            $version = NicheBlueprintVersion::query()->whereKey($fresh->blueprint_version_id)->first();

            if ($version === null || $version->state !== NicheBlueprintVersionState::Draft) {
                throw new NotADraftVersionException(
                    (int) $fresh->blueprint_version_id,
                    $version?->state->value ?? 'missing'
                );
            }

            $changes = [];

            if (array_key_exists('component_key', $attributes)) {
                $newKey = $this->assertComponentKey((string) $attributes['component_key']);

                $taken = NicheBlueprintComponent::query()
                    ->where('blueprint_version_id', $fresh->blueprint_version_id)
                    ->where('component_key', $newKey)
                    ->whereKeyNot($fresh->id)
                    ->exists();

                if ($taken) {
                    throw new DuplicateComponentKeyException((int) $fresh->blueprint_version_id, $newKey);
                }

                $changes['component_key'] = $newKey;
            }

            if (array_key_exists('component_type', $attributes)) {
                $changes['component_type'] = $this->assertComponentType((string) $attributes['component_type']);
            }

            if (array_key_exists('required_feature_key', $attributes)) {
                $changes['required_feature_key'] = $this->assertFeatureKeyShape(
                    $changes['component_key'] ?? $fresh->component_key,
                    (string) $attributes['required_feature_key']
                );
            }

            if (array_key_exists('payload', $attributes)) {
                $changes['payload'] = $attributes['payload'];
            }

            if (array_key_exists('position', $attributes)) {
                $changes['position'] = max(0, (int) $attributes['position']);
            }

            if ($changes !== []) {
                $fresh->forceFill($changes)->save();
            }

            return $fresh->refresh();
        });
    }

    public function removeDraftComponent(int $actorUserId, NicheBlueprintComponent $component): void
    {
        $this->assertPlatformAdministrator($actorUserId);

        DB::transaction(function () use ($component): void {
            $this->lockBlueprint((int) $component->blueprint_id);

            $fresh = NicheBlueprintComponent::query()->whereKey($component->id)->first();

            if ($fresh === null) {
                return;
            }

            $version = NicheBlueprintVersion::query()->whereKey($fresh->blueprint_version_id)->first();

            if ($version === null || $version->state !== NicheBlueprintVersionState::Draft) {
                throw new NotADraftVersionException(
                    (int) $fresh->blueprint_version_id,
                    $version?->state->value ?? 'missing'
                );
            }

            $fresh->delete();
        });
    }

    // =====================================================================
    // Publication — the fail-closed gate
    // =====================================================================

    /**
     * Publish a draft, superseding whatever was published before it.
     *
     * ONE TRANSACTION, BLUEPRINT ROW LOCKED FIRST, EVERYTHING RE-READ INSIDE.
     * Nothing is decided from the caller's possibly-stale model: the target
     * version, its components and the currently-published version are all
     * re-read under the lock. Two concurrent publishes therefore serialize on
     * the Blueprint row, and the loser — finding its target no longer a draft —
     * refuses with a domain exception instead of leaking the `published_guard`
     * integrity error.
     *
     * Versions are locked in a single `ORDER BY id` pass so concurrent callers
     * always acquire row locks in the same order and cannot deadlock.
     *
     * Every §6.2 gate is applied to EVERY component before ANY write happens,
     * so a refusal leaves the draft exactly as it was.
     */
    public function publishVersion(int $actorUserId, NicheBlueprintVersion $version): NicheBlueprintVersion
    {
        $this->assertPlatformAdministrator($actorUserId);

        return DB::transaction(function () use ($version, $actorUserId): NicheBlueprintVersion {
            $blueprint = $this->lockBlueprint((int) $version->blueprint_id);

            // Deterministic lock order across every version of this Blueprint.
            $versions = NicheBlueprintVersion::query()
                ->where('blueprint_id', $blueprint->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $target = $versions->get($version->id);

            if ($target === null) {
                $actual = NicheBlueprintVersion::query()->whereKey($version->id)->value('blueprint_id');

                throw new BlueprintVersionMismatchException(
                    (int) $version->id,
                    (int) $blueprint->id,
                    (int) ($actual ?? 0)
                );
            }

            if ($target->state !== NicheBlueprintVersionState::Draft) {
                throw new NotADraftVersionException((int) $target->id, $target->state->value);
            }

            $components = NicheBlueprintComponent::query()
                ->where('blueprint_version_id', $target->id)
                ->orderBy('position')
                ->orderBy('id')
                ->get();

            $this->assertPublishable($target, $components);

            // Retire the incumbent first, so the published_guard slot is free.
            $incumbent = $versions->first(
                fn (NicheBlueprintVersion $candidate) => $candidate->state === NicheBlueprintVersionState::Published
            );

            if ($incumbent !== null) {
                $incumbent->forceFill(['state' => NicheBlueprintVersionState::Superseded->value])->save();
            }

            $target->forceFill([
                'state' => NicheBlueprintVersionState::Published->value,
                'published_at' => now(),
                'published_by_user_id' => $actorUserId,
            ])->save();

            return $target->refresh();
        });
    }

    /**
     * Retire the currently-published version without publishing a replacement,
     * leaving the Blueprint with no live version.
     *
     * The ONLY lifecycle transition this service performs on an issued version,
     * and it rewrites nothing but `state`: components, `published_at` and
     * `published_by_user_id` are left exactly as published, because
     * installation records cite them as provenance. A superseded version is
     * never deleted and never returns to draft — there is no un-supersede, and
     * no archive/delete state is invented (§5.2).
     */
    public function supersede(int $actorUserId, NicheBlueprintVersion $version): NicheBlueprintVersion
    {
        $this->assertPlatformAdministrator($actorUserId);

        return DB::transaction(function () use ($version): NicheBlueprintVersion {
            $this->lockBlueprint((int) $version->blueprint_id);

            $fresh = NicheBlueprintVersion::query()->whereKey($version->id)->lockForUpdate()->first();

            if ($fresh === null || $fresh->state !== NicheBlueprintVersionState::Published) {
                throw new NotADraftVersionException(
                    (int) $version->id,
                    $fresh?->state->value ?? 'missing'
                );
            }

            $fresh->forceFill(['state' => NicheBlueprintVersionState::Superseded->value])->save();

            return $fresh->refresh();
        });
    }

    // =====================================================================
    // §6.2 — the publish gates
    // =====================================================================

    /**
     * §6.2 defines exactly six gates, and every one is a statement about a
     * component that EXISTS. There is deliberately NO minimum component count:
     * an empty draft satisfies all six vacuously and publishes normally.
     *
     * An earlier revision refused an empty draft. That rule was not in the
     * contract — it was inferred from `PipelineBlueprint`'s own
     * "no stages" refusal — and inventing product authority the contract does
     * not grant is exactly what this domain must not do. Whether operators
     * SHOULD publish an empty version is a product question; it is not this
     * gate's to decide.
     *
     * @param  \Illuminate\Support\Collection<int, NicheBlueprintComponent>  $components
     */
    private function assertPublishable(NicheBlueprintVersion $version, $components): void
    {
        $seenKeys = [];

        foreach ($components as $component) {
            $componentKey = (string) $component->component_key;

            // Gate 6 — component_key unique within the version.
            if (isset($seenKeys[$componentKey])) {
                throw new DuplicateComponentKeyException((int) $version->id, $componentKey);
            }

            $seenKeys[$componentKey] = true;

            // The composite FK already forbids this; re-checked so a publish
            // never issues a version whose component claims another Blueprint.
            if ((int) $component->blueprint_id !== (int) $version->blueprint_id) {
                throw new BlueprintVersionMismatchException(
                    (int) $version->id,
                    (int) $version->blueprint_id,
                    (int) $component->blueprint_id
                );
            }

            // Gate 2 — an entitlement must be declared. No default, ever.
            $featureKey = trim((string) ($component->required_feature_key ?? ''));

            if ($featureKey === '') {
                throw new MissingRequiredFeatureKeyException($componentKey);
            }

            // Gate 3 — the key must name a real PlatformFeature.
            if (! PlatformFeatureRegistry::isKnown($featureKey)) {
                throw new UnknownPlatformFeatureKeyException($componentKey, $featureKey);
            }

            // Gate 4 — and it must be Business-scoped.
            if (! PlatformFeatureRegistry::isBusinessScoped($featureKey)) {
                throw new WrongFeatureScopeException($componentKey, $featureKey);
            }

            // DELIBERATELY ABSENT: isAvailable(). A `Planned` feature is
            // legitimately publishable (§6.2) — it is skipped at install time
            // until the feature ships, never rejected here.

            // Gate 1 — an adapter must exist for this component_type. Throws
            // UnknownBlueprintComponentTypeException, which is what makes
            // adapters genuinely additive (§11).
            $componentType = (string) $component->component_type;
            $adapter = $this->adapters->adapterFor($componentType);

            // Gate 5 — the adapter validates its own descriptor, here and not
            // in the installer.
            try {
                $adapter->validateDescriptor(is_array($component->payload) ? $component->payload : []);
            } catch (UnknownBlueprintComponentTypeException $e) {
                throw $e;
            } catch (Throwable $e) {
                throw new InvalidComponentDescriptorException($componentKey, $componentType, $e);
            }
        }
    }

    // =====================================================================
    // Authority
    // =====================================================================

    /**
     * Contract 20 §6.1 — Platform Administrator only.
     *
     * Re-derived from persistence on every call, reproducing
     * `EntitlementManager::assertPlatformAdministrator()`'s exact shape
     * (`users.is_admin`, `AuthorizationException`) in this domain. Never a
     * caller-supplied role string, a serialized flag, a Workspace membership,
     * an Agency relationship, or a customer capability.
     */
    private function assertPlatformAdministrator(int $actorUserId): void
    {
        $isAdmin = (bool) $this->userRepository->query()->whereKey($actorUserId)->value('is_admin');

        if (! $isAdmin) {
            throw new AuthorizationException('This action is restricted to platform administrators.');
        }
    }

    // =====================================================================
    // Shared internals
    // =====================================================================

    private function lockBlueprint(int $blueprintId): NicheBlueprint
    {
        $locked = NicheBlueprint::query()->whereKey($blueprintId)->lockForUpdate()->first();

        if ($locked === null) {
            throw new InvalidArgumentException("Niche Blueprint [{$blueprintId}] does not exist.");
        }

        return $locked;
    }

    /**
     * Re-read a version under the already-held Blueprint lock and prove it is
     * still a draft. Every authoring write goes through this: the caller's own
     * model may be stale, and an issued version must never be rewritten.
     */
    private function reloadAsDraft(NicheBlueprintVersion $version): NicheBlueprintVersion
    {
        $fresh = NicheBlueprintVersion::query()->whereKey($version->id)->lockForUpdate()->first();

        if ($fresh === null || $fresh->state !== NicheBlueprintVersionState::Draft) {
            throw new NotADraftVersionException((int) $version->id, $fresh?->state->value ?? 'missing');
        }

        return $fresh;
    }

    private function assertBlueprintKey(string $key): string
    {
        $key = trim($key);

        if ($key === '' || mb_strlen($key) > self::MAX_BLUEPRINT_KEY || preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException(
                'A Blueprint key must be a lowercase, hyphen/underscore-separated string of at most '
                    . self::MAX_BLUEPRINT_KEY . ' characters.'
            );
        }

        return $key;
    }

    private function assertDisplayName(string $displayName): string
    {
        $displayName = trim($displayName);

        if ($displayName === '' || mb_strlen($displayName) > self::MAX_DISPLAY_NAME) {
            throw new InvalidArgumentException(
                'A Blueprint display name must be 1-' . self::MAX_DISPLAY_NAME . ' characters.'
            );
        }

        return $displayName;
    }

    /**
     * Validated in the domain layer, mirroring how
     * `BusinessKnowledgeProfileManager` validates its own `vertical_key`
     * against the active catalog — so an operator gets a clear refusal rather
     * than a raw foreign-key error.
     */
    private function assertVerticalKey(?string $verticalKey): void
    {
        if ($verticalKey === null) {
            return;
        }

        $exists = BusinessVertical::query()
            ->where('key', $verticalKey)
            ->where('is_active', true)
            ->exists();

        if (! $exists) {
            throw new InvalidArgumentException(
                "vertical_key [{$verticalKey}] must reference an active business_verticals entry."
            );
        }
    }

    private function assertBroadIndustry(?string $broadIndustry): void
    {
        if ($broadIndustry === null) {
            return;
        }

        if (BusinessIndustry::tryFrom($broadIndustry) === null) {
            throw new InvalidArgumentException("broad_industry [{$broadIndustry}] is not a known BusinessIndustry value.");
        }
    }

    private function assertComponentKey(string $componentKey): string
    {
        $componentKey = trim($componentKey);

        if (
            $componentKey === ''
            || mb_strlen($componentKey) > self::MAX_COMPONENT_KEY
            || preg_match(self::KEY_PATTERN, $componentKey) !== 1
        ) {
            throw new InvalidArgumentException(
                'A component key must be a lowercase, hyphen/underscore-separated string of at most '
                    . self::MAX_COMPONENT_KEY . ' characters.'
            );
        }

        return $componentKey;
    }

    private function assertComponentType(string $componentType): string
    {
        $componentType = trim($componentType);

        if ($componentType === '' || mb_strlen($componentType) > self::MAX_COMPONENT_TYPE) {
            throw new InvalidArgumentException(
                'A component type must be 1-' . self::MAX_COMPONENT_TYPE . ' characters.'
            );
        }

        return $componentType;
    }

    /**
     * Shape only. Whether the key is KNOWN and BUSINESS-SCOPED is decided at
     * publish (§6.2 gates 3 and 4), deliberately: a draft is a work in
     * progress, and the publish gate is the single place a Blueprint's
     * correctness is finally proven.
     */
    private function assertFeatureKeyShape(string $componentKey, string $requiredFeatureKey): string
    {
        $requiredFeatureKey = trim($requiredFeatureKey);

        if ($requiredFeatureKey === '') {
            throw new MissingRequiredFeatureKeyException($componentKey);
        }

        if (mb_strlen($requiredFeatureKey) > self::MAX_FEATURE_KEY) {
            throw new InvalidArgumentException(
                'A required_feature_key must be at most ' . self::MAX_FEATURE_KEY . ' characters.'
            );
        }

        return $requiredFeatureKey;
    }
}

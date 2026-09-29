<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Business\BusinessIndustry;
use App\Enums\Entitlement\PlatformFeature;
use App\Enums\NicheBlueprint\NicheBlueprintVersionState;
use App\Exceptions\NicheBlueprint\BlueprintAuthoringException;
use App\Exceptions\NicheBlueprint\UnknownBlueprintComponentTypeException;
use App\Http\Requests\Admin\NicheBlueprint\StoreDraftComponentRequest;
use App\Http\Requests\Admin\NicheBlueprint\StoreDraftVersionRequest;
use App\Http\Requests\Admin\NicheBlueprint\StoreNicheBlueprintRequest;
use App\Http\Requests\Admin\NicheBlueprint\UpdateDraftComponentRequest;
use App\Http\Requests\Admin\NicheBlueprint\UpdateDraftVersionRequest;
use App\Http\Requests\Admin\NicheBlueprint\UpdateNicheBlueprintRequest;
use App\Library\Entitlement\PlatformFeatureRegistry;
use App\Library\NicheBlueprint\Adapters\BlueprintComponentAdapterRegistry;
use App\Library\NicheBlueprint\NicheBlueprintPublisher;
use App\Models\BusinessVertical;
use App\Models\NicheBlueprint;
use App\Models\NicheBlueprintComponent;
use App\Models\NicheBlueprintVersion;
use Illuminate\Contracts\View\View;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Contract 20 §6.1/§12.F/§18.F — the Platform Owner's "Niche Blueprints"
 * authoring surface (Blueprint §30).
 *
 * EVERY WRITE ACTION DELEGATES TO NicheBlueprintPublisher (Sub-slice B).
 * This controller never creates, updates or deletes a `niche_blueprint_*`
 * row itself, through Eloquent or the query builder — the publisher is the
 * sole write authority, and this surface does not modify it: §12.F's own
 * domain boundary is the publisher, the routes and the views, nothing more.
 * Platform-administrator authority is checked twice, by design: the route's
 * own 'can:access backend' + EnsureUserIsAdministrator group middleware
 * (routes/admin.php), and again inside every NicheBlueprintPublisher method
 * (assertPlatformAdministrator(), re-derived from users.is_admin) — the same
 * defense-in-depth every other admin-only, cross-tenant surface in this
 * application already uses. There is no Agency path and no customer path to
 * any action here (§6.1, §15).
 *
 * TWO HTTP-LAYER CONCERNS LIVE HERE, DELIBERATELY, RATHER THAN IN THE
 * PUBLISHER: identity-uniqueness-conflict presentation (identityConflictMessage())
 * and inactive-current-vertical retention under a fresh row lock (update()).
 * Both are about how this ONE surface presents and orchestrates a write the
 * publisher already fully validates and performs; neither adds a second
 * write authority, a second uniqueness check, or a second entitlement/
 * authorization decision. The database's own unique constraints
 * (nb_key_unique, nb_vertical_key_unique) remain the sole concurrency
 * authority throughout.
 *
 * Distinct from BlueprintTemplateLibraryController (Blueprint §30 names two
 * surfaces): this one authors a Blueprint's single draft and publishes it;
 * the Template Library is the read-only catalog view over the exact same
 * rows.
 */
class NicheBlueprintController extends AdminBaseController
{
    public function __construct(
        private readonly NicheBlueprintPublisher $publisher,
        private readonly BlueprintComponentAdapterRegistry $adapters,
    ) {
    }

    public function index(): View
    {
        $blueprints = NicheBlueprint::query()
            ->withCount('versions')
            ->with(['versions' => function ($query): void {
                $query->whereIn('state', [
                    NicheBlueprintVersionState::Draft->value,
                    NicheBlueprintVersionState::Published->value,
                ]);
            }])
            ->orderBy('display_name')
            ->paginate(25);

        return view('admin.niche-blueprints.index', [
            'blueprints' => $blueprints,
            'breadcrumbs' => $this->breadcrumbs(),
        ]);
    }

    public function create(): View
    {
        return view('admin.niche-blueprints.create', [
            'verticals' => BusinessVertical::query()->where('is_active', true)->orderBy('display_name')->get(),
            'industries' => BusinessIndustry::cases(),
            'breadcrumbs' => $this->breadcrumbs([['name' => 'Create Blueprint']]),
        ]);
    }

    public function store(StoreNicheBlueprintRequest $request): RedirectResponse
    {
        $key = (string) $request->validated('key');
        $verticalKey = $request->validated('vertical_key');

        try {
            $blueprint = $this->publisher->createBlueprint(
                (int) Auth::id(),
                $key,
                (string) $request->validated('display_name'),
                $verticalKey,
                $request->validated('broad_industry'),
            );
        } catch (InvalidArgumentException $e) {
            return redirect()
                ->route('admin.niche-blueprints.create')
                ->withInput()
                ->with('flash_error', $e->getMessage());
        } catch (UniqueConstraintViolationException $e) {
            $message = $this->identityConflictMessage($e, $key, $verticalKey);

            if ($message === null) {
                throw $e;
            }

            return redirect()
                ->route('admin.niche-blueprints.create')
                ->withInput()
                ->with('flash_error', $message);
        }

        return redirect()
            ->route('admin.niche-blueprints.show', $blueprint)
            ->with('flash_success', "Blueprint [{$blueprint->display_name}] created.");
    }

    public function show(NicheBlueprint $blueprint): View
    {
        $blueprint->load(['versions' => function ($query): void {
            $query->with('components')->orderByDesc('version_number');
        }]);

        $draft = $blueprint->versions->first(
            fn (NicheBlueprintVersion $version) => $version->state === NicheBlueprintVersionState::Draft
        );
        $published = $blueprint->versions->first(
            fn (NicheBlueprintVersion $version) => $version->state === NicheBlueprintVersionState::Published
        );

        return view('admin.niche-blueprints.show', [
            'blueprint' => $blueprint,
            'draft' => $draft,
            'published' => $published,
            'registeredComponentTypes' => $this->adapters->registeredComponentTypes(),
            'businessScopedFeatures' => array_values(array_filter(
                PlatformFeature::cases(),
                fn (PlatformFeature $feature) => PlatformFeatureRegistry::isBusinessScoped($feature->value)
            )),
            'verticals' => $this->verticalChoicesFor($blueprint),
            'industries' => BusinessIndustry::cases(),
            'breadcrumbs' => $this->breadcrumbs([['name' => $blueprint->display_name]]),
        ]);
    }

    /**
     * Every active vertical, plus this Blueprint's own CURRENT vertical even
     * if it has since been deactivated. §7.1's resolution keeps working from
     * `broad_industry` for a Blueprint whose vertical went inactive, but the
     * edit form must still be able to show and retain that value — dropping
     * it from the choices would leave nothing for the browser to resubmit
     * when the operator saves an unrelated field, silently detaching the
     * vertical (NicheBlueprintPublisher::updateBlueprintIdentity() is what
     * actually allows retaining it unchanged; this only makes sure the
     * current value is a selectable option in the first place).
     *
     * @return \Illuminate\Support\Collection<int, BusinessVertical>
     */
    private function verticalChoicesFor(NicheBlueprint $blueprint): \Illuminate\Support\Collection
    {
        $active = BusinessVertical::query()->where('is_active', true)->orderBy('display_name')->get();

        if ($blueprint->vertical_key === null || $active->contains('key', $blueprint->vertical_key)) {
            return $active;
        }

        $current = BusinessVertical::query()->where('key', $blueprint->vertical_key)->first();

        return $current === null ? $active : $active->push($current);
    }

    /**
     * VERTICAL RETENTION IS DECIDED HERE, AGAINST A FRESH LOCKED ROW — NEVER
     * AGAINST THE STALE ROUTE-BOUND $blueprint. NicheBlueprintPublisher
     * (Sub-slice B) is unmodified by this surface (Contract 20 §12.F: F's
     * concurrency "inherits B"); it still unconditionally requires an ACTIVE
     * vertical whenever `vertical_key` is present in the attributes it
     * receives. So the "resubmitting the current, now-inactive vertical must
     * not be rejected or detach it" behaviour has to happen entirely in this
     * orchestration layer, by deciding whether to even SEND `vertical_key`
     * to the publisher — and that decision is only safe against the row's
     * CURRENT persisted value, taken under a `lockForUpdate()` held through
     * the publisher's own write in the same transaction. Comparing against
     * $blueprint (resolved earlier by route-model binding, before this
     * request's transaction even opened) would let a concurrent request that
     * already moved the vertical elsewhere be silently overwritten by a
     * stale "unchanged" decision — exactly the race this method closes.
     */
    public function update(UpdateNicheBlueprintRequest $request, NicheBlueprint $blueprint): RedirectResponse
    {
        $attributes = $request->validated();
        $submittedVerticalKey = $attributes['vertical_key'] ?? null;

        try {
            DB::transaction(function () use ($blueprint, $attributes): void {
                $locked = NicheBlueprint::query()->whereKey($blueprint->id)->lockForUpdate()->first();

                if ($locked === null) {
                    abort(404);
                }

                if (array_key_exists('vertical_key', $attributes) && $attributes['vertical_key'] === $locked->vertical_key) {
                    // Retention/no-op against the FRESH locked value: strip it
                    // so the publisher never sees `vertical_key` at all and
                    // therefore never re-validates an unchanged assignment,
                    // active or not.
                    unset($attributes['vertical_key']);
                }

                $this->publisher->updateBlueprintIdentity((int) Auth::id(), $locked, $attributes);
            });
        } catch (InvalidArgumentException $e) {
            return redirect()->route('admin.niche-blueprints.show', $blueprint)->with('flash_error', $e->getMessage());
        } catch (UniqueConstraintViolationException $e) {
            $message = $this->identityConflictMessage($e, $blueprint->key, $submittedVerticalKey);

            if ($message === null) {
                throw $e;
            }

            return redirect()->route('admin.niche-blueprints.show', $blueprint)->with('flash_error', $message);
        }

        return redirect()->route('admin.niche-blueprints.show', $blueprint)->with('flash_success', 'Blueprint identity updated.');
    }

    /**
     * §5.1's two identity uniqueness constraints (`nb_key_unique`,
     * `nb_vertical_key_unique`) are the database's own concurrency
     * authority — this surface never pre-empts it with a check-then-write
     * race, only translates the two KNOWN constraints it can name into a
     * safe admin-facing message. Anything else (an unrelated constraint, a
     * connection failure) returns null so the caller rethrows the original
     * exception unchanged — never swallowed, never misreported as
     * "duplicate".
     */
    private function identityConflictMessage(UniqueConstraintViolationException $e, string $key, ?string $verticalKey): ?string
    {
        if (str_contains($e->getMessage(), 'nb_key_unique')) {
            return "The Blueprint key [{$key}] is already in use.";
        }

        if ($verticalKey !== null && str_contains($e->getMessage(), 'nb_vertical_key_unique')) {
            return "The vertical [{$verticalKey}] is already assigned to another Blueprint.";
        }

        return null;
    }

    public function activate(NicheBlueprint $blueprint): RedirectResponse
    {
        $this->publisher->activateBlueprint((int) Auth::id(), $blueprint);

        return redirect()->route('admin.niche-blueprints.show', $blueprint)->with('flash_success', 'Blueprint activated.');
    }

    public function deactivate(NicheBlueprint $blueprint): RedirectResponse
    {
        $this->publisher->deactivateBlueprint((int) Auth::id(), $blueprint);

        return redirect()->route('admin.niche-blueprints.show', $blueprint)->with('flash_success', 'Blueprint deactivated.');
    }

    public function storeVersion(StoreDraftVersionRequest $request, NicheBlueprint $blueprint): RedirectResponse
    {
        try {
            $this->publisher->createDraftVersion((int) Auth::id(), $blueprint, $request->validated('notes'));
        } catch (BlueprintAuthoringException $e) {
            return redirect()->route('admin.niche-blueprints.show', $blueprint)->with('flash_error', $e->getMessage());
        }

        return redirect()->route('admin.niche-blueprints.show', $blueprint)->with('flash_success', 'Draft version created.');
    }

    public function updateVersion(UpdateDraftVersionRequest $request, NicheBlueprint $blueprint, NicheBlueprintVersion $version): RedirectResponse
    {
        $this->assertVersionBelongsToBlueprint($blueprint, $version);

        try {
            $this->publisher->updateDraftNotes((int) Auth::id(), $version, $request->validated('notes'));
        } catch (BlueprintAuthoringException $e) {
            return redirect()->route('admin.niche-blueprints.show', $blueprint)->with('flash_error', $e->getMessage());
        }

        return redirect()->route('admin.niche-blueprints.show', $blueprint)->with('flash_success', 'Draft notes updated.');
    }

    public function destroyVersion(NicheBlueprint $blueprint, NicheBlueprintVersion $version): RedirectResponse
    {
        $this->assertVersionBelongsToBlueprint($blueprint, $version);

        try {
            $this->publisher->deleteDraftVersion((int) Auth::id(), $version);
        } catch (BlueprintAuthoringException $e) {
            return redirect()->route('admin.niche-blueprints.show', $blueprint)->with('flash_error', $e->getMessage());
        }

        return redirect()->route('admin.niche-blueprints.show', $blueprint)->with('flash_success', 'Draft version discarded.');
    }

    public function publishVersion(NicheBlueprint $blueprint, NicheBlueprintVersion $version): RedirectResponse
    {
        $this->assertVersionBelongsToBlueprint($blueprint, $version);

        try {
            $published = $this->publisher->publishVersion((int) Auth::id(), $version);
        } catch (BlueprintAuthoringException|UnknownBlueprintComponentTypeException $e) {
            return redirect()->route('admin.niche-blueprints.show', $blueprint)->with('flash_error', $e->getMessage());
        }

        return redirect()
            ->route('admin.niche-blueprints.show', $blueprint)
            ->with('flash_success', "Version {$published->version_number} published.");
    }

    public function supersedeVersion(NicheBlueprint $blueprint, NicheBlueprintVersion $version): RedirectResponse
    {
        $this->assertVersionBelongsToBlueprint($blueprint, $version);

        try {
            $this->publisher->supersede((int) Auth::id(), $version);
        } catch (BlueprintAuthoringException $e) {
            return redirect()->route('admin.niche-blueprints.show', $blueprint)->with('flash_error', $e->getMessage());
        }

        return redirect()->route('admin.niche-blueprints.show', $blueprint)->with('flash_success', 'Version superseded.');
    }

    public function storeComponent(StoreDraftComponentRequest $request, NicheBlueprint $blueprint, NicheBlueprintVersion $version): RedirectResponse
    {
        $this->assertVersionBelongsToBlueprint($blueprint, $version);

        $position = $request->validated('position');

        try {
            $this->publisher->addDraftComponent(
                (int) Auth::id(),
                $version,
                (string) $request->validated('component_key'),
                (string) $request->validated('component_type'),
                (string) $request->validated('required_feature_key'),
                $request->decodedPayload(),
                $position !== null ? (int) $position : null,
            );
        } catch (BlueprintAuthoringException|InvalidArgumentException $e) {
            return redirect()->route('admin.niche-blueprints.show', $blueprint)->with('flash_error', $e->getMessage());
        }

        return redirect()->route('admin.niche-blueprints.show', $blueprint)->with('flash_success', 'Component added to draft.');
    }

    public function updateComponent(
        UpdateDraftComponentRequest $request,
        NicheBlueprint $blueprint,
        NicheBlueprintVersion $version,
        NicheBlueprintComponent $component,
    ): RedirectResponse {
        $this->assertVersionBelongsToBlueprint($blueprint, $version);
        $this->assertComponentBelongsToVersion($version, $component);

        try {
            $this->publisher->updateDraftComponent((int) Auth::id(), $component, $request->attributesForPublisher());
        } catch (BlueprintAuthoringException|InvalidArgumentException $e) {
            return redirect()->route('admin.niche-blueprints.show', $blueprint)->with('flash_error', $e->getMessage());
        }

        return redirect()->route('admin.niche-blueprints.show', $blueprint)->with('flash_success', 'Component updated.');
    }

    public function destroyComponent(NicheBlueprint $blueprint, NicheBlueprintVersion $version, NicheBlueprintComponent $component): RedirectResponse
    {
        $this->assertVersionBelongsToBlueprint($blueprint, $version);
        $this->assertComponentBelongsToVersion($version, $component);

        try {
            $this->publisher->removeDraftComponent((int) Auth::id(), $component);
        } catch (BlueprintAuthoringException $e) {
            return redirect()->route('admin.niche-blueprints.show', $blueprint)->with('flash_error', $e->getMessage());
        }

        return redirect()->route('admin.niche-blueprints.show', $blueprint)->with('flash_success', 'Component removed from draft.');
    }

    /**
     * Addressability boundary: a version uid that resolves but does not
     * belong to the {blueprint} named in the URL fails closed with 404,
     * rather than silently operating against a mismatched pair the
     * composite FK would refuse anyway at the database layer.
     */
    private function assertVersionBelongsToBlueprint(NicheBlueprint $blueprint, NicheBlueprintVersion $version): void
    {
        if ((int) $version->blueprint_id !== (int) $blueprint->id) {
            abort(404);
        }
    }

    private function assertComponentBelongsToVersion(NicheBlueprintVersion $version, NicheBlueprintComponent $component): void
    {
        if ((int) $component->blueprint_version_id !== (int) $version->id) {
            abort(404);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $trailing
     * @return array<int, array<string, mixed>>
     */
    private function breadcrumbs(array $trailing = []): array
    {
        return [
            ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Dashboard')],
            ['link' => route('admin.niche-blueprints.index'), 'name' => 'Niche Blueprints'],
            ...$trailing,
        ];
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Enums\NicheBlueprint\NicheBlueprintVersionState;
use App\Library\Entitlement\PlatformFeatureRegistry;
use App\Library\NicheBlueprint\Adapters\BlueprintComponentAdapterRegistry;
use App\Models\NicheBlueprint;
use App\Models\NicheBlueprintVersion;
use Illuminate\Contracts\View\View;

/**
 * Contract 20 §12.F/§18.F — the Platform Owner's "Template Library" surface
 * (Blueprint §30): a read-only catalog view over the same
 * niche_blueprints/niche_blueprint_versions/niche_blueprint_components rows
 * NicheBlueprintController authors.
 *
 * NO WRITE ACTION EXISTS ON THIS CONTROLLER, and none is added by this
 * sub-slice — Contract 20 §12.F authorizes only the read-oriented catalog
 * surface here; any future authoring entry point from this surface would
 * still have to delegate to NicheBlueprintPublisher, never invent a second
 * write path or a second persistence model for template content.
 *
 * Platform-administrator authority is enforced by the route's own
 * 'can:access backend' + EnsureUserIsAdministrator group middleware
 * (routes/admin.php) — the same boundary NicheBlueprintController uses.
 * There is no Agency or customer path to any action here.
 */
class BlueprintTemplateLibraryController extends AdminBaseController
{
    public function __construct(
        private readonly BlueprintComponentAdapterRegistry $adapters,
    ) {
    }

    public function index(): View
    {
        $blueprints = NicheBlueprint::query()
            ->with(['versions' => function ($query): void {
                $query->where('state', NicheBlueprintVersionState::Published->value)->withCount('components');
            }])
            ->orderBy('display_name')
            ->paginate(25);

        return view('admin.template-library.index', [
            'blueprints' => $blueprints,
            'breadcrumbs' => $this->breadcrumbs(),
        ]);
    }

    public function show(NicheBlueprint $blueprint): View
    {
        $blueprint->load(['versions' => function ($query): void {
            $query->withCount('components')->orderByDesc('version_number');
        }]);

        $published = $blueprint->versions->first(
            fn (NicheBlueprintVersion $version) => $version->state === NicheBlueprintVersionState::Published
        );

        return view('admin.template-library.show', [
            'blueprint' => $blueprint,
            'published' => $published,
            'breadcrumbs' => $this->breadcrumbs([['name' => $blueprint->display_name]]),
        ]);
    }

    public function showVersion(NicheBlueprint $blueprint, NicheBlueprintVersion $version): View
    {
        if ((int) $version->blueprint_id !== (int) $blueprint->id) {
            abort(404);
        }

        $version->load('components');

        return view('admin.template-library.version', [
            'blueprint' => $blueprint,
            'version' => $version,
            'registeredComponentTypes' => $this->adapters->registeredComponentTypes(),
            'availabilityByFeatureKey' => $version->components
                ->pluck('required_feature_key')
                ->unique()
                ->mapWithKeys(fn (string $featureKey) => [
                    $featureKey => PlatformFeatureRegistry::isAvailable($featureKey),
                ]),
            'breadcrumbs' => $this->breadcrumbs([
                ['link' => route('admin.template-library.show', $blueprint), 'name' => $blueprint->display_name],
                ['name' => "v{$version->version_number}"],
            ]),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $trailing
     * @return array<int, array<string, mixed>>
     */
    private function breadcrumbs(array $trailing = []): array
    {
        return [
            ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Dashboard')],
            ['link' => route('admin.template-library.index'), 'name' => 'Template Library'],
            ...$trailing,
        ];
    }
}

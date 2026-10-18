<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\PlatformFeatureRegistry;
use App\Library\PlatformOwner\PlatformFeatureGroups;
use App\Models\WorkspacePlanCatalog;
use App\Repositories\Contracts\WorkspacePlanFeatureRepository;
use Illuminate\View\View;

/**
 * Platform Owner V1 final — Feature Management: which plan includes which
 * capability, and whether the capability is live yet. Read-only; editing
 * happens on the plan (PlatformPlansController). The raw feature keys are shown
 * only as small secondary text for support use.
 */
class PlatformFeaturesController extends Controller
{
    public function __construct(private readonly WorkspacePlanFeatureRepository $features)
    {
    }

    public function index(): View
    {
        $this->authorize('view workspace plans');

        $plans = [];

        foreach (WorkspacePlanTier::cases() as $tier) {
            $catalog = WorkspacePlanCatalog::query()->where('tier', $tier->value)->first();

            if ($catalog) {
                $plans[] = ['catalog' => $catalog, 'keys' => $this->features->featureKeysForCatalog($catalog)->all()];
            }
        }

        return view('admin.platform-features.index', [
            'plans' => $plans,
            'groups' => PlatformFeatureGroups::all(),
            'available' => collect(PlatformFeatureGroups::allKeys())->mapWithKeys(fn ($k) => [$k => PlatformFeatureRegistry::isAvailable($k)])->all(),
            'breadcrumbs' => [['link' => url(config('app.admin_path') . '/platform-owner'), 'name' => 'Home'], ['name' => 'Feature Management']],
        ]);
    }
}

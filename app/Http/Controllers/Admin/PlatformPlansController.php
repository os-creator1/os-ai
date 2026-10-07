<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Exceptions\Entitlement\PlanCatalogPricingInUseException;
use App\Library\Entitlement\PlatformFeatureRegistry;
use App\Library\PlatformBilling\PlatformPlanPresenter;
use App\Library\PlatformOwner\PlatformFeatureGroups;
use App\Library\PlatformOwner\PlatformPlanAdministrator;
use App\Models\WorkspacePlanCatalog;
use App\Repositories\Contracts\WorkspacePlanFeatureRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Platform Owner V1 final — Plans. The single place the three customer SaaS
 * plans (Core, Growth, Agency) are read and edited. Thin: every write is
 * PlatformPlanAdministrator::apply(); every read is the catalog + presenter.
 */
class PlatformPlansController extends Controller
{
    public function __construct(
        private readonly PlatformPlanAdministrator $plans,
        private readonly PlatformPlanPresenter $presenter,
        private readonly WorkspacePlanFeatureRepository $features,
    ) {
    }

    public function index(): View
    {
        $this->authorize('view workspace plans');

        $rows = [];

        foreach (WorkspacePlanTier::cases() as $tier) {
            $catalog = WorkspacePlanCatalog::query()->with('currency:id,code')->where('tier', $tier->value)->first();

            if ($catalog) {
                $rows[] = [
                    'catalog' => $catalog,
                    'subscribers' => $this->plans->assignmentCount($catalog),
                    'featureCount' => $this->features->featureKeysForCatalog($catalog)->count(),
                    'plan' => $this->presenter->presentTier($tier),
                ];
            }
        }

        return view('admin.platform-plans.index', ['rows' => $rows, 'breadcrumbs' => $this->crumbs()]);
    }

    public function edit(string $tier): View
    {
        $this->authorize('view workspace plans');

        $catalog = $this->catalog($tier);

        return view('admin.platform-plans.edit', [
            'catalog' => $catalog,
            'subscribers' => $this->plans->assignmentCount($catalog),
            'currencies' => DB::table('currencies')->orderBy('code')->get(['id', 'code', 'name']),
            'groups' => PlatformFeatureGroups::all(),
            'packaged' => $this->features->featureKeysForCatalog($catalog)->all(),
            'available' => collect(PlatformFeatureGroups::allKeys())->mapWithKeys(fn ($k) => [$k => PlatformFeatureRegistry::isAvailable($k)])->all(),
            'canSlotRatio' => $catalog->tier !== WorkspacePlanTier::Agency,
            'breadcrumbs' => $this->crumbs($catalog->display_name),
        ]);
    }

    public function update(Request $request, string $tier): RedirectResponse
    {
        $this->authorize('manage workspace plans');

        $catalog = $this->catalog($tier);

        $v = $request->validate([
            'display_name' => ['required', 'string', 'min:2', 'max:60'],
            'price' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
            'billing_cycle' => ['required', 'in:monthly,yearly'],
            'is_active' => ['nullable', 'boolean'],
            'available_for_signup' => ['nullable', 'boolean'],
            'trial_enabled' => ['nullable', 'boolean'],
            'trial_days' => ['nullable', 'integer', 'min:1', 'max:730'],
            'provider_price_id' => ['nullable', 'string', 'regex:/\Aprice_[A-Za-z0-9]{6,}\z/'],
            'business_slot_included' => ['required', 'integer', 'min:1', 'max:250'],
            'business_slot_max' => ['nullable', 'integer', 'min:1', 'max:250'],
            'unlimited_business_slots' => ['nullable', 'boolean'],
            'additional_business_slot_price_ratio' => ['nullable', 'numeric', 'min:0', 'max:99'],
            'location_slot_included' => ['required', 'integer', 'min:0', 'max:250'],
            'location_slot_max' => ['nullable', 'integer', 'min:0', 'max:250'],
            'unlimited_location_slots' => ['nullable', 'boolean'],
            'feature_keys' => ['nullable', 'array'],
            'feature_keys.*' => ['string'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $price = ($v['price'] ?? '') === '' ? null : $v['price'];

        if (($price === null) !== blank($v['currency_id'] ?? null)) {
            return back()->withInput()->withErrors(['price' => __('Price and currency go together: set both or clear both.')]);
        }

        $data = [
            'display_name' => trim($v['display_name']),
            'price' => $price,
            'currency_id' => $price === null ? null : (int) $v['currency_id'],
            'billing_cycle' => $v['billing_cycle'],
            'is_active' => $request->boolean('is_active'),
            'available_for_signup' => $request->boolean('available_for_signup'),
            'trial_enabled' => $request->boolean('trial_enabled'),
            'trial_days' => $v['trial_days'] ?? null,
            'provider_price_id' => ($v['provider_price_id'] ?? '') === '' ? null : $v['provider_price_id'],
            'business_slot_included' => (int) $v['business_slot_included'],
            'business_slot_max' => ($v['business_slot_max'] ?? '') === '' ? null : (int) $v['business_slot_max'],
            'unlimited_business_slots' => $request->boolean('unlimited_business_slots'),
            'location_slot_included' => (int) $v['location_slot_included'],
            'location_slot_max' => ($v['location_slot_max'] ?? '') === '' ? null : (int) $v['location_slot_max'],
            'unlimited_location_slots' => $request->boolean('unlimited_location_slots'),
            'feature_keys' => array_values($v['feature_keys'] ?? []),
            'reason' => $v['reason'],
        ];

        // The additional-Business price ratio is only editable where the tier
        // can carry one; otherwise it is left untouched.
        if ($catalog->tier !== WorkspacePlanTier::Agency) {
            $data['additional_business_slot_price_ratio'] = ($v['additional_business_slot_price_ratio'] ?? '') === '' ? null : (string) $v['additional_business_slot_price_ratio'];
        }

        try {
            $changes = $this->plans->apply($catalog, $data, (int) Auth::id());
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        } catch (PlanCatalogPricingInUseException) {
            return back()->withInput()->withErrors(['price' => __('Customers are subscribed to this plan, so its price cannot be cleared. Archive the plan instead.')]);
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['price' => $e->getMessage()]);
        }

        return redirect()->route('admin.platform-plans.edit', $catalog->tier->value)->with([
            'status' => 'success',
            'message' => $changes === [] ? __('Nothing changed.') : __(':plan saved.', ['plan' => $data['display_name']]),
        ]);
    }

    private function catalog(string $tier): WorkspacePlanCatalog
    {
        abort_unless(WorkspacePlanTier::tryFrom($tier) !== null, 404);

        return WorkspacePlanCatalog::query()->where('tier', $tier)->firstOrFail();
    }

    /** @return array<int, array<string, mixed>> */
    private function crumbs(?string $trail = null): array
    {
        $home = ['link' => url(config('app.admin_path') . '/platform-owner'), 'name' => 'Home'];

        return $trail
            ? [$home, ['link' => route('admin.platform-plans.index'), 'name' => 'Plans'], ['name' => $trail]]
            : [$home, ['name' => 'Plans']];
    }
}

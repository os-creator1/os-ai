<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Business\BusinessIndustry;
use App\Enums\Business\BusinessStatus;
use App\Http\Requests\Business\AdminBusinessIndexRequest;
use App\Http\Requests\Business\AdminUpdateBusinessRequest;
use App\Http\Requests\Business\UpdateBusinessStatusRequest;
use App\Library\Business\BusinessManager;
use App\Models\Business;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\CustomerOnboardingRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;

/**
 * Admin-only, intentionally cross-tenant Business management (RFC-001 §16,
 * §19 — Milestone 6). Every write still goes through BusinessManager or the
 * narrow BusinessRepository methods established in Milestones 1–2; nothing
 * here duplicates persistence logic. No create or delete action exists —
 * the RFC does not permit either from the admin surface.
 */
class BusinessController extends AdminBaseController
{
    public function __construct(
        private readonly BusinessRepository $businessRepository,
        private readonly BusinessManager $businessManager,
        private readonly CustomerOnboardingRepository $onboardingRepository,
    ) {
    }

    /**
     * Deliberately zero-argument to stay signature-compatible with
     * AdminBaseController::index() (the dashboard action every other admin
     * controller inherits) — PHP rejects a child override that adds a
     * required parameter the parent doesn't have. The dedicated Form
     * Request is instead resolved through the container, which triggers
     * Laravel's normal authorization/validation lifecycle identically to
     * type-hinting it as a method parameter (both paths fire the same
     * FormRequestServiceProvider resolving()/afterResolving() container
     * callbacks).
     */
    public function index(): View
    {
        $this->authorize('view business');

        $request = app(AdminBusinessIndexRequest::class);

        $businesses = $this->businessRepository->paginateForAdmin($request->filters(), $request->perPage());

        return view('admin.businesses.index', [
            'businesses' => $businesses,
            'filters' => $request->filters(),
            'statuses' => BusinessStatus::cases(),
            'industries' => BusinessIndustry::cases(),
            'breadcrumbs' => $this->breadcrumbs(),
        ]);
    }

    public function show(Business $business): View
    {
        $this->authorize('view business');

        $business->loadMissing(['primaryLocation', 'services', 'customer.user']);
        $onboarding = $business->customer !== null
            ? $this->onboardingRepository->findByCustomer($business->customer)
            : null;

        return view('admin.businesses.show', [
            'business' => $business,
            'onboarding' => $onboarding,
            'breadcrumbs' => $this->breadcrumbs($business, 'View'),
        ]);
    }

    public function edit(Business $business): View
    {
        $this->authorize('edit business');

        $business->loadMissing(['customer.user']);

        return view('admin.businesses.edit', [
            'business' => $business,
            'industries' => BusinessIndustry::cases(),
            'breadcrumbs' => $this->breadcrumbs($business, 'Edit'),
        ]);
    }

    public function update(AdminUpdateBusinessRequest $request, Business $business): RedirectResponse
    {
        $this->authorize('edit business');

        try {
            // The business's own owning customer is resolved from the record
            // itself, never from the authenticated admin — this is what
            // makes the update intentionally cross-tenant while still
            // reusing BusinessManager's ordinary ownership-checked path
            // unmodified (the check trivially passes: a business always
            // belongs to its own customer).
            $this->businessManager->updateBusiness($business->customer, $business, $request->validated());
        } catch (InvalidArgumentException) {
            return redirect()
                ->route('admin.businesses.edit', $business)
                ->withInput()
                ->withErrors(['business' => 'We could not save these changes. Please check the entries and try again.']);
        }

        return redirect()->route('admin.businesses.show', $business)->with([
            'status' => 'success',
            'message' => 'Business updated successfully.',
        ]);
    }

    public function updateStatus(UpdateBusinessStatusRequest $request, Business $business): RedirectResponse
    {
        $this->authorize('edit business');

        $this->businessRepository->updateStatus($business, BusinessStatus::from($request->validated('status')));

        return redirect()->route('admin.businesses.show', $business)->with([
            'status' => 'success',
            'message' => 'Business status updated.',
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function breadcrumbs(?Business $business = null, ?string $trailing = null): array
    {
        $breadcrumbs = [
            ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Dashboard')],
            ['link' => route('admin.businesses.index'), 'name' => 'Businesses'],
        ];

        if ($business !== null && $trailing !== null) {
            $breadcrumbs[] = ['name' => $business->name . ' — ' . $trailing];
        } elseif ($business !== null) {
            $breadcrumbs[] = ['name' => $business->name];
        }

        return $breadcrumbs;
    }
}

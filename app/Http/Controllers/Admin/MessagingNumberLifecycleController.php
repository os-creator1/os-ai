<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\ReleaseMessagingNumberRequest;
use App\Library\Messaging\Exceptions\NumberReleaseNotEligibleException;
use App\Library\Messaging\NumberLifecycleManager;
use App\Models\BusinessMessagingNumber;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Phone Numbers + A2P lane — messaging contract §13.2/§13.3's platform-ops
 * surface for suspended numbers. Gated by the admin route group's blanket
 * 'can:access backend' gate plus the independent, explicit
 * EnsureUserIsAdministrator middleware — the same defense-in-depth pairing
 * as every other admin-only, cross-tenant surface in routes/admin.php
 * (MessagingProvisioningIncidentController, MessagingPortOutRequestController).
 * Never a customer-facing route.
 *
 * Release is the one genuinely human, irreversible action here: explicit,
 * audited (actor + required note), and refused by
 * NumberLifecycleManager::release() itself — never merely by this
 * controller's own view — unless every §13.3 precondition already holds.
 */
class MessagingNumberLifecycleController extends AdminBaseController
{
    public function __construct(
        private readonly NumberLifecycleManager $lifecycle,
    ) {
    }

    public function index(): View
    {
        return view('admin.messaging.number-lifecycle.index', [
            'numbers' => BusinessMessagingNumber::query()
                ->suspended()
                ->with('identity.business')
                ->orderBy('grace_expires_at')
                ->paginate(50)
                ->withQueryString(),
            'breadcrumbs' => $this->breadcrumbs(),
        ]);
    }

    public function release(ReleaseMessagingNumberRequest $request, int $number): RedirectResponse
    {
        $numberModel = BusinessMessagingNumber::find($number);

        if ($numberModel === null) {
            return redirect()
                ->route('admin.messaging-number-lifecycle.index')
                ->with('flash_error', 'That number no longer exists.');
        }

        try {
            $this->lifecycle->release($numberModel, (int) Auth::id(), $request->validated('note'));
        } catch (NumberReleaseNotEligibleException $e) {
            return redirect()
                ->route('admin.messaging-number-lifecycle.index')
                ->with('flash_error', $e->getMessage());
        }

        return redirect()
            ->route('admin.messaging-number-lifecycle.index')
            ->with('flash_success', 'Number released.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function breadcrumbs(): array
    {
        return [
            ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Dashboard')],
            ['name' => 'Messaging Number Lifecycle'],
        ];
    }
}

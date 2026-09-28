<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\ConfirmCarrierReleaseRequest;
use App\Http\Requests\Admin\ReleaseMessagingNumberRequest;
use App\Library\Messaging\Exceptions\MessagingProviderNotConfiguredException;
use App\Library\Messaging\Exceptions\NumberCarrierReleaseNotConfirmedException;
use App\Library\Messaging\Exceptions\NumberReleaseNotEligibleException;
use App\Library\Messaging\NumberLifecycleManager;
use App\Library\Messaging\ProvisioningAvailability;
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
 * Two distinct, sequential human actions, each explicit and audited:
 * release() records only the DECISION to release (never itself a claim
 * the carrier has released anything — status stays Suspended), and
 * confirmCarrierRelease() is the later, separate action that actually
 * calls the carrier and, only on confirmation, transitions status to
 * Released. Both are refused by NumberLifecycleManager itself — never
 * merely by this controller's own view — unless every precondition
 * already holds.
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
            'carrierReleaseAvailable' => ProvisioningAvailability::isConfigured(),
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
            $this->lifecycle->recordReleaseDecision($numberModel, (int) Auth::id(), $request->validated('note'));
        } catch (NumberReleaseNotEligibleException $e) {
            return redirect()
                ->route('admin.messaging-number-lifecycle.index')
                ->with('flash_error', $e->getMessage());
        }

        return redirect()
            ->route('admin.messaging-number-lifecycle.index')
            ->with('flash_success', 'Release decision recorded — the number remains suspended pending confirmed carrier release.');
    }

    /**
     * The real, irreversible carrier call. Only reachable once release()
     * above has already recorded a decision; refuses (via
     * NumberReleaseNotEligibleException) if that decision is missing, the
     * number is no longer Suspended, it has no provider reference on file,
     * or an active port-out request now exists. A NotConfirmed carrier
     * response is surfaced as a flash error with the exact failure reason
     * already durably recorded — the number stays Suspended and remains
     * available for a retry.
     */
    public function confirmCarrierRelease(ConfirmCarrierReleaseRequest $request, int $number): RedirectResponse
    {
        $numberModel = BusinessMessagingNumber::find($number);

        if ($numberModel === null) {
            return redirect()
                ->route('admin.messaging-number-lifecycle.index')
                ->with('flash_error', 'That number no longer exists.');
        }

        try {
            $this->lifecycle->confirmCarrierRelease($numberModel, (int) Auth::id(), $request->validated('note'));
        } catch (MessagingProviderNotConfiguredException) {
            return redirect()
                ->route('admin.messaging-number-lifecycle.index')
                ->with('flash_error', 'Carrier release confirmation is not configured in this environment.');
        } catch (NumberReleaseNotEligibleException|NumberCarrierReleaseNotConfirmedException $e) {
            return redirect()
                ->route('admin.messaging-number-lifecycle.index')
                ->with('flash_error', $e->getMessage());
        }

        return redirect()
            ->route('admin.messaging-number-lifecycle.index')
            ->with('flash_success', 'Carrier release confirmed — the number is now Released.');
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

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\DispositionPaymentProviderEventRequest;
use App\Library\Usage\PaymentProviderEventRetryPolicy;
use App\Repositories\Contracts\PaymentProviderEventRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * RFC-005 M3 contract §14/§17/§18 — exhausted webhook-event review and
 * disposition, platform-administrator only (gated by the group-level
 * `can:access backend` + EnsureUserIsAdministrator middleware; no new
 * config/permissions.php entry is authorized, §18). Never displays the raw
 * payload — only non-sensitive identity/state columns.
 */
class PaymentProviderEventController extends AdminBaseController
{
    public function __construct(
        private readonly PaymentProviderEventRepository $eventRepository,
    ) {
    }

    public function index(): View
    {
        $maxAttempts = PaymentProviderEventRetryPolicy::normalizedMaxAttempts();

        return view('admin.usage-billing.provider-events.index', [
            'events' => $this->eventRepository->exhausted($maxAttempts),
            'recentOutcomes' => $this->eventRepository->recentOutcomes(),
            'breadcrumbs' => $this->breadcrumbs(),
        ]);
    }

    public function dispose(DispositionPaymentProviderEventRequest $request, int $event): RedirectResponse
    {
        $maxAttempts = PaymentProviderEventRetryPolicy::normalizedMaxAttempts();

        $disposed = $this->eventRepository->dispose(
            $event,
            (int) Auth::id(),
            $request->validated('disposition_note'),
            $maxAttempts,
        );

        if ($disposed === 0) {
            return redirect()->back()->with('flash_error', 'This event is no longer eligible for disposition.');
        }

        return redirect()
            ->route('admin.provider-events.index')
            ->with('flash_success', 'Provider event disposed.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function breadcrumbs(): array
    {
        return [
            ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Dashboard')],
            ['name' => 'Payment Provider Events'],
        ];
    }
}

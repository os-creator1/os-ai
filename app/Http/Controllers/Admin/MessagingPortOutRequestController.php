<?php

namespace App\Http\Controllers\Admin;

use App\Models\BusinessMessagingNumberPortOutRequest;
use Illuminate\Contracts\View\View;

/**
 * Phone Numbers + A2P lane — messaging contract §13.4's "supported,
 * documented request path" for porting a managed number out. Read-only
 * platform-ops visibility into every port-out request, current and
 * historical, for the human follow-up (Telnyx's own porting process, §13.3)
 * that this slice deliberately does not automate. Gated by the admin route
 * group's blanket 'can:access backend' gate plus the independent, explicit
 * EnsureUserIsAdministrator middleware — the same defense-in-depth pairing
 * as every other admin-only, cross-tenant surface in routes/admin.php.
 * Never a customer-facing route, and this slice performs no mutation here:
 * no release, replace, transfer or purchase of a number, and no Telnyx
 * call.
 */
class MessagingPortOutRequestController extends AdminBaseController
{
    public function index(): View
    {
        return view('admin.messaging.port-out-requests.index', [
            'requests' => BusinessMessagingNumberPortOutRequest::query()
                ->with(['business', 'requestedByUser', 'cancelledByUser'])
                ->orderByDesc('id')
                ->paginate(50)
                ->withQueryString(),
            'breadcrumbs' => $this->breadcrumbs(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function breadcrumbs(): array
    {
        return [
            ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Dashboard')],
            ['name' => 'Messaging Port-Out Requests'],
        ];
    }
}

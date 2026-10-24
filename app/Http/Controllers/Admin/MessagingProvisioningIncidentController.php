<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\ResolveMessagingProvisioningIncidentRequest;
use App\Library\Messaging\ProvisioningIncidentRecorder;
use App\Models\BusinessMessagingProvisioningIncident;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Phone Numbers + A2P lane — the support/ops reconciliation surface for
 * business_messaging_provisioning_incidents (see
 * ProvisioningIncidentRecorder and PR #295 Correction Round 1, item 3).
 * Gated by the admin route group's blanket 'can:access backend' gate plus
 * the independent, explicit EnsureUserIsAdministrator middleware — the same
 * defense-in-depth pairing as every other admin-only, cross-tenant surface
 * in routes/admin.php (Business, Opportunity, Workspace modules,
 * PaymentProviderEventController). Never reachable from any customer-facing
 * route, and never rendered to a customer role.
 *
 * A row here means a real Telnyx provider resource may exist that this
 * platform's own local state does not fully reflect (§ProvisioningIncidentRecorder).
 * Resolution is an explicit, auditable operator action — never automatic,
 * never inferred from a later successful provisioning attempt — because
 * only a human can confirm the provider and local state were actually
 * reconciled.
 */
class MessagingProvisioningIncidentController extends AdminBaseController
{
    public function __construct(
        private readonly ProvisioningIncidentRecorder $incidents,
    ) {
    }

    public function index(): View
    {
        return view('admin.messaging.provisioning-incidents.index', [
            'incidents' => BusinessMessagingProvisioningIncident::query()
                ->unresolved()
                ->with('business')
                ->orderBy('id')
                ->paginate(50)
                ->withQueryString(),
            'breadcrumbs' => $this->breadcrumbs(),
        ]);
    }

    public function resolve(ResolveMessagingProvisioningIncidentRequest $request, int $incident): RedirectResponse
    {
        $record = BusinessMessagingProvisioningIncident::query()->find($incident);
        $state = $request->validated('provider_state');

        $resolved = DB::transaction(function () use ($record, $state, $request, $incident): int {
            // Write the reconciled provider state back first, so the retry
            // this resolution unblocks reuses what exists instead of
            // creating a duplicate. Funding-ambiguous resolution (fee
            // committed, ids saved) lets the saved ids complete without a
            // second charge.
            if ($record !== null && $state !== null && $record->resolved_at === null) {
                $ids = [
                    'provider_brand_id' => $state === 'nothing_created' ? null : $request->validated('provider_brand_id'),
                    'provider_campaign_id' => $state === 'brand_and_campaign_created' ? $request->validated('provider_campaign_id') : null,
                ];

                DB::table('business_messaging_registrations')->where('business_id', $record->business_id)->update($ids);
            }

            return $this->incidents->resolve(
                $incident,
                (int) Auth::id(),
                $request->validated('resolution_note'),
            );
        });

        if ($resolved === 0) {
            return redirect()
                ->route('admin.messaging-provisioning-incidents.index')
                ->with('flash_error', 'This incident is no longer eligible for resolution — it may already be resolved.');
        }

        return redirect()
            ->route('admin.messaging-provisioning-incidents.index')
            ->with('flash_success', 'Provisioning incident resolved.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function breadcrumbs(): array
    {
        return [
            ['link' => \App\Helpers\Helper::adminHomeUrl(), 'name' => __('locale.menu.Dashboard')],
            ['name' => 'Messaging Provisioning Incidents'],
        ];
    }
}

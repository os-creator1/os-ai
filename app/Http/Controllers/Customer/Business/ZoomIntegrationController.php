<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Entitlement\PlatformFeature;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Library\Calendar\Zoom\ZoomApiException;
use App\Library\Calendar\Zoom\ZoomClient;
use App\Library\Calendar\Zoom\ZoomConnectionManager;
use App\Models\Business;
use App\Models\Workspace;
use App\Models\ZoomConnection;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Calendar → Settings → Integrations → Zoom. Every action is scoped to ONE Business through the normal tenancy resolver
 * (Workspace/Business authorization, Calendar entitlement), and every connection is read through that Business's id, so a
 * Business can never see or use another Business's Zoom connection. No password, secret or token is ever typed or shown.
 */
class ZoomIntegrationController extends Controller
{
    use ResolvesBusinessTenancy;

    public function __construct(
        private readonly ZoomConnectionManager $zoom,
        private readonly ZoomClient $client,
    ) {
    }

    public function show(string $workspaceUid, string $businessUid): View
    {
        [$workspace, $business] = $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::Calendar->value);

        $connections = ZoomConnection::query()->where('business_id', $business->id)
            ->orderBy('id')->get();
        $users = \App\Models\User::query()->whereIn('id', $connections->pluck('user_id'))->get()->keyBy('id');

        // The Calendar sub-navigation belongs to a Location: use the first one this person may reach.
        $location = app(\App\Library\Calendar\CalendarScheduleService::class)->accessibleLocations($business, (int) Auth::id())->first();

        return view('customer.business.calendar.zoom', [
            'workspace' => $workspace,
            'business' => $business,
            'location' => $location,
            'configured' => $this->client->isConfigured(),
            'eligible' => app(\App\Library\Calendar\Zoom\ZoomEligibility::class)->allows($business),
            'connections' => $connections,
            'users' => $users,
            'mine' => $connections->firstWhere('user_id', Auth::id()),
        ]);
    }

    public function connect(string $workspaceUid, string $businessUid): RedirectResponse
    {
        [, $business] = $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::Calendar->value);

        try {
            return redirect()->away($this->zoom->begin($business, (int) Auth::id()));
        } catch (ZoomApiException) {
            return back()->with('flash_error', 'Zoom is not available for this calendar yet.');
        }
    }

    public function disconnect(string $workspaceUid, string $businessUid, int $connectionId): RedirectResponse
    {
        [$workspace, $business] = $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::Calendar->value);

        // Keyed by THIS Business: another Business's connection id is simply "not there".
        $connection = ZoomConnection::query()->where('business_id', $business->id)->whereKey($connectionId)->first() ?? abort(404);

        // A person disconnects their own Zoom; the Workspace owner may also disconnect a host.
        abort_unless((int) $connection->user_id === (int) Auth::id() || $this->isWorkspaceOwner($workspace), 403);

        $this->zoom->disconnect($connection);

        return back()->with('flash_success', 'Zoom disconnected. Booking types that use that host will stop offering Zoom until it is reconnected.');
    }

    /** The fixed OAuth redirect. The signed state decides the Business; the signed-in user must be the one who started it. */
    public function callback(Request $request): RedirectResponse
    {
        $code = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');
        $user = Auth::user();

        $connection = ($request->query('error') !== null || $code === '' || $state === '' || $user === null)
            ? null
            : $this->zoom->complete($code, $state, $user);

        if ($connection === null) {
            return redirect()->route('user.home')->with('flash_error', 'Zoom could not be connected. Please start again from Calendar → Settings → Integrations → Zoom.');
        }

        $business = Business::query()->find($connection->business_id);
        $workspace = Workspace::query()->find($business->workspace_id);

        return redirect()->route('customer.workspaces.businesses.calendar.integrations.zoom.show', [$workspace->uid, $business->uid])
            ->with('flash_success', 'Zoom connected.');
    }

    private function isWorkspaceOwner(Workspace $workspace): bool
    {
        return (int) $workspace->owner_user_id === (int) Auth::id();
    }
}

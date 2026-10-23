<?php

namespace App\Http\Controllers\Customer\Business;

use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Crm\CrmLocationScope;
use App\Library\Tasks\ContactTaskManager;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The call-task inbox: the open tasks automations (and people) have raised for this Business's contacts, soonest due
 * first, each completable. Same tenancy, permission and Location-reach rules as the Opportunities board it lives under:
 * a task of another Business — or of a Location the actor cannot reach — is simply not found.
 */
class CrmTasksController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;

    public function __construct(private readonly ContactTaskManager $tasks)
    {
    }

    public function list(string $workspaceUid, string $businessUid): View
    {
        $business = $this->business($workspaceUid, $businessUid);
        $this->authorize(CrmOpportunitiesController::VIEW_PERMISSION);

        $open = $this->scoped($business)->where('t.status', 'open')->orderByRaw('t.due_at is null, t.due_at')->orderBy('t.id')->limit(200)->get();
        $done = $this->scoped($business)->where('t.status', 'done')->orderByDesc('t.completed_at')->limit(20)->get();

        $contactIds = $open->merge($done)->pluck('contact_id')->unique()->all();
        $names = $this->names($contactIds);
        $uids = DB::table('contacts')->where('business_id', $business->id)->whereIn('id', $contactIds)->pluck('uid', 'id');

        return view('customer.crm.tasks', [
            'workspaceUid' => $workspaceUid, 'businessUid' => $businessUid, 'business' => $business,
            'open' => $open, 'done' => $done, 'names' => $names, 'contactUids' => $uids,
            'timezone' => $business->timezone ?: config('app.timezone'),
            'canManage' => \Illuminate\Support\Facades\Gate::allows(CrmOpportunitiesController::MANAGE_PERMISSION),
        ]);
    }

    public function complete(string $workspaceUid, string $businessUid, string $taskUid): RedirectResponse
    {
        $business = $this->business($workspaceUid, $businessUid);
        $this->authorize(CrmOpportunitiesController::MANAGE_PERMISSION);

        $task = $this->scoped($business)->where('t.uid', $taskUid)->first();
        abort_if($task === null, 404);

        $this->tasks->complete($business, (int) $task->id, (int) Auth::id());

        return back()->with('status', 'Task marked done.');
    }

    /** Workspace/Business tenancy + the CRM entitlement, exactly as the Opportunities board resolves them. */
    private function business(string $workspaceUid, string $businessUid): \App\Models\Business
    {
        [, $business] = $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, \App\Enums\Entitlement\PlatformFeature::Crm->value);

        return $business;
    }

    private function scoped($business)
    {
        $query = DB::table('contact_tasks as t')->where('t.business_id', $business->id)->select('t.*');
        app(CrmLocationScope::class)->restrict($query, $business, (int) Auth::id(), 't.location_id');

        return $query;
    }

    /** @return array<int, string> contact id => display name (first + last, else the phone number) */
    private function names(array $contactIds): array
    {
        if ($contactIds === []) {
            return [];
        }

        $rows = DB::table('contacts_custom_field as v')
            ->join('contact_group_fields as f', 'f.id', '=', 'v.field_id')
            ->whereIn('v.contact_id', $contactIds)->whereIn('f.tag', ['FIRST_NAME', 'LAST_NAME'])
            ->get(['v.contact_id', 'f.tag', 'v.value']);

        $names = [];
        foreach ($rows->groupBy('contact_id') as $id => $group) {
            $first = (string) ($group->firstWhere('tag', 'FIRST_NAME')->value ?? '');
            $last = (string) ($group->firstWhere('tag', 'LAST_NAME')->value ?? '');
            $names[(int) $id] = trim($first.' '.$last);
        }

        return $names;
    }
}

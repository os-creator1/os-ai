<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\GeneralException;
use App\Http\Requests\Administrator\StoreAdministrator;
use App\Http\Requests\Administrator\UpdateAdministrator;
use App\Library\Tool;
use App\Models\Language;
use App\Models\User;
use App\Repositories\Contracts\RoleRepository;
use App\Repositories\Contracts\UserRepository;
use Generator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use OpenSpout\Common\Exception\InvalidArgumentException;
use OpenSpout\Common\Exception\IOException;
use OpenSpout\Common\Exception\UnsupportedTypeException;
use OpenSpout\Writer\Exception\WriterNotOpenedException;
use Rap2hpoutre\FastExcel\FastExcel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AdministratorController extends AdminBaseController
{
    protected UserRepository $users;

    protected RoleRepository $roles;

    /**
     * Create a new controller instance.
     */
    protected \App\Library\PlatformOwner\PlatformAdministratorManager $manager;

    public function __construct(UserRepository $users, RoleRepository $roles, \App\Library\PlatformOwner\PlatformAdministratorManager $manager)
    {
        $this->users = $users;
        $this->roles = $roles;
        $this->manager = $manager;
    }

    /**
     * @throws AuthorizationException
     */
    public function index(): Factory|View|Application
    {

        $this->authorize('view administrator');

        $breadcrumbs = [
            ['link' => url(config('app.admin_path').'/dashboard'), 'name' => __('locale.menu.Dashboard')],
            ['link' => url(config('app.admin_path').'/dashboard'), 'name' => __('locale.menu.Administrator')],
            ['name' => __('locale.menu.Administrators')],
        ];

        $administrators = User::query()->where('is_admin', true)->with('roles')->orderBy('first_name')->get();

        return view('admin.Administrator.index', compact('breadcrumbs', 'administrators'));
    }

    /**
     * @throws AuthorizationException
     */
    public function search(Request $request): JsonResponse
    {

        $this->authorize('view administrator');

        $columns = [
            0 => 'responsive_id',
            1 => 'uid',
            2 => 'uid',
            3 => 'name',
            4 => 'roles',
            5 => 'created_at',
            6 => 'status',
            7 => 'actions',
        ];

        $totalData = User::where('is_admin', 1)->where('id', '!=', 1)->count();

        $totalFiltered = $totalData;

        $limit = $request->input('length');
        $start = $request->input('start');
        $order = $columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');
        if ($order == 'name') {
            $order = 'first_name';
        }

        if (empty($request->input('search.value'))) {
            $administrators = User::where('is_admin', 1)->where('id', '!=', 1)->offset($start)
                ->limit($limit)
                ->orderBy($order, $dir)
                ->get();
        } else {
            $search = $request->input('search.value');

            $administrators = User::where('is_admin', 1)->where('id', '!=', 1)->whereLike(['uid', 'first_name', 'last_name', 'status', 'email', 'created_at'], $search)
                ->offset($start)
                ->limit($limit)
                ->orderBy($order, $dir)
                ->get();

            $totalFiltered = User::where('is_admin', 1)->where('id', '!=', 1)->whereLike(['uid', 'first_name', 'last_name', 'status', 'email', 'created_at'], $search)->count();
        }

        $data = [];
        if (! empty($administrators)) {
            foreach ($administrators as $administrator) {
                $show = route('admin.administrators.show', $administrator->uid);

                if ($administrator->status) {
                    $status = 'checked';
                } else {
                    $status = '';
                }

                $get_roles = collect($administrator->roles)->map(function ($key) {
                    return ucfirst($key->display_name());
                })->join(',');

                if ($get_roles) {
                    $roles = $get_roles;
                } else {
                    $roles = __('locale.administrator.no_active_roles');
                }

                $edit = null;
                $delete = null;

                if (Auth::user()->can('edit administrator')) {
                    $edit .= $show;
                }

                if (Auth::user()->can('delete administrator')) {
                    $delete .= $administrator->uid;
                }

                $nestedData['uid'] = $administrator->uid;
                $nestedData['responsive_id'] = '';
                $nestedData['avatar'] = route('admin.customers.avatar', $administrator->uid);
                $nestedData['email'] = $administrator->email;
                $nestedData['name'] = $administrator->first_name.' '.$administrator->last_name;
                $nestedData['roles'] = $roles;
                $nestedData['created_at'] = Tool::formatDate($administrator->created_at);
                $nestedData['status'] = "<div class='form-check form-switch form-check-primary'>
                <input type='checkbox' class='form-check-input get_status' id='status_$administrator->uid' data-id='$administrator->uid' name='status' $status>
                <label class='form-check-label' for='status_$administrator->uid'>
                  <span class='switch-icon-left'><i data-feather='check'></i> </span>
                  <span class='switch-icon-right'><i data-feather='x'></i> </span>
                </label>
              </div>";

                $nestedData['action'] = $this->actionHtml($edit, $delete);
                $data[] = $nestedData;

            }
        }

        $json_data = [
            'draw' => intval($request->input('draw')),
            'recordsTotal' => $totalData,
            'recordsFiltered' => $totalFiltered,
            'data' => $data,
        ];

        return response()->json($json_data);

    }

    /**
     * Server-rendered `action` cell for the DataTables contract: only the
     * actions the current admin is authorized for are emitted, and both
     * values are escaped. $edit is a URL, $delete a role/administrator uid.
     */
    private function actionHtml(?string $edit, ?string $delete): string
    {
        $html = '';

        if ($delete !== null) {
            $html .= '<span class="action-delete text-danger pe-1 cursor-pointer" data-id="'.e($delete).'"><i data-feather="trash" class="font-medium-4"></i></span>';
        }

        if ($edit !== null) {
            $html .= '<a href="'.e($edit).'" class="text-primary"><i data-feather="edit" class="font-medium-4"></i></a>';
        }

        return $html;
    }

    /**
     * create new administrator
     *
     * @throws AuthorizationException
     */
    public function create(): \Illuminate\Contracts\View\View|Factory|Application
    {

        $this->authorize('create administrator');

        $breadcrumbs = [
            ['link' => url(config('app.admin_path').'/dashboard'), 'name' => __('locale.menu.Dashboard')],
            ['link' => url(config('app.admin_path').'/administrators'), 'name' => __('locale.menu.Administrators')],
            ['name' => __('locale.administrator.create_administrator')],
        ];

        $roles = $this->roles->getAllowedRoles();

        return view('admin.Administrator.create', compact('breadcrumbs', 'roles'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create administrator');

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['integer'],
        ]);

        try {
            $admin = $this->manager->invite((int) Auth::id(), $data);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        } catch (\RuntimeException $e) {
            return redirect()->route('admin.administrators.index')->with(['status' => 'error', 'message' => $e->getMessage()]);
        }

        return redirect()->route('admin.administrators.index')->with([
            'status' => 'success',
            'message' => __('Invitation sent to :email. They set their own password from the email.', ['email' => $admin->email]),
        ]);
    }

    /**
     * View administrator for edit
     *
     *
     *
     * @throws AuthorizationException
     */
    public function show(User $administrator): Factory|View|Application
    {
        $this->authorize('edit administrator');

        $breadcrumbs = [
            ['link' => url(config('app.admin_path').'/dashboard'), 'name' => __('locale.menu.Dashboard')],
            ['link' => url(config('app.admin_path').'/administrators'), 'name' => __('locale.menu.Administrators')],
            ['name' => $administrator->displayName()],
        ];

        $get_roles = collect($administrator->roles)->map(function ($key) {
            return $key->id;
        })->join(',');

        $languages = Language::where('status', 1)->get();
        $roles = $this->roles->getAllowedRoles();

        return view('admin.Administrator.show', compact('breadcrumbs', 'administrator', 'languages', 'roles', 'get_roles'));
    }

    /**
     * @throws AuthorizationException
     */
    public function update(User $administrator, Request $request): RedirectResponse
    {
        $this->authorize('edit administrator');

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['integer'],
        ]);

        try {
            $this->manager->updateProfileAndRoles((int) Auth::id(), $administrator, $data);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        } catch (\RuntimeException $e) {
            return back()->with(['status' => 'error', 'message' => $e->getMessage()]);
        }

        return redirect()->route('admin.administrators.index')->with([
            'status' => 'success',
            'message' => __('Administrator updated.'),
        ]);
    }

    public function resendInvitation(User $administrator): RedirectResponse
    {
        $this->authorize('edit administrator');

        try {
            $this->manager->resendInvitation((int) Auth::id(), $administrator);
        } catch (\RuntimeException $e) {
            return back()->with(['status' => 'error', 'message' => $e->getMessage()]);
        }

        return back()->with(['status' => 'success', 'message' => __('Invitation sent to :email.', ['email' => $administrator->email])]);
    }

    public function setStatus(Request $request, User $administrator): RedirectResponse
    {
        $this->authorize('edit administrator');

        $data = $request->validate(['active' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:3', 'max:500']]);

        try {
            $this->manager->setActive((int) Auth::id(), $administrator, (bool) $data['active'], $data['reason']);
        } catch (\RuntimeException $e) {
            return back()->with(['status' => 'error', 'message' => $e->getMessage()]);
        }

        return back()->with(['status' => 'success', 'message' => $data['active'] ? __('Administrator activated.') : __('Administrator deactivated and signed out.')]);
    }

    /**
     * change administrator status
     *
     *
     * @throws AuthorizationException
     * @throws GeneralException
     */
    public function activeToggle(User $administrator): JsonResponse
    {
        if (config('app.stage') == 'demo') {
            return response()->json([
                'status' => 'error',
                'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }
        try {
            $this->authorize('edit administrator');

            if ($administrator->update(['status' => ! $administrator->status])) {
                return response()->json([
                    'status' => 'success',
                    'message' => __('locale.administrator.administrator_successfully_change'),
                ]);
            }

            throw new GeneralException(__('locale.exceptions.something_went_wrong'));
        } catch (ModelNotFoundException $exception) {
            return response()->json([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * delete administrator
     *
     *
     * @throws AuthorizationException
     */
    public function destroy(User $administrator): JsonResponse
    {
        if (config('app.stage') == 'demo') {
            return response()->json([
                'status' => 'error',
                'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        $this->authorize('delete administrator');

        $this->users->destroy($administrator);

        return response()->json([
            'status' => 'success',
            'message' => __('locale.administrator.administrator_successfully_deleted'),
        ]);
    }

    /**
     * Bulk Action with Enable, Disable and Delete
     *
     *
     * @throws AuthorizationException
     */
    public function batchAction(Request $request): JsonResponse
    {
        if (config('app.stage') == 'demo') {
            return response()->json([
                'status' => 'error',
                'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        $action = $request->get('action');
        $ids = $request->get('ids');

        switch ($action) {
            case 'destroy':
                $this->authorize('delete administrator');

                $this->users->batchDestroy($ids);

                return response()->json([
                    'status' => 'success',
                    'message' => __('locale.administrator.administrators_deleted'),
                ]);

            case 'enable':
                $this->authorize('edit administrator');

                $this->users->batchEnable($ids);

                return response()->json([
                    'status' => 'success',
                    'message' => __('locale.administrator.administrators_enabled'),
                ]);

            case 'disable':

                $this->authorize('edit administrator');

                $this->users->batchDisable($ids);

                return response()->json([
                    'status' => 'success',
                    'message' => __('locale.administrator.administrators_disabled'),
                ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => __('locale.exceptions.invalid_action'),
        ]);

    }

    public function AdministratorGenerator(): Generator
    {
        foreach (User::where('is_admin', 1)->cursor() as $administrator) {
            yield $administrator;
        }
    }

    /**
     * @throws AuthorizationException
     */
    public function export(): BinaryFileResponse|RedirectResponse
    {

        if (config('app.stage') == 'demo') {
            return redirect()->route('admin.administrators.index')->with([
                'status' => 'error',
                'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        $this->authorize('edit administrator');

        try {
            $file_name = (new FastExcel($this->AdministratorGenerator()))->export(storage_path('Administrator_'.time().'.xlsx'));

            return response()->download($file_name);
        } catch (IOException|WriterNotOpenedException|UnsupportedTypeException|InvalidArgumentException $e) {
            return redirect()->route('admin.administrators.index')->with([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }
    }
}

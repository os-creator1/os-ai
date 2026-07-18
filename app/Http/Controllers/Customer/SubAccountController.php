<?php

    namespace App\Http\Controllers\Customer;

    use App\Exceptions\GeneralException;
    use App\Http\Requests\SubAccounts\StoreSubAccountRequest;
    use App\Http\Requests\SubAccounts\UpdateSubAccountRequest;
    use App\Library\Tool;
    use App\Models\Customer;
    use App\Models\User;
    use App\Repositories\Contracts\SubAccountRepository;
    use Exception;
    use Illuminate\Auth\Access\AuthorizationException;
    use Illuminate\Database\Eloquent\ModelNotFoundException;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Auth;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Log;
    use Intervention\Image\Exception\NotReadableException;
    use Intervention\Image\Facades\Image;
    use JetBrains\PhpStorm\NoReturn;
    use Throwable;

    class SubAccountController extends CustomerBaseController
    {


        protected SubAccountRepository $subAccount;

        /**
         * SubscriptionController constructor.
         *
         * @param SubAccountRepository $subAccount
         */
        public function __construct(SubAccountRepository $subAccount)
        {
            $this->subAccount = $subAccount;
        }


        public function index()
        {

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['name' => __('locale.labels.sub_accounts')],
            ];

            return view('customer.SubAccounts.index', compact('breadcrumbs'));
        }


        /**
         * view all customers
         *
         *
         * @throws AuthorizationException
         */
        #[NoReturn]
        public function search(Request $request): void
        {

            $columns = [
                0 => 'responsive_id',
                1 => 'uid',
                2 => 'uid',
                3 => 'name',
                6 => 'actions',
            ];

            $parent = Auth::user();

            $totalData = User::where('is_customer', 1)->where('parent_id', $parent->id)->count();

            $totalFiltered = $totalData;

            $limit = $request->input('length');
            $start = $request->input('start');
            $order = $columns[$request->input('order.0.column')];
            $dir   = $request->input('order.0.dir');

            if (empty($request->input('search.value'))) {
                $users = User::where('is_customer', 1)->where('parent_id', $parent->id)->offset($start)
                    ->limit($limit)
                    ->orderBy($order, $dir)
                    ->get();
            } else {
                $search = $request->input('search.value');

                $users = User::where('is_customer', 1)->where('parent_id', $parent->id)->whereLike(['uid', 'first_name', 'last_name', 'status', 'email'], $search)
                    ->offset($start)
                    ->limit($limit)
                    ->orderBy($order, $dir)
                    ->get();

                $totalFiltered = User::where('is_customer', 1)->where('parent_id', $parent->id)->whereLike(['uid', 'first_name', 'last_name', 'status', 'email'], $search)->count();
            }

            $data = [];
            if ( ! empty($users)) {
                foreach ($users as $user) {
                    $show   = route('customer.sub_accounts.show', $user->uid);
                    $edit   = __('locale.buttons.edit');
                    $delete = __('locale.buttons.delete');

                    if ($user->status === true) {
                        $status = 'checked';
                    } else {
                        $status = '';
                    }

                    $nestedData['responsive_id'] = '';
                    $nestedData['uid']           = $user->uid;
                    $nestedData['avatar']        = route('customer.sub_accounts.avatar', $user->uid);
                    $nestedData['email']         = $user->email;
                    $nestedData['name']          = $user->first_name . ' ' . $user->last_name;
                    $nestedData['created_at']    = __('locale.labels.created_at') . ': ' . Tool::formatDate($user->created_at);

                    $nestedData['status'] = "<div class='form-check form-switch form-check-primary'>
                <input type='checkbox' class='form-check-input get_status' id='status_$user->uid' data-id='$user->uid' name='status' $status>
                <label class='form-check-label' for='status_$user->uid'>
                  <span class='switch-icon-left'><i data-feather='check'></i> </span>
                  <span class='switch-icon-right'><i data-feather='x'></i> </span>
                </label>
              </div>";


                    $nestedData['show']         = $show;
                    $nestedData['show_label']   = $edit;
                    $nestedData['delete']       = $user->uid;
                    $nestedData['delete_label'] = $delete;

                    $data[] = $nestedData;

                }
            }

            $json_data = [
                'draw'            => intval($request->input('draw')),
                'recordsTotal'    => $totalData,
                'recordsFiltered' => $totalFiltered,
                'data'            => $data,
            ];

            echo json_encode($json_data);
            exit();

        }

        public function create()
        {
            $breadcrumbs = [
                ['link' => route('user.home'), 'name' => __('locale.menu.Dashboard')],
                ['link' => route('customer.sub_accounts.index'), 'name' => __('locale.labels.sub_accounts')],
                ['name' => __('locale.buttons.add_new')],
            ];

            $user = Auth::user();

            // Decode permissions once
            $existingPermissions = json_decode(optional($user->customer)->permissions, true) ?? [];

            // Prepare permissions collection in one pass
            $categories = collect(config('customer-permissions'))
                ->filter(fn($value, $key) => in_array($key, $existingPermissions))
                ->map(fn($value, $key) => array_merge($value, ['name' => $key]))
                ->groupBy('category');

            // Build permissions array directly
            $permissions = $categories->map(fn($items, $key) => [
                'title'       => $key,
                'permissions' => $items,
            ])->values();

            return view('customer.SubAccounts.create', [
                'breadcrumbs'         => $breadcrumbs,
                'permissions'         => $permissions,
                'existing_permission' => $existingPermissions,
                'categories'          => $categories,
            ]);
        }

        public function store(StoreSubAccountRequest $request)
        {

            if (config('app.stage') == 'demo') {
                return redirect()->route('user.home')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $this->subAccount->store($request->input());

            return redirect()->route('customer.sub_accounts.index')->with([
                'status'  => 'success',
                'message' => __('locale.sub_accounts.sub_account_added'),
            ]);
        }


        public function show(User $subAccount)
        {
            $breadcrumbs = [
                ['link' => route('user.home'), 'name' => __('locale.menu.Dashboard')],
                ['link' => route('customer.sub_accounts.index'), 'name' => __('locale.labels.sub_accounts')],
                ['name' => trim($subAccount->first_name . ' ' . $subAccount->last_name)],
            ];

            $user = Auth::user();

            // Decode existing permissions for both the main customer and the sub-account
            $mainCustomerPermissions = json_decode(optional($user->customer)->permissions, true) ?? [];
            $subAccountPermissions   = json_decode(optional($subAccount->customer)->permissions, true) ?? [];

            $categories = collect(config('customer-permissions'))
                ->filter(fn($value, $key) => in_array($key, $mainCustomerPermissions))
                ->map(fn($value, $key) => array_merge($value, ['name' => $key]))
                ->groupBy('category');


            $permissions = $categories->keys()->map(function ($key) use ($categories) {
                return [
                    'title'       => $key,
                    'permissions' => $categories[$key],
                ];
            });

            return view('customer.SubAccounts.show', [
                'breadcrumbs'         => $breadcrumbs,
                'subAccount'          => $subAccount,
                'permissions'         => $permissions,
                'existing_permission' => $subAccountPermissions,
                'categories'          => $categories,
            ]);
        }

        public function update(User $subAccount, UpdateSubAccountRequest $request)
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('user.home')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $subAccount = $this->subAccount->update($subAccount, $request->input());

            // Upload and save image
            if ($request->hasFile('image')) {
                if ($request->file('image')->isValid()) {
                    $subAccount->image = $subAccount->uploadImage($request->file('image'));
                    $subAccount->save();
                }
            }

            return redirect()->route('customer.sub_accounts.index')->with([
                'status'  => 'success',
                'message' => __('locale.sub_accounts.sub_account_updated'),
            ]);
        }

        /**
         * get customer avatar
         */
        public function avatar(User $subAccount): mixed
        {

            if ( ! empty($subAccount->imagePath())) {

                try {
                    $image = Image::make($subAccount->imagePath());
                } catch (NotReadableException) {
                    $subAccount->image = null;
                    $subAccount->save();

                    $image = Image::make(public_path('images/profile/profile.jpg'));
                }
            } else {
                $image = Image::make(public_path('images/profile/profile.jpg'));
            }

            return $image->response();
        }


        /**
         * @throws GeneralException
         */
        public function activeToggle(User $sub_account): JsonResponse
        {
            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }
            try {

                if ($sub_account->update(['status' => ! $sub_account->status])) {
                    return response()->json([
                        'status'  => 'success',
                        'message' => __('locale.sub_accounts.sub_account_status_updated'),
                    ]);
                }

                throw new GeneralException(__('locale.exceptions.something_went_wrong'));
            } catch (ModelNotFoundException $exception) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $exception->getMessage(),
                ]);
            }

        }


        /**
         * Bulk Action with Enable, Disable
         *
         *
         * @throws AuthorizationException
         */
        public function batchAction(Request $request): JsonResponse
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $action = $request->get('action');
            $ids    = $request->get('ids');

            switch ($action) {

                case 'enable':

                    $this->subAccount->batchEnable($ids);

                    return response()->json([
                        'status'  => 'success',
                        'message' => __('locale.sub_accounts.sub_accounts_enabled'),
                    ]);

                case 'disable':

                    $this->subAccount->batchDisable($ids);

                    return response()->json([
                        'status'  => 'success',
                        'message' => __('locale.sub_accounts.sub_accounts_disabled'),
                    ]);

                case 'delete':

                    $this->subAccount->batchDelete($ids);

                    return response()->json([
                        'status'  => 'success',
                        'message' => __('locale.sub_accounts.sub_accounts_deleted'),
                    ]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => __('locale.exceptions.invalid_action'),
            ]);

        }

        /**
         * @throws Throwable
         */
        public function destroy(User $sub_account): JsonResponse
        {
            if (app()->isLocal() === false && config('app.stage') === 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            // Start a transaction in case there are multiple operations
            DB::beginTransaction();

            try {
                Customer::where('user_id', $sub_account->id)->delete();
                $sub_account->delete();

                DB::commit();

                return response()->json([
                    'status'  => 'success',
                    'message' => __('locale.sub_accounts.sub_account_deleted'),
                ]);
            } catch (Exception $e) {
                DB::rollBack();

                Log::error('Failed to delete sub-account', [
                    'user_id' => $sub_account->id,
                    'error'   => $e->getMessage(),
                ]);

                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.exceptions.something_went_wrong'),
                ]);
            }
        }


    }

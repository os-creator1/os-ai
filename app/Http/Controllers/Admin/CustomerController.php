<?php

    namespace App\Http\Controllers\Admin;

    use App\Exceptions\GeneralException;
    use App\Http\Requests\Customer\AddSendingServerRequest;
    use App\Http\Requests\Customer\AddUnitRequest;
    use App\Http\Requests\Customer\DeleteSendingServerRequest;
    use App\Http\Requests\Customer\DltEntityIdRequest;
    use App\Http\Requests\Customer\DltTelemarketerIdRequest;
    use App\Http\Requests\Customer\PermissionRequest;
    use App\Http\Requests\Customer\StoreCustomerRequest;
    use App\Http\Requests\Customer\UpdateAvatarRequest;
    use App\Http\Requests\Customer\UpdateCustomerRequest;
    use App\Http\Requests\Customer\UpdateInformationRequest;
    use App\Http\Requests\Plan\AddCoverageRequest;
    use App\Http\Requests\Plan\UpdateCoverageRequest;
    use App\Library\StringHelper;
    use App\Library\Tool;
    use App\Models\Announcements;
    use App\Models\Blacklists;
    use App\Models\Campaigns;
    use App\Models\ChatBox;
    use App\Models\ContactGroups;
    use App\Models\Country;
    use App\Models\Customer;
    use App\Models\CustomerBasedPricingPlan;
    use App\Models\CustomerBasedSendingServer;
    use App\Models\Invoices;
    use App\Models\Keywords;
    use App\Models\Language;
    use App\Models\Notifications;
    use App\Models\PhoneNumbers;
    use App\Models\Plan;
    use App\Models\Reports;
    use App\Models\Senderid;
    use App\Models\SendingServer;
    use App\Models\Subscription;
    use App\Models\SubscriptionTransaction;
    use App\Models\Templates;
    use App\Models\User;
    use App\Repositories\Contracts\CustomerRepository;
    use App\Rules\ExcelRule;
    use Exception;
    use Generator;
    use Illuminate\Auth\Access\AuthorizationException;
    use Illuminate\Contracts\Foundation\Application;
    use Illuminate\Contracts\View\Factory;
    use Illuminate\Database\Eloquent\ModelNotFoundException;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\RedirectResponse;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Auth;
    use Illuminate\Support\Facades\Validator;
    use Illuminate\View\View;
    use Intervention\Image\Exception\NotReadableException;
    use Intervention\Image\Facades\Image;
    use JetBrains\PhpStorm\NoReturn;
    use OpenSpout\Common\Exception\InvalidArgumentException;
    use OpenSpout\Common\Exception\IOException;
    use OpenSpout\Common\Exception\UnsupportedTypeException;
    use OpenSpout\Writer\Exception\WriterNotOpenedException;
    use Rap2hpoutre\FastExcel\FastExcel;
    use Symfony\Component\HttpFoundation\BinaryFileResponse;
    use Throwable;

    class CustomerController extends AdminBaseController
    {
        protected CustomerRepository $customers;

        /**
         * Create a new controller instance.
         */
        public function __construct(CustomerRepository $customers)
        {
            $this->customers = $customers;
        }

        /**
         * @throws AuthorizationException
         */
        public function index(): Factory|View|Application
        {

            $this->authorize('view customer');

            $breadcrumbs = [
                ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Customer')],
                ['name' => __('locale.menu.Customers')],
            ];

            $customer_stats = $this->customers->getCustomerStats();
            $currentJob     = Auth::user()->importJobs()->first();

            return view('admin.customer.index', compact('breadcrumbs', 'customer_stats', 'currentJob'));

        }

        /**
         * view all customers
         *
         *
         * @throws AuthorizationException
         */
        #[NoReturn]
        public function search(Request $request): JsonResponse
        {
            $this->authorize('view customer');

            $columns = [
                0 => 'responsive_id',
                1 => 'uid',
                2 => 'uid',
                3 => 'first_name',
                4 => 'parent',
                5 => 'subscription',
                6 => 'status',
                7 => 'actions',
            ];

            $limit  = $request->input('length');
            $start  = $request->input('start');
            $order  = $columns[$request->input('order.0.column')] ?? 'uid';
            $dir    = $request->input('order.0.dir') ?? 'asc';
            $search = $request->input('search.value');

            $query = User::with(['parent', 'customer'])
                ->where('is_customer', 1);

            $totalData = $query->count();

            if ( ! empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->whereLike(['uid', 'first_name', 'last_name', 'status', 'email'], $search);
                });
            }

            $totalFiltered = $query->count();

            $users = $query->offset($start)
                ->limit($limit)
                ->orderBy($order, $dir)
                ->get();

            $data = $users->map(function ($user) {
                $super_user = $user->id === 1;

                $sms_unit = match (true) {
                    is_null($user->sms_unit) => '--',
                    $user->sms_unit == -1 => __('locale.labels.unlimited'),
                    default => Tool::number_with_delimiter($user->sms_unit),
                };

                $subscription = $user->customer?->currentPlanName() ?? __('locale.subscription.no_active_subscription');

                $parentData = $user->parent
                    ? [
                        'parent'            => route('admin.customers.show', $user->parent->uid),
                        'parent_avatar'     => route('admin.customers.avatar', $user->parent->uid),
                        'parent_name'       => $user->parent->first_name . ' ' . $user->parent->last_name,
                        'parent_email'      => $user->parent->email,
                        'parent_created_at' => __('locale.labels.created_at') . ': ' . Tool::formatDate($user->parent->created_at),
                    ]
                    : array_fill_keys(['parent', 'parent_avatar', 'parent_name', 'parent_email', 'parent_created_at'], '--');

                return [
                    'responsive_id'     => '',
                    'uid'               => $user->uid,
                    'avatar'            => route('admin.customers.avatar', $user->uid),
                    'email'             => $user->email,
                    'name'              => "{$user->first_name} {$user->last_name}",
                    'created_at'        => __('locale.labels.created_at') . ': ' . Tool::formatDate($user->created_at),
                    'subscription'      => "<div><p class='text-bold-600'>{$subscription}</p></div>",
                    'status'            => $super_user ? '--' : "<div class='form-check form-switch form-check-primary'>
                <input type='checkbox' class='form-check-input get_status' id='status_{$user->uid}' data-id='{$user->uid}' name='status' " . ($user->status ? 'checked' : '') . ">
                <label class='form-check-label' for='status_{$user->uid}'>
                  <span class='switch-icon-left'><i data-feather='check'></i></span>
                  <span class='switch-icon-right'><i data-feather='x'></i></span>
                </label>
              </div>",
                    'sms_unit'          => $sms_unit,
                    'assign_plan'       => route('admin.subscriptions.create', ['customer_id' => $user->id]),
                    'assign_plan_label' => __('locale.customer.assign_plan'),
                    'login_as'          => $super_user ? '#' : route('admin.customers.login_as', $user->uid),
                    'login_as_label'    => __('locale.customer.login_as_customer'),
                    'show'              => route('admin.customers.show', $user->uid),
                    'show_label'        => __('locale.buttons.edit'),
                    'delete'            => $user->uid,
                    'delete_label'      => __('locale.buttons.delete'),
                    'super_user'        => $super_user,
                    ...$parentData,
                ];
            });

            return response()->json([
                'draw'            => intval($request->input('draw')),
                'recordsTotal'    => $totalData,
                'recordsFiltered' => $totalFiltered,
                'data'            => $data,
            ]);
        }


        /**
         * create new customer
         *
         * @throws AuthorizationException
         */
        public function create(): Factory|View|Application
        {
            $this->authorize('create customer');

            $breadcrumbs = [
                ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url(config('app.admin_path') . '/customers'), 'name' => __('locale.menu.Customers')],
                ['name' => __('locale.customer.add_new')],
            ];

            $languages = Language::where('status', 1)->get();

            return view('admin.customer.create', compact('breadcrumbs', 'languages'));
        }

        /**
         * add new customer
         */
        public function store(StoreCustomerRequest $request): RedirectResponse
        {

            if (config('app.stage') == 'demo') {
                return redirect()->route('admin.customers.index')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $customer = $this->customers->store($request->input());

            // Upload and save image
            if ($request->hasFile('image')) {
                if ($request->file('image')->isValid()) {
                    $customer->image = $customer->uploadImage($request->file('image'));
                    $customer->save();
                }
            }

            return redirect()->route('admin.customers.show', $customer->uid)->with([
                'status'  => 'success',
                'message' => __('locale.customer.customer_successfully_added'),
            ]);
        }

        /**
         * View customer for edit
         *
         *
         *
         * @throws AuthorizationException
         */

        public function show(User $customer)
        {
            // Super admin cannot be edited
            if ( ! Auth::user()->is_super_admin && $customer->is_super_admin) {
                return redirect()->route('admin.customers.index')->with([
                    'status'  => 'error',
                    'message' => 'Super admin can not be edited',
                ]);
            }

            $authUser = auth()->user();

            // Only super admin can edit other admins
            if ($customer->is_admin && $authUser->id !== 1) {
                return redirect()->route('admin.customers.index')->with([
                    'status'  => 'error',
                    'message' => 'Only super admin can edit admin accounts.',
                ]);
            }

            $this->authorize('edit customer');

            // Breadcrumbs & additional data
            $breadcrumbs = [
                ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url(config('app.admin_path') . '/customers'), 'name' => __('locale.menu.Customers')],
                ['name' => $customer->displayName()],
            ];

            $languages = Language::where('status', 1)->get();

            $categories = collect(config('customer-permissions'))->map(function ($value, $key) {
                $value['name'] = $key;

                return $value;
            })->groupBy('category');

            $permissions = $categories->keys()->map(function ($key) use ($categories) {
                return [
                    'title'       => $key,
                    'permissions' => $categories[$key],
                ];
            });

            $existing_permission = json_decode(optional($customer->customer)->permissions ?? Customer::customerPermissions(), true);

            $sending_servers = SendingServer::where('status', 1)->get();

            return view('admin.customer.show', compact(
                'breadcrumbs',
                'customer',
                'languages',
                'permissions',
                'existing_permission',
                'sending_servers'
            ));
        }

        /**
         * get customer avatar
         */
        public function avatar(User $customer): mixed
        {

            if ( ! empty($customer->imagePath())) {

                try {
                    $image = Image::make($customer->imagePath());
                } catch (NotReadableException) {
                    $customer->image = null;
                    $customer->save();

                    $image = Image::make(public_path('images/profile/profile.jpg'));
                }
            } else {
                $image = Image::make(public_path('images/profile/profile.jpg'));
            }

            return $image->response();
        }

        /**
         * update avatar
         */
        public function updateAvatar(User $customer, UpdateAvatarRequest $request): RedirectResponse
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('admin.customers.show', $customer->uid)->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            try {
                // Upload and save image
                if ($request->hasFile('image')) {
                    if ($request->file('image')->isValid()) {

                        // Remove old images
                        $customer->removeImage();
                        $customer->image = $customer->uploadImage($request->file('image'));
                        $customer->save();

                        return redirect()->route('admin.customers.show', $customer->uid)->with([
                            'status'  => 'success',
                            'message' => __('locale.customer.avatar_update_successful'),
                        ]);
                    }

                    return redirect()->route('admin.customers.show', $customer->uid)->with([
                        'status'  => 'error',
                        'message' => __('locale.exceptions.invalid_image'),
                    ]);
                }

                return redirect()->route('admin.customers.show', $customer->uid)->with([
                    'status'  => 'error',
                    'message' => __('locale.exceptions.invalid_image'),
                ]);

            } catch (Exception $exception) {
                return redirect()->route('admin.customers.show', $customer->uid)->with([
                    'status'  => 'error',
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        /**
         * remove avatar
         */
        public function removeAvatar(User $customer): JsonResponse
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            // Remove old images
            $customer->removeImage();
            $customer->image = null;
            $customer->save();

            return response()->json([
                'status'  => 'success',
                'message' => __('locale.customer.avatar_remove_successful'),
            ]);
        }

        /**
         * update customer basic account information
         */
        public function update(User $customer, UpdateCustomerRequest $request): RedirectResponse
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('admin.customers.show', $customer->uid)->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $this->customers->update($customer, $request->input());

            return redirect()->route('admin.customers.show', $customer->uid)->withInput(['tab' => 'account'])->with([
                'status'  => 'success',
                'message' => __('locale.customer.customer_successfully_updated'),
            ]);
        }

        /**
         * update customer detail information
         */
        public function updateInformation(User $customer, UpdateInformationRequest $request): RedirectResponse
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('admin.customers.show', $customer->uid)->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $this->customers->updateInformation($customer, $request->except('_token'));

            return redirect()->route('admin.customers.show', $customer->uid)->withInput(['tab' => 'information'])->with([
                'status'  => 'success',
                'message' => __('locale.customer.customer_successfully_updated'),
            ]);
        }

        /**
         * update user permission
         */
        public function permissions(User $customer, PermissionRequest $request): RedirectResponse
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('admin.customers.show', $customer->uid)->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $this->customers->permissions($customer, $request->only('permissions'));

            return redirect()->route('admin.customers.show', $customer->uid)->withInput(['tab' => 'permission'])->with([
                'status'  => 'success',
                'message' => __('locale.customer.customer_successfully_updated'),
            ]);
        }

        /**
         * change customer status
         *
         *
         * @param User $customer
         * @return JsonResponse
         */
        public function activeToggle(User $customer): JsonResponse
        {
            if (config('app.stage') === 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            try {
                $authUser = auth()->user();

                // Restriction: Only super admin can update admin accounts
                if ($customer->is_admin && $authUser->id !== 1) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Only super admin can update admin accounts.',
                    ]);
                }

                $this->authorize('edit customer');

                $customer->status = ! $customer->status;

                if ($customer->save()) {
                    return response()->json([
                        'status'  => 'success',
                        'message' => __('locale.customer.customer_successfully_change'),
                    ]);
                }

                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.exceptions.something_went_wrong'),
                ]);

            } catch (ModelNotFoundException|AuthorizationException $exception) {
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

                    $this->authorize('edit customer');

                    $this->customers->batchEnable($ids);

                    return response()->json([
                        'status'  => 'success',
                        'message' => __('locale.customer.customers_enabled'),
                    ]);

                case 'disable':

                    $this->authorize('edit customer');

                    $this->customers->batchDisable($ids);

                    return response()->json([
                        'status'  => 'success',
                        'message' => __('locale.customer.customers_disabled'),
                    ]);

                case 'delete':

                    $this->authorize('delete customer');

                    $this->customers->batchDelete($ids);

                    return response()->json([
                        'status'  => 'success',
                        'message' => __('locale.customer.customers_deleted'),
                    ]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => __('locale.exceptions.invalid_action'),
            ]);

        }

        /**
         * destroy customer
         *
         *
         * @throws AuthorizationException
         * @throws Exception
         */
        public function destroy(User $customer): JsonResponse
        {
            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $this->authorize('delete customer');

            if ($customer->is_super_admin) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Super Admin cannot be deleted',
                ]);
            }

            PhoneNumbers::where('user_id', $customer->id)->update([
                'status'  => 'available',
                'user_id' => 1,
            ]);

            Blacklists::where('user_id', $customer->id)->delete();
            Campaigns::where('user_id', $customer->id)->delete();
            ChatBox::where('user_id', $customer->id)->delete();
            ContactGroups::where('customer_id', $customer->id)->delete();
            Customer::where('user_id', $customer->id)->delete();
            Invoices::where('user_id', $customer->id)->delete();
            Keywords::where('user_id', $customer->id)->delete();
            Notifications::where('user_id', $customer->id)->delete();
            Plan::where('user_id', $customer->id)->delete();
            Reports::where('user_id', $customer->id)->delete();
            Senderid::where('user_id', $customer->id)->delete();
            SendingServer::where('user_id', $customer->id)->delete();
            Subscription::where('user_id', $customer->id)->delete();
            Templates::where('user_id', $customer->id)->delete();
            Announcements::where('user_id', $customer->id)->get()->each(function (Announcements $announcement) {
                $announcement->users()->detach();
                $announcement->delete();
            });

            if ( ! $customer->delete()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.exceptions.something_went_wrong'),
                ]);
            }

            return response()->json([
                'status'  => 'success',
                'message' => __('locale.customer.customer_successfully_deleted'),
            ]);

        }

        public function customerGenerator(): Generator
        {
            foreach (User::where('is_customer', 1)->join('customers', 'user_id', '=', 'users.id')->cursor() as $customer) {
                yield $customer;
            }
        }

        /**
         * @throws AuthorizationException
         */
        public function export(): BinaryFileResponse|RedirectResponse
        {

            if (config('app.stage') == 'demo') {
                return redirect()->route('admin.customers.index')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $this->authorize('edit customer');

            try {

                $file_name = (new FastExcel($this->customerGenerator()))->export(storage_path('Customers_' . time() . '.xlsx'));

                return response()->download($file_name);

            } catch (IOException|InvalidArgumentException|WriterNotOpenedException|UnsupportedTypeException $e) {
                return redirect()->route('admin.customers.index')->with([
                    'status'  => 'error',
                    'message' => $e->getMessage(),
                ]);
            }
        }

        /**
         * add custom unit
         *
         *
         * @throws GeneralException
         */
        public function addUnit(User $customer, AddUnitRequest $request): RedirectResponse
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('admin.customers.index')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            try {

                if ($customer->sms_unit != '-1') {

                    $balance = $customer->sms_unit + $request->input('add_unit');

                    if ($customer->update(['sms_unit' => $balance])) {

                        $subscription = $customer->customer->activeSubscription();

                        $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                            'end_at'                 => $subscription->end_at,
                            'current_period_ends_at' => $subscription->current_period_ends_at,
                            'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                            'title'                  => 'Add ' . $request->input('add_unit') . ' sms units',
                            'amount'                 => $request->input('add_unit') . ' sms units',
                        ]);

                        return redirect()->route('admin.customers.show', $customer->uid)->with([
                            'status'  => 'success',
                            'message' => __('locale.customer.add_unit_successful'),
                        ]);
                    }

                    throw new GeneralException(__('locale.exceptions.something_went_wrong'));
                }

                return redirect()->route('admin.customers.show', $customer->uid)->with([
                    'status'  => 'info',
                    'message' => 'You are already in unlimited plan',
                ]);

            } catch (ModelNotFoundException $exception) {
                return redirect()->route('admin.customers.show', $customer->uid)->with([
                    'status'  => 'error',
                    'message' => $exception->getMessage(),
                ]);
            }

        }

        /**
         * remove custom unit
         *
         *
         */
        public function removeUnit(User $customer, AddUnitRequest $request): RedirectResponse
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('admin.customers.index')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            try {

                if ($customer->sms_unit != '-1') {

                    $balance = $customer->sms_unit - $request->input('add_unit');

                    if ($balance < 0) {
                        return redirect()->route('admin.customers.show', $customer->uid)->with([
                            'status'  => 'error',
                            'message' => 'Sorry! You can remove maximum ' . $customer->sms_unit . ' unit',
                        ]);
                    }

                    if ($customer->update(['sms_unit' => $balance])) {

                        $subscription = $customer->customer->activeSubscription();

                        $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                            'end_at'                 => $subscription->end_at,
                            'current_period_ends_at' => $subscription->current_period_ends_at,
                            'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                            'title'                  => 'Remove ' . $request->input('add_unit') . ' sms units',
                            'amount'                 => $request->input('add_unit') . ' sms units',
                        ]);

                        return redirect()->route('admin.customers.show', $customer->uid)->with([
                            'status'  => 'success',
                            'message' => __('locale.customer.add_unit_successful'),
                        ]);
                    }

                    return redirect()->route('admin.customers.show', $customer->uid)->with([
                        'status'  => 'error',
                        'message' => __('locale.exceptions.something_went_wrong'),
                    ]);
                }

                return redirect()->route('admin.customers.show', $customer->uid)->with([
                    'status'  => 'info',
                    'message' => 'You are already in unlimited plan',
                ]);

            } catch (ModelNotFoundException $exception) {
                return redirect()->route('admin.customers.show', $customer->uid)->with([
                    'status'  => 'error',
                    'message' => $exception->getMessage(),
                ]);
            }

        }

        /*
        |--------------------------------------------------------------------------
        | Version 3.3
        |--------------------------------------------------------------------------
        |
        | Logged in as a customer option
        |
        */

        /**
         * @throws AuthorizationException
         */
        public function impersonate(User $customer): mixed
        {

            try {
                $this->authorize('edit customer');

                return $this->customers->impersonate($customer);
            } catch (AuthorizationException $exception) {
                return redirect()->route('admin.customers.index')->with([
                    'status'  => 'error',
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        /**
         * Show Pricing
         *
         * @return void
         *
         * @throws AuthorizationException
         */
        #[NoReturn]
        public function pricing(User $customer, Request $request)
        {

            $this->authorize('edit customer');

            if ($customer->customer->activeSubscription() !== null) {

                $columns = [
                    0 => 'responsive_id',
                    1 => 'uid',
                    2 => 'uid',
                    3 => 'name',
                    4 => 'iso_code',
                    5 => 'country_code',
                    6 => 'status',
                    7 => 'actions',
                ];

                $limit = $request->input('length');
                $start = $request->input('start');
                $order = $columns[$request->input('order.0.column')];
                $dir   = $request->input('order.0.dir');

                $totalData = CustomerBasedPricingPlan::where('user_id', $customer->id)->count();

                $totalFiltered = $totalData;

                if (empty($request->input('search.value'))) {
                    $countries = CustomerBasedPricingPlan::where('user_id', $customer->id)->offset($start)
                        ->limit($limit)
                        ->orderBy($order, $dir)
                        ->get();
                } else {
                    $search = $request->input('search.value');

                    $countries = CustomerBasedPricingPlan::where('user_id', $customer->id)->whereLike(['uid', 'country.name', 'country.iso_code', 'country.country_code'], $search)
                        ->offset($start)
                        ->limit($limit)
                        ->orderBy($order, $dir)
                        ->get();

                    $totalFiltered = CustomerBasedPricingPlan::where('user_id', $customer->id)->whereLike(['uid', 'country.name', 'country.iso_code', 'country.country_code'], $search)->count();
                }

                $data = [];
                if ( ! empty($countries)) {
                    foreach ($countries as $country) {

                        if ($country->status === true) {
                            $status = 'checked';
                        } else {
                            $status = '';
                        }

                        $nestedData['responsive_id'] = '';
                        $nestedData['uid']           = $country->uid;
                        $nestedData['name']          = $country->country->name;
                        $nestedData['country_code']  = $country->country->country_code;
                        $nestedData['iso_code']      = $country->country->iso_code;
                        $nestedData['status']        = "<div class='form-check form-switch form-check-primary'>
                <input type='checkbox' class='form-check-input get_coverage_status' id='status_$country->uid' data-id='$country->uid' name='status' $status>
                <label class='form-check-label' for='status_$country->uid'>
                  <span class='switch-icon-left'><i data-feather='check'></i> </span>
                  <span class='switch-icon-right'><i data-feather='x'></i> </span>
                </label>
              </div>";
                        $nestedData['edit']          = route('admin.customers.edit_coverage', ['customer' => $customer->uid, 'coverage' => $country->uid]);
                        $data[]                      = $nestedData;

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
        }

        /**
         * Add Coverage
         *
         * @return Application|Factory|\Illuminate\Contracts\View\View|\Illuminate\Foundation\Application
         */
        public function coverage(User $customer)
        {
            $breadcrumbs = [
                ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url(config('app.admin_path') . '/customers'), 'name' => __('locale.menu.Customer')],
                ['name' => __('locale.buttons.add_coverage')],
            ];

            $countries       = Country::where('status', 1)->get();
            $sending_servers = SendingServer::where('status', true)->get();

            return view('admin.customer._coverage', compact('breadcrumbs', 'countries', 'customer', 'sending_servers'));

        }

        /**
         * Post Coverage
         *
         * @return RedirectResponse
         *
         * @throws AuthorizationException
         */
        public function postCoverage(User $customer, AddCoverageRequest $request)
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('admin.customers.show', $customer->uid)->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $this->authorize('edit customer');

            $options = $request->except('_token', 'country', 'sending_server', 'voice_sending_server', 'mms_sending_server', 'whatsapp_sending_server', 'viber_sending_server', 'otp_sending_server');

            $options = array_map(function ($value) {
                return is_null($value) ? 0 : $value;
            }, $options);

            $countryIds = $request->input('country');

            if ($countryIds == 0) {
                $countryIds = Country::where('status', 1)->pluck('id')->toArray();
            }

            if (count($countryIds) == 0) {
                return redirect()->route('admin.customers.show', $customer->uid)->with([
                    'status'  => 'error',
                    'message' => 'Please select country',
                ]);
            }

            foreach ($countryIds as $country) {
                $exist = CustomerBasedPricingPlan::where('user_id', $customer->id)->where('country_id', $country)->first();
                if ($exist) {
                    continue;
                }

                CustomerBasedPricingPlan::create([
                    'user_id'                 => $customer->id,
                    'country_id'              => $country,
                    'plan_id'                 => $customer->customer->activeSubscription()->plan_id,
                    'options'                 => json_encode($options),
                    'sending_server'          => $request->input('sending_server'),
                    'voice_sending_server'    => $request->input('voice_sending_server'),
                    'mms_sending_server'      => $request->input('mms_sending_server'),
                    'whatsapp_sending_server' => $request->input('whatsapp_sending_server'),
                    'viber_sending_server'    => $request->input('viber_sending_server'),
                    'otp_sending_server'      => $request->input('otp_sending_server'),
                ]);

            }

            return redirect()->route('admin.customers.show', $customer->uid)->withInput(['tab' => 'usms_pricing'])->with([
                'status'  => 'success',
                'message' => __('locale.plans.coverage_was_successfully_added'),
            ]);
        }

        /**
         * Edit Coverage
         *
         * @return Application|Factory|\Illuminate\Contracts\View\View|\Illuminate\Foundation\Application
         *
         * @throws AuthorizationException
         */
        public function editCoverage(User $customer, CustomerBasedPricingPlan $coverage)
        {

            $this->authorize('edit customer');

            $breadcrumbs = [
                ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url(config('app.admin_path') . '/customers'), 'name' => __('locale.menu.Customer')],
                ['name' => __('locale.buttons.update_coverage')],
            ];

            $options         = json_decode($coverage->options, true);
            $sending_servers = SendingServer::where('status', true)->get();

            return view('admin.customer._coverage', compact('breadcrumbs', 'customer', 'options', 'coverage', 'sending_servers'));
        }

        /**
         *Update coverage post
         *
         *
         * @throws AuthorizationException
         */
        public function editCoveragePost(User $customer, CustomerBasedPricingPlan $coverage, UpdateCoverageRequest $request): RedirectResponse
        {

            if (config('app.stage') == 'demo') {
                return redirect()->route('admin.customers.edit_coverage', ['customer' => $customer->uid, 'coverage' => $coverage->uid])->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $this->authorize('edit customer');

            $get_options = json_decode($coverage->options, true);
            $output      = array_replace($get_options, $request->except('_token', 'country', 'sending_server', 'voice_sending_server', 'mms_sending_server', 'whatsapp_sending_server', 'viber_sending_server', 'otp_sending_server'));

            $output = array_map(function ($value) {
                return is_null($value) ? 0 : $value;
            }, $output);

            if ( ! $coverage->update([
                'options'                 => $output,
                'plan_id'                 => $customer->customer->activeSubscription()->plan_id,
                'sending_server'          => $request->input('sending_server'),
                'voice_sending_server'    => $request->input('voice_sending_server'),
                'mms_sending_server'      => $request->input('mms_sending_server'),
                'whatsapp_sending_server' => $request->input('whatsapp_sending_server'),
                'viber_sending_server'    => $request->input('viber_sending_server'),
                'otp_sending_server'      => $request->input('otp_sending_server'),
            ])) {
                return redirect()->route('admin.customers.edit_coverage', ['customer' => $customer->uid, 'coverage' => $coverage->uid])->with([
                    'status'  => 'error',
                    'message' => __('locale.exceptions.something_went_wrong'),
                ]);
            }

            return redirect()->route('admin.customers.show', $customer->uid)->withInput(['tab' => 'usms_pricing'])->with([
                'status'  => 'success',
                'message' => 'Coverage was successfully updated',
            ]);
        }

        /**
         * @throws GeneralException|AuthorizationException
         */
        public function activeCoverageToggle(User $customer, CustomerBasedPricingPlan $coverage): JsonResponse
        {
            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            try {
                $this->authorize('edit customer');

                if ($coverage->where('user_id', $customer->id)->find($coverage->id)->update(['status' => ! $coverage->status])) {
                    return response()->json([
                        'status'  => 'success',
                        'message' => __('locale.settings.status_successfully_change'),
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
         * @throws GeneralException|AuthorizationException
         */
        public function deleteCoverage(User $customer, CustomerBasedPricingPlan $coverage): JsonResponse
        {
            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            try {
                $this->authorize('edit customer');

                if ($coverage->where('user_id', $customer->id)->find($coverage->id)->delete()) {
                    return response()->json([
                        'status'  => 'success',
                        'message' => __('locale.plans.plan_successfully_deleted'),
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

        /*Version 3.8*/

        /**
         * Add Sending Server
         *
         * @return RedirectResponse
         */
        public function addSendingServer(User $customer, AddSendingServerRequest $request)
        {

            if (config('app.stage') == 'demo') {
                return redirect()->route('admin.customers.show', $customer->uid)->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            foreach ($request->input('sending_servers') as $server) {
                $exist = CustomerBasedSendingServer::where('user_id', $customer->id)->where('sending_server', $server)->first();
                if ($exist) {
                    continue;
                }

                CustomerBasedSendingServer::create([
                    'user_id'        => $customer->id,
                    'business_id'    => app(\App\Library\Business\LegacyBusinessResolver::class)->resolveForCustomer((int) $customer->id)?->id,
                    'sending_server' => $server,
                ]);

            }

            return redirect()->route('admin.customers.show', $customer->uid)->withInput(['tab' => 'usms_sending_server'])->with([
                'status'  => 'success',
                'message' => __('locale.sending_servers.sending_server_successfully_added'),
            ]);
        }

        /**
         * Delete Sending Server
         */
        public function deleteSendingServer(User $customer, DeleteSendingServerRequest $request): JsonResponse
        {
            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            try {

                $status = CustomerBasedSendingServer::where('user_id', $customer->id)->where('sending_server', $request->server_id)->delete();

                if ($status) {
                    return response()->json([
                        'status'  => 'success',
                        'message' => __('locale.sending_servers.sending_server_successfully_deleted'),
                    ]);
                }

                return response()->json([
                    'status'  => 'success',
                    'message' => __('locale.plans.plan_successfully_deleted'),
                ]);

            } catch (ModelNotFoundException $exception) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        public function dltEntityId(User $customer, DltEntityIdRequest $request)
        {

            if (config('app.stage') == 'demo') {
                return redirect()->route('admin.customers.show', $customer->uid)->withInput(['tab' => 'usms_dlt_entity_id'])->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }


            $customer->dlt_entity_id = $request->dlt_entity_id;
            $customer->save();

            return redirect()->route('admin.customers.show', $customer->uid)->withInput(['tab' => 'usms_dlt_entity_id'])->with([
                'status'  => 'success',
                'message' => 'DLT Entity ID was successfully updated',
            ]);

        }

        public function dltTelemarketerId(User $customer, DltTelemarketerIdRequest $request)
        {

            if (config('app.stage') == 'demo') {
                return redirect()->route('admin.customers.show', $customer->uid)->withInput(['tab' => 'usms_telemarketer_id'])->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }


            $customer->dlt_telemarketer_id = $request->dlt_telemarketer_id;
            $customer->save();

            return redirect()->route('admin.customers.show', $customer->uid)->withInput(['tab' => 'usms_telemarketer_id'])->with([
                'status'  => 'success',
                'message' => 'DLT Telemarketer ID was successfully updated',
            ]);

        }


        /*Version 3.13*/

        public function import(): View
        {
            $this->authorize('create customer');

            $breadcrumbs = [
                ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Customer')],
                ['name' => __('locale.customer.import_customers')],
            ];

            return view('admin.customer.import', compact('breadcrumbs'));

        }

        /**
         * @throws Exception
         */
        public function importPost(Request $request)
        {
            $this->authorize('create customer');


            $validator = Validator::make($request->all(), [
                'import_file' => ['required', 'file', 'max:' . (config('app.stage') == 'demo' ? 5 : 500000), new ExcelRule($request->file('import_file'))],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $validator->errors()->first(),
                ]);
            }


            $filepath = Auth::user()->uploadCsv($request->file('import_file'));

            // Redirect to my lists page
            return response()->json([
                'status'     => 'success',
                'message'    => __('locale.filezone.csv_uploaded'),
                'mappingUrl' => route('admin.customers.import-mapping', [
                    'filepath' => $filepath,
                ]),
            ]);

        }

        public function downloadCustomerSampleFile(): BinaryFileResponse
        {
            return response()->download(storage_path('app/import_customer_file_demo.csv'));
        }


        /**
         * @throws Exception
         */
        public function importMapping(Request $request): JsonResponse
        {
            $this->authorize('create customer');

            $filepath = $request->filepath;

            // 🧼 Force BOM cleaning before reading
            StringHelper::checkAndRemoveUTF8BOM($filepath);

            try {
                [$headers, $total, $results] = Auth::user()->readCsv($filepath);
            } catch (Throwable $e) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $e->getMessage(),
                ], 404);
            }

            $view = \Illuminate\Support\Facades\View::make('admin.customer.import.mapping', [
                'headers'  => $headers,
                'filepath' => $filepath,
            ])->render();

            return response()->json(['html' => $view]);
        }

        public function importRun(Request $request)
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $job = Auth::user()->dispatchImportJob($request->filepath, $request->input('mapping'));

            return response()->json([
                'status'      => 'success',
                'job_uid'     => $job->uid,
                'redirectUrl' => route('admin.customers.index'),
                'message'     => __('locale.customer.customers_successfully_imported_in_background'),
            ]);

        }

    }

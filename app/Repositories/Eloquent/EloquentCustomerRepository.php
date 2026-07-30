<?php

    namespace App\Repositories\Eloquent;

    use App\Helpers\Helper;
    use App\Models\Customer;

    use App\Models\User;
    use App\Notifications\WelcomeEmailNotification;
    use App\Repositories\Contracts\CustomerRepository;
    use Exception;
    use Illuminate\Config\Repository;
    use Illuminate\Support\Arr;
    use App\Exceptions\GeneralException;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Hash;
    use Illuminate\Support\Facades\Session;
    use Throwable;


    /**
     * Class EloquentCustomerRepository.
     */
    class EloquentCustomerRepository extends EloquentBaseRepository implements CustomerRepository
    {


        /**
         * @var Repository
         */
        protected Repository $config;

        /**
         * EloquentCustomerRepository constructor.
         *
         * @param User       $user
         * @param Repository $config
         */
        public function __construct(User $user, Repository $config)
        {
            parent::__construct($user);
            $this->config = $config;
        }

        /**
         * The model bound to this repository is User, not Customer (see the
         * constructor above), so this reads the customers table directly —
         * matching store()'s existing Customer::create() usage below rather
         * than $this->query(), which resolves against User.
         */
        public function findByUserId(int $userId): ?Customer
        {
            return Customer::query()->where('user_id', $userId)->first();
        }

        /**
         * @param array $input
         * @param bool  $confirmed
         *
         * @return User
         * @throws GeneralException
         *
         */
        public function store(array $input, bool $confirmed = false): User
        {

            /** @var User $user */
            $user = $this->make(Arr::only($input, ['first_name', 'last_name', 'email', 'status', 'timezone', 'locale']));

            if (empty($user->locale)) {
                $user->locale = $this->config->get('app.locale');
            }

            if (empty($user->timezone)) {
                $user->timezone = $this->config->get('app.timezone');
            }

            $user->email_verified_at = now();
            $user->is_admin          = false;
            $user->is_customer       = true;
            $user->active_portal     = 'customer';

            if ( ! $this->save($user, $input)) {
                throw new GeneralException(__('locale.exceptions.something_went_wrong'));
            }

            $customer = Customer::create([
                'user_id'       => $user->id,
                'phone'         => $input['phone'],
                'permissions'   => Customer::customerPermissions(),
                'notifications' => json_encode([
                    'login'        => 'no',
                    'sender_id'    => 'yes',
                    'keyword'      => 'yes',
                    'subscription' => 'yes',
                    'promotion'    => 'yes',
                    'profile'      => 'yes',
                ]),
            ]);

            if ($customer) {
                $permissions     = json_decode($user->customer->permissions, true);
                $user->api_token = $user->createToken($input['email'], $permissions)->plainTextToken;
                $user->save();

                return $user;
            }


            if (isset($input['welcome_message'])) {
                $user->notify(new WelcomeEmailNotification($user->first_name, $user->last_name, $user->email, route('login'), $input['password']));
            }

            return $user;
        }


        /**
         * @param User  $customer
         * @param array $input
         *
         * @return User
         * @throws GeneralException
         */
        public function update(User $customer, array $input): User
        {

            $customer->fill(Arr::except($input, 'password'));

            if ( ! $this->save($customer, $input)) {
                throw new GeneralException(__('locale.exceptions.something_went_wrong'));
            }

            return $customer;
        }

        /**
         * @param User  $user
         * @param array $input
         *
         * @return bool
         */
        private function save(User $user, array $input): bool
        {
            if ( ! empty($input['password'])) {
                $user->password = Hash::make($input['password']);
            }

            if ( ! $user->save()) {
                return false;
            }

            return true;
        }

        /**
         * update user information
         *
         * @param User  $customer
         * @param array $input
         *
         * @return User
         * @throws GeneralException
         */
        public function updateInformation(User $customer, array $input): User
        {
            $get_customer = Customer::where('user_id', $customer->id)->first();

            if ( ! $get_customer) {
                throw new GeneralException(__('locale.exceptions.something_went_wrong'));
            }

            if (isset($input['notifications']) && count($input['notifications']) > 0) {

                $defaultNotifications = [
                    'login'        => 'no',
                    'sender_id'    => 'no',
                    'keyword'      => 'no',
                    'subscription' => 'no',
                    'promotion'    => 'no',
                    'profile'      => 'no',
                ];

                $notifications          = array_merge($defaultNotifications, $input['notifications']);
                $input['notifications'] = json_encode($notifications);
            }

            $data = $get_customer->update($input);

            if ( ! $data) {
                throw new GeneralException(__('locale.exceptions.something_went_wrong'));
            }

            return $customer;
        }


        /**
         * update permissions
         *
         * @param User  $customer
         * @param array $input
         *
         * @return User
         * @throws GeneralException
         */
        public function permissions(User $customer, array $input): User
        {
            $data = array_values($input['permissions']);

            $status = $customer->customer()->update([
                'permissions' => json_encode($data),
            ]);

            if ( ! $status) {
                throw new GeneralException(__('locale.exceptions.something_went_wrong'));
            }

            return $customer;
        }


        /**
         * @param User $customer
         *
         * @return bool
         * @throws GeneralException
         */
        public function destroy(User $customer): bool
        {
            if ( ! $customer->can_delete) {
                throw new GeneralException(__('locale.exceptions.first_user_cannot_be_destroyed'));
            }

            if ( ! $customer->delete()) {
                throw new GeneralException(__('locale.exceptions.delete'));
            }

            return true;
        }


        /**
         * @param array $ids
         * @return bool
         * @throws Exception|Throwable
         */
        public function batchEnable(array $ids): bool
        {
            DB::transaction(function () use ($ids) {
                $superAdminUid = $this->query()->where('id', 1)->value('uid');

                if (in_array($superAdminUid, $ids, true)) {
                    throw new GeneralException(__('locale.exceptions.super_admin_protect'));
                }

                if ($this->query()->whereIn('uid', $ids)->update(['status' => true])) {
                    return true;
                }

                throw new GeneralException(__('locale.exceptions.update'));
            });

            return true;
        }

        /**
         * @param array $ids
         * @return bool
         * @throws Exception|Throwable
         */
        public function batchDisable(array $ids): bool
        {
            DB::transaction(function () use ($ids) {
                $superAdminUid = $this->query()->where('id', 1)->value('uid');

                if (in_array($superAdminUid, $ids, true)) {
                    throw new GeneralException(__('locale.exceptions.super_admin_protect'));
                }

                if ($this->query()->whereIn('uid', $ids)->update(['status' => false])) {
                    return true;
                }

                throw new GeneralException(__('locale.exceptions.update'));
            });

            return true;
        }

        /**
         * @param array $ids
         * @return bool
         * @throws Exception|Throwable
         */
        public function batchDelete(array $ids): bool
        {
            DB::transaction(function () use ($ids) {
                $superAdminUid = $this->query()->where('id', 1)->value('uid');

                if (in_array($superAdminUid, $ids, true)) {
                    throw new GeneralException(__('locale.exceptions.super_admin_delete'));
                }

                if ($this->query()->whereIn('uid', $ids)->delete()) {
                    return true;
                }

                throw new GeneralException(__('locale.exceptions.delete'));
            });

            return true;
        }



        /*
        |--------------------------------------------------------------------------
        | Version 3.3
        |--------------------------------------------------------------------------
        |
        | Logged in as customer
        |
        */


        /**
         * @throws GeneralException
         */
        public function impersonate(User $customer)
        {
            $authUser = auth()->user();

            // Only super admin can impersonate admin accounts
            if ($customer->is_admin && $authUser->id !== 1) {

                return redirect()->route('admin.customers.index')->with([
                    'status'  => 'error',
                    'message' => 'Only super admin can impersonate admin accounts.',
                ]);
            }

            if ($authUser->id === $customer->id || Session::get('admin_user_id') === $customer->id) {
                return redirect()->route('admin.home');
            }

            if ( ! Session::get('admin_user_id')) {
                session([
                    'admin_user_id'   => $authUser->id,
                    'admin_user_name' => $authUser->displayName(),
                    'temp_user_id'    => $customer->id,
                    'permissions'     => collect(json_decode(optional($customer->customer)->permissions ?? '[]', true)),
                ]);

                $customer->update([
                    'active_portal' => 'customer',
                ]);
            }

            auth()->loginUsingId($customer->id);

            return redirect(Helper::home_route());
        }


        public function getCustomerStats()
        {
            return $this->query()->select(
                DB::raw('COUNT(*) as total_customers'),
                DB::raw('SUM(sms_unit) as total_user_balances'),
                DB::raw('SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as active_customers'),
                DB::raw('SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) as inactive_customers')
            )->limit(1)->where('is_customer', 1)->get()->first();
        }

    }

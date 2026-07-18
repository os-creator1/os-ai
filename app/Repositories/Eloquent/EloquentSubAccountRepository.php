<?php

    namespace App\Repositories\Eloquent;

    use App\Mail\SubAccountInvitation;
    use App\Models\Customer;

    use App\Models\EmailTemplates;
    use App\Models\User;
    use App\Repositories\Contracts\SubAccountRepository;
    use Exception;
    use Illuminate\Config\Repository;
    use Illuminate\Support\Arr;
    use App\Exceptions\GeneralException;
    use Illuminate\Support\Facades\Auth;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Hash;
    use Illuminate\Support\Facades\Mail;
    use Illuminate\Support\Str;
    use Throwable;


    /**
     * Class EloquentSubAccountRepository.
     */
    class EloquentSubAccountRepository extends EloquentBaseRepository implements SubAccountRepository
    {


        /**
         * @var Repository
         */
        protected Repository $config;

        /**
         * EloquentSubAccountRepository constructor.
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
            $user  = $this->make(Arr::only($input, ['first_name', 'last_name', 'email']));
            $token = Str::random(64);

            $user->email_verified_at = now();
            $user->parent_id         = Auth::user()->id;
            $user->is_admin          = false;
            $user->is_customer       = true;
            $user->active_portal     = 'customer';
            $user->timezone          = Auth::user()->timezone;
            $user->locale            = Auth::user()->locale;
            $user->status            = false;
            $user->invitation_token  = $token;

            if ( ! $this->save($user, $input)) {
                throw new GeneralException(__('locale.exceptions.something_went_wrong'));
            }


            $permissionsData = array_values($input['permissions']);

            $subAccount = Customer::create([
                'user_id'       => $user->id,
                'permissions'   => json_encode($permissionsData),
                'notifications' => json_encode([
                    'login'        => 'no',
                    'sender_id'    => 'yes',
                    'keyword'      => 'yes',
                    'subscription' => 'yes',
                    'promotion'    => 'yes',
                    'profile'      => 'yes',
                ]),
            ]);

            if ($subAccount) {

                $permissions = json_decode($user->customer->permissions, true);

                $user->api_token = $user->createToken($input['email'], $permissions)->plainTextToken;
                $user->save();

                $template = EmailTemplates::where('slug', 'subaccount_invitation_notification')->first();

                if (config('mail.from.address') && config('mail.from.name') && $template) {

                    $invitationLink = route('sub_account.accept', ['token' => $token]); // token-based

                    Mail::to($user->email)->send(new SubAccountInvitation($user->first_name, $user->last_name, $invitationLink));
                }

                return $user;
            }

            return $user;
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
         * @param User  $subAccount
         * @param array $input
         *
         * @return User
         * @throws GeneralException
         */
        public function update(User $subAccount, array $input): User
        {
            // Fill the sub-account model with the remaining data (excluding password and permissions).
            $subAccount->fill(Arr::except($input, ['password']));

            if ( ! $subAccount->save()) {
                throw new GeneralException(__('locale.exceptions.something_went_wrong'));
            }


            $permissions = array_values($input['permissions']) ?? [];

            $subAccount->customer()->update([
                'permissions' => json_encode($permissions),
            ]);

            return $subAccount;
        }


        /**
         * @param array $ids
         *
         * @return mixed
         * @throws Exception|Throwable
         *
         */
        public function batchEnable(array $ids): bool
        {
            DB::transaction(function () use ($ids) {
                if ($this->query()->whereIn('uid', $ids)
                    ->update(['status' => true])
                ) {
                    return true;
                }

                throw new GeneralException(__('locale.exceptions.update'));
            });

            return true;
        }

        /**
         * @param array $ids
         *
         * @return mixed
         * @throws Exception|Throwable
         *
         */
        public function batchDisable(array $ids): bool
        {
            DB::transaction(function () use ($ids) {
                if ($this->query()->whereIn('uid', $ids)
                    ->update(['status' => false])
                ) {
                    return true;
                }

                throw new GeneralException(__('locale.exceptions.update'));
            });

            return true;
        }


        /**
         * @param array $ids
         *
         * @return mixed
         * @throws Exception|Throwable
         *
         */
        public function batchDelete(array $ids): bool
        {
            DB::transaction(function () use ($ids) {
                if ($this->query()->whereIn('uid', $ids)
                    ->delete()
                ) {
                    return true;
                }

                throw new GeneralException(__('locale.exceptions.delete'));
            });

            return true;

        }

    }

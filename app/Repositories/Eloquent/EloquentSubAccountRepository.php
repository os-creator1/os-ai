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
    use Illuminate\Validation\ValidationException;
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

            $grantable = $this->permissionsWithinParentCeiling($input['permissions'] ?? []);

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


            $permissionsData = $grantable;

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
         * Release-risk closure item 5 — the privilege ceiling.
         *
         * A parent may grant a sub-account only permissions the parent itself
         * holds (its own `customers.permissions`) AND that are real, defined
         * application permissions (config/customer-permissions). Anything else
         * — a permission the parent lacks, an unknown key such as an admin or
         * platform capability, a non-string — is refused outright with a
         * validation error rather than silently trimmed, so a forged request
         * can never obtain more than the form could have offered. This is the
         * single choke point for both store() and update(); the sub-account
         * routes already tenant-scope the target (ownedSubAccountOrAbort()).
         *
         * @param  mixed  $requested the submitted `permissions` array
         * @return list<string>
         *
         * @throws ValidationException
         */
        private function permissionsWithinParentCeiling(mixed $requested): array
        {
            $requested = is_array($requested) ? array_values($requested) : [];

            // What the parent actually holds right now: the permission set its
            // session authorizes with (loaded from customers.permissions at
            // login, and what every `can:` gate reads), else the stored set.
            $held = session('permissions');
            $ceiling = $held !== null
                ? collect($held)->all()
                : json_decode((string) optional(Auth::user()?->customer)->permissions, true);
            $ceiling = is_array($ceiling) ? $ceiling : [];
            $defined = array_keys((array) config('customer-permissions'));

            foreach ($requested as $permission) {
                if (! is_string($permission)
                    || ! in_array($permission, $ceiling, true)
                    || ! in_array($permission, $defined, true)) {
                    throw ValidationException::withMessages([
                        'permissions' => __('locale.exceptions.something_went_wrong'),
                    ]);
                }
            }

            return array_values(array_unique($requested));
        }

        /**
         * Sub-accounts the acting customer owns; every batch mutation must go through this.
         */
        private function ownedQuery()
        {
            return $this->query()->where('parent_id', Auth::id());
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
            // Fail closed BEFORE any write: the permission set is checked against
            // the acting parent's own ceiling first.
            $permissions = $this->permissionsWithinParentCeiling($input['permissions'] ?? []);

            // Fill the sub-account model with the remaining data (excluding password and permissions).
            // Whitelist: never mass-assign is_admin, status, parent_id, api_token, ... from the request.
            $subAccount->fill(Arr::only($input, ['first_name', 'last_name', 'email']));

            if ( ! $subAccount->save()) {
                throw new GeneralException(__('locale.exceptions.something_went_wrong'));
            }


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
                if ($this->ownedQuery()->whereIn('uid', $ids)
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
                if ($this->ownedQuery()->whereIn('uid', $ids)
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
                if ($this->ownedQuery()->whereIn('uid', $ids)
                    ->delete()
                ) {
                    return true;
                }

                throw new GeneralException(__('locale.exceptions.delete'));
            });

            return true;

        }

    }

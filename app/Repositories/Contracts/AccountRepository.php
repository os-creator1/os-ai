<?php

    namespace App\Repositories\Contracts;

    use App\Models\User;
    use Illuminate\Contracts\Auth\Authenticatable;

    /**
     * Interface AccountRepository.
     */
    interface AccountRepository extends BaseRepository
    {
        /**
         * @param array $input
         *
         * @return mixed
         */
        public function register(array $input);


        /**
         * @param $provider
         * @param $data
         *
         * @return mixed
         */
        public function findOrCreateSocial($provider, $data);

        /**
         * An existing account for this provider identity (or a safely linked one), or the verified identity of someone with no account
         * yet. Never creates a User.
         *
         * @return array{user: mixed, identity: ?array<string, mixed>}
         */
        public function resolveSocialIdentity($provider, $data): array;

        /**
         * @param Authenticatable                            $user
         * @param                                            $name
         *
         * @return bool
         */
        public function hasPermission(Authenticatable $user, $name): bool;

        /**
         * The durable permission set hasPermission() decides from, read without
         * the session copy — see the implementation for why that distinction
         * matters to Implementation Contract 19 §5.8.
         *
         * @return \Illuminate\Support\Collection<int, string>
         */
        public function durablePermissions(Authenticatable $user, bool $fresh = false): \Illuminate\Support\Collection;

        /**
         * @param array $input
         *
         * @return mixed
         */
        public function update(array $input);

        /**
         * @return mixed
         */
        public function delete();


        /**
         * @param Authenticatable $user
         *
         * @return mixed
         */
        public function redirectAfterLogin(Authenticatable $user);

        public function payPayment(array $input);


        /**
         * @param User $customer
         *
         * @return mixed
         */
        public function impersonate(User $customer);

    }

<?php

    namespace App\Repositories\Contracts;

    use App\Models\User;

    /**
     * Interface CustomerRepository.
     */
    interface SubAccountRepository extends BaseRepository
    {

        /**
         * @param array $input
         * @param bool  $confirmed
         *
         * @return mixed
         */
        public function store(array $input, bool $confirmed = false);


        /**
         * @param User  $subAccount
         * @param array $input
         *
         * @return mixed
         */
        public function update(User $subAccount, array $input);


        /**
         * @param array $ids
         *
         * @return mixed
         */
        public function batchEnable(array $ids);

        /**
         * @param array $ids
         *
         * @return mixed
         */
        public function batchDisable(array $ids);


        /**
         * @param array $ids
         *
         * @return mixed
         */
        public function batchDelete(array $ids);


    }

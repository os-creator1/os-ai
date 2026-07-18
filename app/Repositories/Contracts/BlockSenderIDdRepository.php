<?php

    namespace App\Repositories\Contracts;

    /* *
     * Interface BlockSenderIDdRepository
     */

    use App\Models\BlockSenderId;

    interface BlockSenderIDdRepository extends BaseRepository
    {

        /**
         * @param array $input
         *
         * @return mixed
         */
        public function store(array $input);

        /**
         * @param BlockSenderId $sender_id
         *
         * @return mixed
         */

        public function destroy(BlockSenderId $sender_id);

        /**
         * @param array $ids
         *
         * @return mixed
         */
        public function batchDestroy(array $ids);

    }

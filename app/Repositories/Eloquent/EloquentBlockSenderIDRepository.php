<?php

    namespace App\Repositories\Eloquent;

    use App\Exceptions\GeneralException;
    use App\Models\BlockSenderId;
    use App\Repositories\Contracts\BlockSenderIDdRepository;
    use Exception;
    use Illuminate\Support\Arr;
    use Illuminate\Support\Facades\DB;
    use Throwable;

    class EloquentBlockSenderIDRepository extends EloquentBaseRepository implements BlockSenderIDdRepository
    {
        /**
         * EloquentBlockSenderIDRepository constructor.
         *
         * @param BlockSenderID $sender_id
         */
        public function __construct(BlockSenderID $sender_id)
        {
            parent::__construct($sender_id);
        }

        /**
         * @param array $input
         *
         * @return BlockSenderID
         * @throws GeneralException
         */
        public function store(array $input): BlockSenderID
        {

            /** @var BlockSenderID $sender_id */
            $sender_id = $this->make(Arr::only($input, [
                'sender_id',
                'reason',
            ]));

            if ( ! $this->save($sender_id)) {
                throw new GeneralException(__('locale.exceptions.something_went_wrong'));
            }

            return $sender_id;
        }


        /**
         * @param BlockSenderID $sender_id
         *
         * @return bool
         */
        private function save(BlockSenderID $sender_id): bool
        {
            if ( ! $sender_id->save()) {
                return false;
            }

            return true;
        }

        /**
         * @param BlockSenderID $sender_id
         *
         * @return bool
         * @throws GeneralException
         */
        public function destroy(BlockSenderID $sender_id): bool
        {
            if ( ! $sender_id->delete()) {
                throw new GeneralException(__('locale.exceptions.something_went_wrong'));
            }

            return true;
        }

        /**
         * @param array $ids
         *
         * @return mixed
         * @throws Exception|Throwable
         *
         */
        public function batchDestroy(array $ids): bool
        {
            DB::transaction(function () use ($ids) {
                // This wont call eloquent events, change to destroy if needed
                if ($this->query()->whereIn('uid', $ids)->delete()) {
                    return true;
                }

                throw new GeneralException(__('locale.exceptions.something_went_wrong'));
            });

            return true;
        }

    }

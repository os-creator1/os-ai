<?php

    namespace App\Repositories\Contracts;

    /* *
     * Interface PlanRepository
     */

    use App\Models\Plan;

    interface PlanRepository extends BaseRepository
    {
        /**
         * @return mixed
         */
        public function store(array $input, array $options, array $billingCycle);

        /**
         * @return mixed
         */
        public function update(Plan $plan, array $input, array $billingCycle);

        /**
         * @return mixed
         */
        public function destroy(Plan $plan);

        /**
         * @return mixed
         */
        public function batchDestroy(array $ids);

        /**
         * @return mixed
         */
        public function batchActive(array $ids);

        /**
         * @return mixed
         */
        public function batchDisable(array $ids);

        /**
         * update speed limit
         *
         *
         * @return mixed
         */
        public function updateSpeedLimits(Plan $plan, array $input);

        /**
         * update sms pricing
         *
         *
         * @return mixed
         */
        public function updatePricing(Plan $plan, array $input);

        /**
         * copy existing plan
         *
         *
         * @return mixed
         */
        public function copy(Plan $plan, array $input);

        /**
         * Update Sender ID for Customer as Default sender id
         *
         *
         * @return mixed
         */
        public function updateSenderID(Plan $plan, array $post_data);

        public function updateCreditPrice(Plan $plan, array $post_data);

        /**
         * Batch enable coverage for a given array of ids
         *
         * @return mixed
         */

        public function batchCoverageEnable(Plan $plan, array $ids);


        /**
         * Batch disable coverage for a given array of ids
         *
         * @return mixed
         */
        public function batchCoverageDisable(Plan $plan, array $ids);

        /**
         * Batch delete coverage for a given array of ids
         *
         * @return mixed
         */
        public function batchCoverageDelete(Plan $plan, array $ids);

    }

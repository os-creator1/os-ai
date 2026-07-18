<?php

    namespace Database\Seeders;

    use App\Models\SenderidPlan;
    use Illuminate\Database\Seeder;

    class SenderIdPlanSeeder extends Seeder
    {
        /**
         * Run the database seeds.
         */
        public function run(): void
        {
            SenderidPlan::truncate();

            $sender_ids_plan = [
                [
                    'price'            => 0,
                    'billing_cycle'    => 'monthly',
                    'frequency_amount' => '1',
                    'frequency_unit'   => 'month',
                    'currency_id'      => 1,
                ],
                [
                    'price'            => 5,
                    'billing_cycle'    => 'custom',
                    'frequency_amount' => '2',
                    'frequency_unit'   => 'month',
                    'currency_id'      => 1,
                ],
                [
                    'price'            => 12,
                    'billing_cycle'    => 'custom',
                    'frequency_amount' => '3',
                    'frequency_unit'   => 'month',
                    'currency_id'      => 1,
                ],
                [
                    'price'            => 20,
                    'billing_cycle'    => 'custom',
                    'frequency_amount' => '6',
                    'frequency_unit'   => 'month',
                    'currency_id'      => 1,
                ],
                [
                    'price'            => 35,
                    'billing_cycle'    => 'yearly',
                    'frequency_amount' => '1',
                    'frequency_unit'   => 'year',
                    'currency_id'      => 1,
                ],
                [
                    'price'            => 65,
                    'billing_cycle'    => 'custom',
                    'frequency_amount' => '2',
                    'frequency_unit'   => 'year',
                    'currency_id'      => 1,
                ],
                [
                    'price'            => 115,
                    'billing_cycle'    => 'custom',
                    'frequency_amount' => '3',
                    'frequency_unit'   => 'year',
                    'currency_id'      => 1,
                ],
            ];

            foreach ($sender_ids_plan as $plan) {
                SenderidPlan::create($plan);
            }

        }

    }

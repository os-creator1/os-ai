<?php

    namespace Database\Seeders;


    use App\Models\Plan;
    use App\Models\PlanSendingCreditPrice;
    use App\Models\PlansSendingServer;
    use Illuminate\Database\Seeder;
    use Illuminate\Support\Facades\DB;

    class PlanSeeder extends Seeder
    {
        /**
         * Run the database seeders.
         *
         * @return void
         */
        public function run()
        {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            DB::table('plans')->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            $plans = [
                [
                    'user_id'              => 1,
                    'currency_id'          => 1,
                    'name'                 => 'Free',
                    'description'          => 'A simple start for everyone',
                    'price'                => '0',
                    'billing_cycle'        => 'monthly',
                    'frequency_amount'     => 1,
                    'frequency_unit'       => 'month',
                    'options'              => '{"sms_max":"5","list_max":"5","subscriber_max":"500","subscriber_per_list_max":"100","segment_per_list_max":"3","billing_cycle":"monthly","sending_limit":"50000_per_hour","sending_quota":"100","sending_quota_time":"1","sending_quota_time_unit":"hour","max_process":"1","list_import":"yes","list_export":"yes","api_access":"no","create_sub_account":"no","delete_sms_history":"no","add_previous_balance":"no","sender_id_verification":"yes","send_spam_message":"no"}',
                    'status'               => false,
                    'tax_billing_required' => false,
                ],
                [
                    'user_id'              => 1,
                    'currency_id'          => 1,
                    'name'                 => 'Standard',
                    'description'          => 'For small to medium businesses',
                    'price'                => '49',
                    'billing_cycle'        => 'monthly',
                    'frequency_amount'     => 1,
                    'frequency_unit'       => 'month',
                    'is_popular'           => true,
                    'options'              => '{"sms_max":"6000","list_max":"-1","subscriber_max":"-1","subscriber_per_list_max":"5000","segment_per_list_max":"3","billing_cycle":"monthly","sending_limit":"50000_per_hour","sending_quota":"600","sending_quota_time":"1","sending_quota_time_unit":"minute","max_process":"1","list_import":"yes","list_export":"yes","api_access":"yes","create_sub_account":"yes","delete_sms_history":"yes","add_previous_balance":"yes","sender_id_verification":"yes","send_spam_message":"no"}',
                    'status'               => false,
                    'tax_billing_required' => true,
                ],
                [
                    'user_id'              => 1,
                    'currency_id'          => 10,
                    'name'                 => 'DLT Enabled',
                    'description'          => 'TRAI - Distributed Ledger Technology (DLT)',
                    'price'                => '1300',
                    'billing_cycle'        => 'yearly',
                    'frequency_amount'     => 1,
                    'frequency_unit'       => 'year',
                    'options'              => '{"sms_max":"5000","list_max":"-1","subscriber_max":"-1","subscriber_per_list_max":"5000","segment_per_list_max":"3","billing_cycle":"monthly","sending_limit":"50000_per_hour","sending_quota":"600","sending_quota_time":"1","sending_quota_time_unit":"minute","max_process":"1","list_import":"yes","list_export":"yes","api_access":"yes","create_sub_account":"yes","delete_sms_history":"yes","add_previous_balance":"yes","sender_id_verification":"yes","send_spam_message":"no"}',
                    'status'               => false,
                    'is_dlt'               => true,
                    'tax_billing_required' => true,
                ],
                [
                    'user_id'              => 1,
                    'currency_id'          => 1,
                    'name'                 => 'Enterprise',
                    'description'          => 'Solution for big organizations',
                    'price'                => '99',
                    'billing_cycle'        => 'monthly',
                    'frequency_amount'     => 1,
                    'frequency_unit'       => 'month',
                    'options'              => '{"sms_max":"15000","list_max":"-1","subscriber_max":"-1","subscriber_per_list_max":"10000","segment_per_list_max":"-1","billing_cycle":"monthly","sending_limit":"50000_per_hour","sending_quota":"600","sending_quota_time":"1","sending_quota_time_unit":"minute","max_process":"3","list_import":"yes","list_export":"yes","api_access":"yes","create_sub_account":"yes","delete_sms_history":"yes","add_previous_balance":"yes","sender_id_verification":"yes","send_spam_message":"yes"}',
                    'status'               => false,
                    'tax_billing_required' => true,
                ],
            ];

            foreach ($plans as $plan) {
                Plan::create($plan);
            }


            PlanSendingCreditPrice::truncate();

            $plans = [
                1 => 'free',
                2 => 'standard',
                4 => 'premium',
            ];

            foreach ($plans as $planId => $planType) {
                $planCreditPricing = [];

                for ($i = 1; $i <= 8; $i++) {
                    $unitFrom = ($i - 1) * 150000 + 1;
                    $unitTo   = $i * 150000;

                    $planCreditPricing[] = [
                        'uid'             => uniqid(),
                        'plan_id'         => $planId,
                        'unit_from'       => $unitFrom,
                        'unit_to'         => $unitTo,
                        'per_credit_cost' => 0.0079 - ($i * 0.0002), // Example calculation, adjust as needed
                        'created_at'      => now(),
                        'updated_at'      => now(),
                    ];
                }

                PlanSendingCreditPrice::insert($planCreditPricing);
            }


            $dltCreditPricing = [
                [
                    'uid'             => uniqid(),
                    'plan_id'         => 3,
                    'unit_from'       => 1,
                    'unit_to'         => 2999,
                    'per_credit_cost' => 0.24,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ],
                [
                    'uid'             => uniqid(),
                    'plan_id'         => 3,
                    'unit_from'       => 3000,
                    'unit_to'         => 9999,
                    'per_credit_cost' => 0.22,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ],
                [
                    'uid'             => uniqid(),
                    'plan_id'         => 3,
                    'unit_from'       => 10000,
                    'unit_to'         => 24999,
                    'per_credit_cost' => 0.19,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ],
                [
                    'uid'             => uniqid(),
                    'plan_id'         => 3,
                    'unit_from'       => 25000,
                    'unit_to'         => 49999,
                    'per_credit_cost' => 0.18,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ],
                [
                    'uid'             => uniqid(),
                    'plan_id'         => 3,
                    'unit_from'       => 50000,
                    'unit_to'         => 99999,
                    'per_credit_cost' => 0.17,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ],
                [
                    'uid'             => uniqid(),
                    'plan_id'         => 3,
                    'unit_from'       => 100000,
                    'unit_to'         => 499999,
                    'per_credit_cost' => 0.16,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ],
                [
                    'uid'             => uniqid(),
                    'plan_id'         => 3,
                    'unit_from'       => 500000,
                    'unit_to'         => 999999,
                    'per_credit_cost' => 0.14,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ],
                [
                    'uid'             => uniqid(),
                    'plan_id'         => 3,
                    'unit_from'       => 1000000,
                    'unit_to'         => 9999999,
                    'per_credit_cost' => 0.13,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ],
            ];

            PlanSendingCreditPrice::insert($dltCreditPricing);
        }

    }

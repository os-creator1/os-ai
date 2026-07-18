<?php

    namespace App\Console\Commands;

    use App\Helpers\Helper;
    use App\Library\Tool;
    use App\Models\Campaigns;
    use App\Models\Notifications;
    use App\Models\SendingServer;
    use App\Models\Subscription;
    use App\Models\User;
    use App\Notifications\SMSUnitRunningLow;
    use App\Notifications\SubscriptionExpire;
    use Illuminate\Console\Command;
    use Illuminate\Http\Client\ConnectionException;
    use Illuminate\Support\Carbon;

    class CheckUserPreferences extends Command
    {
        /**
         * The name and signature of the console command.
         *
         * @var string
         */
        protected $signature = 'user:preferences';

        /**
         * The console command description.
         *
         * @var string
         */
        protected $description = 'Check user preferences settings';

        /**
         * Create a new command instance.
         *
         * @return void
         */
        public function __construct()
        {
            parent::__construct();
        }

        /**
         * Execute the console command.
         * @throws ConnectionException
         */
        public function handle(): int
        {

            $users = User::where('is_customer', 1)->where('status', 1)->get();

            foreach ($users as $user) {
                if (isset($user->customer) && $user->customer->activeSubscription() !== null) {

                    $subscription = $user->customer->activeSubscription();
                    $remaining    = $user->sms_unit;

                    if ( ! array_key_exists('send_warning', $subscription->getOptions())) {
                        $get_options = json_decode($subscription->options, true);
                        $output      = array_replace($get_options, [
                            'send_warning' => false,
                        ]);

                        $subscription->update([
                            'options' => json_encode($output),
                        ]);
                    }

                    if ($subscription->getOption('subscription_warning') == 1 && ! $subscription->getOption('send_warning')) {
                        $check = Subscription::where('user_id', $user->id)
                            ->whereRaw('date(current_period_ends_at) =?', Carbon::now()->addDays($subscription->end_period_last_days)->toDateString())
                            ->first();

                        if ($check) {

                            Notifications::create([
                                'user_id'           => $user->id,
                                'notification_for'  => 'customer',
                                'notification_type' => 'subscription',
                                'message'           => __('locale.labels.subscription_ends', ['end_date' => Tool::customerDateTime($check->current_period_ends_at)]),
                            ]);

                            if ($subscription->getOption('subscription_notify') == 'both') {
                                $user->notify(new SubscriptionExpire($subscription));

                                $sending_server = SendingServer::where('status', true)->where('uid', Helper::app_config('notification_sms_gateway'))->first();
                                if ($sending_server && isset($user->customer->phone)) {

                                    $input = [
                                        'sender_id'      => Helper::app_config('notification_sender_id'),
                                        'phone'          => $user->customer->phone,
                                        'sending_server' => $sending_server,
                                        'user_id'        => 1,
                                        'sms_type'       => 'plain',
                                        'status'         => null,
                                        'cost'           => 1,
                                        'sms_count'      => 1,
                                        'message'        => __('locale.labels.subscription_ends', ['end_date' => Tool::customerDateTime($check->current_period_ends_at)]),
                                    ];

                                    $campaign = new Campaigns();
                                    $campaign->sendPlainSMS($input);
                                }

                            } else {

                                if ($subscription->getOption('subscription_notify') == 'email') {
                                    $user->notify(new SubscriptionExpire($subscription));
                                }

                                if ($subscription->getOption('subscription_notify') == 'sms') {
                                    $sending_server = SendingServer::where('status', true)->where('uid', Helper::app_config('notification_sms_gateway'))->first();
                                    if ($sending_server && isset($user->customer->phone)) {

                                        $input = [
                                            'sender_id'      => Helper::app_config('notification_sender_id'),
                                            'phone'          => $user->customer->phone,
                                            'sending_server' => $sending_server,
                                            'sms_type'       => 'plain',
                                            'status'         => null,
                                            'user_id'        => 1,
                                            'cost'           => 1,
                                            'sms_count'      => 1,
                                            'message'        => __('locale.labels.subscription_ends', ['end_date' => Tool::customerDateTime($check->current_period_ends_at)]),
                                        ];

                                        $campaign = new Campaigns();
                                        $campaign->sendPlainSMS($input);
                                    }

                                }
                            }


                            $get_options = json_decode($subscription->options, true);
                            $output      = array_replace($get_options, [
                                'send_warning' => true,
                            ]);

                            $subscription->update([
                                'options' => json_encode($output),
                            ]);

                        }

                    }

                    if ($subscription->getOption('credit_warning') == 1 && $user->sms_unit != '-1' && ! $subscription->getOption('send_warning')) {

                        if ($user->sms_unit < $subscription->getOption('credit')) {

                            Notifications::create([
                                'user_id'           => $user->id,
                                'notification_for'  => 'customer',
                                'notification_type' => 'subscription',
                                'message'           => __('locale.labels.sms_unit_running_low', ['remaining_unit' => $remaining]),
                            ]);

                            if ($subscription->getOption('credit_notify') == 'both') {
                                $user->notify(new SMSUnitRunningLow($user->sms_unit));

                                $sending_server = SendingServer::where('status', true)->where('uid', Helper::app_config('notification_sms_gateway'))->first();
                                if ($sending_server && isset($user->customer->phone)) {

                                    $input = [
                                        'sender_id'      => Helper::app_config('notification_sender_id'),
                                        'phone'          => $user->customer->phone,
                                        'sending_server' => $sending_server,
                                        'user_id'        => 1,
                                        'sms_type'       => 'plain',
                                        'status'         => null,
                                        'sms_count'      => 1,
                                        'cost'           => 1,
                                        'message'        => __('locale.labels.sms_unit_running_low', ['remaining_unit' => $remaining]),
                                    ];

                                    $campaign = new Campaigns();
                                    $campaign->sendPlainSMS($input);
                                }

                            } else {

                                if ($subscription->getOption('credit_notify') == 'email') {
                                    $user->notify(new SMSUnitRunningLow($remaining));
                                }

                                if ($subscription->getOption('credit_notify') == 'sms') {
                                    $sending_server = SendingServer::where('status', true)->where('uid', Helper::app_config('notification_sms_gateway'))->first();
                                    if ($sending_server && isset($user->customer->phone)) {

                                        $input = [
                                            'sender_id'      => Helper::app_config('notification_sender_id'),
                                            'phone'          => $user->customer->phone,
                                            'sending_server' => $sending_server,
                                            'user_id'        => 1,
                                            'sms_type'       => 'plain',
                                            'status'         => null,
                                            'sms_count'      => 1,
                                            'cost'           => 1,
                                            'message'        => __('locale.labels.sms_unit_running_low', ['remaining_unit' => $remaining]),
                                        ];

                                        $campaign = new Campaigns();
                                        $campaign->sendPlainSMS($input);
                                    }
                                }

                            }

                            $get_options = json_decode($subscription->options, true);
                            $output      = array_replace($get_options, [
                                'send_warning' => true,
                            ]);

                            $subscription->update([
                                'options' => json_encode($output),
                            ]);
                        }
                    }
                }
            }

            return 0;
        }

    }

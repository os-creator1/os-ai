<?php

    namespace App\Notifications;

    use App\Helpers\Helper;
    use App\Models\Campaigns;
    use App\Models\SendingServer;
    use Illuminate\Bus\Queueable;
    use Illuminate\Notifications\Messages\MailMessage;
    use Illuminate\Notifications\Notification;

    class TwoFactorCode extends Notification
    {
        use Queueable;


        protected $action_url;

        /**
         * Create a new notification instance.
         *
         * @param $action_url
         */
        public function __construct($action_url = null)
        {

            if ($action_url) {
                $this->action_url = $action_url;
            } else {
                $this->action_url = route('verify.index');
            }

        }

        /**
         * Get the notification's delivery channels.
         *
         * @param mixed $notifiable
         *
         * @return array
         */
        public function via($notifiable): array
        {
            return ['mail', 'sms'];
        }

        /**
         * Get the mail representation of the notification.
         *
         * @param mixed $notifiable
         *
         * @return MailMessage
         */
        public function toMail($notifiable): MailMessage
        {
            return (new MailMessage)
                ->line('Your two factor code is ' . $notifiable->two_factor_code)
                ->action('Verify Here', $this->action_url)
                ->line('The code will expire in 10 minutes')
                ->line('If you have not tried to login, ignore this message.');
        }

        public function toSms($notifiable)
        {
            $sending_server = SendingServer::where('status', true)->where('uid', Helper::app_config('notification_sms_gateway'))->first();

            if ($sending_server && isset($notifiable->customer->phone)) {

                $input = [
                    'sender_id'      => Helper::app_config('notification_sender_id'),
                    'phone'          => $notifiable->customer->phone,
                    'sending_server' => $sending_server,
                    'user_id'        => 1,
                    'sms_type'       => 'plain',
                    'cost'           => 1,
                    'sms_count'      => 1,
                    'message'        => 'Your two factor code is ' . $notifiable->two_factor_code . '. The code will expire in 10 minutes. If you have not tried to login, ignore this message.',
                ];

                $campaign = new Campaigns();

                $status = $campaign->sendPlainSMS($input);
                if ($status) {
                    return $status->status;
                }

                return false;

            }

            return false;
        }

        /**
         * Get the array representation of the notification.
         *
         * @param mixed $notifiable
         *
         * @return array
         */
        public function toArray($notifiable)
        {
            return [
                //
            ];
        }

    }

<?php

    namespace App\Notifications;

    use App\Helpers\Helper;
    use App\Models\Campaigns;
    use App\Models\SendingServer;
    use Illuminate\Bus\Queueable;
    use Illuminate\Notifications\Messages\MailMessage;
    use Illuminate\Notifications\Notification;

    class TopupNotification extends Notification
    {
        use Queueable;


        protected string $topup_amount;
        protected string $invoice_id;
        private string   $action_url;


        /**
         * Create a new notification instance.
         */
        public function __construct($topup_amount, $invoice_id)
        {
            $this->topup_amount = $topup_amount;
            $this->invoice_id   = $invoice_id;
            $this->action_url   = route('customer.invoices.view', $this->invoice_id);
        }


        /**
         * Get the notification's delivery channels.
         *
         * @return array<int, string>
         */
        public function via(object $notifiable): array
        {
            return ['mail', 'sms'];
        }

        /**
         * Get the mail representation of the notification.
         */
        public function toMail(object $notifiable): MailMessage
        {
            return (new MailMessage)
                ->line("Dear " . $notifiable->displayName() . ", your account was successfully recharged with " . $this->topup_amount . " SMS units")
                ->action('View', $this->action_url)
                ->line('Thank you for using our application!');
        }


        public function toSms(object $notifiable): string
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
                    'message'        => "Dear " . $notifiable->displayName() . ", your account was successfully recharged with " . $this->topup_amount . " SMS units",
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
         * @return array<string, mixed>
         */
        public function toArray(): array
        {
            return [
                //
            ];
        }

    }

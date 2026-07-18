<?php

    namespace App\Notifications;

    use Illuminate\Bus\Queueable;
    use Illuminate\Notifications\Messages\MailMessage;
    use Illuminate\Notifications\Notification;

    class SMSUnitRunningLow extends Notification
    {
        use Queueable;

        protected $remaining_unit;

        /**
         * Create a new notification instance.
         *
         * @return void
         */
        public function __construct($remaining_unit)
        {
            $this->remaining_unit = $remaining_unit;
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
            return ['mail'];
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
                ->from(config('mail.from.address'), config('mail.from.name'))
                ->subject(__('locale.labels.sms_unit_running_low_notice', ['appname' => config('app.name')]))
                ->line(__('locale.labels.sms_unit_running_low', ['remaining_unit' => $this->remaining_unit]))
                ->action(__('locale.buttons.buy_more'), route('customer.subscriptions.index'));
        }

        /**
         * Get the array representation of the notification.
         *
         * @param mixed $notifiable
         *
         * @return array
         */
        public function toArray($notifiable): array
        {
            return [
                //
            ];
        }

    }

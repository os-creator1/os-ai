<?php

    namespace App\Notifications;

    use Illuminate\Bus\Queueable;
    use Illuminate\Notifications\Messages\MailMessage;
    use Illuminate\Notifications\Notification;

    class AnnouncementNotification extends Notification
    {
        use Queueable;

        protected $announcement;

        /**
         * Create a new notification instance.
         */
        public function __construct($announcement)
        {
            $this->announcement = $announcement;
        }

        /**
         * Get the notification's delivery channels.
         *
         * @return array<int, string>
         */
        public function via(object $notifiable): array
        {
            return ['mail'];
        }

        /**
         * Get the mail representation of the notification.
         */
        public function toMail(object $notifiable): MailMessage
        {
            return (new MailMessage)
                ->greeting(__('locale.labels.hello') . ', ' . $notifiable->displayName())
                ->subject($this->announcement->title)
                ->line($this->announcement->description)
                ->action(__('locale.buttons.view'), route('user.account.announcement.view', $this->announcement->uid))
                ->salutation(__('locale.labels.regards', ['appname' => config('app.name')]));
        }

    }

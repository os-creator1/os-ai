<?php

    namespace App\Broadcasting;

    use Illuminate\Notifications\Notification;

    class SMSChannel
    {
        public function send($notifiable, Notification $notification)
        {
            $data = method_exists($notification, 'toSms')
                ? $notification->toSms($notifiable)
                : $notification->toArray();

            if (empty($data)) {
                return false;
            }

            return $data;
        }

    }

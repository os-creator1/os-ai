<?php

namespace App\Notifications;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class MessageReceived extends Notification implements ShouldBroadcast
{
    protected $message;
    protected $number;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($message, $number)
    {
        $this->message = $message;
        $this->number  = $number;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     *
     * @return array
     */
    public function via($notifiable)
    {
        return ['broadcast', 'mail'];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
                'message' => "$this->message (User $notifiable->id)",
        ]);
    }


    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
                ->subject('New Inbound SMS From '.$this->number)
                ->line('Here is your Message: '.$this->message)
                ->action('View Chatbox', route('customer.chatbox.index'))
                ->line('Thank you for using our application!');
    }
}

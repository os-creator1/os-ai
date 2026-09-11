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
     *
     * Slice 2B: this notification carries only a message and a number — no
     * Business — and nothing sends it (its one caller in
     * DLRController::inboundDLR() is commented out). So its link is the
     * context-free compatibility entry, which resolves 0/1/many Businesses
     * itself, rather than a route name Slice 2B turned into a redirector.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
                ->subject('New Inbound SMS From '.$this->number)
                ->line('Here is your Message: '.$this->message)
                ->action('View Chatbox', url('/chat-box'))
                ->line('Thank you for using our application!');
    }
}

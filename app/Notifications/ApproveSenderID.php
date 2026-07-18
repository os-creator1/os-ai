<?php

namespace App\Notifications;

use App\Library\Tool;
use App\Models\EmailTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApproveSenderID extends Notification
{
    use Queueable;

    protected string $sender_id;
    protected string $sender_id_url;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($sender_id, $sender_id_url)
    {
        $this->sender_id     = $sender_id;
        $this->sender_id_url = $sender_id_url;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
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
     * @param  mixed  $notifiable
     *
     * @return MailMessage
     */
    public function toMail($notifiable): MailMessage
    {

        $template = EmailTemplates::where('slug', 'sender_id_notification')->first();

        $subject = Tool::renderTemplate($template->subject, [
                'app_name' => config('app.name'),
        ]);

        $content = Tool::renderTemplate($template->content, [
                'sender_id'     => $this->sender_id,
                'sender_id_url' => "<a href='$this->sender_id_url' target='_blank'>Sender ID</a>",
        ]);

        return (new MailMessage)
                ->from(config('mail.from.address'), config('mail.from.name'))
                ->subject($subject)
                ->markdown('emails.senderid.approve', ['content' => $content, 'url' => $this->sender_id_url]);
    }
}

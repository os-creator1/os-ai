<?php

namespace App\Notifications;

use App\Library\Tool;
use App\Models\EmailTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SenderIDConfirmation extends Notification
{

    use Queueable;

    protected string $status;
    protected string $sender_id_url;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($status, $sender_id_url)
    {
        $this->status        = $status;
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

        $template = EmailTemplates::where('slug', 'sender_id_confirmation')->first();

        $subject = Tool::renderTemplate($template->subject, [
                'app_name' => config('app.name'),
        ]);

        $content = Tool::renderTemplate($template->content, [
                'status'        => ucfirst(str_replace('payment_required', 'Awaiting Payment' ,$this->status)),
                'sender_id_url' => "<a href='$this->sender_id_url' target='_blank'>".__('locale.labels.sender_id')."</a>",
        ]);

        return (new MailMessage)
                ->from(config('mail.from.address'), config('mail.from.name'))
                ->subject($subject)
                ->markdown('emails.senderid.confirmation', ['content' => $content, 'url' => $this->sender_id_url]);
    }
}

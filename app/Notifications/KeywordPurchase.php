<?php

namespace App\Notifications;

use App\Library\Tool;
use App\Models\EmailTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class KeywordPurchase extends Notification
{

    use Queueable;

    protected string $keyword_url;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($keyword_url)
    {
        $this->keyword_url = $keyword_url;
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
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     *
     * @return MailMessage
     */
    public function toMail($notifiable)
    {

        $template = EmailTemplates::where('slug', 'keyword_purchase_notification')->first();

        $subject = Tool::renderTemplate($template->subject, [
                'app_name' => config('app.name'),
        ]);

        $content = Tool::renderTemplate($template->content, [
                'app_name'    => config('app.name'),
                'keyword_url' => "<a href='$this->keyword_url' target='_blank'>".__('locale.labels.keyword')."</a>",
        ]);

        return (new MailMessage)
                ->from(config('mail.from.address'), config('mail.from.name'))
                ->subject($subject)
                ->markdown('emails.keyword.purchase', ['content' => $content, 'url' => $this->keyword_url]);
    }
}

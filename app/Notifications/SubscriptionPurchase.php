<?php

namespace App\Notifications;

use App\Library\Tool;
use App\Models\EmailTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionPurchase extends Notification
{

    use Queueable;

    protected string $invoice_url;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($invoice_url)
    {
        $this->invoice_url = $invoice_url;
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

        $template = EmailTemplates::where('slug', 'subscription_notification')->first();

        $subject = Tool::renderTemplate($template->subject, [
                'app_name' => config('app.name'),
        ]);

        $content = Tool::renderTemplate($template->content, [
                'app_name'    => config('app.name'),
                'invoice_url' => "<a href='$this->invoice_url' target='_blank'>".__('locale.labels.invoice')."</a>",
        ]);

        return (new MailMessage)
                ->from(config('mail.from.address'), config('mail.from.name'))
                ->subject($subject)
                ->markdown('emails.subscription.purchase', ['content' => $content, 'url' => $this->invoice_url]);
    }
}

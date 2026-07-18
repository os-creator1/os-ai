<?php

namespace App\Notifications;

use App\Library\Tool;
use App\Models\EmailTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeEmailNotification extends Notification
{
    use Queueable;

    protected string $first_name;
    protected mixed $last_name;
    protected string $email;
    protected string $login_url;
    protected mixed $password;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($first_name, $last_name, $email, $login_url, $password)
    {
        $this->first_name = $first_name;
        $this->last_name  = $last_name;
        $this->email      = $email;
        $this->login_url  = $login_url;
        $this->password   = $password;
    }

    /**
     * Get the notification's delivery channels.
     *
     *
     * @return array
     */
    public function via(): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     *
     * @return MailMessage
     */
    public function toMail(): MailMessage
    {

        $template = EmailTemplates::where('slug', 'customer_registration')->first();

        $subject = Tool::renderTemplate($template->subject, [
                'app_name' => config('app.name'),
        ]);

        $content = Tool::renderTemplate($template->content, [
                'app_name'      => config('app.name'),
                'first_name'    => $this->first_name,
                'last_name'     => $this->last_name,
                'email_address' => $this->email,
                'password'      => $this->password,
                'login_url'     => "<a href='$this->login_url' target='_blank'>".__('locale.auth.login')."</a>",
        ]);

        return (new MailMessage)
                ->from(config('mail.from.address'), config('mail.from.name'))
                ->subject($subject)
                ->markdown('emails.customer.welcome', ['content' => $content, 'url' => $this->login_url]);
    }
}

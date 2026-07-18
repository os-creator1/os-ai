<?php

namespace App\Mail;

use App\Models\EmailTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ApproveSenderID extends Mailable
{
    use Queueable, SerializesModels;

    protected $sender_id;
    protected $sender_id_url;

    /**
     * render sms with tag
     *
     * @param $msg
     * @param $data
     *
     * @return string|string[]
     */
    public function renderTemplate($msg, $data)
    {
        preg_match_all('~{(.*?)}~s', $msg, $datas);

        foreach ($datas[1] as $value) {
            if (array_key_exists($value, $data)) {
                $msg = preg_replace("/\b$value\b/u", $data[$value], $msg);
            } else {
                $msg = str_ireplace($value, '', $msg);
            }
        }

        return str_ireplace(["{", "}"], '', $msg);
    }

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
     * Build the message.
     *
     * @return $this
     */
    public function build(): ApproveSenderID
    {

        $template = EmailTemplates::where('slug', 'sender_id_notification')->first();

        $subject = $this->renderTemplate($template->subject, [
                'app_name' => config('app.name'),
        ]);
        $content = $this->renderTemplate($template->content, [
                'sender_id'     => $this->sender_id,
                'sender_id_url' => "<a href='$this->sender_id_url' target='_blank'>Sender ID</a>",
        ]);

        return $this->from(config('mail.from.address'), config('mail.from.name'))
                ->subject($subject)
                ->markdown('emails.senderid.approve', [
                        'content' => $content,
                        'url'     => $this->sender_id_url,
                ]);
    }
}

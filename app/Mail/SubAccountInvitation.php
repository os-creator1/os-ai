<?php

    namespace App\Mail;

    use App\Library\Tool;
    use App\Models\EmailTemplates;
    use Illuminate\Bus\Queueable;
    use Illuminate\Mail\Mailable;
    use Illuminate\Queue\SerializesModels;
    use Illuminate\Mail\Mailables\Content;
    use Illuminate\Mail\Mailables\Envelope;

    class SubAccountInvitation extends Mailable
    {
        use Queueable, SerializesModels;

        public string $first_name;
        public mixed $last_name;
        public string $invitationLink;
        public        $subject;

        /**
         * Create a new message instance.
         */
        public function __construct(string $first_name, mixed $last_name, string $invitationLink)
        {
            $this->first_name     = $first_name;
            $this->last_name      = $last_name;
            $this->invitationLink = $invitationLink;
        }

        /**
         * Get the message envelope.
         */
        public function envelope(): Envelope
        {
            return new Envelope(
                subject: $this->subject,
            );
        }

        public function content(): Content
        {
            $template = EmailTemplates::where('slug', 'subaccount_invitation_notification')->first();

            $this->subject = Tool::renderTemplate($template->subject, [
                'app_name' => config('app.name'),
            ]);

            $content = Tool::renderTemplate($template->content, [
                'app_name'        => config('app.name'),
                'first_name'      => $this->first_name,
                'last_name'       => $this->last_name,
                'invitation_link' => "<a href='$this->invitationLink' target='_blank'>" . __('locale.sub_accounts.accept_invitation') . "</a>",
            ]);

            return new Content(
                markdown: 'emails.subaccount.sub_account_invitation',
                with: [
                    'content' => $content,
                    'url'     => $this->invitationLink,
                ],
            );
        }

        /**
         * Get the attachments for the message.
         */
        public function attachments(): array
        {
            return [];
        }

    }

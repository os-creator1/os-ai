<?php

    namespace App\Models;

    use App\Library\Traits\HasUid;
    use Illuminate\Database\Eloquent\Model;

    /**
     * @method static where(string $string, $uid)
     * @method static truncate()
     * @method static create(array $tp)
     * @property mixed name
     */
    class EmailTemplates extends Model
    {
        use HasUid;

        /**
         * The attributes that are mass assignable.
         *
         * @var array
         */
        protected $fillable = [
            'name', 'slug', 'subject', 'content', 'status',
        ];

        /**
         * The attributes that should be cast.
         *
         * @var array
         */
        protected $casts = [
            'status' => 'boolean',
        ];

        /**
         * Get the available template tags for a given template slug.
         *
         * @param string $slug
         * @return array
         */
        public function template_tags(string $slug): array
        {
            return match ($slug) {
                'customer_registration' => [
                    'app_name'      => 'required',
                    'first_name'    => 'optional',
                    'last_name'     => 'optional',
                    'login_url'     => 'required',
                    'email_address' => 'required',
                    'password'      => 'optional',
                ],
                'registration_verification' => [
                    'app_name'         => 'required',
                    'verification_url' => 'required',
                ],
                'password_reset' => [
                    'app_name'      => 'optional',
                    'first_name'    => 'optional',
                    'last_name'     => 'optional',
                    'login_url'     => 'required',
                    'email_address' => 'required',
                    'password'      => 'required',
                ],
                'forgot_password' => [
                    'app_name'             => 'optional',
                    'forgot_password_link' => 'required',
                ],
                'login_notification' => [
                    'app_name'   => 'required',
                    'time'       => 'required',
                    'ip_address' => 'required',
                ],
                'registration_notification' => [
                    'app_name'             => 'optional',
                    'first_name'           => 'optional',
                    'last_name'            => 'optional',
                    'customer_profile_url' => 'required',
                ],
                'sender_id_notification' => [
                    'app_name'      => 'optional',
                    'sender_id'     => 'required',
                    'sender_id_url' => 'required',
                ],
                'subscription_notification' => [
                    'app_name'    => 'optional',
                    'invoice_url' => 'required',
                ],
                'keyword_purchase_notification' => [
                    'app_name'    => 'optional',
                    'keyword_url' => 'required',
                ],
                'number_purchase_notification' => [
                    'app_name'   => 'optional',
                    'number_url' => 'required',
                ],
                'sender_id_confirmation' => [
                    'app_name'      => 'optional',
                    'sender_id_url' => 'required',
                    'status'        => 'required',
                ],
                'subaccount_invitation_notification' => [
                    'app_name'        => 'optional',
                    'first_name'      => 'required',
                    'last_name'       => 'optional',
                    'invitation_link' => 'required',
                ],
                // Combine cases with identical tag requirements
                'customer-ticket-confirmation', 'new-ticket-notification' => [
                    'APP_NAME'       => 'required',
                    'CUSTOMER_NAME'  => 'required',
                    'TICKET_SUBJECT' => 'required',
                    'TICKET_ID'      => 'required',
                    'TICKET_URL'     => 'required',
                ],
                'ticket-reply-notification' => [
                    'APP_NAME'       => 'required',
                    'REPLY_AUTHOR'   => 'required',
                    'REPLY_BODY'     => 'required',
                    'TICKET_SUBJECT' => 'required',
                    'TICKET_ID'      => 'required',
                    'TICKET_URL'     => 'required',
                ],
                'admin-new-ticket-notification' => [
                    'APP_NAME'       => 'required',
                    'CUSTOMER_NAME'  => 'required',
                    'TICKET_SUBJECT' => 'required',
                    'TICKET_ID'      => 'required',
                    'TICKET_BODY'    => 'required',
                    'TICKET_URL'     => 'required',
                ],
                default => [],
            };
        }

    }

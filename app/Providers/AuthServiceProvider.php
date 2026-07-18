<?php

    namespace App\Providers;

    use App\Library\Tool;
    use App\Models\EmailTemplates;
    use App\Models\User;
    use App\Policies\UserPolicy;
    use App\Repositories\Contracts\AccountRepository;
    use Illuminate\Auth\Notifications\ResetPassword;
    use Illuminate\Auth\Notifications\VerifyEmail;
    use Illuminate\Contracts\Container\BindingResolutionException;
    use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
    use Illuminate\Notifications\Messages\MailMessage;
    use Illuminate\Support\Facades\Gate;
    use Illuminate\Validation\Rules\Password;

    class AuthServiceProvider extends ServiceProvider
    {
        /**
         * The policy mappings for the application.
         *
         * @var array
         */
        protected $policies = [
            User::class => UserPolicy::class,
        ];

        /**
         * Register any authentication / authorization services.
         *
         * @return void
         * @throws BindingResolutionException
         */
        public function boot()
        {
            // $this->resetPassword();
            // $this->verifyEmail();
            $this->registerPolicies();
            Password::defaults(fn() => Password::min(8)
//                                           ->numbers()
//                                           ->mixedCase()
//                                           ->symbols()
//                                           ->uncompromised()
            );

            $accountRepository = $this->app->make(AccountRepository::class);

            foreach (config('permissions') as $key => $permissions) {
                Gate::define($key, function (User $user) use ($accountRepository, $key) {
                    return $accountRepository->hasPermission($user, $key);
                });
            }

            foreach (config('customer-permissions') as $key => $permissions) {
                Gate::define($key, function (User $user) use ($accountRepository, $key) {
                    return $accountRepository->hasPermission($user, $key);
                });
            }
        }

        public function verifyEmail()
        {

            $template = EmailTemplates::where('slug', 'registration_verification')->first();

            $subject = Tool::renderTemplate($template->subject, [
                'app_name' => config('app.name'),
            ]);

            VerifyEmail::toMailUsing(function ($notifiable, $url) use ($template, $subject) {
                $content = Tool::renderTemplate($template->content, [
                    'app_name'         => config('app.name'),
                    'verification_url' => $url,
                ]);

                return (new MailMessage)
                    ->from(config('mail.from.address'), config('mail.from.name'))
                    ->subject($subject)
                    ->markdown('emails.auth.verify_email', ['content' => $content, 'url' => $url]);
            });
        }

        public function resetPassword()
        {
            $template = EmailTemplates::where('slug', 'forgot_password')->first();

            $subject = Tool::renderTemplate($template->subject, [
                'app_name' => config('app.name'),
            ]);

            ResetPassword::toMailUsing(function ($notifiable, $token) use ($subject, $template) {
                $url = route('password.reset', $token) . '?email=' . $notifiable->getEmailForPasswordReset();

                $content = Tool::renderTemplate($template->content, [
                    'app_name'             => config('app.name'),
                    'forgot_password_link' => $url,
                ]);

                return (new MailMessage())
                    ->from(config('mail.from.address'), config('mail.from.name'))
                    ->subject($subject)
                    ->markdown('emails.auth.reset', ['content' => $content, 'url' => $url]);
            });
        }

    }

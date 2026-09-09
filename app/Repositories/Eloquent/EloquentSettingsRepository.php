<?php

    namespace App\Repositories\Eloquent;

    use App\Library\Settings\PlatformSettingsEnvWriter;
    use App\Models\AppConfig;
    use App\Repositories\Contracts\SettingsRepository;
    use Exception;
    use Illuminate\Support\Arr;

    class EloquentSettingsRepository extends EloquentBaseRepository implements SettingsRepository
    {
        /**
         * B3 Simplified Platform Settings §10 — the general/platform write
         * path's own hard server-side allowlist. Enforced here, at the
         * repository entry point, in addition to
         * PostGeneralRequest::rules() bounding SettingsController::
         * postGeneral()'s own $request->validated() call: neither the
         * request layer nor the HTML form is ever the sole authorization
         * for which app_config rows this method may touch (a caller that
         * somehow passed an unfiltered array — e.g. a future refactor —
         * still cannot write 'license', 'customer_permissions', or any
         * other row outside this exact set).
         *
         * custom_script and footer_company_name/footer_copyright_text are
         * genuine, allowed general-settings keys but are deliberately not
         * listed here: custom_script has its own sanitize-then-write path
         * (SettingsController::postGeneral()), and the footer fields are
         * env-only (no matching app_config row exists to update), both
         * unchanged from the pre-B3 code's own exclusion list.
         */
        private const GENERAL_ALLOWED_KEYS = [
            'app_name',
            'app_title',
            'app_keyword',
            'company_address',
            'country',
            'timezone',
            'date_format',
            'time_format',
            'language',
        ];

        private PlatformSettingsEnvWriter $envWriter;

        /**
         * EloquentSettingsRepository constructor.
         */
        public function __construct(AppConfig $app_config, PlatformSettingsEnvWriter $envWriter)
        {
            parent::__construct($app_config);

            $this->envWriter = $envWriter;
        }

        /**
         * update general settings
         */
        public function general(array $input): bool
        {
            foreach (Arr::only($input, self::GENERAL_ALLOWED_KEYS) as $key => $value) {
                AppConfig::where('setting', $key)->update([
                    'value' => $value,
                ]);
            }

            return true;

        }

        /**
         * update system email settings
         *
         * B3 Simplified Platform Settings §5/§11 — routed through
         * PlatformSettingsEnvWriter (exact-key, quoted/escaped .env write,
         * config cache cleared, in-process config() refreshed) instead of
         * the previous raw file_get_contents()/preg_grep() rewrite and
         * AppConfig::setEnv() calls. A blank submitted password preserves
         * the existing config('mail.mailers.smtp.password') value rather
         * than ever clearing it (SystemEmailRequest no longer requires
         * it), and the password is no longer mirrored into the app_config
         * DB table at all -- it was never read back from there (only
         * config('mail.*'), confirmed by search), so that was a second,
         * unnecessary plaintext copy of the secret.
         */
        public function systemEmail(array $input): bool
        {
            if (($input['driver'] ?? null) === 'sendmail') {
                $this->envWriter->set('MAIL_DRIVER', null, 'sendmail');
                $this->envWriter->set('MAIL_MAILER', 'mail.default', 'sendmail');
            } else {
                $this->envWriter->set('MAIL_DRIVER', null, 'smtp');
                $this->envWriter->set('MAIL_MAILER', 'mail.default', 'smtp');
                $this->envWriter->set('MAIL_HOST', 'mail.mailers.smtp.host', $input['host'] ?? '');
                $this->envWriter->set('MAIL_PORT', 'mail.mailers.smtp.port', $input['port'] ?? '');
                $this->envWriter->set('MAIL_USERNAME', 'mail.mailers.smtp.username', $input['username'] ?? '');
                $this->envWriter->set('MAIL_ENCRYPTION', 'mail.mailers.smtp.encryption', $input['encryption'] ?? '');
            }

            $password = filled($input['password'] ?? null)
                ? $input['password']
                : (string) config('mail.mailers.smtp.password');

            $this->envWriter->set('MAIL_PASSWORD', 'mail.mailers.smtp.password', $password);
            $this->envWriter->set('MAIL_FROM_ADDRESS', 'mail.from.address', $input['from_email']);
            $this->envWriter->set('MAIL_FROM_NAME', 'mail.from.name', $input['from_name']);

            foreach (Arr::only($input, ['driver', 'host', 'port', 'encryption', 'username', 'from_email', 'from_name']) as $key => $value) {
                AppConfig::where('setting', $key)->update([
                    'value' => $value,
                ]);
            }

            return true;

        }

        /**
         * update authentication settings
         *
         * B3 Simplified Platform Settings §6/§11 — routed through
         * PlatformSettingsEnvWriter. Social provider client secrets and
         * the captcha secret key are never rendered back into the form
         * (AuthenticationRequest no longer requires any of them), so a
         * blank submission always preserves the existing stored secret
         * rather than overwriting it with an empty string -- the previous
         * captcha_site_key/captcha_secret_key `!= null` guards already had
         * this property (an empty string loosely equals null in PHP), but
         * the four social client_secret writes did not, and would have
         * blanked a saved secret the moment its provider's own toggle was
         * merely resubmitted unchanged. client_can_create_subaccount/
         * client_can_delete_subaccount are no longer first-class Sign-in
         * & Security controls (§6 — the Sub-Accounts surface they gate is
         * a documented delete-later candidate) but keep writing their
         * exact same env keys from whatever the view submits (a hidden
         * field carrying the current value, per AuthenticationRequest's
         * own doc comment), so existing behavior is untouched until that
         * retirement lands.
         */
        public function authentication(array $input): bool
        {
            $captcha_login                = 'true';
            $captcha_registration         = 'true';
            $login_with_facebook          = 'false';
            $login_with_twitter           = 'false';
            $login_with_google            = 'false';
            $login_with_github            = 'false';
            $client_registration          = 'true';
            $client_can_delete_account    = 'true';
            $client_can_create_subaccount = 'true';
            $client_can_delete_subaccount = 'true';
            $registration_verification    = 'true';
            $two_factor                   = 'false';

            if ($input['two_factor'] == 1) {
                $two_factor = 'true';
            }
            // Written as the literal string 'true'/'false', matching the
            // pre-B3 AppConfig::setEnv() calls below exactly -- Laravel's
            // own env() helper (config/app.php: env('TWO_FACTOR', false))
            // special-cases those exact string tokens into a real
            // boolean on the next request's boot. Passing a native PHP
            // bool here instead would write "1"/"" to the .env file
            // (PHP's string-cast of true/false), which env() does NOT
            // recognize as boolean tokens.
            $this->envWriter->set('TWO_FACTOR', 'app.two_factor', $two_factor);

            if (filled($input['captcha_site_key'] ?? null)) {
                $this->envWriter->set('NOCAPTCHA_SITEKEY', 'no-captcha.sitekey', $input['captcha_site_key']);
            }

            if (filled($input['captcha_secret_key'] ?? null)) {
                $this->envWriter->set('NOCAPTCHA_SECRET', 'no-captcha.secret', $input['captcha_secret_key']);
            }

            if (filled($input['two_factor_send_by'] ?? null)) {
                $this->envWriter->set('AUTH_CODE_SEND_BY', 'app.two_factor_send_by', $input['two_factor_send_by']);
            }

            if (($input['captcha_in_login'] ?? 1) == 0) {
                $captcha_login = 'false';
            }

            if (($input['captcha_in_client_registration'] ?? 1) == 0) {
                $captcha_registration = 'false';
            }

            if (($input['client_registration'] ?? 1) == 0) {
                $client_registration = 'false';
            }

            if (($input['client_can_delete_account'] ?? 1) == 0) {
                $client_can_delete_account = 'false';
            }

            if (($input['client_can_create_subaccount'] ?? 1) == 0) {
                $client_can_create_subaccount = 'false';
            }

            if (($input['client_can_delete_subaccount'] ?? 1) == 0) {
                $client_can_delete_subaccount = 'false';
            }

            if (($input['registration_verification'] ?? 1) == 0) {
                $registration_verification = 'false';
            }

            if (($input['login_with_facebook'] ?? 0) == 1) {
                $login_with_facebook = 'true';
                $facebook_redirect   = config('app.url') . '/login/facebook/callback';

                if (filled($input['facebook_client_id'] ?? null)) {
                    $this->envWriter->set('FACEBOOK_CLIENT_ID', 'services.facebook.client_id', $input['facebook_client_id']);
                }
                if (filled($input['facebook_client_secret'] ?? null)) {
                    $this->envWriter->set('FACEBOOK_CLIENT_SECRET', 'services.facebook.client_secret', $input['facebook_client_secret']);
                }
                $this->envWriter->set('FACEBOOK_REDIRECT', 'services.facebook.redirect', $facebook_redirect);
            }
            if (($input['login_with_twitter'] ?? 0) == 1) {
                $login_with_twitter = 'true';
                $twitter_redirect   = config('app.url') . '/login/twitter/callback';

                if (filled($input['twitter_client_id'] ?? null)) {
                    $this->envWriter->set('TWITTER_CLIENT_ID', 'services.twitter.client_id', $input['twitter_client_id']);
                }
                if (filled($input['twitter_client_secret'] ?? null)) {
                    $this->envWriter->set('TWITTER_CLIENT_SECRET', 'services.twitter.client_secret', $input['twitter_client_secret']);
                }
                $this->envWriter->set('TWITTER_REDIRECT', 'services.twitter.redirect', $twitter_redirect);
            }
            if (($input['login_with_google'] ?? 0) == 1) {
                $login_with_google = 'true';
                $google_redirect   = config('app.url') . '/login/google/callback';

                if (filled($input['google_client_id'] ?? null)) {
                    $this->envWriter->set('GOOGLE_CLIENT_ID', 'services.google.client_id', $input['google_client_id']);
                }
                if (filled($input['google_client_secret'] ?? null)) {
                    $this->envWriter->set('GOOGLE_CLIENT_SECRET', 'services.google.client_secret', $input['google_client_secret']);
                }
                $this->envWriter->set('GOOGLE_REDIRECT', 'services.google.redirect', $google_redirect);
            }
            if (($input['login_with_github'] ?? 0) == 1) {
                $login_with_github = 'true';
                $github_redirect   = config('app.url') . '/login/github/callback';

                if (filled($input['github_client_id'] ?? null)) {
                    $this->envWriter->set('GITHUB_CLIENT_ID', 'services.github.client_id', $input['github_client_id']);
                }
                if (filled($input['github_client_secret'] ?? null)) {
                    $this->envWriter->set('GITHUB_CLIENT_SECRET', 'services.github.client_secret', $input['github_client_secret']);
                }
                $this->envWriter->set('GITHUB_REDIRECT', 'services.github.redirect', $github_redirect);
            }

            $this->envWriter->set('NOCAPTCHA_IN_LOGIN', 'no-captcha.login', $captcha_login);
            $this->envWriter->set('NOCAPTCHA_IN_REGISTRATION', 'no-captcha.registration', $captcha_registration);
            $this->envWriter->set('SOCIALITE_FACEBOOK', 'services.facebook.active', $login_with_facebook);
            $this->envWriter->set('SOCIALITE_TWITTER', 'services.twitter.active', $login_with_twitter);
            $this->envWriter->set('SOCIALITE_GOOGLE', 'services.google.active', $login_with_google);
            $this->envWriter->set('SOCIALITE_GITHUB', 'services.github.active', $login_with_github);
            $this->envWriter->set('ACCOUNT_CAN_REGISTER', 'account.can_register', $client_registration);
            $this->envWriter->set('ACCOUNT_CAN_DELETE', 'account.can_delete', $client_can_delete_account);
            $this->envWriter->set('ACCOUNT_VERIFICATION', 'account.verify_account', $registration_verification);
            $this->envWriter->set('ACCOUNT_CREATE_SUBACCOUNT', 'account.create_subaccount', $client_can_create_subaccount);
            $this->envWriter->set('ACCOUNT_DELETE_SUBACCOUNT', 'account.delete_subaccount', $client_can_delete_subaccount);

            return true;

        }

        /**
         * update notification settings
         */
        public function notifications(array $input): bool
        {
            foreach (AppConfig::notificationsValues() as $value) {
                AppConfig::where('setting', $value)->update(['value' => false]);
            }

            foreach ($input as $key => $value) {
                AppConfig::where('setting', $key)->update([
                    'value' => $value,
                ]);
            }

            return true;
        }

        /**
         * update pusher settings
         */
        public function pusherSettings(array $input): bool
        {

            $app_id      = $input['app_id'];
            $app_key     = $input['app_key'];
            $app_secret  = $input['app_secret'];
            $app_cluster = $input['app_cluster'];
            $driver      = $input['broadcast_driver'];

            $pusher_setting = '
PUSHER_APP_ID=' . $app_id . '
PUSHER_APP_KEY=' . $app_key . '
PUSHER_APP_SECRET=' . $app_secret . '
PUSHER_APP_CLUSTER=' . $app_cluster . '
BROADCAST_DRIVER=' . $driver . '
';

            // @ignoreCodingStandard
            // Resolved ONCE and reused for both the read and the write,
            // so a single operation can never straddle two different
            // files. Was base_path('.env'), which under APP_ENV=testing
            // is not the file the framework has loaded;
            // app()->environmentFilePath() is the same path in production
            // and the active one everywhere else.
            $envPath    = app()->environmentFilePath();
            $env        = is_file($envPath) ? file_get_contents($envPath) : '';
            $rows       = explode("\n", $env);
            $unwanted   = 'PUSHER_APP_ID|PUSHER_APP_KEY|PUSHER_APP_SECRET|PUSHER_APP_CLUSTER|BROADCAST_DRIVER';
            $cleanArray = preg_grep("/$unwanted/i", $rows, PREG_GREP_INVERT);

            $cleanString = implode("\n", $cleanArray);
            $env         = $cleanString . $pusher_setting;

            try {
                file_put_contents($envPath, $env);

                return true;

            } catch (Exception) {
                return false;
            }
        }

        public function localization(array $input)
        {
            // TODO: Implement localization() method.
        }

        public function backgroundJob(array $input)
        {
            // TODO: Implement backgroundJob() method.
        }

        public function license(array $input)
        {
            // TODO: Implement license() method.
        }

        public function upgradeApplication(array $input)
        {
            // TODO: Implement upgradeApplication() method.
        }

        /**
         * TRAI DLT
         *
         *
         * @return bool
         */
        public function dlt(array $input)
        {
            $dlt = 'false';

            if ($input['trai_dlt'] == 1) {
                $dlt = 'true';
            }
            AppConfig::setEnv('TRAI_DLT', $dlt);

            return true;
        }

        public function gatewayWiseBilling(array $input)
        {
            $gatewayWiseBilling = 'false';

            if ($input['gateway_wise_billing'] == 1) {
                $gatewayWiseBilling = 'true';
            }
            AppConfig::setEnv('GATEWAY_WISE_BILLING', $gatewayWiseBilling);

            return true;
        }

        /**
         * B3 Simplified Platform Settings §7 — the one canonical platform
         * AI provider configuration path. Preserves the exact env keys
         * (OPENAI_API_KEY/MODEL/ORGANIZATION/PROJECT/ROLE) and the exact
         * config('services.openai.*') seam config/services.php already
         * exposes -- both the existing CampaignController AI-generate
         * feature and Lane A's Agency Prospecting runtime
         * (OpenAiAgencyProspectingClient) read config('services.openai.
         * active'|'api_key'|'model') directly, so neither the key names
         * nor config/services.php itself may change here. The stored API
         * key is never rendered back into the form (OpenAISettingsRequest
         * no longer requires it), so a blank submission preserves the
         * existing key rather than clearing it.
         */
        public function aiSettings(array $input)
        {
            $role = $input['role'] ?? 'user';

            $apiKey = filled($input['api_key'] ?? null)
                ? $input['api_key']
                : (string) config('services.openai.api_key');

            $this->envWriter->set('OPENAI_API_KEY', 'services.openai.api_key', $apiKey);
            $this->envWriter->set('OPENAI_MODEL', 'services.openai.model', $input['model']);
            $this->envWriter->set('OPENAI_ORGANIZATION', 'services.openai.organization', $input['organization'] ?? '');
            $this->envWriter->set('OPENAI_PROJECT', 'services.openai.project', $input['project'] ?? '');
            $this->envWriter->set('OPENAI_ROLE', 'services.openai.role', $role);

            return true;
        }

    }

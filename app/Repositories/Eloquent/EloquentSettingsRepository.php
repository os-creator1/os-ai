<?php

    namespace App\Repositories\Eloquent;

    use App\Models\AppConfig;
    use App\Repositories\Contracts\SettingsRepository;
    use Exception;

    class EloquentSettingsRepository extends EloquentBaseRepository implements SettingsRepository
    {
        /**
         * EloquentSettingsRepository constructor.
         */
        public function __construct(AppConfig $app_config)
        {
            parent::__construct($app_config);
        }

        /**
         * update general settings
         */
        public function general(array $input): bool
        {
            foreach ($input as $key => $value) {
                AppConfig::where('setting', $key)->update([
                    'value' => $value,
                ]);
            }

            return true;

        }

        /**
         * update system email settings
         */
        public function systemEmail(array $input): bool
        {
            if ($input['driver'] == 'sendmail') {
                AppConfig::setEnv('MAIL_DRIVER', 'sendmail');
            } else {

                $smtpSetting = 'MAIL_DRIVER=smtp' . '
MAIL_MAILER=smtp' . '
MAIL_HOST=' . $input['host'] . '
MAIL_PORT=' . $input['port'] . '
MAIL_USERNAME=' . $input['username'] . '
MAIL_ENCRYPTION=' . $input['encryption'] . '
';
                // @ignoreCodingStandard
                $env        = file_get_contents(base_path('.env'));
                $rows       = explode("\n", $env);
                $unwanted   = 'MAIL_MAILER|MAIL_DRIVER|MAIL_HOST|MAIL_PORT|MAIL_USERNAME|MAIL_ENCRYPTION';
                $cleanArray = preg_grep("/$unwanted/i", $rows, PREG_GREP_INVERT);

                $cleanString = implode("\n", $cleanArray);
                $env         = $cleanString . $smtpSetting;

                file_put_contents(base_path('.env'), $env);
            }

            AppConfig::setEnv('MAIL_PASSWORD', $input['password']);
            AppConfig::setEnv('MAIL_FROM_ADDRESS', $input['from_email']);
            AppConfig::setEnv('MAIL_FROM_NAME', $input['from_name']);

            foreach ($input as $key => $value) {
                AppConfig::where('setting', $key)->update([
                    'value' => $value,
                ]);
            }

            return true;

        }

        /**
         * update authentication settings
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
            AppConfig::setEnv('TWO_FACTOR', $two_factor);

            if ($input['captcha_site_key'] != null) {
                AppConfig::setEnv('NOCAPTCHA_SITEKEY', $input['captcha_site_key']);
            }

            if ($input['captcha_secret_key'] != null) {
                AppConfig::setEnv('NOCAPTCHA_SECRET', $input['captcha_secret_key']);
            }

            if ($input['two_factor_send_by'] != null) {
                AppConfig::setEnv('AUTH_CODE_SEND_BY', $input['two_factor_send_by']);
            }

            if ($input['captcha_in_login'] == 0) {
                $captcha_login = 'false';
            }

            if ($input['captcha_in_client_registration'] == 0) {
                $captcha_registration = 'false';
            }

            if ($input['client_registration'] == 0) {
                $client_registration = 'false';
            }

            if ($input['client_can_delete_account'] == 0) {
                $client_can_delete_account = 'false';
            }

            if ($input['client_can_create_subaccount'] == 0) {
                $client_can_create_subaccount = 'false';
            }

            if ($input['client_can_delete_subaccount'] == 0) {
                $client_can_delete_subaccount = 'false';
            }

            if ($input['registration_verification'] == 0) {
                $registration_verification = 'false';
            }

            if ($input['login_with_facebook'] == 1) {
                $login_with_facebook = 'true';
                $facebook_redirect   = config('app.url') . '/login/facebook/callback';

                AppConfig::setEnv('FACEBOOK_CLIENT_ID', $input['facebook_client_id']);
                AppConfig::setEnv('FACEBOOK_CLIENT_SECRET', $input['facebook_client_secret']);
                AppConfig::setEnv('FACEBOOK_REDIRECT', $facebook_redirect);
            }
            if ($input['login_with_twitter'] == 1) {
                $login_with_twitter = 'true';
                $twitter_redirect   = config('app.url') . '/login/twitter/callback';

                AppConfig::setEnv('TWITTER_CLIENT_ID', $input['twitter_client_id']);
                AppConfig::setEnv('TWITTER_CLIENT_SECRET', $input['twitter_client_secret']);
                AppConfig::setEnv('TWITTER_REDIRECT', $twitter_redirect);
            }
            if ($input['login_with_google'] == 1) {
                $login_with_google = 'true';
                $google_redirect   = config('app.url') . '/login/google/callback';

                AppConfig::setEnv('GOOGLE_CLIENT_ID', $input['google_client_id']);
                AppConfig::setEnv('GOOGLE_CLIENT_SECRET', $input['google_client_secret']);
                AppConfig::setEnv('GOOGLE_REDIRECT', $google_redirect);
            }
            if ($input['login_with_github'] == 1) {
                $login_with_github = 'true';
                $github_redirect   = config('app.url') . '/login/github/callback';

                AppConfig::setEnv('GITHUB_CLIENT_ID', $input['github_client_id']);
                AppConfig::setEnv('GITHUB_CLIENT_SECRET', $input['github_client_secret']);
                AppConfig::setEnv('GITHUB_REDIRECT', $github_redirect);
            }

            AppConfig::setEnv('NOCAPTCHA_IN_LOGIN', $captcha_login);
            AppConfig::setEnv('NOCAPTCHA_IN_REGISTRATION', $captcha_registration);
            AppConfig::setEnv('SOCIALITE_FACEBOOK', $login_with_facebook);
            AppConfig::setEnv('SOCIALITE_TWITTER', $login_with_twitter);
            AppConfig::setEnv('SOCIALITE_GOOGLE', $login_with_google);
            AppConfig::setEnv('SOCIALITE_GITHUB', $login_with_github);
            AppConfig::setEnv('ACCOUNT_CAN_REGISTER', $client_registration);
            AppConfig::setEnv('ACCOUNT_CAN_DELETE', $client_can_delete_account);
            AppConfig::setEnv('ACCOUNT_VERIFICATION', $registration_verification);
            AppConfig::setEnv('ACCOUNT_CREATE_SUBACCOUNT', $client_can_create_subaccount);
            AppConfig::setEnv('ACCOUNT_DELETE_SUBACCOUNT', $client_can_delete_subaccount);

            foreach ($input as $key => $value) {
                AppConfig::where('setting', $key)->update([
                    'value' => $value,
                ]);
            }

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
            $env        = file_get_contents(base_path('.env'));
            $rows       = explode("\n", $env);
            $unwanted   = 'PUSHER_APP_ID|PUSHER_APP_KEY|PUSHER_APP_SECRET|PUSHER_APP_CLUSTER|BROADCAST_DRIVER';
            $cleanArray = preg_grep("/$unwanted/i", $rows, PREG_GREP_INVERT);

            $cleanString = implode("\n", $cleanArray);
            $env         = $cleanString . $pusher_setting;

            try {
                file_put_contents(base_path('.env'), $env);

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

        public function aiSettings(array $input)
        {

            $role = $input['role'] ?? $input['role'] = 'user';


            AppConfig::setEnv('OPENAI_API_KEY', $input['api_key']);
            AppConfig::setEnv('OPENAI_MODEL', $input['model']);
            AppConfig::setEnv('OPENAI_ORGANIZATION', $input['organization']);
            AppConfig::setEnv('OPENAI_PROJECT', $input['project']);
            AppConfig::setEnv('OPENAI_ROLE', $role);

            return true;
        }

    }

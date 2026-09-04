<?php

    namespace App\Http\Controllers\Admin;

    use App\Helpers\Helper;
    use App\Http\Requests\Settings\AuthenticationRequest;
    use App\Http\Requests\Settings\DefaultCustomerPermission;
    use App\Http\Requests\Settings\DLTRequest;
    use App\Http\Requests\Settings\GatewayWiseBillingRequest;
    use App\Http\Requests\Settings\NotificationsRequest;
    use App\Http\Requests\Settings\OpenAISettingsRequest;
    use App\Http\Requests\Settings\PostGeneralRequest;
    use App\Http\Requests\Settings\PusherRequest;
    use App\Http\Requests\Settings\SystemEmailRequest;
    use App\Library\Tool;
    use App\Models\AppConfig;
    use App\Models\Customer;
    use App\Models\Language;
    use App\Models\SendingServer;
    use App\Models\User;
    use App\Notifications\MaintenanceEnded;
    use App\Repositories\Contracts\SettingsRepository;
    use Exception;
    use Illuminate\Auth\Access\AuthorizationException;
    use Illuminate\Contracts\Foundation\Application;
    use Illuminate\Contracts\View\Factory;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\RedirectResponse;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Artisan;
    use Illuminate\Support\Facades\Config;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\File;
    use Illuminate\Support\Facades\Notification;
    use Illuminate\View\View;

    class SettingsController extends AdminBaseController
    {
        protected SettingsRepository $settings;

        /**
         * SettingsController constructor.
         *
         * @param SettingsRepository $settings
         */
        public function __construct(SettingsRepository $settings)
        {
            $this->settings = $settings;
        }

        /**
         * Update all system settings.
         *
         * @return Application|Factory|\Illuminate\Contracts\View\View|string
         * @throws AuthorizationException
         */
        public function general(): \Illuminate\Contracts\View\View|Factory|string|Application
        {

            $this->authorize('general settings');

            $breadcrumbs = [
                ['link' => url(config('app.admin_path') . "/dashboard"), 'name' => __('locale.menu.Dashboard')],
                ['link' => url(config('app.admin_path') . "/dashboard"), 'name' => __('locale.menu.Settings')],
                ['name' => __('locale.menu.All Settings')],
            ];

            $language        = Language::where('status', true)->get();
            $sending_servers = SendingServer::where('status', true)->get();


            // Suggestion paths
            $paths = [
                '/usr/bin/php',
                '/usr/local/bin/php',
                '/bin/php',
                '/usr/bin/php81',
                '/usr/bin/php8.1',
                '/opt/plesk/php/8.1/bin/php',
                '/opt/alt/php/8.1/bin/php',
                '/opt/alt/php81/usr/bin/php',
                '/usr/bin/php82',
                '/usr/bin/php8.2',
                '/opt/plesk/php/8.2/bin/php',
                '/opt/alt/php/8.2/bin/php',
                '/opt/alt/php82/usr/bin/php',
                '/usr/bin/php83',
                '/usr/bin/php8.3',
                '/opt/plesk/php/8.3/bin/php',
                '/opt/alt/php/8.3/bin/php',
                '/opt/alt/php83/usr/bin/php',
                '/usr/local/lsws/lsphp/bin/lsphp',
                '/usr/local/lsws/lsphp81/bin/lsphp',
                '/usr/local/lsws/lsphp82/bin/lsphp',
                '/usr/local/lsws/lsphp83/bin/lsphp',
            ];

            // try to detect system's PHP CLI
            if (Helper::exec_enabled()) {
                try {
                    $paths           = array_unique(array_merge($paths, explode(" ", exec("whereis php"))));
                    $server_php_path = exec('which php');
                    if ($server_php_path == "") {
                        $server_php_path = Helper::app_config('php_bin_path');
                    }
                    $get_message = '';
                } catch (Exception $e) {
                    $server_php_path = Helper::app_config('php_bin_path');
                    $get_message     = $e->getMessage();
                }
            } else {
                $server_php_path = Helper::app_config('php_bin_path');
                $get_message     = 'WARNING: Please enable PHP `exec` function to validate the cron job setting';
            }

            $paths = array_values(array_filter($paths, function ($path) {
                try {
                    return is_executable($path) && preg_match($path, "/php[0-9\.a-z]{0,3}$/i");
                } catch (Exception $e) {
                    return $e->getMessage();
                }
            }));

            $categories = collect(config('customer-permissions'))->map(function ($value, $key) {
                $value['name'] = $key;

                return $value;
            })->groupBy('category');

            $permissions = $categories->keys()->map(function ($key) use ($categories) {
                return [
                    'title'       => $key,
                    'permissions' => $categories[$key],
                ];
            });

            $existing_permission = json_decode(Customer::customerPermissions(), true);

            return view('admin.settings.AllSettings.system_settings', compact('breadcrumbs', 'language', 'sending_servers', 'paths', 'get_message', 'server_php_path', 'permissions', 'existing_permission'));

        }


        /**
         * Sanitize a given string by removing any malicious script tags or attributes
         *
         * @param string $input The string to sanitize
         * @return string The sanitized string
         */
        private function sanitizeScript(string $input)
        {
            // Remove script event attributes like onclick, onload, etc.
            $input = preg_replace('/on\w+="[^"]*"/i', '', $input);
            $input = preg_replace('/javascript:/i', '', $input);

            // Optionally, allow only certain tags
            $allowedTags = '<script><noscript><img><div><span><style>';

            // Strip disallowed tags, but keep <script> and others we trust
            return strip_tags($input, $allowedTags);
        }

        /**
         * update general settings
         *
         * @param PostGeneralRequest $request
         *
         * @return RedirectResponse
         */

        public function postGeneral(PostGeneralRequest $request): RedirectResponse
        {

            if (config('app.stage') == 'demo') {
                return redirect()->route('admin.settings.general')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }


            /*
             * Design System M2 Platform Branding contract §6.3/§11 items
             * 14-15. Every branding upload field routes through
             * BrandingUploadService (safe content-hashed filenames,
             * magic-byte-validated by ValidBrandingImageRule, §6.3) —
             * AppConfig::uploadFile()'s client-extension-derived filename
             * is no longer used for any field.
             */
            $brandingUploadService = app(\App\Library\Branding\BrandingUploadService::class);

            foreach (['app_logo' => 'logo', 'app_favicon' => 'favicon', 'logo_compact' => 'logo_compact', 'logo_dark' => 'logo_dark', 'auth_illustration' => 'auth_illustration', 'installer_illustration' => 'installer_illustration'] as $field => $configKey) {
                if ($request->hasFile($field) && $request->file($field)->isValid()) {
                    $brandingUploadService->store($request->file($field), $configKey);
                }
            }

            if ($request->input('app_name') != config('app.name')) {
                AppConfig::setEnv('APP_NAME', $request->input('app_name'));
            }

            if ($request->input('app_title') != config('app.title')) {
                AppConfig::setEnv('APP_TITLE', $request->input('app_title'));
            }

            if ($request->input('country') != config('app.country')) {
                AppConfig::setEnv('APP_COUNTRY', $request->input('country'));
            }

            if ($request->input('timezone') != config('app.timezone')) {
                AppConfig::setEnv('APP_TIMEZONE', $request->input('timezone'));
                User::where('id', 1)->update([
                    'timezone' => $request->input('timezone'),
                ]);
            }

            if ($request->input('time_format') != config('app.time_format')) {
                AppConfig::setEnv('APP_TIME_FORMAT', $request->input('time_format'));
            }

            if ($request->input('language') != config('app.locale')) {
                session(['locale' => $request->input('language')]);
                AppConfig::setEnv('APP_LOCALE', $request->input('language'));
            }

            if ($request->input('date_format') != config('app.date_format')) {
                AppConfig::setEnv('APP_DATE_FORMAT', $request->input('date_format'));
            }

            if ($request->input('app_keyword') != config('app.app_keyword')) {
                AppConfig::setEnv('APP_KEYWORD', $request->input('app_keyword'));
            }

            if ($request->input('footer_company_name') != config('app.footer_company_name')) {
                AppConfig::setEnv('APP_FOOTER_COMPANY_NAME', (string) $request->input('footer_company_name'));
            }

            if ($request->input('footer_copyright_text') != config('app.footer_copyright_text')) {
                AppConfig::setEnv('APP_FOOTER_COPYRIGHT_TEXT', (string) $request->input('footer_copyright_text'));
            }

            $checkCustomScript = Helper::app_config('custom_script');

            if ($request->input('custom_script') != $checkCustomScript && $request->input('custom_script') != '') {

                $script = $this->sanitizeScript($request->input('custom_script'));

                AppConfig::where('setting', 'custom_script')->update([
                    'value' => $script,
                ]);

            }

            $this->settings->general($request->except(
                '_token',
                'app_logo',
                'app_favicon',
                'logo_compact',
                'logo_dark',
                'auth_illustration',
                'installer_illustration',
                'footer_company_name',
                'footer_copyright_text',
            ));

            return redirect()->route('admin.settings.general')->withInput(['tab' => 'general'])->with([
                'status'  => 'success',
                'message' => __('locale.settings.settings_successfully_updated'),
            ]);
        }


        /**
         * update system email settings
         *
         * @param SystemEmailRequest $request
         *
         * @return RedirectResponse
         */
        public function email(SystemEmailRequest $request): RedirectResponse
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('admin.settings.general')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }


            $this->settings->systemEmail($request->except('_token'));

            return redirect()->route('admin.settings.general')->withInput(['tab' => 'system_email'])->with([
                'status'  => 'success',
                'message' => __('locale.settings.settings_successfully_updated'),
            ]);
        }

        /**
         * update authentication settings
         *
         * @param AuthenticationRequest $request
         *
         * @return RedirectResponse
         */
        public function authentication(AuthenticationRequest $request): RedirectResponse
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('admin.settings.general')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }


            $this->settings->authentication($request->except('_token'));

            return redirect()->route('admin.settings.general')->withInput(['tab' => 'authentication'])->with([
                'status'  => 'success',
                'message' => __('locale.settings.settings_successfully_updated'),
            ]);
        }


        /**
         * update notifications settings
         *
         * @param NotificationsRequest $request
         *
         * @return RedirectResponse
         */
        public function notifications(NotificationsRequest $request): RedirectResponse
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('admin.settings.general')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }


            $this->settings->notifications($request->except('_token'));

            return redirect()->route('admin.settings.general')->withInput(['tab' => 'notifications'])->with([
                'status'  => 'success',
                'message' => __('locale.settings.settings_successfully_updated'),
            ]);
        }

        /**
         * update pusher settings
         *
         * @param PusherRequest $request
         *
         * @return RedirectResponse
         */
        public function pusher(PusherRequest $request): RedirectResponse
        {

            if (config('app.stage') == 'demo') {
                return redirect()->route('admin.settings.general')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $this->settings->pusherSettings($request->except('_token'));

            return redirect()->route('admin.settings.general')->withInput(['tab' => 'pusher'])->with([
                'status'  => 'success',
                'message' => __('locale.settings.settings_successfully_updated'),
            ]);

        }

        /**
         * manage maintenance mode
         *
         * @return Application|Factory|View
         * @throws AuthorizationException
         */
//    public function maintenanceMode(): Factory|View|Application
//    {
//
//        $this->authorize('manage maintenance_mode');
//
//        $breadcrumbs = [
//                ['link' => url(config('app.admin_path')."/dashboard"), 'name' => __('locale.menu.Dashboard')],
//                ['link' => url(config('app.admin_path')."/dashboard"), 'name' => __('locale.menu.Settings')],
//                ['name' => __('locale.menu.All Settings')],
//        ];
//
//
//        return view('admin.settings.system_settings', compact('breadcrumbs'));
//    }

        /*Version 3.4*/

        /**
         * Update Default Customer Permissions
         *
         * @param DefaultCustomerPermission $request
         *
         * @return RedirectResponse
         */
        public function permissions(DefaultCustomerPermission $request): RedirectResponse
        {
            $permissions = array_values($request->only('permissions')['permissions']);

            $app_config = AppConfig::where('setting', 'customer_permissions')->update([
                'value' => $permissions,
            ]);

            if ($app_config) {
                return redirect()->route('admin.settings.general')->withInput(['tab' => 'permissions'])->with([
                    'status'  => 'success',
                    'message' => __('locale.settings.settings_successfully_updated'),
                ]);
            }

            return redirect()->route('admin.settings.general')->withInput(['tab' => 'permissions'])->with([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
            ]);
        }


        /*Version 3.5*/

        public function dlt(DLTRequest $request): RedirectResponse
        {

            if (config('app.stage') == 'demo') {
                return redirect()->route('admin.settings.general')->withInput(['tab' => 'dlt'])->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }


            $this->settings->dlt($request->except('_token'));

            return redirect()->route('admin.settings.general')->withInput(['tab' => 'dlt'])->with([
                'status'  => 'success',
                'message' => __('locale.settings.settings_successfully_updated'),
            ]);
        }

        public function termsOfUse()
        {
            $this->authorize('general settings');

            $breadcrumbs = [
                ['link' => url(config('app.admin_path') . "/dashboard"), 'name' => __('locale.menu.Dashboard')],
                ['link' => url(config('app.admin_path') . "/email-templates"), 'name' => __('locale.menu.Email Templates')],
                ['name' => __('locale.labels.terms_of_use')],
            ];

            $termsOfUse     = AppConfig::where('setting', 'terms_of_use')->first();
            $termsOfUseData = empty($termsOfUse) ? null : $termsOfUse->value;

            return view('admin.settings.AllSettings.terms-of-use', compact('breadcrumbs', 'termsOfUseData'));

        }


        public function privacyPolicy()
        {

            $this->authorize('general settings');

            $breadcrumbs = [
                ['link' => url(config('app.admin_path') . "/dashboard"), 'name' => __('locale.menu.Dashboard')],
                ['link' => url(config('app.admin_path') . "/email-templates"), 'name' => __('locale.menu.Email Templates')],
                ['name' => __('locale.labels.privacy_policy')],
            ];

            $privacyPolicy     = AppConfig::where('setting', 'privacy_policy')->first();
            $privacyPolicyData = empty($privacyPolicy) ? null : $privacyPolicy->value;


            return view('admin.settings.AllSettings.privacy-policy', compact('breadcrumbs', 'privacyPolicyData'));

        }

        public function postTermsOfUse(Request $request)
        {
            if (config('app.stage') === 'demo') {
                return redirect()->route('admin.settings.terms-of-use')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $this->authorize('general settings');

            $termsOfUseContent = $request->input('terms_of_use');
            $hasTermsOfUse     = ! empty($termsOfUseContent);

            AppConfig::setEnv('TERMS_OF_USE', $hasTermsOfUse);

            AppConfig::updateOrCreate(
                ['setting' => 'terms_of_use'],
                ['value' => $termsOfUseContent]
            );

            return redirect()->route('admin.settings.terms-of-use')->with([
                'status'  => 'success',
                'message' => __('locale.settings.settings_successfully_updated'),
            ]);
        }

        public function postPrivacyPolicy(Request $request)
        {
            if (config('app.stage') === 'demo') {
                return redirect()->route('admin.settings.privacy-policy')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $this->authorize('general settings');

            $privacyPolicyContent = $request->input('privacy_policy');
            $hasPolicy            = ! empty($privacyPolicyContent);

            AppConfig::setEnv('PRIVACY_POLICY', $hasPolicy);

            AppConfig::updateOrCreate(
                ['setting' => 'privacy_policy'],
                ['value' => $privacyPolicyContent]
            );

            return redirect()->route('admin.settings.privacy-policy')->with([
                'status'  => 'success',
                'message' => __('locale.settings.settings_successfully_updated'),
            ]);
        }


        /*Version 3.13*/

        public function gatewayWiseBilling(GatewayWiseBillingRequest $request): RedirectResponse
        {

            if (config('app.stage') == 'demo') {
                return redirect()->route('admin.settings.general')->withInput(['tab' => 'gateway_wise_billing'])->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }


            $this->settings->gatewayWiseBilling($request->except('_token'));

            return redirect()->route('admin.settings.general')->withInput(['tab' => 'gateway_wise_billing'])->with([
                'status'  => 'success',
                'message' => __('locale.settings.settings_successfully_updated'),
            ]);
        }


        public function maintenanceMode()
        {

            $breadcrumbs = [
                ['link' => url(config('app.admin_path') . "/dashboard"), 'name' => __('locale.menu.Dashboard')],
                ['link' => url(config('app.admin_path') . "/dashboard"), 'name' => __('locale.menu.Settings')],
                ['name' => __('locale.menu.Maintenance Mode')],
            ];

            $this->authorize('manage maintenance_mode');

// You can return a view showing the current maintenance status (optional)
            $isDown = app()->isDownForMaintenance();

            return view('admin.settings.AllSettings.maintenance-mode', compact('isDown', 'breadcrumbs'));
        }

        public function postMaintenanceMode(Request $request)
        {

            if (config('app.stage') == 'demo') {
                return redirect()->back()->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $validated = $request->validate([
                'status' => 'required|in:on,off',
                'secret' => 'required_if:status,on',
            ]);

            $notify = '';


            if ($validated['status'] === 'on' && $validated['secret']) {
                Artisan::call('down', [
                    '--secret' => $validated['secret'],
                    '--render' => 'errors.503',
                    '--retry'  => 60,
                ]);

                AppConfig::setEnv('MAINTENANCE_SECRET_PATH', $validated['secret']);

                $notify = __('locale.settings.maintenance_access_information', [
                    'url'    => Config::get('app.url'),
                    'secret' => $validated['secret'],
                ]);
            } else {
                Artisan::call('up');

                $fromAddress = Config::get('mail.from.address');
                $fromName    = Config::get('mail.from.name');

                if ($fromAddress && $fromName) {
                    DB::table('maintenance_notifications')->orderBy('id')->chunk(100, function ($emails) {
                        foreach ($emails as $row) {
                            Notification::route('mail', $row->email)->notifyNow(new MaintenanceEnded);
                        }
                    });
                }

                DB::table('maintenance_notifications')->truncate();
            }

            return redirect()->back()->with([
                'status'  => 'success',
                'notify'  => $notify,
                'message' => 'Maintenance mode has been ' . ($validated['status'] === 'on' ? 'enabled' : 'disabled'),
            ]);
        }

        public function aiSettings()
        {

            $breadcrumbs = [
                ['link' => url(config('app.admin_path') . "/dashboard"), 'name' => __('locale.menu.Dashboard')],
                ['link' => url(config('app.admin_path') . "/dashboard"), 'name' => __('locale.menu.Settings')],
                ['name' => __('locale.menu.AI Settings')],
            ];


            $this->authorize('manage ai_settings');

            return view('admin.settings.AllSettings.ai-settings', compact('breadcrumbs'));
        }


        public function toggleAiSettings(Request $request)
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            if ($request->has('openai_enabled')) {
                $openai_enabled = $request->input('openai_enabled');


                AppConfig::setEnv('OPENAI_ACTIVE', $openai_enabled);

                return response()->json([
                    'status'  => 'success',
                    'message' => 'AI settings has been ' . ($openai_enabled == 'true' ? 'enabled' : 'disabled'),
                ]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => __('locale.settings.something_went_wrong'),
            ]);

        }


        public function postAiSettings(OpenAISettingsRequest $request)
        {
            if (config('app.stage') == 'demo') {
                return redirect()->back()->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $this->settings->aiSettings($request->except('_token'));

            return redirect()->back()->with([
                'status'  => 'success',
                'message' => __('locale.settings.settings_successfully_updated'),
            ]);

        }


    }

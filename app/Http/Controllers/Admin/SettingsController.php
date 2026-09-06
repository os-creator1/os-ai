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
    use App\Http\Requests\Settings\RemoveBrandingAssetRequest;
    use App\Http\Requests\Settings\SendTestEmailRequest;
    use App\Http\Requests\Settings\SystemEmailRequest;
    use App\Library\Branding\BrandingUploadService;
    use App\Library\Settings\PlatformSettingsEnvWriter;
    use App\Mail\PlatformSettingsTestEmail;
    use App\Models\AppConfig;
    use App\Models\Customer;
    use App\Models\Language;
    use App\Notifications\MaintenanceEnded;
    use App\Repositories\Contracts\SettingsRepository;
    use Illuminate\Auth\Access\AuthorizationException;
    use Illuminate\Contracts\Foundation\Application;
    use Illuminate\Contracts\View\Factory;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\RedirectResponse;
    use Illuminate\Http\Request;
    use Illuminate\Support\Arr;
    use Illuminate\Support\Facades\Artisan;
    use Illuminate\Support\Facades\Config;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Mail;
    use Illuminate\Support\Facades\Notification;
    use Illuminate\View\View;
    use Throwable;

    /**
     * B3 Simplified Platform Settings.
     *
     * Replaces the inherited 9-tab "All Settings" vendor screen with one
     * page of six sections (Platform, Appearance, Email, Sign-in &
     * Security, AI, Advanced). Every write endpoint below keeps its exact
     * pre-B3 route name and env/DB key shapes so nothing outside the
     * presentation layer (customer-facing config reads, Lane A's Agency
     * Prospecting OpenAI seam, existing FormRequest abilities) is
     * affected. See docs/automation for the B3 reconnaissance report this
     * implementation follows.
     */
    class SettingsController extends AdminBaseController
    {
        protected SettingsRepository $settings;

        private PlatformSettingsEnvWriter $envWriter;

        /**
         * B3 §17/§4 — the only six branding fields BrandingUploadService
         * knows how to store or remove. Shared by postGeneral() (store)
         * and removeBrandingAsset() (delete); RemoveBrandingAssetRequest
         * validates against the same key set independently.
         */
        private const BRANDING_FIELDS = [
            'app_logo' => 'logo',
            'app_favicon' => 'favicon',
            'logo_compact' => 'logo_compact',
            'logo_dark' => 'logo_dark',
            'auth_illustration' => 'auth_illustration',
            'installer_illustration' => 'installer_illustration',
        ];

        /**
         * SettingsController constructor.
         */
        public function __construct(SettingsRepository $settings, PlatformSettingsEnvWriter $envWriter)
        {
            $this->settings  = $settings;
            $this->envWriter = $envWriter;
        }

        /**
         * B3 Simplified Platform Settings — the one settings page. Section
         * visibility mirrors each section's own write ability, so a
         * scoped admin never sees a tab whose form they could not submit
         * — including one who holds only 'manage ai_settings' and none of
         * the other abilities: the pre-B3 AI settings page was its own
         * route gated solely by that ability, independent of 'general
         * settings', and that independence is preserved here (the page
         * itself requires at least one of the six section abilities, not
         * 'general settings' specifically). Advanced is shown to anyone
         * holding 'general settings' (custom script, diagnostics) or
         * 'authentication settings' (default customer permissions,
         * mirroring DefaultCustomerPermission's own ability — the pre-B3
         * tab shell scoped that same sub-form to 'authentication
         * settings', not 'general settings', and _advanced.blade.php
         * keeps that per-block @can split).
         *
         * @throws AuthorizationException
         */
        public function general(Request $request): \Illuminate\Contracts\View\View|Factory|string|Application
        {
            $user = $request->user();

            $sectionCandidates = [
                'platform' => ['label' => 'Platform', 'ability' => 'general settings'],
                'appearance' => ['label' => 'Appearance', 'ability' => 'general settings'],
                'email' => ['label' => 'Email', 'ability' => 'system_email settings'],
                'security' => ['label' => 'Sign-in & Security', 'ability' => 'authentication settings'],
                'ai' => ['label' => 'AI', 'ability' => 'manage ai_settings'],
                'advanced' => ['label' => 'Advanced', 'ability' => 'general settings|authentication settings'],
            ];

            $tabs = [];
            foreach ($sectionCandidates as $key => $meta) {
                if ($user->canAny(explode('|', $meta['ability']))) {
                    $tabs[$key] = $meta['label'];
                }
            }

            if ($tabs === []) {
                throw new AuthorizationException();
            }

            $breadcrumbs = [
                ['link' => url(config('app.admin_path') . "/dashboard"), 'name' => __('locale.menu.Dashboard')],
                ['link' => url(config('app.admin_path') . "/dashboard"), 'name' => __('locale.menu.Settings')],
                ['name' => __('locale.menu.All Settings')],
            ];

            $language = Language::where('status', true)->get();

            // B3 §8/§21 — a read-only diagnostic, not a live server scan.
            // The previous implementation ran exec('whereis php') and
            // exec('which php') on every render of this page and offered
            // an editable-looking radio list that was never actually
            // wired to any form (the radios sat outside any <form>, so a
            // selection was never submitted anywhere). This shows the
            // currently configured value only.
            $phpBinPath = Helper::app_config('php_bin_path');
            $execEnabled = Helper::exec_enabled();

            $categories = collect(config('customer-permissions'))->map(function ($value, $key) {
                $value['name'] = $key;

                return $value;
            })->groupBy('category');

            $permissionGroups = $categories->keys()->map(function ($key) use ($categories) {
                return [
                    'title'       => $key,
                    'permissions' => $categories[$key],
                ];
            });

            $existingPermissions = json_decode(Customer::customerPermissions(), true);

            // 'tab' arrives two ways: flashed old input after a POST
            // redirect (withInput(['tab' => ...])), or a plain query
            // string on a fresh GET -- e.g. the old aiSettings() route's
            // own redirect to ?tab=ai, kept for previously bookmarked
            // links.
            $requestedTab = old('tab', $request->query('tab'));

            return view('admin.settings.platform.index', [
                'breadcrumbs' => $breadcrumbs,
                'tabs' => $tabs,
                'activeTab' => (is_string($requestedTab) && isset($tabs[$requestedTab])) ? $requestedTab : (array_key_first($tabs) ?? 'platform'),
                'language' => $language,
                'phpBinPath' => $phpBinPath,
                'execEnabled' => $execEnabled,
                'permissionGroups' => $permissionGroups,
                'existingPermissions' => $existingPermissions,
            ]);
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
         * update general/platform + appearance settings
         *
         * B3 §10 (GENERAL SETTINGS WRITE ALLOWLIST — security blocker).
         * $request->validated() is already bounded by PostGeneralRequest::
         * rules()'s own key set, so nothing outside those declared fields
         * (a submitted 'license', 'password', 'customer_permissions',
         * 'captcha_secret_key', etc.) is ever read here at all — closing
         * the previous $request->except(...) exclusion-list defect, which
         * let any submitted key reach EloquentSettingsRepository::
         * general()'s own generic write loop (that method now also
         * enforces its own independent allowlist; see its doc comment).
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

            $validated = $request->validated();

            /*
             * Design System M2 Platform Branding contract §6.3/§11 items
             * 14-15. Every branding upload field routes through
             * BrandingUploadService (safe content-hashed filenames,
             * magic-byte-validated by ValidBrandingImageRule, §6.3) —
             * AppConfig::uploadFile()'s client-extension-derived filename
             * is no longer used for any field.
             */
            $brandingUploadService = app(BrandingUploadService::class);

            foreach (self::BRANDING_FIELDS as $field => $configKey) {
                if ($request->hasFile($field) && $request->file($field)->isValid()) {
                    $brandingUploadService->store($request->file($field), $configKey);
                }
            }

            $this->envWriter->set('APP_NAME', 'app.name', $validated['app_name']);
            $this->envWriter->set('APP_TITLE', 'app.title', $validated['app_title']);
            $this->envWriter->set('APP_COUNTRY', 'app.country', $validated['country']);
            $this->envWriter->set('APP_TIMEZONE', 'app.timezone', $validated['timezone']);
            $this->envWriter->set('APP_TIME_FORMAT', 'app.time_format', $validated['time_format']);
            $this->envWriter->set('APP_DATE_FORMAT', 'app.date_format', $validated['date_format']);
            // B3 Correction 1 — app_keyword/footer_company_name/
            // footer_copyright_text are each owned by exactly one of the
            // three independent forms that all post here (Platform owns
            // app_keyword, Appearance owns the two footer fields).
            // PostGeneralRequest declares each 'sometimes', so a form
            // that never sent a given field leaves it entirely absent
            // from $validated (array_key_exists() false) rather than
            // present-as-null — the only way to tell "this section
            // didn't send it, preserve the current value" apart from
            // "this section explicitly cleared it" once
            // ConvertEmptyStringsToNull has already turned a submitted
            // '' into null upstream of validation.
            if (array_key_exists('app_keyword', $validated)) {
                $this->envWriter->set('APP_KEYWORD', 'app.keyword', (string) ($validated['app_keyword'] ?? ''));
            }

            if (array_key_exists('footer_company_name', $validated)) {
                $this->envWriter->set('APP_FOOTER_COMPANY_NAME', 'app.footer_company_name', (string) ($validated['footer_company_name'] ?? ''));
            }

            if (array_key_exists('footer_copyright_text', $validated)) {
                $this->envWriter->set('APP_FOOTER_COPYRIGHT_TEXT', 'app.footer_copyright_text', (string) ($validated['footer_copyright_text'] ?? ''));
            }

            if (! empty($validated['language'])) {
                session(['locale' => $validated['language']]);
                $this->envWriter->set('APP_LOCALE', 'app.locale', $validated['language']);
            }

            // B3 §3 — the legacy side effect that silently mutated the
            // hard-coded User id=1's own timezone column whenever the
            // Platform timezone changed has been removed. No consumer
            // depends on it: EloquentUserRepository/EloquentCustomerRepository
            // already fall back to config('app.timezone') whenever a
            // user's own timezone column is empty, so a Platform setting
            // no longer reaches into a specific user's row at all. The
            // global timezone remains exactly config('app.timezone').

            // B3 Correction 1 — custom_script is owned solely by the
            // Advanced form. array_key_exists() (not the previous
            // null/empty check on the value alone) is what tells apart
            // "the Platform/Appearance form submitted this request and
            // never mentioned custom_script at all" (preserve) from "the
            // Advanced form submitted it as an explicit empty string"
            // (clear) -- both look identical as a bare value once
            // ConvertEmptyStringsToNull has turned a submitted '' into
            // null, so only key presence can distinguish them.
            if (array_key_exists('custom_script', $validated)) {
                $submittedCustomScript = (string) ($validated['custom_script'] ?? '');
                $currentCustomScript   = (string) Helper::app_config('custom_script');

                if ($submittedCustomScript === '') {
                    if ($currentCustomScript !== '') {
                        AppConfig::where('setting', 'custom_script')->update([
                            'value' => '',
                        ]);
                    }
                } elseif ($submittedCustomScript !== $currentCustomScript) {
                    $script = $this->sanitizeScript($submittedCustomScript);

                    AppConfig::where('setting', 'custom_script')->update([
                        'value' => $script,
                    ]);
                }
            }

            $this->settings->general(Arr::only($validated, [
                'app_name',
                'app_title',
                'app_keyword',
                'company_address',
                'country',
                'timezone',
                'date_format',
                'time_format',
                'language',
            ]));

            return redirect()->route('admin.settings.general')->withInput(['tab' => 'platform'])->with([
                'status'  => 'success',
                'message' => __('locale.settings.settings_successfully_updated'),
            ]);
        }

        /**
         * B3 §4/§17 — remove one owner-configured branding asset,
         * returning it to the M2 bundled/neutral fallback. Only the six
         * allowlisted logical keys RemoveBrandingAssetRequest validates
         * against are ever accepted; there is no way to pass an arbitrary
         * env key or file path through this endpoint.
         */
        public function removeBrandingAsset(RemoveBrandingAssetRequest $request): RedirectResponse
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('admin.settings.general')->withInput(['tab' => 'appearance'])->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $field = $request->validated()['asset'];

            app(BrandingUploadService::class)->delete(self::BRANDING_FIELDS[$field]);

            return redirect()->route('admin.settings.general')->withInput(['tab' => 'appearance'])->with([
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

            $this->settings->systemEmail($request->validated());

            return redirect()->route('admin.settings.general')->withInput(['tab' => 'email'])->with([
                'status'  => 'success',
                'message' => __('locale.settings.settings_successfully_updated'),
            ]);
        }

        /**
         * B3 §5/§18 — the minimal "Send test email" action. Never leaks
         * SMTP host/username/password or the underlying exception message
         * into the flash message; a failure is reported generically.
         */
        public function testEmail(SendTestEmailRequest $request): RedirectResponse
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('admin.settings.general')->withInput(['tab' => 'email'])->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $destination = $request->validated()['email'];

            try {
                Mail::to($destination)->send(new PlatformSettingsTestEmail());
            } catch (Throwable) {
                return redirect()->route('admin.settings.general')->withInput(['tab' => 'email'])->with([
                    'status'  => 'error',
                    'message' => __('locale.settings.test_email_failed'),
                ]);
            }

            return redirect()->route('admin.settings.general')->withInput(['tab' => 'email'])->with([
                'status'  => 'success',
                'message' => __('locale.settings.test_email_sent'),
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

            $this->settings->authentication($request->validated());

            return redirect()->route('admin.settings.general')->withInput(['tab' => 'security'])->with([
                'status'  => 'success',
                'message' => __('locale.settings.settings_successfully_updated'),
            ]);
        }


        /**
         * update notifications settings
         *
         * B3 §9 — no longer a first-class Settings section; the backend,
         * rows, and consumers are unchanged and this endpoint stays
         * reachable (still gated by 'notifications settings').
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
         * B3 §9 — infrastructure only, no longer a Settings tab. Backend
         * unchanged; still gated by 'pusher settings'.
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

        /*Version 3.4*/

        /**
         * Update Default Customer Permissions
         *
         * B3 §8 — moved to the Advanced section. Fixes the persistence
         * defect the reconnaissance found: the previous code stored the
         * raw PHP array directly into app_config.value (a string column),
         * while every reader (Customer::customerPermissions(), User model,
         * CustomerController) calls json_decode() on it — now persisted as
         * the JSON string the readers already expect. Demo mode is now
         * enforced here too (previously the only settings writer without
         * that guard).
         *
         * @param DefaultCustomerPermission $request
         *
         * @return RedirectResponse
         */
        public function permissions(DefaultCustomerPermission $request): RedirectResponse
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('admin.settings.general')->withInput(['tab' => 'advanced'])->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            // DefaultCustomerPermission::rules() only declares 'permissions'
            // (array) and the fixed leaf 'permissions.access_backend' (always
            // required) -- there is no permissions.* wildcard rule, because
            // the actual set of checkbox names is dynamic
            // (config('customer-permissions')). $request->validated() would
            // therefore silently drop every OTHER submitted permission name,
            // keeping only access_backend. $request->only(...) is correct
            // here (unlike postGeneral()'s former use of except()): this
            // endpoint only ever writes the single, fixed
            // 'customer_permissions' row below, never an arbitrary
            // request-controlled app_config key, so there is no allowlist
            // gap to close by switching extraction methods.
            $permissions = array_values($request->only('permissions')['permissions']);

            $updated = AppConfig::where('setting', 'customer_permissions')->update([
                'value' => json_encode($permissions),
            ]);

            if ($updated) {
                return redirect()->route('admin.settings.general')->withInput(['tab' => 'advanced'])->with([
                    'status'  => 'success',
                    'message' => __('locale.settings.settings_successfully_updated'),
                ]);
            }

            return redirect()->route('admin.settings.general')->withInput(['tab' => 'advanced'])->with([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
            ]);
        }


        /*Version 3.5*/

        /**
         * B3 §9 — provider-specific vendor plumbing, no longer a Settings
         * tab. Backend unchanged; still gated by 'general settings'.
         */
        public function dlt(DLTRequest $request): RedirectResponse
        {

            if (config('app.stage') == 'demo') {
                return redirect()->route('admin.settings.general')->withInput(['tab' => 'advanced'])->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }


            $this->settings->dlt($request->except('_token'));

            return redirect()->route('admin.settings.general')->withInput(['tab' => 'advanced'])->with([
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

        /**
         * B3 §9 — legacy gateway-wise billing, already removed from
         * presentation before B3 (its tab and permission are commented
         * out on main). Backend kept exactly as-is until the RFC-005
         * billing cutover; not a B3 concern.
         */
        public function gatewayWiseBilling(GatewayWiseBillingRequest $request): RedirectResponse
        {

            if (config('app.stage') == 'demo') {
                return redirect()->route('admin.settings.general')->withInput(['tab' => 'advanced'])->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }


            $this->settings->gatewayWiseBilling($request->except('_token'));

            return redirect()->route('admin.settings.general')->withInput(['tab' => 'advanced'])->with([
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
            // B3 §13 — the GET action already required 'manage
            // maintenance_mode'; this destructive POST action (site-wide
            // down/up) did not, so any 'access backend' account could
            // toggle maintenance mode. Fixed here.
            $this->authorize('manage maintenance_mode');

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

        /**
         * B3 §7 — the AI section now lives inline on the main Settings
         * page (admin.settings.general, 'ai' tab). This GET redirect keeps
         * the old bookmarked/linked route working instead of a hard 404.
         */
        public function aiSettings(): RedirectResponse
        {
            $this->authorize('manage ai_settings');

            return redirect()->route('admin.settings.general', ['tab' => 'ai']);
        }


        /**
         * B3 §7 — fixed to require 'manage ai_settings' independently of
         * generic backend access (the pre-B3 action had no authorization
         * check at all beyond the route group's blanket 'access backend').
         */
        public function toggleAiSettings(Request $request)
        {
            $this->authorize('manage ai_settings');

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            if ($request->has('openai_enabled')) {
                $openai_enabled = $request->input('openai_enabled');

                $this->envWriter->set('OPENAI_ACTIVE', 'services.openai.active', $openai_enabled);

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
                return redirect()->route('admin.settings.general')->withInput(['tab' => 'ai'])->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $this->settings->aiSettings($request->validated());

            return redirect()->route('admin.settings.general')->withInput(['tab' => 'ai'])->with([
                'status'  => 'success',
                'message' => __('locale.settings.settings_successfully_updated'),
            ]);

        }


    }

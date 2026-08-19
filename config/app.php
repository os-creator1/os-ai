<?php

    use Illuminate\Support\Facades\Facade;

    return [

        /*
        |--------------------------------------------------------------------------
        | Application Name
        |--------------------------------------------------------------------------
        |
        | This value is the name of your application. This value is used when the
        | framework needs to place the application's name in a notification or
        | any other location as required by the application or its packages.
        |
        */

        'name' => env('APP_NAME', 'AI Business OS'),


        /*
        |--------------------------------------------------------------------------
        | Application Title
        |--------------------------------------------------------------------------
        |
        | This value is the title of your application. This value is used when the
        | framework needs to place the application's title in a notification or
        | any other location as required by the application or its packages.
        |
        */

        'title' => env('APP_TITLE', 'Business Operations Platform'),


        /*
        |--------------------------------------------------------------------------
        | Application Keyword
        |--------------------------------------------------------------------------
        |
        | This value is the seo keyword of your application.
        |
        */

        'keyword'       => env('APP_KEYWORD', 'business operations, automation, ai business os, workspace, crm, messaging'),


        /*
        |--------------------------------------------------------------------------
        | Application logo
        |--------------------------------------------------------------------------
        |
        | This is the logo of your application.
        |
        */
        'logo'          => env('APP_LOGO', 'images/branding/default-logo.svg'),


        /*
        |--------------------------------------------------------------------------
        | Application logo - compact/sidebar variant
        |--------------------------------------------------------------------------
        |
        | This is the compact/collapsed-sidebar/mobile-header logo variant of
        | your application. Falls back to the full logo, then to a neutral mark,
        | if not configured. Deliberately not named APP_LOGO_COMPACT:
        | AppConfig::setEnv()'s own find/replace is a substring match on the
        | key, so any .env key containing "APP_LOGO" as a substring would be
        | silently corrupted by an update to the plain APP_LOGO key.
        |
        */
        'logo_compact'  => env('APP_COMPACT_LOGO', null),


        /*
        |--------------------------------------------------------------------------
        | Application logo - dark-background variant
        |--------------------------------------------------------------------------
        |
        | This is the optional dark-background logo variant of your application.
        | Falls back to the full, light-background logo, then to a neutral mark,
        | if not configured. Deliberately not named APP_LOGO_DARK -- see the
        | APP_COMPACT_LOGO note above; the same substring-collision risk
        | applies identically here.
        |
        */
        'logo_dark'     => env('APP_DARK_LOGO', null),


        /*
        |--------------------------------------------------------------------------
        | Application favicon
        |--------------------------------------------------------------------------
        |
        | This is the favicon of your application.
        |
        */
        'favicon'       => env('APP_FAVICON', 'images/branding/default-favicon.svg'),


        /*
        |--------------------------------------------------------------------------
        | Authentication-page illustration
        |--------------------------------------------------------------------------
        |
        | Optional owner-configured illustration shown on the authentication
        | surface. Falls back to the bundled illustration when not configured.
        |
        */
        'auth_illustration' => env('APP_AUTH_ILLUSTRATION', null),


        /*
        |--------------------------------------------------------------------------
        | Installer illustration
        |--------------------------------------------------------------------------
        |
        | Optional owner-configured illustration shown on the Installer surface.
        | Falls back to the bundled illustration when not configured.
        |
        */
        'installer_illustration' => env('APP_INSTALLER_ILLUSTRATION', null),


        /*
        |--------------------------------------------------------------------------
        | Footer company name
        |--------------------------------------------------------------------------
        |
        | Optional owner-configured company name shown in the footer copyright
        | line. Falls back to the application name when not configured.
        |
        */
        'footer_company_name' => env('APP_FOOTER_COMPANY_NAME', null),


        /*
        |--------------------------------------------------------------------------
        | Footer copyright wording
        |--------------------------------------------------------------------------
        |
        | Optional short owner-configured suffix appended to the footer
        | copyright line (e.g. "All rights reserved"). The copyright year
        | itself is always computed automatically at render time, never
        | stored here.
        |
        */
        'footer_copyright_text' => env('APP_FOOTER_COPYRIGHT_TEXT', null),


        /*
        |--------------------------------------------------------------------------
        | Application custom script
        |--------------------------------------------------------------------------
        |
        | This is the custom script of your application.
        |
        */
        'custom_script' => env('APP_CUSTOM_SCRIPT', ''),


        /*
        |--------------------------------------------------------------------------
        | Application Environment
        |--------------------------------------------------------------------------
        |
        | This value determines the "environment" your application is currently
        | running in. This may determine how you prefer to configure various
        | services the application utilizes. Set this in your ".env" file.
        |
        */

        'env' => env('APP_ENV', 'production'),

        /*
        |--------------------------------------------------------------------------
        | Application Stage
        |--------------------------------------------------------------------------
        |
        | This value determines the "stage" your application is currently
        | running in. This may determine how you prefer to configure various
        | services the application utilizes. Set this in your ".env" file.
        |
        */

        'stage' => env('APP_STAGE', 'Live'),

        /*
        |--------------------------------------------------------------------------
        | Application Debug Mode
        |--------------------------------------------------------------------------
        |
        | When your application is in debug mode, detailed error messages with
        | stack traces will be shown on every error that occurs within your
        | application. If disabled, a simple generic error page is shown.
        |
        */

        'debug' => env('APP_DEBUG', false),

        /*
        |--------------------------------------------------------------------------
        | Application URL
        |--------------------------------------------------------------------------
        |
        | This URL is used by the console to properly generate URLs when using
        | the Artisan command line tool. You should set this to the root of
        | your application so that it is used when running Artisan tasks.
        |
        */

        'url'            => env('APP_URL', 'http://localhost'),
        'asset_url'      => env('ASSET_URL', ''),
        'terms_of_use'   => env('TERMS_OF_USE', ''),
        'privacy_policy' => env('PRIVACY_POLICY', ''),

        /*
        |--------------------------------------------------------------------------
        | Application Timezone
        |--------------------------------------------------------------------------
        |
        | Here you may specify the default timezone for your application, which
        | will be used by the PHP date and date-time functions. We have gone
        | ahead and set this to a sensible default for you out of the box.
        |
        */

        'timezone' => env('APP_TIMEZONE', 'UTC'),

        'date_format' => env('APP_DATE_FORMAT', 'jS M y'),

        'time_format' => env('APP_TIME_FORMAT', 'g:i A'),

        /*
        |--------------------------------------------------------------------------
        | Application Timezone
        |--------------------------------------------------------------------------
        |
        | Here you may specify the default timezone for your application, which
        | will be used by the PHP date and date-time functions. We have gone
        | ahead and set this to a sensible default for you out of the box.
        |
        */

        'version' => env('APP_VERSION', '3.13.0'),

        /*
        |--------------------------------------------------------------------------
        | Application Locale Configuration
        |--------------------------------------------------------------------------
        |
        | The application locale determines the default locale that will be used
        | by the translation service provider. You are free to set this value
        | to any of the locales which will be supported by the application.
        |
        */


        'locale' => env('APP_LOCALE', 'en'),

        'locale_direction' => env('APP_DIRECTION', 'ltr'),
        /*
        |--------------------------------------------------------------------------
        | Application Fallback Locale
        |--------------------------------------------------------------------------
        |
        | The fallback locale determines the locale to use when the current one
        | is not available. You may change the value to correspond to any of
        | the language folders that are provided through your application.
        |
        */


        'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

        /*
        |--------------------------------------------------------------------------
        | Faker Locale
        |--------------------------------------------------------------------------
        |
        | This locale will be used by the Faker PHP library when generating fake
        | data for your database seeders. For example, this will be used to get
        | localized telephone numbers, street address information and more.
        |
        */

        'faker_locale' => 'en_US',

        /*
        |--------------------------------------------------------------------------
        | Encryption Key
        |--------------------------------------------------------------------------
        |
        | This key is used by the Illuminate encrypter service and should be set
        | to a random, 32 character string, otherwise these encrypted strings
        | will not be safe. Please do this before deploying an application!
        |
        */

        'key' => env('APP_KEY'),

        'cipher' => 'AES-256-CBC',


        /*
        |--------------------------------------------------------------------------
        | Application Default Country
        |--------------------------------------------------------------------------
        */

        'country' => env('APP_COUNTRY', 'Bangladesh'),


        /*
        |--------------------------------------------------------------------------
        | Application Custom admin path
        |--------------------------------------------------------------------------
        */

        'admin_path' => env('ADMIN_PATH', 'admin'),

        /*
        |--------------------------------------------------------------------------
        | Application Site Editor
        |--------------------------------------------------------------------------
        */

        'editor_name' => env('EDITOR_NAME'),

        'editor_site_url' => env('EDITOR_SITE_URL'),

        'editor_alert_mail' => env('EDITOR_ALERT_MAIL'),

        /*
        |--------------------------------------------------------------------------
        | Application Site Google Tag Manager
        |--------------------------------------------------------------------------
        */

        'gtm_user_id' => env('GTM_USER_ID'),

        /*
        |--------------------------------------------------------------------------
        | URL config
        |--------------------------------------------------------------------------
        */

        'url_force_https' => env('URL_FORCE_HTTPS', false),

        /*
        |--------------------------------------------------------------------------
        | Set App on read-only mode (demo purpose)
        |--------------------------------------------------------------------------
        */

        'read_only' => env('READ_ONLY', false),


        /*
        |--------------------------------------------------------------------------
        | purchase code from envato marketplace for verify real product
        |--------------------------------------------------------------------------
        */

        'purchase_key' => env('PURCHASE_CODE', ''),


        /*
        |--------------------------------------------------------------------------
        | Two step verification in login
        |--------------------------------------------------------------------------
        */

        'two_factor' => env('TWO_FACTOR', false),


        /*
        |--------------------------------------------------------------------------
        | Two step verification send by
        |--------------------------------------------------------------------------
        */

        'two_factor_send_by' => env('AUTH_CODE_SEND_BY', 'email'),


        /*
        |--------------------------------------------------------------------------
        | Two step verification send by
        |--------------------------------------------------------------------------
        */

        'super_admin_email' => env('SUPER_ADMIN_EMAIL', 'akasham67@gmail.com'),


        /*
        |--------------------------------------------------------------------------
        | API Rate Limit
        |--------------------------------------------------------------------------
        */

        'api_rate_limit' => env('API_RATE_LIMIT', '1000'),


        /*
        |--------------------------------------------------------------------------
        | Theme layout type
        |--------------------------------------------------------------------------
        */

        'theme_layout_type'  => env('THEME_LAYOUT_TYPE', 'vertical'),


        /*
        |--------------------------------------------------------------------------
        | import csv file fields for contact import
        |--------------------------------------------------------------------------
        */
        'db_fields'          => [
            '--'               => '--',
            'phone'            => 'Phone',
            'first_name'       => 'First name',
            'last_name'        => 'Last name',
            'email'            => 'Email',
            'username'         => 'Username',
            'company'          => 'Company',
            'address'          => 'Address',
            'birth_date'       => 'Birthday',
            'anniversary_date' => 'Anniversary Date',
        ],

        /*
        |--------------------------------------------------------------------------
        | import csv file fields for campaign
        |--------------------------------------------------------------------------
        */
        'campaign_db_fields' => [
            '--'    => '--',
            'phone' => 'Phone',
        ],

        /*
        |--------------------------------------------------------------------------
        | Customer Import CSV File
        |--------------------------------------------------------------------------
        */

        'customer_db_fields' => [
            '--'                  => '--',
            'first_name'          => 'First Name',
            'last_name'           => 'Last Name',
            'email'               => 'Email',
            'password'            => 'Password',
            'phone'               => 'Phone',
            'timezone'            => 'Timezone',
            'company'             => 'Company',
            'website'             => 'Website',
            'address'             => 'Address',
            'city'                => 'City',
            'state'               => 'State',
            'postcode'            => 'Zip',
            'tax_number'          => 'Tax ID',
            'country'             => 'Country',
        ],


        /*
        |--------------------------------------------------------------------------
        | TRAI - Distributed Ledger Technology (DLT)
        |--------------------------------------------------------------------------
        |
        | DLT for Indian local operators to send sms
        |
        */

        'trai_dlt'                => env('TRAI_DLT', false),


        /*
        |--------------------------------------------------------------------------
        | Import Batch Size
        |--------------------------------------------------------------------------
        */
        'import_batch_size'       => env('IMPORT_BATCH_SIZE', 9993),


        /*
        |--------------------------------------------------------------------------
        | Gateway Wise Billing
        |--------------------------------------------------------------------------
        */
        'gateway_wise_billing'    => env('GATEWAY_WISE_BILLING', false),


        /*
        |--------------------------------------------------------------------------
        | Maintenance Mode
        |--------------------------------------------------------------------------
        */
        'maintenance_secret_path' => env('MAINTENANCE_SECRET_PATH', 'codeglen'),


        /*
        |--------------------------------------------------------------------------
        | Autoloaded Service Providers
        |--------------------------------------------------------------------------
        |
        | The service providers listed here will be automatically loaded on the
        | request to your application. Feel free to add your own services to
        | this array to grant expanded functionality to your applications.
        |
        */

        'providers' => [

            /*
             * Laravel Framework Service Providers...
             */
            Illuminate\Auth\AuthServiceProvider::class,
            Illuminate\Broadcasting\BroadcastServiceProvider::class,
            Illuminate\Bus\BusServiceProvider::class,
            Illuminate\Cache\CacheServiceProvider::class,
            Illuminate\Foundation\Providers\ConsoleSupportServiceProvider::class,
            Illuminate\Cookie\CookieServiceProvider::class,
            Illuminate\Database\DatabaseServiceProvider::class,
            Illuminate\Encryption\EncryptionServiceProvider::class,
            Illuminate\Filesystem\FilesystemServiceProvider::class,
            Illuminate\Foundation\Providers\FoundationServiceProvider::class,
            Illuminate\Hashing\HashServiceProvider::class,
            Illuminate\Mail\MailServiceProvider::class,
            Illuminate\Notifications\NotificationServiceProvider::class,
            Illuminate\Pagination\PaginationServiceProvider::class,
            Illuminate\Pipeline\PipelineServiceProvider::class,
            Illuminate\Queue\QueueServiceProvider::class,
            Illuminate\Redis\RedisServiceProvider::class,
            Illuminate\Auth\Passwords\PasswordResetServiceProvider::class,
            Illuminate\Session\SessionServiceProvider::class,
            Illuminate\Translation\TranslationServiceProvider::class,
            Illuminate\Validation\ValidationServiceProvider::class,
            Illuminate\View\ViewServiceProvider::class,
            Maatwebsite\Excel\ExcelServiceProvider::class,

            /*
             * Package Service Providers...
             */

            /*
             * Application Service Providers...
             */
            App\Providers\AppServiceProvider::class,
            App\Providers\AuthServiceProvider::class,
            App\Providers\BroadcastServiceProvider::class,
            App\Providers\EventServiceProvider::class,
            App\Providers\RouteServiceProvider::class,
            App\Providers\MenuServiceProvider::class,
            kashem\licenseChecker\licenseCheckerServiceProvider::class,
            App\Providers\JobServiceProvider::class,
        ],

        /*
        |--------------------------------------------------------------------------
        | Class Aliases
        |--------------------------------------------------------------------------
        |
        | This array of class aliases will be registered when this application
        | is started. However, feel free to register as many as you wish as
        | the aliases are "lazy" loaded so they don't hinder performance.
        |
        */

        'aliases' => Facade::defaultAliases()->merge([
            'Helper' => App\Helpers\Helper::class,
            'Excel'  => Maatwebsite\Excel\Facades\Excel::class,
        ])->toArray(),


    ];

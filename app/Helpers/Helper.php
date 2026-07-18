<?php

// Code within app\Helpers\Helper.php

    namespace App\Helpers;

    use App\Library\StringHelper;
    use App\Library\Tool;
    use App\Models\AppConfig;
    use App\Models\Contacts;
    use App\Models\Language;
    use Closure;
    use DateTime;
    use DateTimeZone;
    use Exception;
    use Illuminate\Contracts\Foundation\Application;
    use Illuminate\Contracts\Translation\Translator;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Support\Facades\Config;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\File;
    use Illuminate\Support\Facades\Gate;
    use Illuminate\Support\Facades\Session;

    class Helper
    {
        public static function applClasses(): array
        {
            if (config('app.theme_layout_type') == 'vertical') {
                $data = config('custom.vertical');
            } else {
                $data = config('custom.horizontal');
            }

            // default data array
            $DefaultData = [
                'mainLayoutType'         => 'vertical',
                'theme'                  => 'light',
                'sidebarCollapsed'       => false,
                'navbarColor'            => '',
                'horizontalMenuType'     => 'floating',
                'verticalMenuNavbarType' => 'floating',
                'footerType'             => 'static', //footer
                'layoutWidth'            => 'boxed',
                'showMenu'               => true,
                'bodyClass'              => '',
                'pageClass'              => '',
                'pageHeader'             => true,
                'contentLayout'          => 'default',
                'blankPage'              => false,
                'defaultLanguage'        => config('app.locale'),
                'direction'              => config('app.locale_direction'),
            ];

            // if any key missing of array from custom.php file it will be merged and set a default value from dataDefault array and store in data variable
            $data = array_merge($DefaultData, $data);

            // All options available in the template
            $allOptions = [
                'mainLayoutType'         => ['vertical', 'horizontal'],
                'theme'                  => ['light' => 'light', 'dark' => 'dark-layout', 'bordered' => 'bordered-layout', 'semi-dark' => 'semi-dark-layout'],
                'sidebarCollapsed'       => [true, false],
                'showMenu'               => [true, false],
                'layoutWidth'            => ['full', 'boxed'],
                'navbarColor'            => ['bg-primary', 'bg-info', 'bg-warning', 'bg-success', 'bg-danger', 'bg-dark'],
                'horizontalMenuType'     => ['floating' => 'navbar-floating', 'static' => 'navbar-static', 'sticky' => 'navbar-sticky'],
                'horizontalMenuClass'    => ['static' => '', 'sticky' => 'fixed-top', 'floating' => 'floating-nav'],
                'verticalMenuNavbarType' => ['floating' => 'navbar-floating', 'static' => 'navbar-static', 'sticky' => 'navbar-sticky', 'hidden' => 'navbar-hidden'],
                'navbarClass'            => ['floating' => 'floating-nav', 'static' => 'navbar-static-top', 'sticky' => 'fixed-top', 'hidden' => 'd-none'],
                'footerType'             => ['static' => 'footer-static', 'sticky' => 'footer-fixed', 'hidden' => 'footer-hidden'],
                'pageHeader'             => [true, false],
                'contentLayout'          => ['default', 'content-left-sidebar', 'content-right-sidebar', 'content-detached-left-sidebar', 'content-detached-right-sidebar'],
                'blankPage'              => [false, true],
                'sidebarPositionClass'   => ['content-left-sidebar' => 'sidebar-left', 'content-right-sidebar' => 'sidebar-right', 'content-detached-left-sidebar' => 'sidebar-detached sidebar-left', 'content-detached-right-sidebar' => 'sidebar-detached sidebar-right', 'default' => 'default-sidebar-position'],
                'contentsidebarClass'    => ['content-left-sidebar' => 'content-right', 'content-right-sidebar' => 'content-left', 'content-detached-left-sidebar' => 'content-detached content-right', 'content-detached-right-sidebar' => 'content-detached content-left', 'default' => 'default-sidebar'],
                'defaultLanguage'        => [
                    'af' => 'af',
                    'sq' => 'sq',
                    'am' => 'am',
                    'ar' => 'ar',
                    'hy' => 'hy',
                    'az' => 'az',
                    'bn' => 'bn',
                    'eu' => 'eu',
                    'be' => 'be',
                    'bg' => 'bg',
                    'ca' => 'ca',
                    'zh' => 'zh',
                    'hr' => 'hr',
                    'cs' => 'cs',
                    'da' => 'da',
                    'nl' => 'nl',
                    'en' => 'en',
                    'et' => 'et',
                    'fi' => 'fi',
                    'fr' => 'fr',
                    'gl' => 'gl',
                    'ka' => 'ka',
                    'de' => 'de',
                    'el' => 'el',
                    'gu' => 'gu',
                    'he' => 'he',
                    'hi' => 'hi',
                    'hu' => 'hu',
                    'is' => 'is',
                    'id' => 'id',
                    'ga' => 'ga',
                    'it' => 'it',
                    'ja' => 'ja',
                    'kk' => 'kk',
                    'ko' => 'ko',
                    'lv' => 'lv',
                    'lt' => 'lt',
                    'mk' => 'mk',
                    'ms' => 'ms',
                    'mn' => 'mn',
                    'ne' => 'ne',
                    'nb' => 'nb',
                    'nn' => 'nn',
                    'fa' => 'fa',
                    'pl' => 'pl',
                    'pt' => 'pt',
                    'ro' => 'ro',
                    'ru' => 'ru',
                    'sr' => 'sr',
                    'si' => 'si',
                    'sk' => 'sk',
                    'sl' => 'sl',
                    'es' => 'es',
                    'sw' => 'sw',
                    'sv' => 'sv',
                    'ta' => 'ta',
                    'te' => 'te',
                    'th' => 'th',
                    'tr' => 'tr',
                    'uk' => 'uk',
                    'ur' => 'ur',
                    'uz' => 'uz',
                    'vi' => 'vi',
                    'cy' => 'cy',
                ],
                'direction'              => ['ltr', 'rtl'],
            ];

            //if mainLayoutType value empty or not match with default options in custom.php config file then set a default value
            foreach ($allOptions as $key => $value) {
                if (array_key_exists($key, $DefaultData)) {
                    if (gettype($DefaultData[$key]) === gettype($data[$key])) {
                        // data key should be string
                        if (is_string($data[$key])) {
                            // data key should not be empty
                            if (true) {
                                // data key should not be existed inside allOptions array's sub array
                                if ( ! array_key_exists($data[$key], $value)) {
                                    // ensure that passed value should be match with any of allOptions array value
                                    $result = array_search($data[$key], $value, 'strict');
                                    if (empty($result) && $result !== 0) {
                                        $data[$key] = $DefaultData[$key];
                                    }
                                }
                            }
                        }
                    } else {
                        $data[$key] = $DefaultData[$key];
                    }
                }
            }

            //layout classes
            $layoutClasses = [
                'theme'                  => $data['theme'],
                'layoutTheme'            => $allOptions['theme'][$data['theme']],
                'sidebarCollapsed'       => $data['sidebarCollapsed'],
                'showMenu'               => $data['showMenu'],
                'layoutWidth'            => $data['layoutWidth'],
                'verticalMenuNavbarType' => $allOptions['verticalMenuNavbarType'][$data['verticalMenuNavbarType']],
                'navbarClass'            => $allOptions['navbarClass'][$data['verticalMenuNavbarType']],
                'navbarColor'            => $data['navbarColor'],
                'horizontalMenuType'     => $allOptions['horizontalMenuType'][$data['horizontalMenuType']],
                'horizontalMenuClass'    => $allOptions['horizontalMenuClass'][$data['horizontalMenuType']],
                'footerType'             => $allOptions['footerType'][$data['footerType']],
                'sidebarClass'           => '',
                'bodyClass'              => $data['bodyClass'],
                'pageClass'              => $data['pageClass'],
                'pageHeader'             => $data['pageHeader'],
                'blankPage'              => $data['blankPage'],
                'blankPageClass'         => '',
                'contentLayout'          => $data['contentLayout'],
                'sidebarPositionClass'   => $allOptions['sidebarPositionClass'][$data['contentLayout']],
                'contentsidebarClass'    => $allOptions['contentsidebarClass'][$data['contentLayout']],
                'mainLayoutType'         => $data['mainLayoutType'],
                'defaultLanguage'        => $allOptions['defaultLanguage'][$data['defaultLanguage']],
                'direction'              => $data['direction'],
            ];
            // set default language if session hasn't locale value the set default language
            if ( ! Session::has('locale')) {
                app()->setLocale(config('app.locale'));
            }

            // sidebar Collapsed
            if ($layoutClasses['sidebarCollapsed'] == 'true') {
                $layoutClasses['sidebarClass'] = 'menu-collapsed';
            }

            // blank page class
            if ($layoutClasses['blankPage'] == 'true') {
                $layoutClasses['blankPageClass'] = 'blank-page';
            }

            return $layoutClasses;
        }

        public static function updatePageConfig($pageConfigs): bool
        {
            $demo = config('app.theme_layout_type');
            if (isset($pageConfigs)) {
                if (count($pageConfigs) > 0) {
                    foreach ($pageConfigs as $config => $val) {
                        Config::set('custom.' . $demo . '.' . $config, $val);
                    }
                }
            }

            return false;
        }

        public static function home_route(): string
        {
            if (Gate::allows('access backend')) {
                return route('admin.home');
            }

            return route('user.home');
        }

        public static function is_admin_route(Request $request): bool
        {
            $action = $request->route()->getAction();

            return $action['namespace'] === 'App\Http\Controllers\Admin';
        }

        public static function app_config(string $value = ''): mixed
        {
            $conf = AppConfig::where('setting', $value)->first();

            return $conf->value;
        }

        /**
         * Get all countries.
         */
        public static function countries(): array
        {
            $countries   = [];
            $countries[] = ['code' => 'AF', 'name' => 'Afghanistan', 'd_code' => '+93'];
            $countries[] = ['code' => 'AL', 'name' => 'Albania', 'd_code' => '+355'];
            $countries[] = ['code' => 'DZ', 'name' => 'Algeria', 'd_code' => '+213'];
            $countries[] = ['code' => 'AS', 'name' => 'American Samoa', 'd_code' => '+1'];
            $countries[] = ['code' => 'AD', 'name' => 'Andorra', 'd_code' => '+376'];
            $countries[] = ['code' => 'AO', 'name' => 'Angola', 'd_code' => '+244'];
            $countries[] = ['code' => 'AI', 'name' => 'Anguilla', 'd_code' => '+1'];
            $countries[] = ['code' => 'AG', 'name' => 'Antigua', 'd_code' => '+1'];
            $countries[] = ['code' => 'AR', 'name' => 'Argentina', 'd_code' => '+54'];
            $countries[] = ['code' => 'AM', 'name' => 'Armenia', 'd_code' => '+374'];
            $countries[] = ['code' => 'AW', 'name' => 'Aruba', 'd_code' => '+297'];
            $countries[] = ['code' => 'AU', 'name' => 'Australia', 'd_code' => '+61'];
            $countries[] = ['code' => 'AT', 'name' => 'Austria', 'd_code' => '+43'];
            $countries[] = ['code' => 'AZ', 'name' => 'Azerbaijan', 'd_code' => '+994'];
            $countries[] = ['code' => 'BH', 'name' => 'Bahrain', 'd_code' => '+973'];
            $countries[] = ['code' => 'BD', 'name' => 'Bangladesh', 'd_code' => '+880'];
            $countries[] = ['code' => 'BB', 'name' => 'Barbados', 'd_code' => '+1'];
            $countries[] = ['code' => 'BY', 'name' => 'Belarus', 'd_code' => '+375'];
            $countries[] = ['code' => 'BE', 'name' => 'Belgium', 'd_code' => '+32'];
            $countries[] = ['code' => 'BZ', 'name' => 'Belize', 'd_code' => '+501'];
            $countries[] = ['code' => 'BJ', 'name' => 'Benin', 'd_code' => '+229'];
            $countries[] = ['code' => 'BM', 'name' => 'Bermuda', 'd_code' => '+1'];
            $countries[] = ['code' => 'BT', 'name' => 'Bhutan', 'd_code' => '+975'];
            $countries[] = ['code' => 'BO', 'name' => 'Bolivia', 'd_code' => '+591'];
            $countries[] = ['code' => 'BA', 'name' => 'Bosnia and Herzegovina', 'd_code' => '+387'];
            $countries[] = ['code' => 'BW', 'name' => 'Botswana', 'd_code' => '+267'];
            $countries[] = ['code' => 'BR', 'name' => 'Brazil', 'd_code' => '+55'];
            $countries[] = ['code' => 'IO', 'name' => 'British Indian Ocean Territory', 'd_code' => '+246'];
            $countries[] = ['code' => 'VG', 'name' => 'British Virgin Islands', 'd_code' => '+1'];
            $countries[] = ['code' => 'BN', 'name' => 'Brunei', 'd_code' => '+673'];
            $countries[] = ['code' => 'BG', 'name' => 'Bulgaria', 'd_code' => '+359'];
            $countries[] = ['code' => 'BF', 'name' => 'Burkina Faso', 'd_code' => '+226'];
            $countries[] = ['code' => 'MM', 'name' => 'Burma Myanmar', 'd_code' => '+95'];
            $countries[] = ['code' => 'BI', 'name' => 'Burundi', 'd_code' => '+257'];
            $countries[] = ['code' => 'KH', 'name' => 'Cambodia', 'd_code' => '+855'];
            $countries[] = ['code' => 'CM', 'name' => 'Cameroon', 'd_code' => '+237'];
            $countries[] = ['code' => 'CA', 'name' => 'Canada', 'd_code' => '+1'];
            $countries[] = ['code' => 'CV', 'name' => 'Cape Verde', 'd_code' => '+238'];
            $countries[] = ['code' => 'KY', 'name' => 'Cayman Islands', 'd_code' => '+1'];
            $countries[] = ['code' => 'CF', 'name' => 'Central African Republic', 'd_code' => '+236'];
            $countries[] = ['code' => 'TD', 'name' => 'Chad', 'd_code' => '+235'];
            $countries[] = ['code' => 'CL', 'name' => 'Chile', 'd_code' => '+56'];
            $countries[] = ['code' => 'CN', 'name' => 'China', 'd_code' => '+86'];
            $countries[] = ['code' => 'CO', 'name' => 'Colombia', 'd_code' => '+57'];
            $countries[] = ['code' => 'KM', 'name' => 'Comoros', 'd_code' => '+269'];
            $countries[] = ['code' => 'CK', 'name' => 'Cook Islands', 'd_code' => '+682'];
            $countries[] = ['code' => 'CR', 'name' => 'Costa Rica', 'd_code' => '+506'];
            $countries[] = ['code' => 'CI', 'name' => "Côte d'Ivoire", 'd_code' => '+225'];
            $countries[] = ['code' => 'HR', 'name' => 'Croatia', 'd_code' => '+385'];
            $countries[] = ['code' => 'CU', 'name' => 'Cuba', 'd_code' => '+53'];
            $countries[] = ['code' => 'CY', 'name' => 'Cyprus', 'd_code' => '+357'];
            $countries[] = ['code' => 'CZ', 'name' => 'Czech Republic', 'd_code' => '+420'];
            $countries[] = ['code' => 'CD', 'name' => 'Democratic Republic of Congo', 'd_code' => '+243'];
            $countries[] = ['code' => 'DK', 'name' => 'Denmark', 'd_code' => '+45'];
            $countries[] = ['code' => 'DJ', 'name' => 'Djibouti', 'd_code' => '+253'];
            $countries[] = ['code' => 'DM', 'name' => 'Dominica', 'd_code' => '+1'];
            $countries[] = ['code' => 'DO', 'name' => 'Dominican Republic', 'd_code' => '+1'];
            $countries[] = ['code' => 'EC', 'name' => 'Ecuador', 'd_code' => '+593'];
            $countries[] = ['code' => 'EG', 'name' => 'Egypt', 'd_code' => '+20'];
            $countries[] = ['code' => 'SV', 'name' => 'El Salvador', 'd_code' => '+503'];
            $countries[] = ['code' => 'GQ', 'name' => 'Equatorial Guinea', 'd_code' => '+240'];
            $countries[] = ['code' => 'ER', 'name' => 'Eritrea', 'd_code' => '+291'];
            $countries[] = ['code' => 'EE', 'name' => 'Estonia', 'd_code' => '+372'];
            $countries[] = ['code' => 'ET', 'name' => 'Ethiopia', 'd_code' => '+251'];
            $countries[] = ['code' => 'FK', 'name' => 'Falkland Islands', 'd_code' => '+500'];
            $countries[] = ['code' => 'FO', 'name' => 'Faroe Islands', 'd_code' => '+298'];
            $countries[] = ['code' => 'FM', 'name' => 'Federated States of Micronesia', 'd_code' => '+691'];
            $countries[] = ['code' => 'FJ', 'name' => 'Fiji', 'd_code' => '+679'];
            $countries[] = ['code' => 'FI', 'name' => 'Finland', 'd_code' => '+358'];
            $countries[] = ['code' => 'FR', 'name' => 'France', 'd_code' => '+33'];
            $countries[] = ['code' => 'GF', 'name' => 'French Guiana', 'd_code' => '+594'];
            $countries[] = ['code' => 'PF', 'name' => 'French Polynesia', 'd_code' => '+689'];
            $countries[] = ['code' => 'GA', 'name' => 'Gabon', 'd_code' => '+241'];
            $countries[] = ['code' => 'GE', 'name' => 'Georgia', 'd_code' => '+995'];
            $countries[] = ['code' => 'DE', 'name' => 'Germany', 'd_code' => '+49'];
            $countries[] = ['code' => 'GH', 'name' => 'Ghana', 'd_code' => '+233'];
            $countries[] = ['code' => 'GI', 'name' => 'Gibraltar', 'd_code' => '+350'];
            $countries[] = ['code' => 'GR', 'name' => 'Greece', 'd_code' => '+30'];
            $countries[] = ['code' => 'GL', 'name' => 'Greenland', 'd_code' => '+299'];
            $countries[] = ['code' => 'GD', 'name' => 'Grenada', 'd_code' => '+1'];
            $countries[] = ['code' => 'GP', 'name' => 'Guadeloupe', 'd_code' => '+590'];
            $countries[] = ['code' => 'GU', 'name' => 'Guam', 'd_code' => '+1'];
            $countries[] = ['code' => 'GT', 'name' => 'Guatemala', 'd_code' => '+502'];
            $countries[] = ['code' => 'GN', 'name' => 'Guinea', 'd_code' => '+224'];
            $countries[] = ['code' => 'GW', 'name' => 'Guinea-Bissau', 'd_code' => '+245'];
            $countries[] = ['code' => 'GY', 'name' => 'Guyana', 'd_code' => '+592'];
            $countries[] = ['code' => 'HT', 'name' => 'Haiti', 'd_code' => '+509'];
            $countries[] = ['code' => 'HN', 'name' => 'Honduras', 'd_code' => '+504'];
            $countries[] = ['code' => 'HK', 'name' => 'Hong Kong', 'd_code' => '+852'];
            $countries[] = ['code' => 'HU', 'name' => 'Hungary', 'd_code' => '+36'];
            $countries[] = ['code' => 'IS', 'name' => 'Iceland', 'd_code' => '+354'];
            $countries[] = ['code' => 'IN', 'name' => 'India', 'd_code' => '+91'];
            $countries[] = ['code' => 'ID', 'name' => 'Indonesia', 'd_code' => '+62'];
            $countries[] = ['code' => 'IR', 'name' => 'Iran', 'd_code' => '+98'];
            $countries[] = ['code' => 'IQ', 'name' => 'Iraq', 'd_code' => '+964'];
            $countries[] = ['code' => 'IE', 'name' => 'Ireland', 'd_code' => '+353'];
            $countries[] = ['code' => 'IL', 'name' => 'Israel', 'd_code' => '+972'];
            $countries[] = ['code' => 'IT', 'name' => 'Italy', 'd_code' => '+39'];
            $countries[] = ['code' => 'JM', 'name' => 'Jamaica', 'd_code' => '+1'];
            $countries[] = ['code' => 'JP', 'name' => 'Japan', 'd_code' => '+81'];
            $countries[] = ['code' => 'JO', 'name' => 'Jordan', 'd_code' => '+962'];
            $countries[] = ['code' => 'KZ', 'name' => 'Kazakhstan', 'd_code' => '+7'];
            $countries[] = ['code' => 'KE', 'name' => 'Kenya', 'd_code' => '+254'];
            $countries[] = ['code' => 'KI', 'name' => 'Kiribati', 'd_code' => '+686'];
            $countries[] = ['code' => 'XK', 'name' => 'Kosovo', 'd_code' => '+381'];
            $countries[] = ['code' => 'KW', 'name' => 'Kuwait', 'd_code' => '+965'];
            $countries[] = ['code' => 'KG', 'name' => 'Kyrgyzstan', 'd_code' => '+996'];
            $countries[] = ['code' => 'LA', 'name' => 'Laos', 'd_code' => '+856'];
            $countries[] = ['code' => 'LV', 'name' => 'Latvia', 'd_code' => '+371'];
            $countries[] = ['code' => 'LB', 'name' => 'Lebanon', 'd_code' => '+961'];
            $countries[] = ['code' => 'LS', 'name' => 'Lesotho', 'd_code' => '+266'];
            $countries[] = ['code' => 'LR', 'name' => 'Liberia', 'd_code' => '+231'];
            $countries[] = ['code' => 'LY', 'name' => 'Libya', 'd_code' => '+218'];
            $countries[] = ['code' => 'LI', 'name' => 'Liechtenstein', 'd_code' => '+423'];
            $countries[] = ['code' => 'LT', 'name' => 'Lithuania', 'd_code' => '+370'];
            $countries[] = ['code' => 'LU', 'name' => 'Luxembourg', 'd_code' => '+352'];
            $countries[] = ['code' => 'MO', 'name' => 'Macau', 'd_code' => '+853'];
            $countries[] = ['code' => 'MK', 'name' => 'Macedonia', 'd_code' => '+389'];
            $countries[] = ['code' => 'MG', 'name' => 'Madagascar', 'd_code' => '+261'];
            $countries[] = ['code' => 'MW', 'name' => 'Malawi', 'd_code' => '+265'];
            $countries[] = ['code' => 'MY', 'name' => 'Malaysia', 'd_code' => '+60'];
            $countries[] = ['code' => 'MV', 'name' => 'Maldives', 'd_code' => '+960'];
            $countries[] = ['code' => 'ML', 'name' => 'Mali', 'd_code' => '+223'];
            $countries[] = ['code' => 'MT', 'name' => 'Malta', 'd_code' => '+356'];
            $countries[] = ['code' => 'MH', 'name' => 'Marshall Islands', 'd_code' => '+692'];
            $countries[] = ['code' => 'MQ', 'name' => 'Martinique', 'd_code' => '+596'];
            $countries[] = ['code' => 'MR', 'name' => 'Mauritania', 'd_code' => '+222'];
            $countries[] = ['code' => 'MU', 'name' => 'Mauritius', 'd_code' => '+230'];
            $countries[] = ['code' => 'YT', 'name' => 'Mayotte', 'd_code' => '+262'];
            $countries[] = ['code' => 'MX', 'name' => 'Mexico', 'd_code' => '+52'];
            $countries[] = ['code' => 'MD', 'name' => 'Moldova', 'd_code' => '+373'];
            $countries[] = ['code' => 'MC', 'name' => 'Monaco', 'd_code' => '+377'];
            $countries[] = ['code' => 'MN', 'name' => 'Mongolia', 'd_code' => '+976'];
            $countries[] = ['code' => 'ME', 'name' => 'Montenegro', 'd_code' => '+382'];
            $countries[] = ['code' => 'MS', 'name' => 'Montserrat', 'd_code' => '+1'];
            $countries[] = ['code' => 'MA', 'name' => 'Morocco', 'd_code' => '+212'];
            $countries[] = ['code' => 'MZ', 'name' => 'Mozambique', 'd_code' => '+258'];
            $countries[] = ['code' => 'NA', 'name' => 'Namibia', 'd_code' => '+264'];
            $countries[] = ['code' => 'NR', 'name' => 'Nauru', 'd_code' => '+674'];
            $countries[] = ['code' => 'NP', 'name' => 'Nepal', 'd_code' => '+977'];
            $countries[] = ['code' => 'NL', 'name' => 'Netherlands', 'd_code' => '+31'];
            $countries[] = ['code' => 'AN', 'name' => 'Netherlands Antilles', 'd_code' => '+599'];
            $countries[] = ['code' => 'NC', 'name' => 'New Caledonia', 'd_code' => '+687'];
            $countries[] = ['code' => 'NZ', 'name' => 'New Zealand', 'd_code' => '+64'];
            $countries[] = ['code' => 'NI', 'name' => 'Nicaragua', 'd_code' => '+505'];
            $countries[] = ['code' => 'NE', 'name' => 'Niger', 'd_code' => '+227'];
            $countries[] = ['code' => 'NG', 'name' => 'Nigeria', 'd_code' => '+234'];
            $countries[] = ['code' => 'NU', 'name' => 'Niue', 'd_code' => '+683'];
            $countries[] = ['code' => 'NF', 'name' => 'Norfolk Island', 'd_code' => '+672'];
            $countries[] = ['code' => 'KP', 'name' => 'North Korea', 'd_code' => '+850'];
            $countries[] = ['code' => 'MP', 'name' => 'Northern Mariana Islands', 'd_code' => '+1'];
            $countries[] = ['code' => 'NO', 'name' => 'Norway', 'd_code' => '+47'];
            $countries[] = ['code' => 'OM', 'name' => 'Oman', 'd_code' => '+968'];
            $countries[] = ['code' => 'PK', 'name' => 'Pakistan', 'd_code' => '+92'];
            $countries[] = ['code' => 'PW', 'name' => 'Palau', 'd_code' => '+680'];
            $countries[] = ['code' => 'PS', 'name' => 'Palestine', 'd_code' => '+970'];
            $countries[] = ['code' => 'PA', 'name' => 'Panama', 'd_code' => '+507'];
            $countries[] = ['code' => 'PG', 'name' => 'Papua New Guinea', 'd_code' => '+675'];
            $countries[] = ['code' => 'PY', 'name' => 'Paraguay', 'd_code' => '+595'];
            $countries[] = ['code' => 'PE', 'name' => 'Peru', 'd_code' => '+51'];
            $countries[] = ['code' => 'PH', 'name' => 'Philippines', 'd_code' => '+63'];
            $countries[] = ['code' => 'PL', 'name' => 'Poland', 'd_code' => '+48'];
            $countries[] = ['code' => 'PT', 'name' => 'Portugal', 'd_code' => '+351'];
            $countries[] = ['code' => 'PR', 'name' => 'Puerto Rico', 'd_code' => '+1'];
            $countries[] = ['code' => 'QA', 'name' => 'Qatar', 'd_code' => '+974'];
            $countries[] = ['code' => 'CG', 'name' => 'Republic of the Congo', 'd_code' => '+242'];
            $countries[] = ['code' => 'RE', 'name' => 'Réunion', 'd_code' => '+262'];
            $countries[] = ['code' => 'RO', 'name' => 'Romania', 'd_code' => '+40'];
            $countries[] = ['code' => 'RU', 'name' => 'Russia', 'd_code' => '+7'];
            $countries[] = ['code' => 'RW', 'name' => 'Rwanda', 'd_code' => '+250'];
            $countries[] = ['code' => 'BL', 'name' => 'Saint Barthélemy', 'd_code' => '+590'];
            $countries[] = ['code' => 'SH', 'name' => 'Saint Helena', 'd_code' => '+290'];
            $countries[] = ['code' => 'KN', 'name' => 'Saint Kitts and Nevis', 'd_code' => '+1'];
            $countries[] = ['code' => 'MF', 'name' => 'Saint Martin', 'd_code' => '+590'];
            $countries[] = ['code' => 'PM', 'name' => 'Saint Pierre and Miquelon', 'd_code' => '+508'];
            $countries[] = ['code' => 'VC', 'name' => 'Saint Vincent and the Grenadines', 'd_code' => '+1'];
            $countries[] = ['code' => 'WS', 'name' => 'Samoa', 'd_code' => '+685'];
            $countries[] = ['code' => 'SM', 'name' => 'San Marino', 'd_code' => '+378'];
            $countries[] = ['code' => 'ST', 'name' => 'São Tomé and Príncipe', 'd_code' => '+239'];
            $countries[] = ['code' => 'SA', 'name' => 'Saudi Arabia', 'd_code' => '+966'];
            $countries[] = ['code' => 'SN', 'name' => 'Senegal', 'd_code' => '+221'];
            $countries[] = ['code' => 'RS', 'name' => 'Serbia', 'd_code' => '+381'];
            $countries[] = ['code' => 'SC', 'name' => 'Seychelles', 'd_code' => '+248'];
            $countries[] = ['code' => 'SL', 'name' => 'Sierra Leone', 'd_code' => '+232'];
            $countries[] = ['code' => 'SG', 'name' => 'Singapore', 'd_code' => '+65'];
            $countries[] = ['code' => 'SK', 'name' => 'Slovakia', 'd_code' => '+421'];
            $countries[] = ['code' => 'SI', 'name' => 'Slovenia', 'd_code' => '+386'];
            $countries[] = ['code' => 'SB', 'name' => 'Solomon Islands', 'd_code' => '+677'];
            $countries[] = ['code' => 'SO', 'name' => 'Somalia', 'd_code' => '+252'];
            $countries[] = ['code' => 'ZA', 'name' => 'South Africa', 'd_code' => '+27'];
            $countries[] = ['code' => 'KR', 'name' => 'South Korea', 'd_code' => '+82'];
            $countries[] = ['code' => 'ES', 'name' => 'Spain', 'd_code' => '+34'];
            $countries[] = ['code' => 'LK', 'name' => 'Sri Lanka', 'd_code' => '+94'];
            $countries[] = ['code' => 'LC', 'name' => 'St. Lucia', 'd_code' => '+1'];
            $countries[] = ['code' => 'SD', 'name' => 'Sudan', 'd_code' => '+249'];
            $countries[] = ['code' => 'SS', 'name' => 'South Sudan', 'd_code' => '+211'];
            $countries[] = ['code' => 'SR', 'name' => 'Suriname', 'd_code' => '+597'];
            $countries[] = ['code' => 'SZ', 'name' => 'Swaziland', 'd_code' => '+268'];
            $countries[] = ['code' => 'SE', 'name' => 'Sweden', 'd_code' => '+46'];
            $countries[] = ['code' => 'CH', 'name' => 'Switzerland', 'd_code' => '+41'];
            $countries[] = ['code' => 'SY', 'name' => 'Syria', 'd_code' => '+963'];
            $countries[] = ['code' => 'TW', 'name' => 'Taiwan', 'd_code' => '+886'];
            $countries[] = ['code' => 'TJ', 'name' => 'Tajikistan', 'd_code' => '+992'];
            $countries[] = ['code' => 'TZ', 'name' => 'Tanzania', 'd_code' => '+255'];
            $countries[] = ['code' => 'TH', 'name' => 'Thailand', 'd_code' => '+66'];
            $countries[] = ['code' => 'BS', 'name' => 'The Bahamas', 'd_code' => '+1'];
            $countries[] = ['code' => 'GM', 'name' => 'The Gambia', 'd_code' => '+220'];
            $countries[] = ['code' => 'TL', 'name' => 'Timor-Leste', 'd_code' => '+670'];
            $countries[] = ['code' => 'TG', 'name' => 'Togo', 'd_code' => '+228'];
            $countries[] = ['code' => 'TK', 'name' => 'Tokelau', 'd_code' => '+690'];
            $countries[] = ['code' => 'TO', 'name' => 'Tonga', 'd_code' => '+676'];
            $countries[] = ['code' => 'TT', 'name' => 'Trinidad and Tobago', 'd_code' => '+1'];
            $countries[] = ['code' => 'TN', 'name' => 'Tunisia', 'd_code' => '+216'];
            $countries[] = ['code' => 'TR', 'name' => 'Turkey', 'd_code' => '+90'];
            $countries[] = ['code' => 'TM', 'name' => 'Turkmenistan', 'd_code' => '+993'];
            $countries[] = ['code' => 'TC', 'name' => 'Turks and Caicos Islands', 'd_code' => '+1'];
            $countries[] = ['code' => 'TV', 'name' => 'Tuvalu', 'd_code' => '+688'];
            $countries[] = ['code' => 'UG', 'name' => 'Uganda', 'd_code' => '+256'];
            $countries[] = ['code' => 'UA', 'name' => 'Ukraine', 'd_code' => '+380'];
            $countries[] = ['code' => 'AE', 'name' => 'United Arab Emirates', 'd_code' => '+971'];
            $countries[] = ['code' => 'GB', 'name' => 'United Kingdom', 'd_code' => '+44'];
            $countries[] = ['code' => 'US', 'name' => 'United States', 'd_code' => '+1'];
            $countries[] = ['code' => 'UY', 'name' => 'Uruguay', 'd_code' => '+598'];
            $countries[] = ['code' => 'VI', 'name' => 'US Virgin Islands', 'd_code' => '+1'];
            $countries[] = ['code' => 'UZ', 'name' => 'Uzbekistan', 'd_code' => '+998'];
            $countries[] = ['code' => 'VU', 'name' => 'Vanuatu', 'd_code' => '+678'];
            $countries[] = ['code' => 'VA', 'name' => 'Vatican City', 'd_code' => '+39'];
            $countries[] = ['code' => 'VE', 'name' => 'Venezuela', 'd_code' => '+58'];
            $countries[] = ['code' => 'VN', 'name' => 'Vietnam', 'd_code' => '+84'];
            $countries[] = ['code' => 'WF', 'name' => 'Wallis and Futuna', 'd_code' => '+681'];
            $countries[] = ['code' => 'YE', 'name' => 'Yemen', 'd_code' => '+967'];
            $countries[] = ['code' => 'ZM', 'name' => 'Zambia', 'd_code' => '+260'];
            $countries[] = ['code' => 'ZW', 'name' => 'Zimbabwe', 'd_code' => '+263'];

            return $countries;
        }

        /**
         * get timezone list
         *
         * @throws Exception
         */
        public static function timezoneList(): array
        {
            $timezoneIdentifiers = DateTimeZone::listIdentifiers();
            $utcTime             = new DateTime('now', new DateTimeZone('UTC'));

            $tempTimezones = [];
            foreach ($timezoneIdentifiers as $timezoneIdentifier) {
                $currentTimezone = new DateTimeZone($timezoneIdentifier);

                $tempTimezones[] = [
                    'offset'     => $currentTimezone->getOffset($utcTime),
                    'identifier' => $timezoneIdentifier,
                ];
            }
            usort($tempTimezones, function ($a, $b) {
                return ($a['offset'] == $b['offset'])
                    ? strcmp($a['identifier'], $b['identifier'])
                    : $a['offset'] - $b['offset'];
            });

            $timezoneList = [];
            foreach ($tempTimezones as $tz) {
                $sign                            = ($tz['offset'] > 0) ? '+' : '-';
                $offset                          = gmdate('H:i', abs($tz['offset']));
                $timezoneList[$tz['identifier']] = '(UTC ' . $sign . $offset . ') ' .
                    $tz['identifier'];
            }

            return $timezoneList;
        }

        /**
         * Check if exec() function is available.
         */
        public static function exec_enabled(): bool
        {
            try {
                // make a small test
                //   exec('ls');

                return function_exists('exec') && ! in_array('exec', array_map('trim', explode(', ', ini_get('disable_functions'))));
            } catch (Exception) {
                return false;
            }
        }

        /**
         * application menu
         *
         * @return array[]
         */
        public static function menuData(): array
        {
            return [
                'admin'    => [
                    [
                        'url'    => url(config('app.admin_path') . '/dashboard'),
                        'slug'   => config('app.admin_path') . '/dashboard',
                        'name'   => 'Dashboard',
                        'i18n'   => 'Dashboard',
                        'icon'   => 'home',
                        'access' => 'access backend',
                    ],
                    [
                        'url'     => '',
                        'name'    => 'Customer',
                        'icon'    => 'users',
                        'i18n'    => 'Customer',
                        'access'  => 'view customer|view subscription|view announcement',
                        'submenu' => [
                            [
                                'url'    => url(config('app.admin_path') . '/customers'),
                                'slug'   => config('app.admin_path') . '/customers',
                                'name'   => 'Customers',
                                'i18n'   => 'Customers',
                                'access' => 'view customer',
                                'icon'   => 'users',
                            ],
                            [
                                'url'    => url(config('app.admin_path') . '/subscriptions'),
                                'slug'   => config('app.admin_path') . '/subscriptions',
                                'name'   => 'Subscriptions',
                                'i18n'   => 'Subscriptions',
                                'access' => 'view subscription',
                                'icon'   => 'credit-card',
                            ],
                            [
                                'url'    => url(config('app.admin_path') . '/announcements'),
                                'slug'   => config('app.admin_path') . '/announcements',
                                'name'   => 'Announcements',
                                'i18n'   => 'Announcements',
                                'access' => 'view announcement',
                                'icon'   => 'tv',
                            ],
                        ],
                    ],

                    [
                        'url'     => '',
                        'name'    => 'Plan',
                        'i18n'    => 'Plan',
                        'icon'    => 'credit-card',
                        'access'  => 'manage plans|manage currencies',
                        'submenu' => [
                            [
                                'url'    => url(config('app.admin_path') . '/plans'),
                                'slug'   => config('app.admin_path') . '/plans',
                                'name'   => 'Plans',
                                'i18n'   => 'Plans',
                                'access' => 'manage plans',
                                'icon'   => 'credit-card',
                            ],
                            [
                                'url'    => url(config('app.admin_path') . '/currencies'),
                                'slug'   => config('app.admin_path') . '/currencies',
                                'name'   => 'Currencies',
                                'i18n'   => 'Currencies',
                                'access' => 'manage currencies',
                                'icon'   => 'dollar-sign',
                            ],
                            [
                                'url'    => url(config('app.admin_path') . '/tax/settings'),
                                'slug'   => config('app.admin_path') . '/tax/settings',
                                'name'   => 'Tax Settings',
                                'i18n'   => 'Tax Settings',
                                'access' => 'manage tax',
                                'icon'   => 'percent',
                            ],
                        ],
                    ],
                    [
                        'url'     => '',
                        'name'    => 'Sending',
                        'icon'    => 'send',
                        'i18n'    => 'Sending',
                        'access'  => 'view sender_id|view keywords|view sending_servers|view phone_numbers|view tags',
                        'submenu' => [
                            [
                                'url'    => url(config('app.admin_path') . '/sending-servers'),
                                'slug'   => config('app.admin_path') . '/sending-servers',
                                'name'   => 'Sending Servers',
                                'i18n'   => 'Sending Servers',
                                'access' => 'view sending_servers',
                                'icon'   => 'send',
                            ],
                            [
                                'url'    => url(config('app.admin_path') . '/senderid'),
                                'slug'   => config('app.admin_path') . '/senderid',
                                'name'   => 'Sender ID',
                                'i18n'   => 'Sender ID',
                                'access' => 'view sender_id',
                                'icon'   => 'book',
                            ],
                            [
                                'url'    => url(config('app.admin_path') . '/phone-numbers'),
                                'slug'   => config('app.admin_path') . '/phone-numbers',
                                'name'   => 'Numbers',
                                'i18n'   => 'Numbers',
                                'access' => 'view phone_numbers',
                                'icon'   => 'phone',
                            ],
                            [
                                'url'    => url(config('app.admin_path') . '/keywords'),
                                'slug'   => config('app.admin_path') . '/keywords',
                                'name'   => 'Keywords',
                                'i18n'   => 'Keywords',
                                'access' => 'view keywords',
                                'icon'   => 'hash',
                            ],
                            /*Version 3.5*/

                            [
                                'url'    => url(config('app.admin_path') . '/templates'),
                                'slug'   => config('app.admin_path') . '/templates',
                                'name'   => 'Templates',
                                'i18n'   => 'Templates',
                                'access' => 'view templates',
                                'icon'   => 'bookmark',
                            ],
                        ],
                    ],
                    [
                        'url'     => '',
                        'name'    => 'Security',
                        'i18n'    => 'Security',
                        'icon'    => 'shield',
                        'access'  => 'view blacklist|view spam_word|view blocked_senderids',
                        'submenu' => [
                            [
                                'url'    => url(config('app.admin_path') . '/blacklists'),
                                'slug'   => config('app.admin_path') . '/blacklists',
                                'name'   => 'Blacklist',
                                'i18n'   => 'Blacklist',
                                'access' => 'view blacklist',
                                'icon'   => 'user-x',
                            ],
                            [
                                'url'    => url(config('app.admin_path') . '/spam-word'),
                                'slug'   => config('app.admin_path') . '/spam-word',
                                'name'   => 'Spam Word',
                                'i18n'   => 'Spam Word',
                                'access' => 'view spam_word',
                                'icon'   => 'x-square',
                            ],
                            [
                                'url'    => url(config('app.admin_path') . '/block-senderid'),
                                'slug'   => config('app.admin_path') . '/block-senderid',
                                'name'   => 'Block Sender ID',
                                'i18n'   => 'Block Sender ID',
                                'access' => 'view block_senderid',
                                'icon'   => 'slash',
                            ],
                        ],
                    ],
                    [
                        'url'     => '',
                        'name'    => 'Administrator',
                        'i18n'    => 'Administrator',
                        'icon'    => 'user',
                        'access'  => 'view administrator|view roles',
                        'submenu' => [
                            [
                                'url'    => url(config('app.admin_path') . '/administrators'),
                                'slug'   => config('app.admin_path') . '/administrators',
                                'name'   => 'Administrators',
                                'i18n'   => 'Administrators',
                                'access' => 'view administrator',
                                'icon'   => 'users',
                            ],
                            [
                                'url'    => url(config('app.admin_path') . '/roles'),
                                'slug'   => config('app.admin_path') . '/roles',
                                'name'   => 'Admin Roles',
                                'i18n'   => 'Admin Roles',
                                'access' => 'view roles',
                                'icon'   => 'user-check',
                            ],
                        ],
                    ],
                    [
                        'url'     => '',
                        'name'    => 'Settings',
                        'i18n'    => 'Settings',
                        'icon'    => 'settings',
                        'access'  => 'general settings|view languages|view payment_gateways|view email_templates|manage update_application|manage maintenance_mode|manage ai_settings',
                        'submenu' => [
                            [
                                'url'    => url(config('app.admin_path') . '/settings'),
                                'slug'   => config('app.admin_path') . '/settings',
                                'name'   => 'All Settings',
                                'i18n'   => 'All Settings',
                                'access' => 'general settings',
                                'icon'   => 'settings',
                            ],
                            [
                                'url'    => url(config('app.admin_path') . '/countries'),
                                'slug'   => config('app.admin_path') . '/countries',
                                'name'   => 'Countries',
                                'i18n'   => 'Countries',
                                'access' => 'general settings',
                                'icon'   => 'map-pin',
                            ],
                            [
                                'url'    => url(config('app.admin_path') . '/ai-settings'),
                                'slug'   => config('app.admin_path') . '/ai-settings',
                                'name'   => 'AI Settings',
                                'i18n'   => 'AI Settings',
                                'access' => 'manage ai_settings',
                                'icon'   => 'zap',
                            ],
                            [
                                'url'    => url(config('app.admin_path') . '/languages'),
                                'slug'   => config('app.admin_path') . '/languages',
                                'name'   => 'Language',
                                'i18n'   => 'Language',
                                'access' => 'view languages',
                                'icon'   => 'globe',
                            ],
                            [
                                'url'    => url(config('app.admin_path') . '/payment-gateways'),
                                'slug'   => config('app.admin_path') . '/payment-gateways',
                                'name'   => 'Payment Gateways',
                                'i18n'   => 'Payment Gateways',
                                'access' => 'view payment_gateways',
                                'icon'   => 'shopping-bag',
                            ],
                            [
                                'url'    => url(config('app.admin_path') . '/email-templates'),
                                'slug'   => config('app.admin_path') . '/email-templates',
                                'name'   => 'Email Templates',
                                'i18n'   => 'Email Templates',
                                'access' => 'view email_templates',
                                'icon'   => 'mail',
                            ],
                            [
                                'url'    => url(config('app.admin_path') . '/terms-of-use'),
                                'slug'   => config('app.admin_path') . '/terms-of-use',
                                'name'   => 'Terms Of Use',
                                'i18n'   => 'Terms Of Use',
                                'access' => 'general settings',
                                'icon'   => 'file-text',
                            ],
                            [
                                'url'    => url(config('app.admin_path') . '/privacy-policy'),
                                'slug'   => config('app.admin_path') . '/privacy-policy',
                                'name'   => 'Privacy Policy',
                                'i18n'   => 'Privacy Policy',
                                'access' => 'general settings',
                                'icon'   => 'shield',
                            ],
                            [
                                'url'    => url(config('app.admin_path') . '/maintenance-mode'),
                                'slug'   => config('app.admin_path') . '/maintenance-mode',
                                'name'   => 'Maintenance Mode',
                                'i18n'   => 'Maintenance Mode',
                                'access' => 'manage maintenance_mode',
                                'icon'   => 'alert-triangle',
                            ],
                            [
                                'url'    => url(config('app.admin_path') . '/update-application'),
                                'slug'   => config('app.admin_path') . '/update-application',
                                'name'   => 'Update Application',
                                'i18n'   => 'Update Application',
                                'access' => 'manage update_application',
                                'icon'   => 'upload',
                            ],
                        ],
                    ],
                    [
                        'url'     => '',
                        'name'    => 'Reports',
                        'i18n'    => 'Reports',
                        'icon'    => 'bar-chart-2',
                        'access'  => 'view sms_history',
                        'submenu' => [
                            [
                                'url'    => url(config('app.admin_path') . '/reports/dashboard'),
                                'slug'   => config('app.admin_path') . '/reports/dashboard',
                                'name'   => 'Dashboard',
                                'i18n'   => 'Dashboard',
                                'access' => 'view sms_history',
                                'icon'   => 'home',
                            ],
                            [
                                'url'    => url(config('app.admin_path') . '/reports/history'),
                                'slug'   => config('app.admin_path') . '/reports/history',
                                'name'   => 'SMS History',
                                'i18n'   => 'SMS History',
                                'access' => 'view sms_history',
                                'icon'   => 'bar-chart-2',
                            ],
                            [
                                'url'    => url(config('app.admin_path') . '/reports/campaigns'),
                                'slug'   => config('app.admin_path') . '/reports/campaigns',
                                'name'   => 'Campaigns',
                                'i18n'   => 'Campaigns',
                                'access' => 'view sms_history',
                                'icon'   => 'pie-chart',
                            ],
                        ],
                    ],

                    [
                        'url'     => '',
                        'name'    => 'Plugins',
                        'i18n'    => 'Plugins',
                        'icon'    => 'package',
                        'access'  => 'view plugins|install plugins|update plugins|delete plugins',
                        'submenu' => [
                            [
                                'url'    => url(config('app.admin_path') . '/install-plugin'),
                                'slug'   => config('app.admin_path') . '/install-plugin',
                                'name'   => 'Add Plugin',
                                'i18n'   => 'Add Plugin',
                                'access' => 'install plugins',
                                'icon'   => 'plus-square',
                            ],
                            [
                                'url'    => url(config('app.admin_path') . '/plugins'),
                                'slug'   => config('app.admin_path') . '/plugins',
                                'name'   => 'All Plugins',
                                'i18n'   => 'All Plugins',
                                'access' => 'view plugins',
                                'icon'   => 'package',
                            ],
                        ],
                    ],
                    [
                        'url'    => url(config('app.admin_path') . '/invoices'),
                        'slug'   => config('app.admin_path') . '/invoices',
                        'name'   => 'Invoices',
                        'i18n'   => 'Invoices',
                        'access' => 'view invoices',
                        'icon'   => 'shopping-cart',
                    ],
                    [
                        'url'    => url(config('app.admin_path') . '/customizer'),
                        'slug'   => config('app.admin_path') . '/customizer',
                        'name'   => 'Theme Customizer',
                        'i18n'   => 'Theme Customizer',
                        'icon'   => 'grid',
                        'access' => 'general settings',
                    ],
                ],
                'customer' => [
                    [
                        'url'    => url('dashboard'),
                        'slug'   => 'dashboard',
                        'name'   => 'Dashboard',
                        'i18n'   => 'Dashboard',
                        'icon'   => 'home',
                        'access' => 'access_backend',
                    ],
                    [
                        'url'     => '',
                        'name'    => 'Sending',
                        'icon'    => 'send',
                        'i18n'    => 'Sending',
                        'access'  => 'view_numbers|view_keywords|view_sender_id|sms_template',
                        'submenu' => [
                            [
                                'url'    => url('senderid'),
                                'slug'   => 'senderid',
                                'name'   => 'Sender ID',
                                'i18n'   => 'Sender ID',
                                'access' => 'view_sender_id',
                                'icon'   => 'book',
                            ],
                            [
                                'url'    => url('numbers'),
                                'slug'   => 'numbers',
                                'name'   => 'Numbers',
                                'i18n'   => 'Numbers',
                                'access' => 'view_numbers',
                                'icon'   => 'phone',
                            ],
                            [
                                'url'    => url('keywords'),
                                'slug'   => 'keywords',
                                'name'   => 'Keywords',
                                'i18n'   => 'Keywords',
                                'access' => 'view_keywords',
                                'icon'   => 'hash',
                            ],
                            [
                                'url'    => url('templates'),
                                'slug'   => 'templates',
                                'name'   => 'SMS Template',
                                'i18n'   => 'SMS Template',
                                'access' => 'sms_template',
                                'icon'   => 'smartphone',
                            ],
                        ],
                    ],
                    [
                        'url'    => url('contacts'),
                        'slug'   => 'contacts',
                        'name'   => 'Contacts',
                        'i18n'   => 'Contacts',
                        'icon'   => 'user',
                        'access' => 'view_contact_group|create_contact_group|update_contact_group|delete_contact_group|view_contact|create_contact|update_contact|delete_contact',
                    ],
                    [
                        'url'     => '',
                        'name'    => 'SMS',
                        'i18n'    => 'SMS',
                        'icon'    => 'message-square',
                        'access'  => 'sms_campaign_builder|sms_quick_send|sms_bulk_messages',
                        'submenu' => [
                            [
                                'url'    => url('sms/quick-send'),
                                'slug'   => 'sms/quick-send',
                                'name'   => 'Quick Send',
                                'i18n'   => 'Quick Send',
                                'access' => 'sms_quick_send',
                                'icon'   => 'send',
                            ],
                            [
                                'url'    => url('sms/campaign-builder'),
                                'slug'   => 'sms/campaign-builder',
                                'name'   => 'Campaign Builder',
                                'i18n'   => 'Campaign Builder',
                                'access' => 'sms_campaign_builder',
                                'icon'   => 'server',
                            ],
                            [
                                'url'    => url('sms/import'),
                                'slug'   => 'sms/import',
                                'name'   => 'Send Using File',
                                'i18n'   => 'Send Using File',
                                'access' => 'sms_bulk_messages',
                                'icon'   => 'file-text',
                            ],
                        ],
                    ],
                    [
                        'url'     => '',
                        'name'    => 'Voice',
                        'i18n'    => 'Voice',
                        'icon'    => 'phone-call',
                        'access'  => 'voice_campaign_builder|voice_quick_send|voice_bulk_messages',
                        'submenu' => [
                            [
                                'url'    => url('voice/quick-send'),
                                'slug'   => 'voice/quick-send',
                                'name'   => 'Quick Send',
                                'i18n'   => 'Quick Send',
                                'access' => 'voice_quick_send',
                                'icon'   => 'send',
                            ],
                            [
                                'url'    => url('voice/campaign-builder'),
                                'slug'   => 'voice/campaign-builder',
                                'name'   => 'Campaign Builder',
                                'i18n'   => 'Campaign Builder',
                                'access' => 'voice_campaign_builder',
                                'icon'   => 'server',
                            ],
                            [
                                'url'    => url('voice/import'),
                                'slug'   => 'voice/import',
                                'name'   => 'Send Using File',
                                'i18n'   => 'Send Using File',
                                'access' => 'voice_bulk_messages',
                                'icon'   => 'file-text',
                            ],
                        ],
                    ],
                    [
                        'url'     => '',
                        'name'    => 'MMS',
                        'i18n'    => 'MMS',
                        'icon'    => 'image',
                        'access'  => 'mms_campaign_builder|mms_quick_send|mms_bulk_messages',
                        'submenu' => [
                            [
                                'url'    => url('mms/quick-send'),
                                'slug'   => 'mms/quick-send',
                                'name'   => 'Quick Send',
                                'i18n'   => 'Quick Send',
                                'access' => 'mms_quick_send',
                                'icon'   => 'send',
                            ],
                            [
                                'url'    => url('mms/campaign-builder'),
                                'slug'   => 'mms/campaign-builder',
                                'name'   => 'Campaign Builder',
                                'i18n'   => 'Campaign Builder',
                                'access' => 'mms_campaign_builder',
                                'icon'   => 'server',
                            ],
                            [
                                'url'    => url('mms/import'),
                                'slug'   => 'mms/import',
                                'name'   => 'Send Using File',
                                'i18n'   => 'Send Using File',
                                'access' => 'mms_bulk_messages',
                                'icon'   => 'file-text',
                            ],
                        ],
                    ],
                    [
                        'url'     => '',
                        'name'    => 'WhatsApp',
                        'i18n'    => 'WhatsApp',
                        'icon'    => 'message-circle',
                        'access'  => 'whatsapp_campaign_builder|whatsapp_quick_send|whatsapp_bulk_messages',
                        'submenu' => [
                            [
                                'url'    => url('whatsapp/quick-send'),
                                'slug'   => 'whatsapp/quick-send',
                                'name'   => 'Quick Send',
                                'i18n'   => 'Quick Send',
                                'access' => 'whatsapp_quick_send',
                                'icon'   => 'send',
                            ],
                            [
                                'url'    => url('whatsapp/campaign-builder'),
                                'slug'   => 'whatsapp/campaign-builder',
                                'name'   => 'Campaign Builder',
                                'i18n'   => 'Campaign Builder',
                                'access' => 'whatsapp_campaign_builder',
                                'icon'   => 'server',
                            ],
                            [
                                'url'    => url('whatsapp/import'),
                                'slug'   => 'whatsapp/import',
                                'name'   => 'Send Using File',
                                'i18n'   => 'Send Using File',
                                'access' => 'whatsapp_bulk_messages',
                                'icon'   => 'file-text',
                            ],
                        ],
                    ],
                    [
                        'url'     => '',
                        'name'    => 'Viber',
                        'i18n'    => 'Viber',
                        'icon'    => 'tablet',
                        'access'  => 'viber_campaign_builder|viber_quick_send|viber_bulk_messages',
                        'submenu' => [
                            [
                                'url'    => url('viber/quick-send'),
                                'slug'   => 'viber/quick-send',
                                'name'   => 'Quick Send',
                                'i18n'   => 'Quick Send',
                                'access' => 'viber_quick_send',
                                'icon'   => 'send',
                            ],
                            [
                                'url'    => url('viber/campaign-builder'),
                                'slug'   => 'viber/campaign-builder',
                                'name'   => 'Campaign Builder',
                                'i18n'   => 'Campaign Builder',
                                'access' => 'viber_campaign_builder',
                                'icon'   => 'server',
                            ],
                            [
                                'url'    => url('viber/import'),
                                'slug'   => 'viber/import',
                                'name'   => 'Send Using File',
                                'i18n'   => 'Send Using File',
                                'access' => 'viber_bulk_messages',
                                'icon'   => 'file-text',
                            ],
                        ],
                    ],
                    [
                        'url'     => '',
                        'name'    => 'OTP',
                        'i18n'    => 'OTP',
                        'icon'    => 'unlock',
                        'access'  => 'otp_campaign_builder|otp_quick_send|otp_bulk_messages',
                        'submenu' => [
                            [
                                'url'    => url('otp/quick-send'),
                                'slug'   => 'otp/quick-send',
                                'name'   => 'Quick Send',
                                'i18n'   => 'Quick Send',
                                'access' => 'otp_quick_send',
                                'icon'   => 'send',
                            ],
                            [
                                'url'    => url('otp/campaign-builder'),
                                'slug'   => 'otp/campaign-builder',
                                'name'   => 'Campaign Builder',
                                'i18n'   => 'Campaign Builder',
                                'access' => 'otp_campaign_builder',
                                'icon'   => 'server',
                            ],
                            [
                                'url'    => url('otp/import'),
                                'slug'   => 'otp/import',
                                'name'   => 'Send Using File',
                                'i18n'   => 'Send Using File',
                                'access' => 'otp_bulk_messages',
                                'icon'   => 'file-text',
                            ],
                        ],
                    ],
                    [
                        'url'    => url('blacklists'),
                        'slug'   => 'blacklists',
                        'name'   => 'Blacklist',
                        'i18n'   => 'Blacklist',
                        'icon'   => 'shield',
                        'access' => 'view_blacklist|create_blacklist|update_blacklist|delete_blacklist',
                    ],
                    [
                        'url'    => url('chat-box'),
                        'slug'   => 'chat-box',
                        'name'   => 'Chat Box',
                        'i18n'   => 'Chat Box',
                        'icon'   => 'slack',
                        'access' => 'chat_box',
                    ],
                 [
    'url'    => url('admin/hot-leads'),
    'slug'   => 'hot-leads',
    'name'   => 'Hot Leads',
    'i18n'   => 'Hot Leads',
    'icon'   => 'phone-call',
    'access' => 'chat_box',
],

[
    'url'    => url('admin/ai-analytics'),
    'slug'   => 'ai-analytics',
    'name'   => 'AI Analytics',
    'i18n'   => 'AI Analytics',
    'icon'   => 'bar-chart-2',
    'access' => 'chat_box',
],



                    [
                        'url'    => url('automations'),
                        'slug'   => 'automations',
                        'name'   => 'Automations',
                        'i18n'   => 'Automations',
                        'icon'   => 'cpu',
                        'access' => 'automations',
                    ],
                    [
                        'url'     => '',
                        'name'    => 'Reports',
                        'i18n'    => 'Reports',
                        'icon'    => 'bar-chart-2',
                        'access'  => 'view_reports|sms_campaign_builder|voice_campaign_builder|mms_campaign_builder|whatsapp_campaign_builder|otp_campaign_builder|viber_campaign_builder',
                        'submenu' => [
                            [
                                'url'    => url('reports/analyze'),
                                'slug'   => 'reports/analyze',
                                'name'   => 'Analyze',
                                'i18n'   => 'Analyze',
                                'access' => 'view_reports',
                                'icon'   => 'activity',
                            ], [
                                'url'    => url('reports/all'),
                                'slug'   => 'reports/all',
                                'name'   => 'All Messages',
                                'i18n'   => 'All Messages',
                                'access' => 'view_reports',
                                'icon'   => 'bar-chart-2',
                            ],
                            [
                                'url'    => url('reports/campaigns'),
                                'slug'   => 'reports/campaigns',
                                'name'   => 'Campaigns',
                                'i18n'   => 'Campaigns',
                                'access' => 'sms_campaign_builder',
                                'icon'   => 'pie-chart',
                            ],
                        ],
                    ],
                    [
                        'url'    => url('developers'),
                        'slug'   => 'developers',
                        'name'   => 'Developers',
                        'i18n'   => 'Developers',
                        'icon'   => 'terminal',
                        'access' => 'developers',
                    ],
                ],
            ];
        }

        public static function languages()
        {
            $lang_count  = Language::where('status', 1)->count();
            $availLocale = Session::get('available_languages');

            if ( ! isset($availLocale) || count($availLocale) !== $lang_count) {
                $availLocale = Language::where('status', 1)->cursor()->map(function ($lang) {
                    return [
                        'name'     => $lang->name,
                        'code'     => $lang->code,
                        'iso_code' => $lang->iso_code,
                    ];
                })->toArray();

                Session::put('available_languages', $availLocale);
            }

            return $availLocale;
        }

        /**
         * make round-robin
         */
        public static function makeRoundRobin(array $teams, int $rounds = null, bool $shuffle = true, int $seed = null): array
        {
            $teamCount = count($teams);

            if ($teamCount < 2) {
                return [];
            }
            //Account for odd number of teams by adding a bye
            if ($teamCount % 2 === 1) {
                $teams[]   = null;
                $teamCount += 1;
            }
            if ($shuffle) {
                //Seed shuffle with random_int for better randomness if seed is null
                try {
                    srand($seed ?? random_int(PHP_INT_MIN, PHP_INT_MAX));
                } catch (Exception) {
                }
                shuffle($teams);
            } else if ( ! is_null($seed)) {
                //Generate friendly notice that seed is set but shuffle is set to false
                trigger_error('Seed parameter has no effect when shuffle parameter is set to false');
            }
            $halfTeamCount = $teamCount / 2;
            if ($rounds === null) {
                $rounds = $teamCount - 1;
            }
            $schedule = [];
            for ($round = 1; $round <= $rounds; $round += 1) {
                foreach ($teams as $key => $team) {
                    if ($key >= $halfTeamCount) {
                        break;
                    }
                    $team1 = $team;
                    $team2 = $teams[$key + $halfTeamCount];
                    //Home-away swapping
                    $matchup            = $round % 2 === 0 ? [$team1, $team2] : [$team2, $team1];
                    $schedule[$round][] = $matchup;
                }

                $itemCount = count($teams);

                if ($itemCount < 3) {
                    return [];
                }
                $lastIndex = $itemCount - 1;
                /**
                 * Though not technically part of the round-robin algorithm, odd-even
                 * factor differentiation included to have intuitive behavior for arrays
                 * with an odd number of elements
                 */
                $factor          = (int) ($itemCount % 2 === 0 ? $itemCount / 2 : ($itemCount / 2) + 1);
                $topRightIndex   = $factor - 1;
                $topRightItem    = $teams[$topRightIndex];
                $bottomLeftIndex = $factor;
                $bottomLeftItem  = $teams[$bottomLeftIndex];
                for ($i = $topRightIndex; $i > 0; $i -= 1) {
                    $teams[$i] = $teams[$i - 1];
                }
                for ($i = $bottomLeftIndex; $i < $lastIndex; $i += 1) {
                    $teams[$i] = $teams[$i + 1];
                }
                $teams[1]          = $bottomLeftItem;
                $teams[$lastIndex] = $topRightItem;
            }

            return $schedule;
        }

        /**
         * voice regions
         *
         * @return string[]
         */
        public static function voice_regions(): array
        {
            return [
                'de-DE' => 'German, Germany',
                'en-AU' => 'English, Australia',
                'en-GB' => 'English, UK',
                'en-US' => 'English, US',
                'es-ES' => 'Spanish, Spain',
                'es-MX' => 'Spanish, Mexico',
                'es-US' => 'Spanish, US',
                'fr-CA' => 'French, Canada',
                'fr-FR' => 'French, France',
                'is-IS' => 'Icelandic, Iceland',
                'it-IT' => 'Italian, Italy',
                'ja-JP' => 'Japanese, Japan',
                'ko-KR' => 'Korean, Korea',
                'nl-NL' => 'Dutch, Netherlands',
                'pl-PL' => 'Polish, Poland',
                'pt-BR' => 'Portuguese, Brazil',
                'ro-RO' => 'Romanian, Romania',
                'ru-RU' => 'Russian, Russia',
                'zh-CN' => 'Chinese (Mandarin',
                'da-DK' => 'Danish, Denmark',
                'en-IN' => 'English, Indian',
                'cy-GB' => 'Welsh, Wales',
                'nb-NO' => 'Norwegian, Norway',
                'pt-PT' => 'Portuguese, Portugal',
                'sv-SE' => 'Swedish, Sweden',
                'tr-TR' => 'Turkish, Turkey',
                'el-GR' => 'Greek, Greece',
                'zh-HK' => 'Chinese, Hong',
                'id-ID' => 'Indonesian, Indonesia',
                'vi-VN' => 'Vietnamese, Vietnam',
                'th-TH' => 'Thai, Thailand',
                'ta-IN' => 'Tamil, India',
                'ms-MY' => 'Malay, Malaysia',
                'ml-IN' => 'Malayalam, Indian',
                'kn-IN' => 'Kannada, Indian',
            ];
        }

        public static function greetingMessage(): array|string|Translator|Application|null
        {
            /* This sets the $time variable to the current hour in the 24-hour clock format */
            $time = date('H');
            /* If the time is less than 1200 hours, show good morning */
            if ($time < '12') {
                return __('locale.labels.greeting_message', [
                    'time' => __('locale.labels.good_morning'),
                    'name' => auth()->user()->displayName(),
                ]);
            } else if ($time >= '12' && $time < '17') {
                return __('locale.labels.greeting_message', [
                    'time' => __('locale.labels.good_afternoon'),
                    'name' => auth()->user()->displayName(),
                ]);
            } else if ($time >= '17' && $time < '19') {
                return __('locale.labels.greeting_message', [
                    'time' => __('locale.labels.good_evening'),
                    'name' => auth()->user()->displayName(),
                ]);
            } else {
                return __('locale.labels.greeting_message', [
                    'time' => __('locale.labels.good_night'),
                    'name' => auth()->user()->displayName(),
                ]);
            }
        }

        public static function contactName($number, $user_id)
        {
            $contact = Contacts::where('phone', $number)->where('customer_id', $user_id)->first();

            if ($contact && $contact->first_name != null) {
                return $contact->first_name . ' ' . $contact->last_name;
            }

            return $number;
        }

        public static function contactAddress($number)
        {
            $contact = Contacts::where('phone', $number)->first();

            if ($contact && $contact->address != null) {
                return $contact->address;
            }

            return false;
        }

        public static function randProb(array $items): int|string|null
        {
            $totalProbability = 0; // This is defined to keep track of the total amount of entries

            foreach ($items as $probability) {
                $totalProbability += $probability;
            }

            $stopAt             = rand(0, $totalProbability); // This picks a random entry to select
            $currentProbability = 0; // The current entry count, when this reaches $stopAt the winner is chosen

            foreach ($items as $item => $probability) { // Go through each possible item
                $currentProbability += $probability; // Add the probability to our $currentProbability tracker
                if ($currentProbability >= $stopAt) { // When we reach the $stopAt variable, we have found our winner
                    return $item;
                }
            }

            return null;
        }

        public static function table($name): string
        {
            return DB::getTablePrefix() ? DB::getTablePrefix() . $name : $name;
        }


        /*Version 3.6*/

        /**
         * whatsapp_languages
         *
         * @return string[]
         */
        public static function whatsapp_languages(): array
        {
            return [
                'af'    => 'Afrikaans',
                'sq'    => 'Albanian',
                'ar'    => 'Arabic',
                'az'    => 'Azerbaijani',
                'bn'    => 'Bengali',
                'bg'    => 'Bulgarian',
                'ca'    => 'Catalan',
                'zh_CN' => 'Chinese (CHN)',
                'zh_HK' => 'Chinese (HKG)',
                'zh_TW' => 'Chinese (TAI)',
                'hr'    => 'Croatian',
                'cs'    => 'Czech',
                'da'    => 'Danish',
                'nl'    => 'Dutch',
                'en'    => 'English',
                'en_GB' => 'English (UK)',
                'en_US' => 'English (US)',
                'et'    => 'Estonian',
                'fil'   => 'Filipino',
                'fi'    => 'Finnish',
                'fr'    => 'French',
                'ka'    => 'Georgian',
                'de'    => 'German',
                'el'    => 'Greek',
                'gu'    => 'Gujarati',
                'ha'    => 'Hausa',
                'he'    => 'Hebrew',
                'hi'    => 'Hindi',
                'hu'    => 'Hungarian',
                'id'    => 'Indonesian',
                'ga'    => 'Irish',
                'it'    => 'Italian',
                'ja'    => 'Japanese',
                'kn'    => 'Kannada',
                'kk'    => 'Kazakh',
                'rw_RW' => 'Kinyarwanda',
                'ko'    => 'Korean',
                'ky_KG' => 'Kyrgyz (Kyrgyzstan)',
                'lo'    => 'Lao',
                'lv'    => 'Latvian',
                'lt'    => 'Lithuanian',
                'mk'    => 'Macedonian',
                'ms'    => 'Malay',
                'ml'    => 'Malayalam',
                'mr'    => 'Marathi',
                'nb'    => 'Norwegian',
                'fa'    => 'Persian',
                'pl'    => 'Polish',
                'pt_BR' => 'Portuguese (BR)',
                'pt_PT' => 'Portuguese (POR)',
                'pa'    => 'Punjabi',
                'ro'    => 'Romanian',
                'ru'    => 'Russian',
                'sr'    => 'Serbian',
                'sk'    => 'Slovak',
                'sl'    => 'Slovenian',
                'es'    => 'Spanish',
                'es_AR' => 'Spanish (ARG)',
                'es_ES' => 'Spanish (SPA)',
                'es_MX' => 'Spanish (MEX)',
                'sw'    => 'Swahili',
                'sv'    => 'Swedish',
                'ta'    => 'Tamil',
                'te'    => 'Telugu',
                'th'    => 'Thai',
                'tr'    => 'Turkish',
                'uk'    => 'Ukrainian',
                'ur'    => 'Urdu',
                'uz'    => 'Uzbek',
                'vi'    => 'Vietnamese',
                'zu'    => 'Zulu',
            ];
        }

        /*
     *  Iterate through a Eloquent $query using cursor paginate
     *  The $orderBy parameter is critically required for a cursor pagination
     */
        public static function cursorIterate($query, $orderBy, $size, $callback): void
        {
            $cursor = null;
            $page   = 1;
            do {
                $q = clone $query;
                // The 4th parameter contains the offset cursor
                $list = $q->orderBy($orderBy)->cursorPaginate($size, ['*'], 'cursor', $cursor);
                $callback($list->items(), $page);
                $cursor = $list->nextCursor();
                $page   += 1;
            } while ($list->hasMorePages());
        }

        /**
         * Join filesystem path strings.
         *
         * @param * parts of the path
         * @return string a full path
         *
         * @throws Exception
         */
        public static function join_paths()
        {
            $paths = [];
            foreach (func_get_args() as $arg) {
                if (is_null($arg) || $arg === '') {
                    continue;
                }

                if (preg_match('/https?:\/\//i', $arg)) {
                    throw new Exception('Path contains HTTP/HTTPS! Use `join_url` instead. Error for ' . implode('/', func_get_args()));
                }

                $paths[] = $arg;
            }

            return preg_replace('#/+#', '/', implode('/', $paths));
        }


        public static function each_batch($array, $batchSize, $skipHeader, $callback)
        {
            $batch = [];
            foreach ($array as $i => $value) {
                // skip the header
                if ($i == 0 && $skipHeader) {
                    continue;
                }

                if ($i % $batchSize == 0) {
                    $callback($batch);
                    $batch = [];
                }
                $batch[] = $value;
            }

            // the last callback
            if (sizeof($batch) > 0) {
                $callback($batch);
            }
        }


        /**
         * @throws Exception
         */
        public static function get_tmp_quota($name)
        {
            // PART 1: PLAN & MISC SETTINGS
            $settings = [
                "sms_max"                    => "-1",
                "whatsapp_max"               => "-1",
                "list_max"                   => "-1",
                "subscriber_max"             => "-1",
                "subscriber_per_list_max"    => "-1",
                "segment_per_list_max"       => "-1",
                "billing_cycle"              => "monthly",
                "sending_limit"              => "100_per_minute",
                "sending_quota"              => 100,
                "sending_quota_time"         => 1,
                "sending_quota_time_unit"    => "minute",
                "max_process"                => "1",
                "unsubscribe_url_required"   => "yes",
                "list_import"                => "yes",
                "list_export"                => "yes",
                "api_access"                 => "no",
                "create_sub_account"         => "no",
                "delete_sms_history"         => "no",
                "add_previous_balance"       => "no",
                "sender_id_verification"     => "yes",
                "send_spam_message"          => "no",
                "quota_value"                => 100,
                "quota_base"                 => 1,
                "quota_unit"                 => "minute",
                "sender_id"                  => "Codeglen",
                "sender_id_price"            => "0",
                "sender_id_billing_cycle"    => "monthly",
                "sender_id_frequency_amount" => 1,
                "sender_id_frequency_unit"   => "month",
            ];


            if ( ! array_key_exists($name, $settings)) {
                throw new Exception("Key '{$name}' not listed");
            }

            return $settings[$name];

        }

        public static function array_unique_by($array, $callback)
        {
            $result = [];
            foreach ($array as $value) {
                $key          = $callback($value);
                $result[$key] = $value;
            }

            return array_values($result);
        }

        public static function db_quote($value)
        {
            return DB::connection()->getPdo()->quote($value);
        }

        /**
         * @throws Exception
         */
        public static function with_cache_lock(string $resourceKey, Closure $task, int $timeout = 15, $lockTime = null, Closure $waitTimeoutCallback = null)
        {
            $defaultLockTime = 10;
            $lock            = Cache::lock($resourceKey, $lockTime ?: $defaultLockTime);
            $start           = time();

            $checkTimeoutFunc = function ($startTime, $timeoutDuration) {
                return (time() - $startTime > $timeoutDuration);
            };

            while (true) {
                if ($checkTimeoutFunc($start, $timeout)) {
                    if (is_null($waitTimeoutCallback)) {
                        throw new Exception('Timeout getting cache lock for: ' . $resourceKey);
                    } else {
                        $waitTimeoutCallback();
                        break;
                    }
                }

                if ($lock->get()) {
                    // $waitTime = floor(microtime(true)*1000)/1000 - $startmili;
                    // echo "Got lock after {$waitTime}\n";

                    try {
                        $task();
                    } finally {
                        // In case of Exception, lock is automatically release with cache driver "array"
                        // However, with "redis" driver, need explicitly release it
                        $lock->release();
                    }

                    break;
                }
            }
        }

        public static function paginate_query($query, $perPage, $orderBy, Closure $callback, $maxPageToLoad = null)
        {
            $count = $query->count();
            $pages = (int) ceil($count / $perPage);

            for ($page = 1; $page <= $pages && (is_null($maxPageToLoad) || $page <= $maxPageToLoad); $page += 1) {
                $offset = ($page - 1) * $perPage;

                $callback($page, $query->orderBy($orderBy)->limit($perPage)->offset($offset));
            }
        }

        /**
         * Return the amount to be sent to Stripe in the currency's smallest unit
         *
         * @param float  $price the price to convert
         * @param string $currency the currency to convert to
         *
         * @return int the amount in the currency's smallest unit
         */

        public static function getStripeAmount(float $price, string $currency)
        {
            $zeroDecimalCurrencies = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

            return in_array(strtoupper($currency), $zeroDecimalCurrencies)
                ? (int) $price
                : (int) round($price * 100);
        }

        /**
         * @throws Exception
         */
        public static function generatePublicPath($absPath, $withHost = false)
        {
            // Notice: $relativePath must be relative to storage/ folder
            // then $relativePath should be "app/sub/example.png"

            if (empty(trim($absPath))) {
                throw new Exception('Empty path');
            }

            $excludeBase = storage_path();
            $pos         = strpos($absPath, $excludeBase); // Expect pos to be exactly 0

            if ($pos === false) {
                throw new Exception(sprintf("File '%s' cannot be made public, only files under storage/ folder can", $absPath));
            }

            if ($pos != 0) {
                throw new Exception(sprintf("Invalid path '%s', cannot make it public", $absPath));
            }

            // Do not use string replace, as path parts may occur more than once
            // For example: abc/xyz/abc/xyz...
            $relativePath = substr($absPath, strlen($excludeBase) + 1);

            $dirname        = dirname($relativePath);
            $basename       = basename($relativePath);
            $encodedDirname = StringHelper::base64UrlEncode($dirname);

            // If Laravel is under a subdirectory
            $subdirectory = getAppSubdirectory();

            if (empty($subdirectory) || $withHost) {

                $dirname = StringHelper::base64UrlDecode($dirname);
                $absPath = storage_path(self::join_paths($dirname, $basename));

                if (File::exists($absPath)) {
                    $mimetype = Tool::getFileType($absPath);

                    return response()->file($absPath, [
                        'Content-Type'   => $mimetype,
                        'Content-Length' => filesize($absPath),
                    ]);
                } else {
                    abort(404);
                }

            } else {
                // Make sure the $subdirectory has a leading slash ('/')
                $subdirectory = self::join_paths('/', $subdirectory);
                $url          = self::join_paths($subdirectory, route('admin.plugins.asset', ['dirname' => $encodedDirname, 'basename' => $basename], $withHost));
            }

            return $url;
        }

    }

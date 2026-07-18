<?php

    namespace App\Models;

    use App\Library\Traits\HasUid;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Relations\HasMany;

    /**
     * @property mixed name
     * @method static create(array $array)
     * @method static cursor()
     * @method static where(string $string, string $uid)
     * @method static count()
     * @method static offset($start)
     * @method static whereLike(string[] $array, $search)
     * @method static find(mixed $country_code)
     */
    class Country extends Model
    {
        use HasUid;

        protected $table = 'countries';

        protected $fillable = ['name', 'iso_code', 'country_code', 'status'];

        /**
         * get iso code using country
         *
         * @param $country
         *
         * @return mixed
         */
        public static function getIsoCode($country): mixed
        {
            return self::where('name', $country)->first()->iso_code;
        }

        public static function getAll()
        {
            return self::all();
        }

        public static function getActiveOnes()
        {
            return self::where('status', 1)->get();
        }

        /**
         * @var array
         */
        protected $casts = [
            'status' => 'boolean',
        ];

        public function plans_coverage_countries(): HasMany
        {
            return $this->hasMany(PlansCoverageCountries::class, 'country_id', 'id');
        }

        public static function getSelectOptions()
        {
            $options = self::getAll();

            return $options->map(function ($item) {
                return ['value' => $item->id, 'text' => $item->name];
            });
        }

        /**
         * Get all countries.
         *
         * @return array
         */
        public static function countries()
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

        public static function findByCode($code)
        {
            return self::where('iso_code', '=', $code)->first();
        }

    }

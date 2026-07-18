<?php


    namespace App\Library;


    use App\Models\Country;
    use Exception;
    use Illuminate\Support\Facades\Session;

    class MPGS
    {
        protected $config;
        protected $paymentData;

        public function __construct($config, $paymentData)
        {
            $this->config      = $config;
            $this->paymentData = $paymentData;
        }


        public function submit()
        {

            // Prepare session request
            $session_request = [];


            if ((int) $this->config['api_version'] >= 62) {
                $session_request['initiator']['userId'] = $this->paymentData['user_id'];
            } else {
                $session_request['userId'] = $this->paymentData['user_id'];
            }

            $session_request['order']['id']              = $this->paymentData['order_id'];
            $session_request['order']['amount']          = $this->paymentData['amount'];
            $session_request['order']['currency']        = $this->paymentData['currency'];
            $session_request['interaction']['returnUrl'] = $this->paymentData['return_url'];

            if ((int) $this->config['api_version'] >= 63) {
                $session_request['apiOperation'] = "INITIATE_CHECKOUT";
            } else {
                $session_request['apiOperation'] = "CREATE_CHECKOUT_SESSION";
            }

            if ((int) $this->config['api_version'] >= 52) {
                $session_request['interaction']['operation'] = "PURCHASE";
            }


            $request_url = $this->config['payment_url'] . "api/rest/version/" . $this->config['api_version'] . "/merchant/" . $this->config['merchant_id'] . "/session";


            try {
                $ch = curl_init();

                curl_setopt($ch, CURLOPT_URL, $request_url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($session_request));
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

                $headers   = [];
                $headers[] = "Authorization: Basic " . base64_encode('merchant.' . $this->config['merchant_id'] . ":" . $this->config['authentication_password']);
                $headers[] = "Content-Type: application/x-www-form-urlencoded";


                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                $result = curl_exec($ch);


                if (curl_error($ch)) {

                    return response()->json([
                        'status'  => 'error',
                        'message' => curl_error($ch),
                    ]);
                } else {

                    $response = json_decode($result, true);

                    curl_close($ch);
                    if ($response['result'] == 'SUCCESS' && ! empty($response['successIndicator'])) {

                        $session_id = $response['session']['id'];

                        Session::put('payment_method', 'mpgs');
                        Session::put('order_id', $this->paymentData['order_id']);


                        $this->add_checkout_script($session_id);
                        $this->receipt_page($this->paymentData['order_id'], $session_id);


                        return response()->json([
                            'status'   => 'success',
                            'redirect' => $this->paymentData['return_url'],
                        ]);

                    } else {

                        return response()->json([
                            'status'  => 'error',
                            'message' => $response['error']['explanation'],
                        ]);

                    }
                }

            } catch (Exception $e) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $e->getMessage(),
                ]);
            }

        }


        /**
         * load checkout script
         */
        public function add_checkout_script($session_id)
        {
            if ( ! empty($session_id)) {

                if ((int) $this->config['api_version'] >= 63) {
                    $src = $this->config['payment_url'] . "static/checkout/checkout.min.js";
                } else {
                    $src = $this->config['payment_url'] . "checkout/version/" . $this->config['api_version'] . "/checkout.js";
                }

                ?>
                <script
                        src=<?php echo $src; ?>
                        data-error="errorCallback"
                        data-cancel="<?php echo $this->paymentData['cancel_url']; ?>"
                ></script>
                <?php
            }
        }


        public function receipt_page($order_id, $session_id)
        {

            if ( ! empty($session_id)) {

                ?>
                <script type="text/javascript">
                    function errorCallback(error) {
                        alert(error.error.explanation);
                        window.location.href = "<?php echo $this->paymentData['cancel_url']; ?>";
                    }

                    Checkout.configure({
                        <?php if((int) $this->config['api_version'] <= 62) { ?>
                        merchant: "<?php echo $this->config['merchant_id']; ?>",
                        <?php } ?>
                        order: {
                            id: "<?php echo $order_id; ?>",
                            <?php if((int) $this->config['api_version'] <= 62) { ?>
                            amount: "<?php echo $this->paymentData['amount']; ?>",
                            currency: "<?php echo $this->paymentData['currency']; ?>",
                            <?php } ?>
                            description: "<?php echo $this->paymentData['description']; ?>",
                            customerOrderDate: "<?php echo date('Y-m-d'); ?>",
                            customerReference: "<?php echo $this->paymentData['user_id']; ?>",
                            reference: "<?php echo $order_id; ?>"
                        },
                        session: {
                            id: "<?php echo $session_id?>"
                        },
                        transaction: {
                            reference: "TRF" + "<?php echo $order_id; ?>"
                        },
                        billing: {
                            address: {
                                city: "<?php echo $this->paymentData['city'] ?>",
                                country: "<?php echo ($this->kia_convert_country_code($this->paymentData['country'])) ? $this->kia_convert_country_code($this->paymentData['country']) : 'USA'; ?>",
                                postcodeZip: "<?php echo $this->paymentData['post_code']; ?>",
                                street: "<?php echo $this->paymentData['address']; ?>",
                            }
                        },
                        <?php if( ! empty($this->paymentData['first_name']) && ! empty($this->paymentData['last_name']) && ! empty($this->paymentData['email']) && ! empty($this->paymentData['phone']) ) { ?>
                        customer: {
                            email: "<?php echo $this->paymentData['email']; ?>",
                            firstName: "<?php echo $this->paymentData['first_name']; ?>",
                            lastName: "<?php echo $this->paymentData['last_name']; ?>",
                            phone: "<?php echo $this->paymentData['phone']; ?>"
                        },
                        <?php } ?>
                        interaction: {
                            <?php if( (int) $this->config['api_version'] >= 52 ) { ?>
                            operation: "PURCHASE",
                            <?php } ?>
                            merchant: {
                                name: "<?php echo ( ! empty($this->config['merchant_name'])) ? $this->config['merchant_name'] : 'MPGS'; ?>",
                                address: {
                                    line1: "<?php echo ( ! empty($this->config['merchant_address'])) ? $this->config['merchant_address'] : ''; ?>",
                                }
                            },
                            displayControl: {
                                billingAddress: "HIDE",
                                customerEmail: "HIDE",
                                <?php if((int) $this->config['api_version'] <= 62) { ?>
                                orderSummary: "HIDE",
                                <?php } ?>
                                shipping: "HIDE"
                            }
                        }
                    });
                </script>
                <p class="loading-payment-text"><?php echo 'Loading payment method, please wait. This may take up to 30 seconds.'; ?></p>
                <script type="text/javascript">
                    <?php  echo 'Checkout.showPaymentPage();'; ?>
                </script>
                <?php
                return true;

            } else {

                return response()->json([
                    'status'  => 'error',
                    'message' => 'Payment error: Session not found.',
                ]);
            }
        }


        public function kia_convert_country_code($country)
        {
            $checkCountry = Country::where('name', $country)->first();
            if ($country) {
                return false;
            }

            $countryIso = $checkCountry->iso_code;

            $countries = [
                'AF' => 'AFG', //Afghanistan
                'AX' => 'ALA', //&#197;land Islands
                'AL' => 'ALB', //Albania
                'DZ' => 'DZA', //Algeria
                'AS' => 'ASM', //American Samoa
                'AD' => 'AND', //Andorra
                'AO' => 'AGO', //Angola
                'AI' => 'AIA', //Anguilla
                'AQ' => 'ATA', //Antarctica
                'AG' => 'ATG', //Antigua and Barbuda
                'AR' => 'ARG', //Argentina
                'AM' => 'ARM', //Armenia
                'AW' => 'ABW', //Aruba
                'AU' => 'AUS', //Australia
                'AT' => 'AUT', //Austria
                'AZ' => 'AZE', //Azerbaijan
                'BS' => 'BHS', //Bahamas
                'BH' => 'BHR', //Bahrain
                'BD' => 'BGD', //Bangladesh
                'BB' => 'BRB', //Barbados
                'BY' => 'BLR', //Belarus
                'BE' => 'BEL', //Belgium
                'BZ' => 'BLZ', //Belize
                'BJ' => 'BEN', //Benin
                'BM' => 'BMU', //Bermuda
                'BT' => 'BTN', //Bhutan
                'BO' => 'BOL', //Bolivia
                'BQ' => 'BES', //Bonaire, Saint Estatius and Saba
                'BA' => 'BIH', //Bosnia and Herzegovina
                'BW' => 'BWA', //Botswana
                'BV' => 'BVT', //Bouvet Islands
                'BR' => 'BRA', //Brazil
                'IO' => 'IOT', //British Indian Ocean Territory
                'BN' => 'BRN', //Brunei
                'BG' => 'BGR', //Bulgaria
                'BF' => 'BFA', //Burkina Faso
                'BI' => 'BDI', //Burundi
                'KH' => 'KHM', //Cambodia
                'CM' => 'CMR', //Cameroon
                'CA' => 'CAN', //Canada
                'CV' => 'CPV', //Cape Verde
                'KY' => 'CYM', //Cayman Islands
                'CF' => 'CAF', //Central African Republic
                'TD' => 'TCD', //Chad
                'CL' => 'CHL', //Chile
                'CN' => 'CHN', //China
                'CX' => 'CXR', //Christmas Island
                'CC' => 'CCK', //Cocos (Keeling) Islands
                'CO' => 'COL', //Colombia
                'KM' => 'COM', //Comoros
                'CG' => 'COG', //Congo
                'CD' => 'COD', //Congo, Democratic Republic of the
                'CK' => 'COK', //Cook Islands
                'CR' => 'CRI', //Costa Rica
                'CI' => 'CIV', //Côte d\'Ivoire
                'HR' => 'HRV', //Croatia
                'CU' => 'CUB', //Cuba
                'CW' => 'CUW', //Curaçao
                'CY' => 'CYP', //Cyprus
                'CZ' => 'CZE', //Czech Republic
                'DK' => 'DNK', //Denmark
                'DJ' => 'DJI', //Djibouti
                'DM' => 'DMA', //Dominica
                'DO' => 'DOM', //Dominican Republic
                'EC' => 'ECU', //Ecuador
                'EG' => 'EGY', //Egypt
                'SV' => 'SLV', //El Salvador
                'GQ' => 'GNQ', //Equatorial Guinea
                'ER' => 'ERI', //Eritrea
                'EE' => 'EST', //Estonia
                'ET' => 'ETH', //Ethiopia
                'FK' => 'FLK', //Falkland Islands
                'FO' => 'FRO', //Faroe Islands
                'FJ' => 'FIJ', //Fiji
                'FI' => 'FIN', //Finland
                'FR' => 'FRA', //France
                'GF' => 'GUF', //French Guiana
                'PF' => 'PYF', //French Polynesia
                'TF' => 'ATF', //French Southern Territories
                'GA' => 'GAB', //Gabon
                'GM' => 'GMB', //Gambia
                'GE' => 'GEO', //Georgia
                'DE' => 'DEU', //Germany
                'GH' => 'GHA', //Ghana
                'GI' => 'GIB', //Gibraltar
                'GR' => 'GRC', //Greece
                'GL' => 'GRL', //Greenland
                'GD' => 'GRD', //Grenada
                'GP' => 'GLP', //Guadeloupe
                'GU' => 'GUM', //Guam
                'GT' => 'GTM', //Guatemala
                'GG' => 'GGY', //Guernsey
                'GN' => 'GIN', //Guinea
                'GW' => 'GNB', //Guinea-Bissau
                'GY' => 'GUY', //Guyana
                'HT' => 'HTI', //Haiti
                'HM' => 'HMD', //Heard Island and McDonald Islands
                'VA' => 'VAT', //Holy See (Vatican City State)
                'HN' => 'HND', //Honduras
                'HK' => 'HKG', //Hong Kong
                'HU' => 'HUN', //Hungary
                'IS' => 'ISL', //Iceland
                'IN' => 'IND', //India
                'ID' => 'IDN', //Indonesia
                'IR' => 'IRN', //Iran
                'IQ' => 'IRQ', //Iraq
                'IE' => 'IRL', //Republic of Ireland
                'IM' => 'IMN', //Isle of Man
                'IL' => 'ISR', //Israel
                'IT' => 'ITA', //Italy
                'JM' => 'JAM', //Jamaica
                'JP' => 'JPN', //Japan
                'JE' => 'JEY', //Jersey
                'JO' => 'JOR', //Jordan
                'KZ' => 'KAZ', //Kazakhstan
                'KE' => 'KEN', //Kenya
                'KI' => 'KIR', //Kiribati
                'KP' => 'PRK', //Korea, Democratic People\'s Republic of
                'KR' => 'KOR', //Korea, Republic of (South)
                'KW' => 'KWT', //Kuwait
                'KG' => 'KGZ', //Kyrgyzstan
                'LA' => 'LAO', //Laos
                'LV' => 'LVA', //Latvia
                'LB' => 'LBN', //Lebanon
                'LS' => 'LSO', //Lesotho
                'LR' => 'LBR', //Liberia
                'LY' => 'LBY', //Libya
                'LI' => 'LIE', //Liechtenstein
                'LT' => 'LTU', //Lithuania
                'LU' => 'LUX', //Luxembourg
                'MO' => 'MAC', //Macao S.A.R., China
                'MK' => 'MKD', //Macedonia
                'MG' => 'MDG', //Madagascar
                'MW' => 'MWI', //Malawi
                'MY' => 'MYS', //Malaysia
                'MV' => 'MDV', //Maldives
                'ML' => 'MLI', //Mali
                'MT' => 'MLT', //Malta
                'MH' => 'MHL', //Marshall Islands
                'MQ' => 'MTQ', //Martinique
                'MR' => 'MRT', //Mauritania
                'MU' => 'MUS', //Mauritius
                'YT' => 'MYT', //Mayotte
                'MX' => 'MEX', //Mexico
                'FM' => 'FSM', //Micronesia
                'MD' => 'MDA', //Moldova
                'MC' => 'MCO', //Monaco
                'MN' => 'MNG', //Mongolia
                'ME' => 'MNE', //Montenegro
                'MS' => 'MSR', //Montserrat
                'MA' => 'MAR', //Morocco
                'MZ' => 'MOZ', //Mozambique
                'MM' => 'MMR', //Myanmar
                'NA' => 'NAM', //Namibia
                'NR' => 'NRU', //Nauru
                'NP' => 'NPL', //Nepal
                'NL' => 'NLD', //Netherlands
                'AN' => 'ANT', //Netherlands Antilles
                'NC' => 'NCL', //New Caledonia
                'NZ' => 'NZL', //New Zealand
                'NI' => 'NIC', //Nicaragua
                'NE' => 'NER', //Niger
                'NG' => 'NGA', //Nigeria
                'NU' => 'NIU', //Niue
                'NF' => 'NFK', //Norfolk Island
                'MP' => 'MNP', //Northern Mariana Islands
                'NO' => 'NOR', //Norway
                'OM' => 'OMN', //Oman
                'PK' => 'PAK', //Pakistan
                'PW' => 'PLW', //Palau
                'PS' => 'PSE', //Palestinian Territory
                'PA' => 'PAN', //Panama
                'PG' => 'PNG', //Papua New Guinea
                'PY' => 'PRY', //Paraguay
                'PE' => 'PER', //Peru
                'PH' => 'PHL', //Philippines
                'PN' => 'PCN', //Pitcairn
                'PL' => 'POL', //Poland
                'PT' => 'PRT', //Portugal
                'PR' => 'PRI', //Puerto Rico
                'QA' => 'QAT', //Qatar
                'RE' => 'REU', //Reunion
                'RO' => 'ROU', //Romania
                'RU' => 'RUS', //Russia
                'RW' => 'RWA', //Rwanda
                'BL' => 'BLM', //Saint Barth&eacute;lemy
                'SH' => 'SHN', //Saint Helena
                'KN' => 'KNA', //Saint Kitts and Nevis
                'LC' => 'LCA', //Saint Lucia
                'MF' => 'MAF', //Saint Martin (French part)
                'SX' => 'SXM', //Sint Maarten / Saint Matin (Dutch part)
                'PM' => 'SPM', //Saint Pierre and Miquelon
                'VC' => 'VCT', //Saint Vincent and the Grenadines
                'WS' => 'WSM', //Samoa
                'SM' => 'SMR', //San Marino
                'ST' => 'STP', //S&atilde;o Tom&eacute; and Pr&iacute;ncipe
                'SA' => 'SAU', //Saudi Arabia
                'SN' => 'SEN', //Senegal
                'RS' => 'SRB', //Serbia
                'SC' => 'SYC', //Seychelles
                'SL' => 'SLE', //Sierra Leone
                'SG' => 'SGP', //Singapore
                'SK' => 'SVK', //Slovakia
                'SI' => 'SVN', //Slovenia
                'SB' => 'SLB', //Solomon Islands
                'SO' => 'SOM', //Somalia
                'ZA' => 'ZAF', //South Africa
                'GS' => 'SGS', //South Georgia/Sandwich Islands
                'SS' => 'SSD', //South Sudan
                'ES' => 'ESP', //Spain
                'LK' => 'LKA', //Sri Lanka
                'SD' => 'SDN', //Sudan
                'SR' => 'SUR', //Suriname
                'SJ' => 'SJM', //Svalbard and Jan Mayen
                'SZ' => 'SWZ', //Swaziland
                'SE' => 'SWE', //Sweden
                'CH' => 'CHE', //Switzerland
                'SY' => 'SYR', //Syria
                'TW' => 'TWN', //Taiwan
                'TJ' => 'TJK', //Tajikistan
                'TZ' => 'TZA', //Tanzania
                'TH' => 'THA', //Thailand
                'TL' => 'TLS', //Timor-Leste
                'TG' => 'TGO', //Togo
                'TK' => 'TKL', //Tokelau
                'TO' => 'TON', //Tonga
                'TT' => 'TTO', //Trinidad and Tobago
                'TN' => 'TUN', //Tunisia
                'TR' => 'TUR', //Turkey
                'TM' => 'TKM', //Turkmenistan
                'TC' => 'TCA', //Turks and Caicos Islands
                'TV' => 'TUV', //Tuvalu
                'UG' => 'UGA', //Uganda
                'UA' => 'UKR', //Ukraine
                'AE' => 'ARE', //United Arab Emirates
                'GB' => 'GBR', //United Kingdom
                'US' => 'USA', //United States
                'UM' => 'UMI', //United States Minor Outlying Islands
                'UY' => 'URY', //Uruguay
                'UZ' => 'UZB', //Uzbekistan
                'VU' => 'VUT', //Vanuatu
                'VE' => 'VEN', //Venezuela
                'VN' => 'VNM', //Vietnam
                'VG' => 'VGB', //Virgin Islands, British
                'VI' => 'VIR', //Virgin Island, U.S.
                'WF' => 'WLF', //Wallis and Futuna
                'EH' => 'ESH', //Western Sahara
                'YE' => 'YEM', //Yemen
                'ZM' => 'ZMB', //Zambia
                'ZW' => 'ZWE', //Zimbabwe

            ];

            return $countries[$countryIso] ?? $countryIso;

        }

        public function process_response()
        {

            $request_url = $this->config['payment_url'] . "api/rest/version/" . $this->config['api_version'] . "/merchant/" . $this->config['merchant_id'] . "/order/" . $this->paymentData['order_id'];

            try {
                $ch = curl_init();

                curl_setopt($ch, CURLOPT_URL, $request_url);
                curl_setopt($ch, CURLOPT_HTTPGET, 1);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

                $headers   = [];
                $headers[] = "Authorization: Basic " . base64_encode('merchant.' . $this->config['merchant_id'] . ":" . $this->config['authentication_password']);
                $headers[] = "Content-Type: application/x-www-form-urlencoded";


                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                $result = curl_exec($ch);

                if (curl_errno($ch)) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => curl_error($ch),
                    ]);
                } else {

                    curl_close($ch);

                    $response            = json_decode(utf8_decode($result), true);
                    $transaction_index   = count($response['transaction']) - 1;
                    $transaction_result  = $response['transaction'][$transaction_index]['result'];
                    $transaction_receipt = $response['transaction'][$transaction_index]['transaction']['receipt'];

                    if ($transaction_result == "SUCCESS" && ! empty($transaction_receipt)) {

                        return response()->json([
                            'status'         => 'success',
                            'message'        => __('locale.payment_gateways.payment_successfully_made'),
                            'transaction_id' => $transaction_receipt,
                        ]);

                    } else {

                        return response()->json([
                            'status'  => 'error',
                            'message' => __('locale.exceptions.something_went_wrong'),
                        ]);
                    }

                }
            } catch (Exception $e) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $e->getMessage(),
                ]);
            }

        }


    }

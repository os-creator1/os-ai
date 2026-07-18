<?php

    namespace App\Models;

    use App\Library\Contracts\HasQuota as HasQuotaInterface;
    use App\Library\DynamicRateTracker;
    use App\Library\QuotaManager;
    use App\Library\RateLimit;
    use App\Library\RateTracker;
    use App\Library\Tool;
    use App\Library\Traits\HasUid;
    use App\Models\Traits\HasQuota;
    use Exception;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Relations\BelongsTo;
    use Illuminate\Database\Eloquent\Relations\BelongsToMany;

    /**
     * @method static status(bool $true)
     * @method static where(string $string, string $uid)
     * @method static cursor()
     * @method static whereIn(string $string, array $sending_servers_ids)
     * @method static find(int|mixed|string $id)
     * @method static create(array $server)
     * @method static limit(mixed $limit)
     * @method static whereLike(string[] $array, mixed $search)
     * @method static count()
     *
     * @property mixed quota_value
     * @property mixed quota_base
     * @property mixed quota_unit
     * @property mixed quotaTracker
     * @property mixed created_at
     * @property mixed uid
     * @property mixed name
     */
    class SendingServer extends Model implements HasQuotaInterface
    {
        use HasQuota, HasUid;

        // sending server type

        const TYPE_TWILIO                      = 'Twilio';
        const TYPE_TWILIOCOPILOT               = 'TwilioCopilot';
        const TYPE_EASYSENDSMS                 = 'EasySendSMS';
        const TYPE_CHEAPGLOBALSMS              = 'CheapGlobalSMS';
        const TYPE_SAFARICOM                   = 'Safaricom';
        const TYPE_FACILITAMOVEL               = 'FacilitaMovel';
        const TYPE_SMSDELIVERER                = 'Smsdeliverer';
        const TYPE_ROUNDSMS                    = 'RoundSMS';
        const TYPE_YOSMS                       = 'YoSMS';
        const TYPE_DIGINTRA                    = 'Digintra';
        const TYPE_ALLMYSMS                    = 'AllMySMS';
        const TYPE_ESOLUTIONS                  = 'ESolutions';
        const TYPE_GUPSHUPIO                   = 'GupshupIO';
        const TYPE_SEMAPHORE                   = 'SemaPhore';
        const TYPE_ESTORESMS                   = 'EStoreSMS';
        const TYPE_GOIP                        = 'GoIP';
        const TYPE_MAILJET                     = 'Mailjet';
        const TYPE_ADVANCEMSGSYS               = 'AdvanceMSGSys';
        const TYPE_UIPAPP                      = 'UIPApp';
        const TYPE_SMSFRL                      = 'SMSFRL';
        const TYPE_IMARTGROUP                  = 'IMartGroup';
        const TYPE_GOSMSFUN                    = 'GoSMSFun';
        const TYPE_VIBER                       = 'Viber';
        const TYPE_TEXT_CALIBUR                = 'TextCalibur';
        const TYPE_D7NETWORKS                  = 'D7Networks';
        const TYPE_WHATSAPP                    = 'WhatsApp';
        const TYPE_ARKESEL                     = 'Arkesel';
        const TYPE_SAVEWEBHOSTNET              = 'SaveWebHostNet';
        const TYPE_FAST2SMS                    = 'Fast2SMS';
        const TYPE_MSG91                       = 'MSG91';
        const TYPE_TELEAPI                     = 'TeleAPI';
        const TYPE_BUDGETSMS                   = 'BudgetSMS';
        const TYPE_OZONEDESK                   = 'OzoneDesk';
        const TYPE_SKEBBY                      = 'Skebby';
        const TYPE_BULKREPLY                   = 'BulkReply';
        const TYPE_BULkSMS4BTC                 = 'Bulksms4btc';
        const TYPE_INFOBIP                     = 'Infobip';
        const TYPE_CLICKATELLTOUCH             = 'ClickatellTouch';
        const TYPE_CLICKATELLCENTRAL           = 'ClickatellCentral';
        const TYPE_ROUTEMOBILE                 = 'RouteMobile';
        const TYPE_TEXTLOCAL                   = 'TextLocal';
        const TYPE_PLIVO                       = 'Plivo';
        const TYPE_PLIVOPOWERPACK              = 'PlivoPowerpack';
        const TYPE_SMSGLOBAL                   = 'SMSGlobal';
        const TYPE_BULKSMS                     = 'BulkSMS';
        const TYPE_VONAGE                      = 'Vonage';
        const TYPE_1S2U                        = '1s2u';
        const TYPE_MESSAGEBIRD                 = 'Messagebird';
        const TYPE_AMAZONSNS                   = 'AmazonSNS';
        const TYPE_TYNTEC                      = 'Tyntec';
        const TYPE_WHATSAPPCHATAPI             = 'WhatsAppChatApi';
        const TYPE_KARIXIO                     = 'KarixIO';
        const TYPE_SIGNALWIRE                  = 'SignalWire';
        const TYPE_TELNYX                      = 'Telnyx';
        const TYPE_TELNYXNUMBERPOOL            = 'TelnyxNumberPool';
        const TYPE_BANDWIDTH                   = 'Bandwidth';
        const TYPE_SMPP                        = 'SMPP';
        const TYPE_ROUTEENET                   = 'RouteeNet';
        const TYPE_HUTCHLK                     = 'HutchLk';
        const TYPE_TELETOPIASMS                = 'Teletopiasms';
        const TYPE_BROADCASTERMOBILE           = 'BroadcasterMobile';
        const TYPE_SOLUTIONS4MOBILES           = 'Solutions4mobiles';
        const TYPE_BEEMAFRICA                  = 'BeemAfrica';
        const TYPE_BULKSMSONLINE               = 'BulkSMSOnline';
        const TYPE_FLOWROUTE                   = 'FlowRoute';
        const TYPE_WAAPI                       = 'WaApi';
        const TYPE_ELITBUZZBD                  = 'ElitBuzzBD';
        const TYPE_GREENWEBBD                  = 'GreenWebBD';
        const TYPE_HABLAMEV2                   = 'HablameV2';
        const TYPE_ZAMTELCOZM                  = 'ZamtelCoZm';
        const TYPE_CELLCAST                    = 'CellCast';
        const TYPE_AFRICASTALKING              = 'AfricasTalking';
        const TYPE_CAIHCOM                     = 'CaihCom';
        const TYPE_KECCELSMS                   = 'KeccelSMS';
        const TYPE_JOHNSONCONNECT              = 'JohnsonConnect';
        const TYPE_SPEEDAMOBILE                = 'SpeedaMobile';
        const TYPE_SMSALA                      = 'SMSala';
        const TYPE_TEXT2WORLD                  = 'Text2World';
        const TYPE_ENABLEX                     = 'EnableX';
        const TYPE_SPOOFSEND                   = 'SpoofSend';
        const TYPE_ALHAJSMS                    = 'AlhajSms';
        const TYPE_SENDROIDULTIMATE            = 'SendroidUltimate';
        const TYPE_REALSMS                     = 'RealSMS';
        const TYPE_CALLR                       = 'Callr';
        const TYPE_SKYETEL                     = 'Skyetel';
        const TYPE_SOLUCOESDIGITAIS            = 'Solucoesdigitais';
        const TYPE_GATEWAYAPI                  = 'Gatewayapi';
        const TYPE_INTELIQUENT                 = 'Inteliquent';
        const TYPE_TEXTBELT                    = 'TextBelt';
        const TYPE_BURSTSMS                    = 'BurstSMS';
        const TYPE_EJOIN                       = 'Ejoin';
        const TYPE_NIMBUZ                      = 'Nimbuz';
        const TYPE_MOBITECH                    = 'Mobitech';
        const TYPE_TRUBLOQ                     = 'TRUBLOQ';
        const TYPE_HOSTPINNACLE                = 'HostPinnacle';
        const TYPE_LANKABELL                   = 'LankaBell';
        const TYPE_PICKYASSIST                 = 'PickyAssist';
        const TYPE_ZORRA                       = 'Zorra';
        const TYPE_HOTMOBILE                   = 'HotMobile';
        const TYPE_YUPCHAT                     = 'YupChat';
        const TYPE_8x8                         = '8x8';
        const TYPE_FONOIP                      = 'FonoIP';
        const TYPE_WAZONE                      = 'WaZone';
        const TYPE_QOOLIZE                     = 'Qoolize';
        const TYPE_EBULKSMS                    = 'EBulkSMS';
        const TYPE_CLICKSEND                   = 'ClickSend';
        const TYPE_WHATSAPPBYTEMPLATE          = 'FBWhatsAppByTemplate';
        const TYPE_ALIBABACLOUDSMS             = 'AlibabaCloudSMS';
        const TYPE_SMSMODE                     = 'SMSMode';
        const TYPE_TECHCORE                    = 'TechCore';
        const TYPE_ORANGE                      = 'Orange';
        const TYPE_MMSCONSOLE                  = 'MMSConsole';
        const TYPE_BMSGLOBAL                   = 'BMSGlobal';
        const TYPE_GBESTSMS                    = 'GBestSMS';
        const TYPE_SILVERSTREET                = 'SilverStreet';
        const TYPE_GLINTSMS                    = 'GlintSMS';
        const TYPE_DATAGIFTING                 = 'DataGifting';
        const TYPE_SMSHTTPREVE                 = 'SMSHTTPReve';
        const TYPE_BULKSMSPROVIDERNG           = 'BulkSMSProviderNG';
        const TYPE_OZONESMS                    = 'OzoneSMS';
        const TYPE_NIGERIABULKSMS              = 'NigeriaBulkSMS';
        const TYPE_AIRTELINDIA                 = 'AirtelIndia';
        const TYPE_SMSAPI                      = 'SMSAPI';
        const TYPE_SMSAPIONLINE                = 'SMSAPIOnline';
        const TYPE_TERMII                      = 'Termii';
        const TYPE_VOXIMPLANT                  = 'Voximplant';
        const TYPE_CLIQSMS                     = 'CliqSMS';
        const TYPE_SMSVEND                     = 'SMSVend';
        const TYPE_PMCSMS                      = 'PMCSMS';
        const TYPE_WA2SALES                    = 'WA2Sales';
        const TYPE_INTERAKT                    = 'Interakt';
        const TYPE_JUICYSIMS                   = 'Juicysims';
        const TYPE_SMSAFRICANG                 = 'SMSAfricaNg';
        const TYPE_MOBILESMSNG                 = 'MobileSMSNg';
        const TYPE_BSGWORLD                    = 'BSGWorld';
        const TYPE_SNAPISMS                    = 'SnapiSMS';
        const TYPE_SMSEXPERIENCE               = 'SMSExperience';
        const TYPE_CHALLENGESMS                = 'ChallengeSMS';
        const TYPE_BLACKSMS                    = 'BlackSMS';
        const TYPE_ULTIMATESMS                 = 'UltimateSMS';
        const TYPE_MOCEANAPI                   = 'MoceanAPI';
        const TYPE_MESSAGGIO                   = 'Messaggio';
        const TYPE_SMSURWAY                    = 'SMSurWay';
        const TYPE_SMARTSMSSOLUTIONS           = 'SmartSMSSolutions';
        const TYPE_VOICEANDTEXT                = 'VoiceAndText';
        const TYPE_ETROSS                      = 'Etross';
        const TYPE_DINSTAR                     = 'Dinstar';
        const TYPE_SIMPLETEXTING               = 'SimpleTexting';
        const TYPE_WAVIX                       = 'Wavix';
        const TYPE_UIPSMS                      = 'UipSMS';
        const TYPE_TXTRIA                      = 'TxTria';
        const TYPE_WHATSENDER                  = 'Whatsender';
        const TYPE_YOOAPI                      = 'YooAPI';
        const TYPE_DIAFAAN                     = 'Diafaan';
        const TYPE_LAFRICAMOBILE               = 'LAfricaMobile';
        const TYPE_GUPSHUPIOTEMPLATE           = 'GupshupIOTemplate';
        const TYPE_AUDIENCEONE                 = 'AudienceOne';
        const TYPE_LTR                         = 'LTR';
        const TYPE_BULKSMSPLANS                = 'Bulksmsplans';
        const TYPE_SINCH                       = 'Sinch';
        const TYPE_CMCOM                       = 'CMCOM';
        const TYPE_SMSAERO                     = 'SMSAero';
        const TYPE_TEXTGRID                    = 'TextGrid';
        const TYPE_TOPYING                     = 'Topying';
        const TYPE_MASCOM                      = 'MASCOM';
        const TYPE_MP                          = 'MP';
        const TYPE_360DIALOG                   = '360dialog';
        const TYPE_BROADBASED                  = 'BroadBased';
        const TYPE_MOVIDER                     = 'Movider';
        const TYPE_ZENDER                      = 'Zender';
        const TYPE_EKOSMS                      = 'EkoSMS';
        const TYPE_ALDEAMO                     = 'Aldeamo';
        const TYPE_TONKRA                      = 'TONKRA';
        const TYPE_SMSDENVER                   = 'Smsdenver';
        const TYPE_WAUSMS                      = 'WauSMS';
        const TYPE_SMSTO                       = 'SMSTO';
        const TYPE_DIDWW                       = 'DIDWW';
        const TYPE_4JAWALY                     = '4Jawaly';
        const TYPE_SMSGATEWAYHUB               = 'SMSGatewayHub';
        const TYPE_SLING                       = 'Sling';
        const TYPE_SLEENGSHORT                 = 'SLEENGSHORT';
        const TYPE_EMISRI                      = 'Emisri';
        const TYPE_MTN                         = 'MTN';
        const TYPE_MOBILETEXTALERTS            = 'MobileTextAlerts';
        const TYPE_MSEGAT                      = 'MSEGAT';
        const TYPE_LABSMOBILE                  = 'LabsMobile';
        const TYPE_WHGATE                      = 'WHGate';
        const TYPE_WAWHGATE                    = 'WaWHGate';
        const TYPE_SMSLIVE247                  = 'SMSLive247';
        const TYPE_LINKMOBILITY                = 'LinkMobility';
        const TYPE_SMSCOUNTRY                  = 'SMSCountry';
        const TYPE_TEXTBEE                     = 'TextBee';
        const TYPE_800COM                      = '800Com';
        const TYPE_PHONECOM                    = 'PhoneCom';
        const TYPE_ULTRAMSG                    = 'UltraMsg';
        const TYPE_AFFILIATESMS                = 'AffiliateSMS';
        const TYPE_SMSEEDGE                    = 'SMSEdge';
        const TYPE_DOTGO                       = 'Dotgo';
        const TYPE_TELUCOMAPIS                 = 'TelucomAPIs';
        const TYPE_NOTIFYRE                    = 'notifyre';
        const TYPE_NOBELSMS                    = 'NobelSMS';
        const TYPE_EASYSMSXYZ                  = 'SMSGateway';
        const TYPE_FORDEMO                     = 'ForDemo';
        const TYPE_SKYELINE                    = 'Skyeline';
        const TYPE_OURSMS                      = 'OurSMS';
        const TYPE_BROADNET                    = 'BroadNet';
        const TYPE_SEVENIO                     = 'SevenIO';
        const TYPE_MNOTIFY                     = 'mNotify';
        const TYPE_HUBTEL                      = 'Hubtel';
        const TYPE_BEENET                      = 'BeeNet';
        const TYPE_SMSWORLDPRO                 = 'SMSWorldPro';
        const TYPE_UNITEL                      = 'Unitel';
        const TYPE_TURKEYSMS                   = 'TurkeySMS';
        const TYPE_ESENDEX                     = 'Esendex';
        const TYPE_RUBSMSRU                    = 'RubSMSRU';
        const TYPE_SMSHUBAO                    = 'SmshubAo';
        const TYPE_TEXTBACK                    = 'TextBack';
        const TYPE_OMBALA                      = 'Ombala';
        const TYPE_TEXTHUB                     = 'TextHub';
        const TYPE_VERIFYSMSURWAY              = 'VerifySMSURWay';
        const TYPE_FASTSMS101                  = 'FastSMS101';
        const TYPE_WHATAPICLOUD                = 'WhatAPICloud';
        const TYPE_BIRD                        = 'Bird';
        const TYPE_MOZESMS                     = 'MOZESMS';
        const TYPE_MESSAGECENTRAL              = 'MessageCentral';
        const TYPE_PHDSMS                      = 'PhdSMS';
        const TYPE_MDSMS                       = 'MDSMS';
        const TYPE_DECISIONTELECOM             = 'DecisionTelecom';
        const TYPE_MESSAGINGSERVICECOTZ        = 'MessagingServiceCoTz';
        const TYPE_CLOSUM                      = 'Closum';
        const TYPE_EGOSMS                      = 'Egosms';
        const TYPE_ANKAREXIO                   = 'AnkarexIo';
        const TYPE_FORTYTWO                    = 'FortyTwo';
        const TYPE_HOLLYRENEW                  = 'HollyRenew';
        const TYPE_NEXAH                       = 'Nexah';
        const TYPE_GENNET                      = 'Gennet';
        const TYPE_DURBAR                      = 'Durbar';
        const TYPE_SMSCOUNTRY_STANDARD         = 'SMSCountry_Standard';
        const TYPE_TWILIO_WHATSAPP_BY_TEMPLATE = 'Twilio_WhatsAppByTemplate';
        const TYPE_EASIFY                      = 'Easify';
        const TYPE_KUDISMS                     = 'Kudisms';
        const TYPE_ADVANSYSTELECOM             = 'AdvansysTelecom';
        const TYPE_HTTPSMS                     = 'HttpSMS';
        const TYPE_BULKSMSNG                   = 'BulkSMSNG';
        const TYPE_MONTYMOBILE                 = 'MontyMobile';
        const TYPE_ITEXMO                      = 'Itexmo';
        const TYPE_EVOLUTIONAPI                = 'EvolutionAPI';
        const TYPE_SLINGTRUECALLER             = 'SlingTruecaller';
        const TYPE_MOBISHASTRA                 = 'MobiShastra';

        /**
         * The table associated with the model.
         *
         * @var string
         */
        protected $table = 'sending_servers';

        /**
         * The attributes that are mass assignable.
         *
         * @var array
         *
         * @note important! consider updating the $fillable variable, it will affect some other methods
         */
        protected $fillable = [
            'name',
            'user_id',
            'settings',
            'auth_link',
            'api_link',
            'voice_api_link',
            'mms_api_link',
            'whatsapp_api_link',
            'viber_api_link',
            'otp_api_link',
            'port',
            'username',
            'password',
            'route',
            'sms_type',
            'account_sid',
            'auth_id',
            'auth_token',
            'access_key',
            'access_token',
            'secret_access',
            'api_key',
            'api_secret',
            'user_token',
            'project_id',
            'api_token',
            'auth_key',
            'device_id',
            'region',
            'application_id',
            'c1',
            'c2',
            'c3',
            'c4',
            'c5',
            'c6',
            'c7',
            'type',
            'sms_per_request',
            'quota_value',
            'quota_base',
            'quota_unit',
            'custom_order',
            'schedule',
            'custom',
            'status',
            'two_way',
            'plain',
            'mms',
            'voice',
            'whatsapp',
            'viber',
            'otp',
            'source_addr_ton',
            'source_addr_npi',
            'dest_addr_ton',
            'dest_addr_npi',
            'success_keyword',
            'gateway_balance',
        ];

        /**
         *  The attributes that should be cast.
         *
         * @var string[]
         */
        protected $casts = [
            'schedule'        => 'boolean',
            'custom'          => 'boolean',
            'status'          => 'boolean',
            'two_way'         => 'boolean',
            'plain'           => 'boolean',
            'mms'             => 'boolean',
            'voice'           => 'boolean',
            'whatsapp'        => 'boolean',
            'viber'           => 'boolean',
            'otp'             => 'boolean',
            'quota_value'     => 'integer',
            'quota_base'      => 'integer',
            'sms_per_request' => 'integer',
            'port'            => 'integer',
        ];

        /**
         * Get sending limit types.
         */
        public static function sendingLimitValues(): array
        {
            return [
                'unlimited'      => [
                    'quota_value' => -1,
                    'quota_base'  => -1,
                    'quota_unit'  => 'day',
                ],
                '100_per_minute' => [
                    'quota_value' => 100,
                    'quota_base'  => 1,
                    'quota_unit'  => 'minute',
                ],
                '1000_per_hour'  => [
                    'quota_value' => 1000,
                    'quota_base'  => 1,
                    'quota_unit'  => 'hour',
                ],
                '10000_per_day'  => [
                    'quota_value' => 10000,
                    'quota_base'  => 1,
                    'quota_unit'  => 'day',
                ],
            ];
        }

        /**
         * Plans
         */
        public function plans(): BelongsToMany
        {
            return $this->belongsToMany(Plan::class, 'plans_sending_servers');
        }

        /**
         * Customer
         */
        public function customer(): BelongsTo
        {
            return $this->belongsTo(Customer::class);
        }

        /**
         * Admin
         */
        public function admin(): BelongsTo
        {
            return $this->belongsTo(Admin::class);
        }

        /**
         * get custom sending server
         */
        public function customSendingServer(): BelongsTo
        {
            return $this->belongsTo(CustomSendingServer::class, 'id', 'server_id');
        }

        /**
         * Active status scope
         */
        public function scopeStatus($query, bool $status): mixed
        {
            return $query->where('status', $status);
        }

        /**
         * Get sending server's quota.
         */
        public function getSendingQuota(): string
        {
            return $this->quota_value;
        }

        /**
         * Quota display.
         */
        public function displayQuota(): string
        {
            if ($this->quota_value == -1) {
                return __('locale.plans.unlimited');
            }

            return $this->quota_value . '/' . $this->quota_base . ' ' . __('locale.labels.' . Tool::getPluralParse($this->quota_unit, $this->quota_base));
        }

        /**
         * Quota display.
         */
        public function displayQuotaHtml(): string
        {
            if ($this->quota_value == -1) {
                return __('locale.plans.unlimited');
            }

            return '<code>' . $this->quota_value . '</code>/<code>' . $this->quota_base . ' ' . __('locale.labels.' . Tool::getPluralParse($this->quota_unit, $this->quota_base)) . '</code>';
        }

        public function setQuotaSettings(int $value, string $periodUnit, int $periodBase): void
        {
            $this->quota_base  = $periodBase;
            $this->quota_unit  = $periodUnit;
            $this->quota_value = $value;
        }

        /*** IMPLEMENTATION OF HasQuotaInterface ***/
        public function getQuotaSettings($name): ?array
        {
            $quota = [];

            if ($this->quota_value != QuotaManager::QUOTA_UNLIMITED) {
                $quota[] = [
                    'name'         => "Server's sending limit of $this->quota_value per $this->quota_base $this->quota_unit",
                    'period_unit'  => $this->quota_unit,
                    'period_value' => $this->quota_base,
                    'limit'        => $this->quota_value,
                ];
            }

            return $quota;
        }


        /**
         * @throws Exception
         */
        public function getRateLimits()
        {
            $limits = [];

            if ($this->quota_value != RateLimit::UNLIMITED) {
                $limits[] = new RateLimit(
                    $this->quota_value,
                    $this->quota_base,
                    $this->quota_unit,
                    "Server's sending limit of {$this->quota_value} per {$this->quota_base} {$this->quota_unit}"
                );
            }

            return $limits;
        }

        /**
         * @throws Exception
         */
        public function getRateLimitTracker()
        {
            if (config('custom.distributed_worker')) {
                $key     = "server-send-email-rate-tracking-log-{$this->uid}";
                $tracker = new DynamicRateTracker($key, $this->getRateLimits());
            } else {
                $file    = storage_path('app/quota/server-send-email-rate-tracking-log-' . $this->uid);
                $tracker = new RateTracker($file, $this->getRateLimits());
            }

            return $tracker;
        }

        public function getCapabilities(): string
        {
            $return_data = '';

            if ($this->plain == 1) {
                $return_data .= '<span class="badge bg-primary text-uppercase me-1"><span>' . __('locale.labels.sms') . '</span></span>';
            }

            if ($this->schedule == 1) {
                $return_data .= '<span class="badge bg-success text-uppercase me-1"><span>' . __('locale.labels.schedule') . '</span></span>';
            }

            if ($this->two_way == 1) {
                $return_data .= '<span class="badge bg-info text-uppercase me-1"><span>' . __('locale.labels.two_way') . '</span></span>';
            }

            if ($this->voice == 'voice') {
                $return_data .= '<span class="badge bg-secondary text-uppercase me-1"><span>' . __('locale.labels.voice') . '</span></span>';
            }
            if ($this->mms == 'mms') {
                $return_data .= '<span class="badge bg-warning text-uppercase me-1"><span>' . __('locale.labels.mms') . '</span></span>';
            }
            if ($this->whatsapp == 'whatsapp') {
                $return_data .= '<span class="badge bg-danger text-uppercase me-1"><span>' . __('locale.labels.whatsapp') . '</span></span>';
            }

            if ($this->viber == 'viber') {
                $return_data .= '<span class="badge bg-dark text-uppercase me-1"><span>' . __('locale.menu.Viber') . '</span></span>';
            }

            if ($this->otp == 'otp') {
                $return_data .= '<span class="badge bg-danger text-uppercase"><span>' . __('locale.menu.OTP') . '</span></span>';
            }

            return $return_data;
        }


    }

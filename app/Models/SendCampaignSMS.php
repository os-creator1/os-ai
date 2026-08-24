<?php

    namespace App\Models;

    use AlibabaCloud\Client\AlibabaCloud;
    use AlibabaCloud\Client\Exception\ClientException;
    use AlibabaCloud\Client\Exception\ServerException;
    use App\Library\SMSCounter;
    use App\Library\Tool;
    use Aws\Sns\Exception\SnsException;
    use Aws\Sns\SnsClient;
    use ClickSend\Api\MMSApi;
    use ClickSend\Api\SMSApi;
    use ClickSend\ApiException;
    use ClickSend\Configuration;
    use ClickSend\Model\MmsMessage;
    use ClickSend\Model\MmsMessageCollection;
    use ClickSend\Model\SmsMessage;
    use ClickSend\Model\SmsMessageCollection;
    use CURLFile;
    use Exception;
    use Illuminate\Contracts\Foundation\Application;
    use Illuminate\Contracts\Translation\Translator;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Http\Client\ConnectionException;
    use Illuminate\Support\Facades\Http;
    use libphonenumber\NumberParseException;
    use libphonenumber\PhoneNumberUtil;
    use Plivo\Exceptions\PlivoResponseException;
    use Plivo\RestClient;
    use Sendpulse\RestApi\ApiClient;
    use Sendpulse\RestApi\Storage\FileStorage;
    use Smpp\ClientBuilder;
    use Smpp\Configs\SmppConfig;
    use Smpp\Configs\SocketTransportConfig;
    use Smpp\Exceptions\ClosedTransportException;
    use Smpp\Exceptions\SmppException;
    use Smpp\Exceptions\SocketTransportException;
    use Smpp\Pdu\Address;
    use Smpp\Pdu\Tag;
    use Smpp\Smpp;
    use Smpp\Transport\NonBlockingReadStrategy;
    use Smpp\Transport\RetryableReadDecorator;
    use stdClass;
    use Twilio\Exceptions\ConfigurationException;
    use Twilio\Exceptions\TwilioException;
    use Twilio\Rest\Client;
    use Twilio\TwiML\VoiceResponse;
    use UltraMsg\WhatsAppApi;

    class SendCampaignSMS extends Model
    {


        public static function generateHash(array $chain)
        {
            // Convert array to comma-separated string
            $inputString = implode(",", $chain);

            // Generate SHA256 hash
            return hash("sha256", $inputString);
        }

        /**
         * send plain message
         *
         *
         * @return array|Application|Translator|string|null
         * @throws ConnectionException
         */
        public function sendPlainSMS($data)
        {
            $phone          = $data['phone'];
            $sending_server = $data['sending_server'];
            $gateway_name   = $data['sending_server']->settings;
            $sms_type       = $data['sms_type'];
            $message        = null;

            if (isset($data['message'])) {
                $message = $data['message'];
            }

            if ($sending_server->custom && $sending_server->type == 'http') {
                $cg_info = $sending_server->customSendingServer;

                $send_custom_data = [];

                $username_param = $cg_info->username_param;
                $username_value = $cg_info->username_value;
                $password_value = null;

                if ($cg_info->authorization == 'no_auth') {
                    $send_custom_data[$username_param] = $username_value;
                }

                if ($cg_info->password_status) {
                    $password_param = $cg_info->password_param;
                    $password_value = $cg_info->password_value;

                    if ($cg_info->authorization == 'no_auth') {
                        $send_custom_data[$password_param] = $password_value;
                    }
                }

                if ($cg_info->action_status) {
                    $action_param = $cg_info->action_param;
                    $action_value = $cg_info->action_value;

                    $send_custom_data[$action_param] = $action_value;
                }

                if ($cg_info->source_status) {
                    $source_param = $cg_info->source_param;
                    $source_value = $cg_info->source_value;

                    if ($data['sender_id'] != '') {
                        $send_custom_data[$source_param] = $data['sender_id'];
                    } else {
                        $send_custom_data[$source_param] = $source_value;
                    }
                }

                $destination_param                    = $cg_info->destination_param;
                $send_custom_data[$destination_param] = $data['phone'];

                $message_param                    = $cg_info->message_param;
                $send_custom_data[$message_param] = $data['message'];

                if ($cg_info->unicode_status && $data['sms_type'] == 'unicode') {
                    $unicode_param                    = $cg_info->unicode_param;
                    $unicode_value                    = $cg_info->unicode_value;
                    $send_custom_data[$unicode_param] = $unicode_value;
                }

                if ($cg_info->route_status) {
                    $route_param = $cg_info->route_param;
                    $route_value = $cg_info->route_value;

                    $send_custom_data[$route_param] = $route_value;
                }

                if ($cg_info->language_status) {
                    $language_param = $cg_info->language_param;
                    $language_value = $cg_info->language_value;

                    $send_custom_data[$language_param] = $language_value;
                }

                if ($cg_info->custom_one_status) {
                    $custom_one_param = $cg_info->custom_one_param;
                    $custom_one_value = $cg_info->custom_one_value;

                    $send_custom_data[$custom_one_param] = $custom_one_value;
                }

                if ($cg_info->custom_two_status) {
                    $custom_two_param = $cg_info->custom_two_param;
                    $custom_two_value = $cg_info->custom_two_value;

                    $send_custom_data[$custom_two_param] = $custom_two_value;
                }

                if ($cg_info->custom_three_status) {
                    $custom_three_param = $cg_info->custom_three_param;
                    $custom_three_value = $cg_info->custom_three_value;

                    $send_custom_data[$custom_three_param] = $custom_three_value;
                }

                //if json encoded then encode custom data json_encode($send_custom_data) otherwise do http_build_query
                if ($cg_info->json_encoded_post) {
                    $parameters = json_encode($send_custom_data);
                } else {
                    $parameters = http_build_query($send_custom_data);
                }

                $ch = curl_init();

                //if http method get
                if ($cg_info->http_request_method == 'get') {
                    $gateway_url = $sending_server->api_link . '?' . $parameters;

                    curl_setopt($ch, CURLOPT_URL, $gateway_url);
                    curl_setopt($ch, CURLOPT_HTTPGET, 1);
                } else {

                    //if http method post
                    $gateway_url = $sending_server->api_link;

                    curl_setopt($ch, CURLOPT_URL, $gateway_url);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);
                }

                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                // if ssl verify ignore set yes then add these two values in curl  curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
                if ($cg_info->ssl_certificate_verification) {
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                }
                $headers = [];
                //if content type value not none then insert content type in curl headers. $headers[] = "Content-Type: application/x-www-form-urlencoded";
                if ($cg_info->content_type != 'none') {
                    $headers[] = 'Content-Type: ' . $cg_info->content_type;
                }

                //if content type accept value not none then insert content type accept in curl headers. $headers[] = "Accept: application/json";
                if ($cg_info->content_type_accept != 'none') {
                    $headers[] = 'Accept: ' . $cg_info->content_type_accept;
                }

                //if content encoding value not none then insert content type accept in curl headers. $headers[] = "charset=utf-8";
                if ($cg_info->character_encoding != 'none') {
                    $headers[] = 'charset=' . $cg_info->character_encoding;
                }
                // if authorization set Bearer then add this line on curl header $header[] = "Authorization: Bearer ".$gateway_user_name;

                if ($cg_info->authorization == 'bearer_token') {
                    $headers[] = 'Authorization: Bearer ' . $username_value;
                }

                // if authorization set basic auth then add this line on curl header $header[] = "Authorization: Basic ".base64_encode("$gateway_user_name:$gateway_password");

                if ($cg_info->authorization == 'basic_auth') {
                    $headers[] = 'Authorization: Basic ' . base64_encode("$username_value:$password_value");
                }

                if (count($headers)) {
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                }

                $get_sms_status = curl_exec($ch);

                if (curl_errno($ch)) {
                    $get_sms_status = curl_error($ch);
                } else {
                    if (substr_count(strtolower($get_sms_status), strtolower($sending_server->success_keyword)) == 1) {
                        $get_sms_status = $customer_status = 'Delivered';
                    } else {
                        $customer_status = 'Failed';
                    }
                }
                curl_close($ch);
            } else if ($sending_server->type == 'smpp') {

                $senderId = $data['sender_id'];
                $message  = $data['message'];

// Determine source TON
                $sourceTon = match (true) {
                    $sending_server->source_addr_ton !== 5 => $sending_server->source_addr_ton,
                    ctype_digit($senderId) && strlen($senderId) <= 8 => Smpp::TON_NETWORKSPECIFIC,
                    ctype_digit($senderId) && strlen($senderId) >= 10 && strlen($senderId) <= 15 => Smpp::TON_INTERNATIONAL,
                    default => Smpp::TON_ALPHANUMERIC,
                };

// Determine destination TON
                $destinationTon = $sending_server->dest_addr_ton !== 1
                    ? $sending_server->dest_addr_ton
                    : Smpp::TON_INTERNATIONAL;

// Prepare TRAI DLT tags
                $tags = null;

                if (
                    config('app.trai_dlt') &&
                    ! empty($data['dlt_entity_id']) &&
                    ! empty($data['dlt_template_id'])
                ) {
                    $tags = [
                        new Tag(0x1400, $data['dlt_entity_id']),
                        new Tag(0x1401, $data['dlt_template_id']),
                    ];

                    if ( ! empty($data['dlt_telemarketer_id'])) {
                        $tmData = $data['dlt_telemarketer_id'];

                        if (is_string($tmData)) {
                            $decoded = json_decode($tmData, true);
                            $tmData  = json_last_error() === JSON_ERROR_NONE ? $decoded : [$tmData];
                        }

                        $tmData = is_array($tmData) ? $tmData : [$tmData];
                        $hash   = self::generateHash($tmData);

                        if ($hash) {
                            $tags[] = new Tag(0x1402, $hash);
                        }
                    }
                }

                try {

                    $transportConfig = new SocketTransportConfig();
                    $transportConfig->setReadStrategy(new RetryableReadDecorator(new NonBlockingReadStrategy(), 5, 200))->setForceIpv4(true)->setDefaultSendTimeout(10000);

                    $smppConfig = new SmppConfig();
                    $smppConfig->setAddressNumberingPlanIndicator(Smpp::NPI_E164); // Explicitly set NPI
                    $smppConfig->setSystemType($sending_server->c2);


                    $client = ClientBuilder::createForSockets(["{$sending_server->api_link}:{$sending_server->port}"], $transportConfig)
                        ->setClientConfig($smppConfig)  // Apply configuration
                        ->setCredentials($sending_server->username, $sending_server->password)
                        ->buildClient();


                    $client->transport->open();


                    $client->bindTransceiver();

                    $from = new Address($senderId, $sourceTon);
                    $to   = new Address($phone, $destinationTon, Smpp::NPI_E164);

                    $options = [
                        'from'    => $from,
                        'to'      => $to,
                        'message' => $message,
                        'tags'    => $tags,
                    ];

                    $options['validityPeriod'] = date('ymdHis', time() + 3600) . '000+';

                    if ($sms_type === 'unicode') {
                        $options['dataCoding'] = Smpp::DATA_CODING_UCS2;
                    }

                    $output = $client->sendSMS(...$options);
                    $client->close();


                    preg_match('/\d+/', $output, $matches);
                    $message_id = $matches[0] ?? null;

                    if ($output) {
                        $get_sms_status = $customer_status = isset($message_id) ? 'Delivered|' . $message_id : 'Delivered';
                    } else {
                        $get_sms_status = $customer_status = match (strtolower((string) $output)) {
                            '0' => isset($message_id) ? 'Delivered|' . $message_id : 'Delivered',
                            '1' => 'Message Length is invalid',
                            '2' => 'Command Length is invalid',
                            '3' => 'Invalid Command ID',
                            '4' => 'Incorrect BIND Status for given command',
                            '5' => 'ESME Already in Bound State',
                            '6' => 'Invalid Priority Flag',
                            '7' => 'Invalid Registered Delivery Flag',
                            '8' => 'System Error',
                            '10' => 'Invalid Source Address',
                            '11' => 'Invalid Destination Address',
                            '12' => 'Message ID is invalid',
                            '13' => 'Bind Failed',
                            '14' => 'Invalid Password',
                            '15' => 'Invalid System ID',
                            '0x11' => 'Cancel SM Failed',
                            '0x13' => 'Replace SM Failed',
                            '0x15' => 'Invalid Service Type',
                            '0x33' => 'Invalid number of destinations',
                            '0x34' => 'Invalid Distribution List name',
                            '0x40' => 'Destination flag is invalid (submit_multi)',
                            '0x42' => 'Submit w/replace unsupported or inappropriate',
                            '0x43' => 'Invalid esm_class field data',
                            '0x44' => 'Cannot Submit to Distribution List',
                            '0x45' => 'submit_sm/data_sm/submit_multi failed',
                            '0x48' => 'Invalid Source address TON',
                            '0x49' => 'Invalid Source address NPI',
                            '0x51' => 'Invalid Destination address NPI',
                            '0x53' => 'Invalid system_type field',
                            '0x54' => 'Invalid replace_if_present flag',
                            '0x55' => 'Invalid number of messages',
                            '0x58' => 'Throttling error (ESME exceeded limits)',
                            '0x61' => 'Invalid Scheduled Delivery Time',
                            '0x62' => 'Invalid message validity period',
                            '0x63' => 'Predefined Message ID is Invalid',
                            '0x65' => 'ESME Receiver Permanent App Error',
                            '0x66' => 'ESME Receiver Reject Message Error',
                            '0x67' => 'Query_Sm request failed',
                            '0xc0' => 'Error in optional part of the PDU Body',
                            '0xc1' => 'TLV not allowed',
                            '0xc2' => 'Invalid Parameter Length',
                            '0xc3' => 'Expected TLV missing',
                            '0xc4' => 'Invalid TLV Value',
                            '0xfe' => 'Transaction Delivery Failure',
                            '0xff' => 'Unknown Error',
                            '0x100' => 'ESME Not authorized to use specified service type',
                            '0x101' => 'ESME Prohibited from using operation (Blacklisted)',
                            '0x102' => 'Specified service type is unavailable',
                            '0x103' => 'Specified service type is denied',
                            '0x105' => 'Source Address Sub unit is Invalid',
                            '0x106' => 'Destination Address Sub unit is Invalid',
                            '0x107' => 'Broadcast Frequency Interval is invalid',
                            '0x108' => 'Broadcast Alias Name is invalid',
                            '0x109' => 'Broadcast Area Format is invalid',
                            '0x10a' => 'Number of Broadcast Areas is invalid',
                            '0x10b' => 'Broadcast Content Type is invalid',
                            '0x10c' => 'Broadcast Message Class is invalid',
                            '0x10d' => 'Broadcast_sm operation failed',
                            '0x10f' => 'Cancel_broadcast_sm operation failed',
                            '0x110' => 'Number of Repeated Broadcasts is invalid',
                            '0x111' => 'Broadcast Service Group is invalid',
                            '0x112' => 'Broadcast Channel Indicator is invalid',
                            '0x15f95' => 'Local Exception. Check LastException property',
                            '439' => 'Country Undef',
                            '43a' => 'Invalid Network',
                            '43b' => 'Price Undefined',
                            '43c' => 'Expired (Loss Protection)',
                            '43d' => 'Route undef',
                            '43e' => 'Failover Route Undef',
                            '43f' => 'Failover expired',
                            '440' => 'Failover Price Undef',
                            '441' => 'Failover Failed',
                            '442' => 'Account Validity Expired',
                            '443' => 'Encoding error',
                            '444' => 'OA/DA Error',
                            '445' => 'Queue Message Expired',
                            '446' => 'Invalid HTTP gateway config',
                            '417' => 'User account inactive',
                            '0x400' => 'User Account Locked',
                            '0x401' => 'Insufficient balance',
                            '0x402' => 'Template Mismatch',
                            '0x405' => 'Invalid sender id',
                            '0x40b' => 'No active connection',
                            '0x40c' => 'Queue server error',
                            '0x40d' => 'Cache server error',
                            '0x40e' => 'Invalid connection state',
                            '0x40f' => 'Smpp timeout',
                            '0x410' => 'Unknown SMPP error',
                            '0x411' => 'Final disconnection',
                            '0x412' => 'Final timeout',
                            '0x413' => 'No gateway found',
                            '0x404' => 'Invalid IP',
                            '0x414' => 'Spam detected',
                            '110' => 'Error while getting text response',
                            '111' => 'Error while getting JSON/XML response',
                            '112' => 'Error while reading response',
                            '113' => 'Error reading response from result',
                            '114' => 'Error requesting rest/json api',
                            '115' => 'Error requesting simple http api',
                            '116' => 'Error requesting SOAP api',
                            '1095' => 'Combined SOAP/Simple/RestJson error',
                            default => __('locale.labels.failed'),
                        };

                    }
                } catch (SocketTransportException|ClosedTransportException|SmppException|Exception $e) {
                    $get_sms_status = $customer_status = $e->getMessage();
                }

            } else {

                $auth_url    = $sending_server->auth_link;
                $gateway_url = $sending_server->api_link;

                switch ($gateway_name) {

                    case SendingServer::TYPE_TWILIO:

                        $sender_id = str_replace(['(', ')', '+', '-', ' '], '', $data['sender_id']);
                        $phone     = '+' . str_replace(['(', ')', '+', '-', ' '], '', $phone);

                        if (is_numeric($sender_id)) {
                            $sender_id = '+' . $sender_id;
                        } else {
                            $sender_id = $data['sender_id'];
                        }

                        try {
                            $client       = new Client($sending_server->account_sid, $sending_server->auth_token);
                            $get_response = $client->messages->create($phone, [
                                'from' => $sender_id,
                                'body' => $message,
                            ]);

                            if ($get_response->status == 'queued' || $get_response->status == 'accepted') {
                                $get_sms_status  = 'Delivered|' . $get_response->sid;
                                $customer_status = 'Delivered';

                                if ($data['m5_conversations_usage_tracking'] ?? false) {
                                    $m5Outcome = 'accepted';
                                }
                            } else {
                                $get_sms_status  = $get_response->status . '|' . $get_response->sid;
                                $customer_status = ucfirst($get_response->status);

                                if ($data['m5_conversations_usage_tracking'] ?? false) {
                                    $m5Outcome = 'ambiguous';
                                }
                            }

                        } catch (ConfigurationException|TwilioException $e) {
                            $get_sms_status  = $e->getMessage();
                            $customer_status = 'Rejected';

                            if ($data['m5_conversations_usage_tracking'] ?? false) {
                                $m5Outcome = 'rejected';
                            }
                        }

                        break;

                    case SendingServer::TYPE_TWILIOCOPILOT:

                        $sender_id = str_replace(['(', ')', '+', '-', ' '], '', $data['sender_id']);
                        $phone     = '+' . str_replace(['(', ')', '+', '-', ' '], '', $phone);

                        if (is_numeric($sender_id)) {
                            $sender_id = '+' . $sender_id;
                        } else {
                            $sender_id = $data['sender_id'];
                        }

                        try {
                            $client       = new Client($sending_server->account_sid, $sending_server->auth_token);
                            $get_response = $client->messages->create($phone, [
                                'messagingServiceSid' => $sender_id,
                                'body'                => $message,
                            ]);

                            if ($get_response->status == 'queued' || $get_response->status == 'accepted') {
                                $get_sms_status  = 'Delivered|' . $get_response->sid;
                                $customer_status = 'Delivered';

                                if ($data['m5_conversations_usage_tracking'] ?? false) {
                                    $m5Outcome = 'accepted';
                                }
                            } else {
                                $get_sms_status  = $get_response->status . '|' . $get_response->sid;
                                $customer_status = ucfirst($get_response->status);

                                if ($data['m5_conversations_usage_tracking'] ?? false) {
                                    $m5Outcome = 'ambiguous';
                                }
                            }

                        } catch (ConfigurationException|TwilioException $e) {
                            $get_sms_status  = $e->getMessage();
                            $customer_status = 'Rejected';

                            if ($data['m5_conversations_usage_tracking'] ?? false) {
                                $m5Outcome = 'rejected';
                            }
                        }
                        break;

                    case SendingServer::TYPE_CLICKATELLTOUCH:
                        $send_message     = urlencode($message);
                        $sms_sent_to_user = $gateway_url . "?apiKey=$sending_server->api_key" . "&to=$phone" . "&content=$send_message";

                        if ($data['sender_id']) {
                            $sender_id        = str_replace(['(', ')', '+', '-', ' '], '', $data['sender_id']);
                            $sms_sent_to_user .= '&from=' . $sender_id;
                        }

                        try {

                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $sms_sent_to_user);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            $response = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $get_result = json_decode($response);

                                if (isset($get_result->messages[0]->accepted) && $get_result->messages[0]->accepted) {
                                    $get_sms_status  = 'Delivered|' . $get_result->messages[0]->apiMessageId;
                                    $customer_status = 'Delivered';
                                } else if (isset($get_result->messages[0]->errorDescription) && $get_result->messages[0]->errorDescription != '') {
                                    $get_sms_status  = $get_result->messages[0]->errorDescription;
                                    $customer_status = 'Rejected';
                                } else if (isset($get_result->errorDescription) && $get_result->errorDescription != '') {
                                    $get_sms_status  = $get_result->errorDescription;
                                    $customer_status = 'Rejected';
                                } else {
                                    $get_sms_status  = 'Invalid request';
                                    $customer_status = 'Failed';
                                }
                            }

                            curl_close($ch);

                        } catch (Exception $exception) {
                            $get_sms_status  = $exception->getMessage();
                            $customer_status = 'Failed';
                        }
                        break;

                    case SendingServer::TYPE_CLICKATELLCENTRAL:

                        $parameters = [
                            'user'     => $sending_server->username,
                            'password' => $sending_server->password,
                            'api_id'   => $sending_server->api_key,
                            'to'       => $phone,
                            'text'     => $message,
                        ];

                        if (isset($data['sender_id'])) {
                            $parameters['from'] = $data['sender_id'];
                        }

                        if ($sms_type == 'unicode') {
                            $parameters['unicode'] = 1;
                        } else {
                            $parameters['unicode'] = 0;
                        }

                        $sending_url = $gateway_url . '?' . http_build_query($parameters);

                        try {
                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $sending_url);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            $get_sms_status = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                if (substr_count($get_sms_status, 'ID:') == 1) {
                                    $get_sms_status = $customer_status = 'Delivered';
                                } else {
                                    $customer_status = 'Failed';
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $exception) {
                            $get_sms_status  = $exception->getMessage();
                            $customer_status = 'Failed';
                        }
                        break;

                    case SendingServer::TYPE_ROUTEMOBILE:
                        $parameters = [
                            'username'    => $sending_server->username,
                            'password'    => $sending_server->password,
                            'source'      => $data['sender_id'],
                            'destination' => $phone,
                            'dlr'         => 1,
                        ];

                        if ($sms_type == 'unicode') {
                            $parameters['type']    = 2;
                            $parameters['message'] = $this->sms_unicode($message);
                        } else {
                            $parameters['type']    = 0;
                            $parameters['message'] = $message;
                        }

                        $sending_url = $gateway_url . '?' . http_build_query($parameters);

                        try {
                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $sending_url);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            $get_sms_status = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {

                                $get_data = explode('|', $get_sms_status);

                                if (is_array($get_data) && array_key_exists('0', $get_data)) {
                                    $get_sms_status  = match ($get_data[0]) {
                                        '1701' => 'Delivered|' . $get_data['2'],
                                        '1702' => 'Invalid URL',
                                        '1703' => 'Invalid User or Password',
                                        '1704' => 'Invalid Type',
                                        '1705' => 'Invalid SMS',
                                        '1706' => 'Invalid receiver',
                                        '1707' => 'Invalid sender',
                                        '1709' => 'User Validation Failed',
                                        '1710' => 'Internal Error',
                                        '1715' => 'Response Timeout',
                                        '1025' => 'Insufficient Credit',
                                        default => 'Invalid request',
                                    };
                                    $customer_status = match ($get_data[0]) {
                                        '1701' => 'Delivered',
                                        '1702' => 'Skipped',
                                        '1703', '1704', '1705', '1706', '1707', '1709', '1710', '1715', '1025' => 'Rejected',
                                        default => 'Failed',
                                    };
                                } else {
                                    $get_sms_status  = 'Invalid request';
                                    $customer_status = 'Failed';
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $exception) {
                            $get_sms_status  = $exception->getMessage();
                            $customer_status = 'Failed';
                        }
                        break;

                    case SendingServer::TYPE_TEXTLOCAL:

                        $unique_id = time();

                        $parameters = [
                            'apikey'      => $sending_server->api_key,
                            'numbers'     => $phone,
                            'message'     => $message,
                            'sender'      => $data['sender_id'],
                            'receipt_url' => route('dlr.textlocal'),
                            'custom'      => $unique_id,
                        ];

                        if ($sms_type == 'unicode') {
                            $parameters['unicode'] = true;
                        }

                        try {
                            $ch = curl_init($gateway_url);
                            curl_setopt($ch, CURLOPT_POST, true);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            $response = curl_exec($ch);
                            $err      = curl_error($ch);
                            curl_close($ch);

                            if ($err) {
                                $get_sms_status = $err;
                            } else {
                                $get_data = json_decode($response, true);

                                if (isset($get_data) && is_array($get_data) && array_key_exists('status', $get_data)) {
                                    if ($get_data['status'] == 'failure') {
                                        foreach ($get_data['errors'] as $err) {
                                            $get_sms_status = $err['message'];
                                        }
                                        $customer_status = 'Failed';
                                    } else if ($get_data['status'] == 'success') {
                                        $get_sms_status  = 'Delivered|' . $unique_id;
                                        $customer_status = 'Delivered';
                                    } else {
                                        $get_sms_status  = $response;
                                        $customer_status = 'Rejected';
                                    }
                                } else {
                                    $get_sms_status  = $response;
                                    $customer_status = 'Failed';
                                }
                            }
                        } catch (Exception $exception) {
                            $get_sms_status  = $exception->getMessage();
                            $customer_status = 'Failed';
                        }
                        break;

                    case SendingServer::TYPE_PLIVO:

                        $client = new RestClient($sending_server->auth_id, $sending_server->auth_token);
                        try {
                            $client->messages->create(
                                $data['sender_id'],
                                [$phone],
                                $message,
                            );

                            $get_sms_status = $customer_status = 'Delivered';

                        } catch (PlivoResponseException $e) {
                            $get_sms_status  = $e->getMessage();
                            $customer_status = 'Failed';
                        }

                        break;

                    case SendingServer::TYPE_PLIVOPOWERPACK:

                        $client = new RestClient($sending_server->auth_id, $sending_server->auth_token);
                        try {
                            $client->messages->create(
                                null,
                                [$phone],
                                $message,
                                null,
                                $data['sender_id']
                            );

                            $get_sms_status = $customer_status = 'Delivered';

                        } catch (PlivoResponseException $e) {
                            $get_sms_status  = $e->getMessage();
                            $customer_status = 'Failed';
                        }

                        break;

                    case SendingServer::TYPE_SMSGLOBAL:

                        $parameters = [
                            'action'   => 'sendsms',
                            'user'     => $sending_server->username,
                            'password' => $sending_server->password,
                            'from'     => $data['sender_id'],
                            'to'       => $phone,
                            'text'     => $message,
                        ];

                        if (strlen($message) > 160) {
                            $parameters['maxsplit'] = 9;
                        }

                        $sending_url = $gateway_url . '?' . http_build_query($parameters);

                        try {

                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $sending_url);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            $get_sms_status = curl_exec($ch);
                            curl_close($ch);

                            if (substr_count($get_sms_status, 'OK') == 1) {
                                $get_sms_status = explode(':', $get_sms_status);
                                if (isset($get_sms_status) && is_array($get_sms_status) && array_key_exists('3', $get_sms_status)) {
                                    $get_sms_status = 'Delivered|' . trim($get_sms_status['3']);
                                } else {
                                    $get_sms_status = 'Delivered';
                                }
                                $customer_status = 'Delivered';
                            } else {
                                $get_sms_status  = str_replace('ERROR:', '', $get_sms_status);
                                $customer_status = 'Rejected';
                            }
                        } catch (Exception $exception) {
                            $get_sms_status  = $exception->getMessage();
                            $customer_status = 'Failed';
                        }
                        break;

                    case SendingServer::TYPE_BULKSMS:

                        $parameters = [
                            'longMessageMaxParts' => 6,
                            'to'                  => $phone,
                            'body'                => $message,
                        ];

                        if (isset($data['sender_id'])) {
                            $parameters['from'] = $data['sender_id'];
                        }

                        try {
                            $ch      = curl_init();
                            $headers = [
                                'Content-Type:application/json',
                                'Authorization:Basic ' . base64_encode("$sending_server->username:$sending_server->password"),
                            ];
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            curl_setopt($ch, CURLOPT_URL, $gateway_url . '?auto-unicode=true');
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            $response = curl_exec($ch);
                            curl_close($ch);

                            $get_data = json_decode($response, true);

                            if (isset($get_data) && is_array($get_data) && array_key_exists('0', $get_data)) {
                                if (array_key_exists('id', $get_data[0])) {
                                    $get_sms_status  = 'Delivered|' . $get_data[0]['id'];
                                    $customer_status = 'Delivered';
                                } else if (array_key_exists('detail', $get_data)) {
                                    $get_sms_status  = $get_data['detail'];
                                    $customer_status = 'Undelivered';
                                }
                            } else {
                                $get_sms_status  = $response;
                                $customer_status = 'Undelivered';
                            }
                        } catch (Exception $exception) {
                            $get_sms_status  = $exception->getMessage();
                            $customer_status = 'Failed';
                        }
                        break;

                    case SendingServer::TYPE_VONAGE:

                        $parameters = [
                            'api_key'    => $sending_server->api_key,
                            'api_secret' => $sending_server->api_secret,
                            'from'       => $data['sender_id'],
                            'to'         => $phone,
                            'text'       => $message,
                        ];

                        try {
                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameters));

                            $headers   = [];
                            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                            $result = curl_exec($ch);
                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $response = json_decode($result, true);

                                if (json_last_error() == JSON_ERROR_NONE) {
                                    if (is_array($response) && array_key_exists('messages', $response) && array_key_exists('status', $response['messages'][0])) {
                                        if ($response['messages'][0]['status'] == 0) {
                                            $get_sms_status  = 'Delivered|' . $response['messages'][0]['message-id'];
                                            $customer_status = 'Delivered';
                                        } else {
                                            $get_sms_status  = $response['messages'][0]['error-text'];
                                            $customer_status = 'Undelivered';
                                        }
                                    } else {
                                        $get_sms_status  = $result;
                                        $customer_status = 'Undelivered';
                                    }
                                } else {
                                    $get_sms_status  = $result;
                                    $customer_status = 'Undelivered';
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status  = $e->getMessage();
                            $customer_status = 'Failed';
                        }
                        break;

                    case SendingServer::TYPE_INFOBIP:
                        $destination = [
                            'messageId' => time(),
                            'to'        => $phone,
                        ];

                        $parameters = [
                            'messages' => [
                                'from'              => $data['sender_id'],
                                'destinations'      => [$destination],
                                'text'              => $message,
                                'notifyUrl'         => route('dlr.infobip'),
                                'notifyContentType' => 'application/json',
                            ],
                        ];

                        if (isset($data['dlt_template_id'])) {
                            $parameters['messages']['indiaDltContentTemplateId'] = $data['dlt_template_id'];
                        }

                        if (isset($data['dlt_entity_id'])) {
                            $parameters['messages']['indiaDltPrincipalEntityId'] = $data['dlt_entity_id'];
                        }

                        try {

                            $ch     = curl_init();
                            $header = [
                                "Authorization: App $sending_server->api_key",
                                'Content-Type: application/json',
                                'Accept: application/json',
                            ];

                            // setting options
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
                            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                            curl_setopt($ch, CURLOPT_MAXREDIRS, 2);
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));

                            // response of the POST request
                            $response = curl_exec($ch);
                            $get_data = json_decode($response, true);
                            curl_close($ch);

                            if (is_array($get_data)) {
                                if (array_key_exists('messages', $get_data)) {
                                    foreach ($get_data['messages'] as $msg) {
                                        $get_sms_status = 'Delivered|' . $msg['messageId'];
                                    }
                                    $customer_status = 'Delivered';
                                } else if (array_key_exists('requestError', $get_data)) {
                                    foreach ($get_data['requestError'] as $msg) {
                                        $get_sms_status = $msg['messageId'];
                                    }
                                    $customer_status = 'Undelivered';
                                } else {
                                    $get_sms_status  = 'Unknown error';
                                    $customer_status = 'Rejected';
                                }
                            } else {
                                $get_sms_status  = 'Unknown error';
                                $customer_status = 'Rejected';
                            }

                        } catch (Exception $exception) {
                            $get_sms_status  = $exception->getMessage();
                            $customer_status = 'Failed';
                        }
                        break;

                    case SendingServer::TYPE_1S2U:

                        if ($sms_type == 'unicode') {
                            $mt      = 1;
                            $message = bin2hex(mb_convert_encoding($message, 'UTF-16', 'UTF-8'));
                        } else {
                            $mt = 0;
                        }

                        $parameters = [
                            'username' => $sending_server->username,
                            'password' => $sending_server->password,
                            'mno'      => $phone,
                            'msg'      => $message,
                            'sid'      => $data['sender_id'],
                            'mt'       => $mt,
                            'fl'       => 0,
                        ];

                        $sending_url = $gateway_url . '?' . http_build_query($parameters);

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $sending_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);

                            $get_data = curl_exec($ch);

                            curl_close($ch);

                            if (str_contains($get_data, 'OK')) {
                                $get_sms_status  = 'Delivered|' . trim(str_replace('OK: ', '', $get_data));
                                $customer_status = 'Delivered';
                            } else {
                                $get_sms_status  = match ($get_data) {
                                    '0005' => 'Invalid Sender',
                                    '0010' => 'Username not provided',
                                    '0011' => 'Password not provided',
                                    '00' => 'Invalid username/password',
                                    '0020' => 'Insufficient Credits',
                                    '0030' => 'Invalid Sender ID',
                                    '0040' => 'Mobile number not provided',
                                    '0041' => 'Invalid mobile number',
                                    '0066', '0042' => 'Network not supported',
                                    '0050' => 'Invalid message',
                                    '0060' => 'Invalid quantity specified',
                                    '0000' => 'Message not sent',
                                    default => 'Unknown Error',
                                };
                                $customer_status = match ($get_data) {
                                    '0005', '0010', '0011', '00', '0020', '0040', '0066', '0042', '0041' => 'Rejected',
                                    '0030', '0050', '0060', '0000' => 'Undelivered',
                                    default => 'Failed',
                                };

                            }
                        } catch (Exception $ex) {
                            $get_sms_status  = $ex->getMessage();
                            $customer_status = 'Failed';
                        }
                        break;


                    case SendingServer::TYPE_MESSAGEBIRD:
                        $parameters = [
                            'receiver' => [
                                'contacts' => [
                                    [
                                        'identifierValue' => '+' . $data['phone'],
                                    ],

                                ],
                            ],
                            'body'     => [
                                'type' => 'text',
                                'text' => [
                                    'text' => $message,
                                ],
                            ],

                        ];

                        $ch = curl_init();

                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_POST, 1);

                        $headers   = [];
                        $headers[] = "Authorization: AccessKey $sending_server->access_key";
                        $headers[] = 'Content-Type: application/json';
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                        $result = curl_exec($ch);


                        if (curl_errno($ch)) {
                            $get_sms_status  = curl_error($ch);
                            $customer_status = 'Rejected';
                        } else {
                            $response = json_decode($result, true);

                            if (is_array($response) && array_key_exists('id', $response)) {
                                $get_sms_status  = 'Delivered|' . $response['id'];
                                $customer_status = 'Delivered';
                            } else if (is_array($response) && array_key_exists('code', $response)) {
                                $get_sms_status  = $response['code'];
                                $customer_status = 'Rejected';
                            } else {
                                $get_sms_status  = 'Unknown Error';
                                $customer_status = 'Rejected';
                            }
                        }
                        curl_close($ch);
                        break;

                    case SendingServer::TYPE_AMAZONSNS:
                        $credentials = [
                            'credentials' => [
                                'key'    => $sending_server->access_key,
                                'secret' => $sending_server->secret_access,
                            ],
                            'region'      => $sending_server->region, // < your aws from SNS Topic region
                            'version'     => 'latest',
                        ];

                        $sns = new SnsClient($credentials);

                        $parameters = [
                            'MessageAttributes' => [
                                'AWS.SNS.SMS.SMSType'  => [
                                    'DataType'    => 'String',
                                    'StringValue' => $sending_server->sms_type,
                                ],
                                'AWS.SNS.SMS.SenderID' => [
                                    'DataType'    => 'String',
                                    'StringValue' => $data['sender_id'], // Set the desired sender ID here
                                ],
                            ],
                            'PhoneNumber'       => '+' . $phone,
                            'Message'           => $message,
                        ];

                        if (isset($data['dlt_template_id'])) {
                            $parameters['MessageAttributes']['AWS.MM.SMS.TemplateId'] = [
                                'DataType'    => 'String',
                                'StringValue' => $data['dlt_template_id'],
                            ];
                        }

                        if (isset($data['dlt_entity_id'])) {
                            $parameters['MessageAttributes']['AWS.MM.SMS.EntityId'] = [
                                'DataType'    => 'String',
                                'StringValue' => $data['dlt_entity_id'],
                            ];
                        }

                        try {
                            $result = $sns->publish($parameters)->toArray();
                            if (is_array($result) && array_key_exists('MessageId', $result)) {
                                $get_sms_status  = 'Delivered|' . $result['MessageId'];
                                $customer_status = 'Delivered';
                            } else {
                                $get_sms_status  = 'Unknown error';
                                $customer_status = 'Rejected';
                            }
                        } catch (SnsException $exception) {
                            $get_sms_status  = $exception->getAwsErrorMessage();
                            $customer_status = 'Failed';
                        }

                        break;

                    case SendingServer::TYPE_TYNTEC:
                        $parameters = [
                            'from'    => $data['sender_id'],
                            'to'      => $phone,
                            'message' => $message,
                        ];

                        try {
                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_POST, 1);

                            $headers = [
                                "apikey: $sending_server->api_key",
                                'Content-Type: application/json',
                                'Accept: application/json',
                            ];
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                            $result = curl_exec($ch);
                            curl_close($ch);
                            $result = json_decode($result, true);

                            if (is_array($result) && array_key_exists('requestId', $result)) {
                                $get_sms_status = $customer_status = 'Delivered';
                            } else if (is_array($result) && array_key_exists('status', $result)) {
                                $get_sms_status  = $result['message'];
                                $customer_status = 'Rejected';
                            } else {
                                $get_sms_status  = 'Invalid request';
                                $customer_status = 'Rejected';
                            }

                        } catch (Exception $e) {
                            $get_sms_status  = $e->getMessage();
                            $customer_status = 'Failed';
                        }
                        break;

                    case SendingServer::TYPE_KARIXIO:

                        //                        $find_c_code = substr($phone, 2);
                        //                        if($find_c_code != '91'){
                        //                            $phone = '91'.$phone;
                        //                        }

                        $parameters = [
                            'channel'     => 'sms',
                            'source'      => $data['sender_id'],
                            'destination' => ['+' . $phone],
                            'content'     => [
                                'text' => $message,
                            ],
                        ];

                        try {

                            $headers = [
                                'Content-Type:application/json',
                            ];

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            curl_setopt($ch, CURLOPT_USERPWD, "$sending_server->auth_id" . ':' . "$sending_server->auth_token");
                            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            $response = curl_exec($ch);
                            curl_close($ch);

                            $get_response = json_decode($response, true);

                            if (isset($get_response) && is_array($get_response)) {
                                if (array_key_exists('objects', $get_response)) {
                                    if ($get_response['objects']['0']['status'] == 'queued') {
                                        $get_sms_status  = 'Delivered|' . $get_response['objects']['0']['account_uid'];
                                        $customer_status = 'Delivered';
                                    } else {
                                        $get_sms_status  = $get_response['objects']['0']['status'];
                                        $customer_status = 'Rejected';
                                    }
                                } else if (array_key_exists('error', $get_response)) {
                                    $get_sms_status  = $get_response['error']['message'];
                                    $customer_status = 'Rejected';
                                } else {
                                    $get_sms_status  = 'Unknown error';
                                    $customer_status = 'Failed';
                                }
                            } else {
                                $get_sms_status  = 'Unknown error';
                                $customer_status = 'Failed';
                            }

                        } catch (Exception $e) {
                            $get_sms_status  = $e->getMessage();
                            $customer_status = 'Failed';
                        }
                        break;

                    case SendingServer::TYPE_SIGNALWIRE:

                        $parameters = [
                            'From' => '+' . $data['sender_id'],
                            'Body' => $message,
                            'To'   => '+' . $phone,
                        ];

                        $sending_url = $gateway_url . "/api/laml/2010-04-01/Accounts/$sending_server->project_id/Messages.json";

                        $ch = curl_init();

                        curl_setopt($ch, CURLOPT_URL, $sending_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameters));
                        curl_setopt($ch, CURLOPT_USERPWD, "$sending_server->project_id" . ':' . "$sending_server->api_token");

                        $get_response = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status = curl_error($ch);
                        } else {

                            $result = json_decode($get_response, true);

                            if (isset($result) && is_array($result) && array_key_exists('status', $result) && array_key_exists('error_code', $result)) {
                                if ($result['status'] == 'queued' && $result['error_code'] === null) {
                                    $get_sms_status  = 'Delivered|' . $result['sid'];
                                    $customer_status = 'Delivered';
                                } else {
                                    $get_sms_status  = $result['error_message'];
                                    $customer_status = 'Rejected';
                                }
                            } else if (isset($result) && is_array($result) && array_key_exists('status', $result) && array_key_exists('message', $result)) {
                                $get_sms_status  = $result['message'];
                                $customer_status = 'Rejected';
                            } else {
                                $get_sms_status  = $get_response;
                                $customer_status = 'Rejected';
                            }

                            if ($get_sms_status === null) {
                                $get_sms_status  = 'Check your settings';
                                $customer_status = 'Failed';
                            }
                        }
                        curl_close($ch);

                        break;

                    case SendingServer::TYPE_TELNYX:

                        $phone     = str_replace(['+', '(', ')', '-', ' '], '', $phone);
                        $sender_id = str_replace(['+', '(', ')', '-', ' '], '', $data['sender_id']);

                        $parameters = [
                            'to'   => '+' . $phone,
                            'text' => $message,
                        ];

                        if (is_numeric($data['sender_id'])) {
                            $parameters['from'] = '+' . $sender_id;
                        } else {
                            $parameters['from']                 = $data['sender_id'];
                            $parameters['messaging_profile_id'] = $sending_server->c1;
                        }

                        try {

                            $headers = [
                                'Content-Type:application/json',
                                'Authorization: Bearer ' . $sending_server->api_key,
                            ];

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            $response = curl_exec($ch);
                            curl_close($ch);

                            $get_response = json_decode($response, true);

                            if (isset($get_response) && is_array($get_response)) {
                                if (array_key_exists('data', $get_response) && array_key_exists('to', $get_response['data']) && $get_response['data']['to'][0]['status'] == 'queued') {
                                    $get_sms_status  = 'Delivered|' . $get_response['data']['id'];
                                    $customer_status = 'Delivered';
                                } else if (array_key_exists('errors', $get_response)) {
                                    $get_sms_status  = $get_response['errors'][0]['detail'];
                                    $customer_status = 'Rejected';
                                } else {
                                    $get_sms_status  = (string) $response;
                                    $customer_status = 'Rejected';
                                }
                            } else {
                                $get_sms_status  = 'Unknown error';
                                $customer_status = 'Rejected';
                            }

                        } catch (Exception $e) {
                            $get_sms_status  = $e->getMessage();
                            $customer_status = 'Failed';
                        }
                        break;

                    case SendingServer::TYPE_TELNYXNUMBERPOOL:

                        $parameters = [
                            'to'                   => '+' . $phone,
                            'text'                 => $message,
                            'messaging_profile_id' => $sending_server->c1,
                        ];

                        try {

                            $headers = [
                                'Content-Type:application/json',
                                'Authorization: Bearer ' . $sending_server->api_key,
                            ];

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            $response = curl_exec($ch);
                            curl_close($ch);

                            $get_response = json_decode($response, true);

                            if (isset($get_response) && is_array($get_response)) {
                                if (array_key_exists('data', $get_response) && array_key_exists('to', $get_response['data']) && $get_response['data']['to'][0]['status'] == 'queued') {
                                    $get_sms_status = $customer_status = 'Delivered';
                                } else if (array_key_exists('errors', $get_response)) {
                                    $get_sms_status  = $get_response['errors'][0]['detail'];
                                    $customer_status = 'Rejected';
                                } else {
                                    $get_sms_status  = (string) $response;
                                    $customer_status = 'Rejected';
                                }
                            } else {
                                $get_sms_status  = 'Unknown error';
                                $customer_status = 'Failed';
                            }

                        } catch (Exception $e) {
                            $get_sms_status  = $e->getMessage();
                            $customer_status = 'Failed';
                        }
                        break;

                    case SendingServer::TYPE_BANDWIDTH:

                        $parameters = [
                            'from'          => '+' . $data['sender_id'],
                            'to'            => ['+' . $phone],
                            'text'          => $message,
                            'applicationId' => $sending_server->application_id,
                        ];

                        try {

                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_USERPWD, $sending_server->api_token . ':' . $sending_server->api_secret);

                            $headers   = [];
                            $headers[] = 'Content-Type: application/json';
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                            $result = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status  = curl_error($ch);
                                $customer_status = 'Rejected';
                            } else {

                                $result = json_decode($result, true);

                                if (isset($result) && is_array($result)) {
                                    if (array_key_exists('id', $result)) {
                                        $get_sms_status  = 'Delivered|' . $result['id'];
                                        $customer_status = 'Delivered';
                                    } else if (array_key_exists('error', $result)) {
                                        $get_sms_status  = $result['error'];
                                        $customer_status = 'Rejected';
                                    } else if (array_key_exists('fieldErrors', $result)) {
                                        $get_sms_status  = $result['fieldErrors'][0]['fieldName'] . ' ' . $result['fieldErrors'][0]['description'];
                                        $customer_status = 'Rejected';
                                    } else {
                                        $get_sms_status  = implode(' ', $result);
                                        $customer_status = 'Rejected';
                                    }
                                } else {
                                    $get_sms_status  = $result;
                                    $customer_status = 'Rejected';
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status  = $e->getMessage();
                            $customer_status = 'Failed';
                        }
                        break;

                    case SendingServer::TYPE_ROUTEENET:

                        $curl = curl_init();

                        curl_setopt_array($curl, [
                            CURLOPT_URL            => 'https://auth.routee.net/oauth/token',
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_ENCODING       => '',
                            CURLOPT_MAXREDIRS      => 10,
                            CURLOPT_TIMEOUT        => 30,
                            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                            CURLOPT_CUSTOMREQUEST  => 'POST',
                            CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
                            CURLOPT_HTTPHEADER     => [
                                'authorization: Basic ' . base64_encode($sending_server->application_id . ':' . $sending_server->api_secret),
                                'content-type: application/x-www-form-urlencoded',
                            ],
                        ]);

                        $response = curl_exec($curl);
                        $err      = curl_error($curl);

                        curl_close($curl);

                        if ($err) {
                            $get_sms_status = $err;
                        } else {
                            $response = json_decode($response, true);

                            if (isset($response) && is_array($response) && array_key_exists('access_token', $response)) {
                                $access_token = $response['access_token'];

                                $parameters = [
                                    'body' => $message,
                                    'to'   => '+' . $phone,
                                    'from' => $data['sender_id'],
                                ];

                                $sendSMS = json_encode($parameters);
                                $curl    = curl_init();

                                curl_setopt_array($curl, [
                                    CURLOPT_URL            => $gateway_url,
                                    CURLOPT_RETURNTRANSFER => true,
                                    CURLOPT_ENCODING       => '',
                                    CURLOPT_MAXREDIRS      => 10,
                                    CURLOPT_TIMEOUT        => 30,
                                    CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                                    CURLOPT_CUSTOMREQUEST  => 'POST',
                                    CURLOPT_POSTFIELDS     => $sendSMS,
                                    CURLOPT_HTTPHEADER     => [
                                        'authorization: Bearer ' . $access_token,
                                        'content-type: application/json',
                                    ],
                                ]);

                                $getResponse = curl_exec($curl);

                                curl_close($curl);
                                $get_response = json_decode($getResponse, true);
                                if (isset($get_response) && is_array($get_response) && array_key_exists('status', $get_response)) {
                                    if ($get_response['status'] == 'Queued') {
                                        $get_sms_status = 'Delivered';
                                    } else {
                                        $get_sms_status = $get_response['status'];
                                    }
                                } else if (str_contains($getResponse, 'Queued')) {
                                    $get_sms_status = 'Delivered';
                                } else {
                                    $get_sms_status = $response;
                                }

                            } else {
                                $get_sms_status = 'Access token not found';
                            }
                        }
                        break;

                    case SendingServer::TYPE_HUTCHLK:

                        $auth_data = [
                            'username' => $sending_server->username,
                            'password' => $sending_server->password,
                        ];

                        $headers = [
                            'Content-Type: application/json',
                            'Accept: */*',
                            'X-API-VERSION: v1',
                        ];

                        $login_url = rtrim($gateway_url, '/') . '/api/login';

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $login_url);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($auth_data));
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            $get_sms_status = curl_exec($ch);
                            curl_close($ch);

                            $get_response = json_decode($get_sms_status, true);

                            if (isset($get_response) && is_array($get_response) && array_key_exists('accessToken', $get_response)) {
                                $parameters = [
                                    'campaignName'          => str_random(10),
                                    'mask'                  => $data['sender_id'],
                                    'numbers'               => $phone,
                                    'content'               => $message,
                                    'deliveryReportRequest' => true,
                                ];

                                $sending_url = rtrim($gateway_url, '/') . '/api/sendsms';
                                $headers[]   = 'Authorization: Bearer ' . $get_response['accessToken'];

                                $ch = curl_init();
                                curl_setopt($ch, CURLOPT_URL, $sending_url);
                                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                                curl_setopt($ch, CURLOPT_POST, 1);
                                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                $get_sms_status = curl_exec($ch);
                                curl_close($ch);

                                $get_response = json_decode($get_sms_status, true);

                                if (isset($get_response) && is_array($get_response)) {
                                    if (array_key_exists('serverRef', $get_response)) {
                                        $get_sms_status = 'Delivered|' . $get_response['serverRef'];
                                    }
                                    if (array_key_exists('message', $get_response)) {
                                        $get_sms_status = $get_response['message'];
                                    }
                                    if (array_key_exists('error', $get_response)) {
                                        $get_sms_status = $get_response['error'];
                                    }
                                }
                            } else {
                                if (array_key_exists('error', $get_response)) {
                                    $get_sms_status = $get_response['error'];
                                }
                            }
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }

                        break;

                    case SendingServer::TYPE_TELETOPIASMS:

                        $parameters = [
                            'username'  => $sending_server->username,
                            'password'  => $sending_server->password,
                            'recipient' => $phone,
                            'text'      => $message,
                        ];

                        if ($data['sender_id'] != '') {
                            $parameters['sender'] = $data['sender_id'];
                        }

                        $parameters  = http_build_query($parameters);
                        $gateway_url = $gateway_url . '?' . $parameters;

                        $ch = curl_init();

                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_HTTPGET, 1);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        $headers   = [];
                        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                        $get_sms_status = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status = curl_error($ch);
                        } else {
                            if (substr_count($get_sms_status, 'accepted')) {
                                $get_sms_status = 'Delivered';
                            }
                        }

                        curl_close($ch);
                        break;

                    case SendingServer::TYPE_BROADCASTERMOBILE:

                        $dataFields = [
                            'apiKey'  => (int) $sending_server->api_key,
                            'country' => $sending_server->c1,
                            'dial'    => (int) $data['sender_id'],
                            'tag'     => 'Prueba',
                            'message' => $message,
                            'msisdns' => [$phone],
                        ];

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_POST, true);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dataFields));
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_14_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/76.0.3809.132 Safari/537.36');
                            $headers = [
                                'Content-Type: application/json',
                                'Authorization: ' . $sending_server->api_token,
                            ];

                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            $get_sms_status = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {

                                $output = json_decode($get_sms_status, true);

                                if (isset($output) && is_array($output) && array_key_exists('code', $output)) {
                                    if ($output['code'] == 0) {
                                        $get_sms_status = 'Delivered';
                                    } else {
                                        $get_sms_status = $output['message'];
                                    }
                                }
                            }

                            curl_close($ch);
                        } catch (Exception $ex) {
                            $get_sms_status = $ex->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_SOLUTIONS4MOBILES:

                        $auth_body = [
                            'type'     => 'access_token',
                            'username' => $sending_server->username,
                            'password' => $sending_server->password,
                        ];

                        $auth_curl_params = [
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_POST           => true,
                            CURLOPT_URL            => $auth_url,
                            CURLOPT_CONNECTTIMEOUT => 10,
                            CURLOPT_TIMEOUT        => 10,
                            CURLOPT_HTTPHEADER     => ['cache-control: no-cache', 'content-type: application/json'],
                            CURLOPT_POSTFIELDS     => json_encode($auth_body, true),
                        ];

                        //Setup request and execute
                        $auth_curl = curl_init();
                        curl_setopt_array($auth_curl, ($auth_curl_params));
                        $result = curl_exec($auth_curl);

                        $info = curl_getinfo($auth_curl);

                        //If server returned HTTP Status 200 the request was successful
                        if ($info['http_code'] == 200) {
                            //Store access token - Valid for 30 minutes - We must log in every 30 minutes
                            $arr_res      = json_decode($result);
                            $access_token = $arr_res->payload->access_token;
                            //Send SMS
                            //Setup body
                            $send_body = [
                                [
                                    'to'      => [$phone],
                                    'from'    => $data['sender_id'],
                                    'message' => $message,
                                ],
                            ];

                            $send_curl_params = [
                                CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_POST           => true,
                                CURLOPT_URL            => $gateway_url,
                                CURLOPT_CONNECTTIMEOUT => 10,
                                CURLOPT_TIMEOUT        => 10,
                                CURLOPT_HTTPHEADER     => ['cache-control: no-cache', 'content-type: application/json', "Authorization: Bearer $access_token"],
                                CURLOPT_POSTFIELDS     => json_encode($send_body, true),
                            ];

                            //Setup request and execute
                            $send_curl = curl_init();
                            curl_setopt_array($send_curl, ($send_curl_params));
                            $result = curl_exec($send_curl);

                            $send_info = curl_getinfo($send_curl);

                            $output = json_decode($result, true);

                            //If server returned HTTP Status 200 the request was successful
                            if ($send_info['http_code'] == 200) {
                                if (isset($output) && is_array($output) && array_key_exists('payload', $output)) {
                                    if ($output['payload'][0]['status'] == 'error') {
                                        $get_sms_status = $output['payload'][0]['error']['message'];
                                    } else {
                                        $get_sms_status = 'Delivered';
                                    }
                                } else {
                                    $get_sms_status = json_decode($result);
                                }
                            } else {
                                if (isset($output) && is_array($output) && array_key_exists('errors', $output)) {
                                    $get_sms_status = $output['errors'][0]['message'];
                                } else {
                                    $get_sms_status = json_decode($result);
                                }
                            }
                            curl_close($send_curl);
                        } else {
                            $getAuthData = json_decode($result, true);
                            if (is_array($getAuthData) && array_key_exists('errors', $getAuthData)) {
                                $get_sms_status = $getAuthData['errors'][0]['message'];
                            } else {
                                $get_sms_status = __('locale.labels.failed');
                            }
                        }

                        curl_close($auth_curl);
                        break;

                    case SendingServer::TYPE_BEEMAFRICA:

                        $parameters = [
                            'source_addr'   => $data['sender_id'],
                            'encoding'      => 0,
                            'schedule_time' => '',
                            'message'       => $message,
                            'recipients'    => [['recipient_id' => rand(1000, 99999), 'dest_addr' => (string) $phone]],
                        ];

                        $ch = curl_init($gateway_url);

                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
                        curl_setopt_array($ch, [
                            CURLOPT_POST           => true,
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_HTTPHEADER     => [
                                'Authorization:Basic ' . base64_encode("$sending_server->api_key:$sending_server->api_secret"),
                                'Content-Type: application/json',
                            ],
                            CURLOPT_POSTFIELDS     => json_encode($parameters),
                        ]);
                        $response = curl_exec($ch);

                        if ($response === false) {
                            $get_sms_status = curl_error($ch);
                        } else {
                            $output = json_decode($response, true);

                            if (isset($output) && is_array($output) && array_key_exists('code', $output)) {
                                if ($output['code'] == 100) {
                                    $get_sms_status = 'Delivered|' . $output['request_id'];
                                } else {
                                    $get_sms_status = $output['message'];
                                }
                            } else {
                                $get_sms_status = (string) $response;
                            }
                        }
                        break;

                    case SendingServer::TYPE_BULKSMSONLINE:

                        $parameters = [
                            'username' => $sending_server->username,
                            'password' => $sending_server->password,
                            'to'       => $phone,
                            'message'  => $message,
                            'source'   => $data['sender_id'],
                        ];

                        if ($sms_type == 'unicode' || $sms_type == 'arabic') {
                            $parameters['type'] = 'u';
                        } else {
                            $parameters['type'] = 't';
                        }

                        $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

                            $get_sms_status = curl_exec($ch);
                            $get_sms_status = trim($get_sms_status);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                if (str_contains($get_sms_status, 'OK')) {
                                    $get_sms_status = 'Delivered|' . str_replace('OK: ', '', $get_sms_status);
                                } else {

                                    switch ($get_sms_status) {

                                        case 'E0002':
                                            $get_sms_status = 'Invalid URL. This means that one of the parameters was not provided or left blank.';
                                            break;

                                        case 'E0003':
                                            $get_sms_status = 'Invalid username or password parameter.';
                                            break;

                                        case 'E0004':
                                            $get_sms_status = 'Invalid type parameter.';
                                            break;

                                        case 'E0005':
                                            $get_sms_status = 'Invalid message.';
                                            break;

                                        case 'E0006':
                                            $get_sms_status = 'Invalid TO number.';
                                            break;

                                        case 'E0007':
                                            $get_sms_status = 'Invalid source (Sender name).';
                                            break;

                                        case 'E0008':
                                            $get_sms_status = 'Authentication failed.';
                                            break;

                                        case 'E0010':
                                            $get_sms_status = 'Internal server error.';
                                            break;

                                        case 'E0022':
                                            $get_sms_status = 'Insufficient credit.';
                                            break;

                                        case 'E0033':
                                            $get_sms_status = 'If more than 30 API request per second throughput restriction by default';
                                            break;

                                        case 'E0044':
                                            $get_sms_status = 'mobile network not supported';
                                            break;
                                    }
                                }
                            }

                            curl_close($ch);
                        } catch (Exception $ex) {
                            $get_sms_status = $ex->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_FLOWROUTE:
                        $phone     = str_replace(['+', '(', ')', '-', ' '], '', $phone);
                        $sender_id = str_replace(['+', '(', ')', '-', ' '], '', $data['sender_id']);

                        $sms = [
                            'from' => $sender_id,
                            'to'   => $phone,
                            'body' => $message,
                        ];

                        try {

                            $headers   = [];
                            $headers[] = 'Content-Type: application/vnd.api+json';

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($sms));
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_USERPWD, $sending_server->access_key . ':' . $sending_server->api_secret);

                            $response = curl_exec($ch);
                            curl_close($ch);

                            $get_response = json_decode($response, true);

                            if (isset($get_response) && is_array($get_response)) {
                                if (array_key_exists('data', $get_response)) {
                                    $get_sms_status = 'Delivered';
                                } else if (array_key_exists('errors', $get_response)) {
                                    $get_sms_status = $get_response['errors'][0]['detail'];
                                } else {
                                    $get_sms_status = (string) $response;
                                }
                            } else {
                                $get_sms_status = (string) $response;
                            }

                        } catch (Exception $ex) {
                            $get_sms_status = $ex->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_CHEAPGLOBALSMS:

                        $parameters = [
                            'sub_account'      => $sending_server->username,
                            'sub_account_pass' => $sending_server->password,
                            'action'           => 'send_sms',
                            'sender_id'        => $data['sender_id'],
                            'recipients'       => $phone,
                            'message'          => $message,
                        ];

                        if ($sms_type == 'unicode') {
                            $parameters['unicode'] = 1;
                        }

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_POST, true);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                        $response      = curl_exec($ch);
                        $response_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        if ($response_code != 200) {
                            $get_sms_status = curl_error($ch);
                        } else {
                            if ($response_code != 200) {
                                $get_sms_status = "HTTP ERROR $response_code: $response";
                            } else {
                                $json = @json_decode($response, true);

                                if ($json === null) {
                                    $get_sms_status = "INVALID RESPONSE: $response";
                                } else if ( ! empty($json['error'])) {
                                    $get_sms_status = $json['error'];
                                } else {
                                    $get_sms_status = 'Delivered|' . $json['batch_id'];
                                }
                            }
                        }
                        curl_close($ch);
                        break;

                    case SendingServer::TYPE_ELITBUZZBD:
                        $parameters = [
                            'api_key'  => $sending_server->api_key,
                            'contacts' => $phone,
                            'senderid' => $data['sender_id'],
                            'msg'      => $message,
                        ];

                        if ($sms_type == 'unicode' || $sms_type == 'arabic') {
                            $parameters['type'] = 'unicode';
                        } else {
                            $parameters['type'] = 'text';
                        }

                        $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

                            $get_sms_status = curl_exec($ch);

                            $get_sms_status = trim($get_sms_status);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                if (str_contains($get_sms_status, 'SMS SUBMITTED')) {
                                    $get_sms_status = 'Delivered';
                                } else {

                                    switch ($get_sms_status) {

                                        case '1002':
                                            $get_sms_status = 'Sender Id/Masking Not Found';
                                            break;

                                        case '1003':
                                            $get_sms_status = 'API Not found';
                                            break;

                                        case '1004':
                                            $get_sms_status = 'SPAM Detected';
                                            break;

                                        case '1005':
                                        case '1006':
                                            $get_sms_status = 'Internal Error';
                                            break;

                                        case '1007':
                                            $get_sms_status = 'Balance Insufficient';
                                            break;

                                        case '1008':
                                            $get_sms_status = 'Message is empty';
                                            break;

                                        case '1009':
                                            $get_sms_status = 'Message Type Not Set (text/unicode)';
                                            break;

                                        case '1010':
                                            $get_sms_status = 'Invalid User & Password';
                                            break;

                                        case '1011':
                                            $get_sms_status = 'Invalid User Id';
                                            break;

                                        case '1012':
                                            $get_sms_status = 'Invalid Number';
                                            break;

                                        case '1013':
                                            $get_sms_status = 'API limit error';
                                            break;

                                        case '1014':
                                            $get_sms_status = 'No matching template';
                                            break;
                                    }
                                }
                            }

                            curl_close($ch);
                        } catch (Exception $ex) {
                            $get_sms_status = $ex->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_GREENWEBBD:

                        $parameters = [
                            'to'      => $phone,
                            'message' => $message,
                            'token'   => $sending_server->api_token,
                        ];

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_ENCODING, '');
                        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameters));
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        $response = curl_exec($ch);

                        if ($response === false) {
                            $get_sms_status = curl_error($ch);
                        } else {
                            $output = json_decode($response, true);

                            if (isset($output) && is_array($output) && array_key_exists('status', $output[0])) {
                                if ($output[0]['status'] == 'SENT') {
                                    $get_sms_status = 'Delivered';
                                } else {
                                    $get_sms_status = $output[0]['statusmsg'];
                                }
                            } else {
                                $get_sms_status = (string) $response;
                            }
                        }

                        curl_close($ch);

                        break;

                    case SendingServer::TYPE_HABLAMEV2:
                        $parameters = [
                            'account'           => $sending_server->c1,
                            'apiKey'            => $sending_server->api_key,
                            'token'             => $sending_server->api_token,
                            'toNumber'          => $phone,
                            'sms'               => $message,
                            'isPriority'        => 1,
                            'flash'             => 0,
                            'request_dlvr_rcpt' => 0,
                        ];

                        if (isset($data['sender_id'])) {
                            $parameters['sc'] = $data['sender_id'];
                        }

                        $ch = curl_init();

                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
                        $response = curl_exec($ch);
                        curl_close($ch);

                        $response = json_decode($response, true);

                        if (isset($response) && is_array($response) && array_key_exists('status', $response)) {
                            if ($response['status'] == '1x000') {
                                $get_sms_status = 'Delivered';
                            } else {
                                $get_sms_status = $response['error_description'];
                            }
                        } else {
                            $get_sms_status = 'Invalid Request';
                        }
                        break;

                    case SendingServer::TYPE_EASYSENDSMS:

                        if (is_numeric($data['sender_id'])) {
                            $sender_id = str_replace(['(', ')', '+', '-', ' '], '', $data['sender_id']);
                        } else {
                            $sender_id = $data['sender_id'];
                        }

                        if ($sms_type == 'unicode') {
                            $data_encoding = 1;
                        } else {
                            $data_encoding = 0;
                        }

                        $parameters = http_build_query([
                            'username' => $sending_server->username,
                            'password' => $sending_server->password,
                            'to'       => $phone,
                            'text'     => $message,
                            'type'     => $data_encoding,
                            'from'     => $sender_id,
                        ]);

                        try {

                            $sms_sent_to_user = $gateway_url . '?' . $parameters;

                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $sms_sent_to_user);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            $get_response = curl_exec($ch);
                            curl_close($ch);

                            if (substr_count($get_response, 'OK') == 1) {
                                $get_sms_status = explode(':', $get_response);
                                if (isset($get_sms_status) && is_array($get_sms_status) && array_key_exists('1', $get_sms_status)) {
                                    $get_sms_status = 'Delivered|' . trim($get_sms_status['1']);
                                } else {
                                    $get_sms_status = 'Delivered';
                                }
                            } else {

                                $data_code = filter_var($get_response, FILTER_SANITIZE_NUMBER_INT);

                                $get_sms_status = match ($data_code) {
                                    '1001' => 'Invalid URL. This means that one of the parameters was not provided or left blank',
                                    '1002' => 'Invalid username or password parameter',
                                    '1003' => 'Invalid type parameter',
                                    '1004' => 'Invalid message',
                                    '1005' => 'Invalid mobile number',
                                    '1006' => 'Invalid Sender name',
                                    '1007' => 'Insufficient credit',
                                    '1008' => 'Internal error',
                                    '1009' => 'Service not available',
                                    default => 'Unknown error',
                                };

                            }

                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_ZAMTELCOZM:

                        try {
                            $gateway_url = $gateway_url . 'api_key/' . $sending_server->api_key . '/senderId/' . $data['sender_id'] . '/contacts/' . $phone . '/message/' . urlencode($message);

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_ENCODING, '');
                            curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 0);
                            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');

                            $get_sms_status = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status  = curl_error($ch);
                                $customer_status = 'Rejected';
                            } else {

                                $get_data = json_decode($get_sms_status, true);

                                if (isset($get_data) && is_array($get_data) && array_key_exists('success', $get_data)) {
                                    if ($get_data['success']) {
                                        $get_sms_status = $customer_status = 'Delivered';
                                    } else {
                                        $get_sms_status  = $get_data['responseText'];
                                        $customer_status = 'Rejected';
                                    }
                                }
                            }

                            curl_close($ch);

                        } catch (Exception $ex) {
                            $get_sms_status  = $ex->getMessage();
                            $customer_status = 'Failed';
                        }

                        break;

                    case SendingServer::TYPE_CELLCAST:

                        $parameters = [
                            'sms_text' => $message,
                            'numbers'  => [$phone],
                        ];

                        if (isset($data['sender_id'])) {
                            $parameters['from'] = $data['sender_id'];
                        }

                        try {

                            $headers = [
                                'APPKEY:' . $sending_server->api_key,
                                'Accept: application/json',
                                'Content-Type: application/json',
                            ];

                            $ch = curl_init(); //open connection
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            curl_setopt($ch, CURLOPT_HEADER, false);
                            curl_setopt($ch, CURLOPT_POST, count($parameters));
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            if ( ! $result = curl_exec($ch)) {
                                $get_sms_status = json_decode(curl_error($ch));
                            } else {
                                $output = json_decode($result, true);
                                if (isset($output) && is_array($output) && array_key_exists('msg', $output)) {
                                    if ($output['msg'] == 'Queued') {
                                        $get_sms_status = 'Delivered';
                                    } else {
                                        $get_sms_status = $output['msg'];
                                    }
                                }
                            }
                            curl_close($ch);

                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_AFRICASTALKING:

                        $parameters = [
                            'username' => $sending_server->username,
                            'message'  => $message,
                            'to'       => $phone,
                        ];
                        if (isset($data['sender_id'])) {
                            $parameters['from'] = $data['sender_id'];
                        }

                        try {

                            $headers = [
                                'apiKey:' . $sending_server->api_key,
                                'Accept: application/json',
                                'Content-Type: application/x-www-form-urlencoded',
                            ];

                            $ch = curl_init(); //open connection
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            curl_setopt($ch, CURLOPT_HEADER, false);
                            curl_setopt($ch, CURLOPT_POST, count($parameters));
                            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameters));
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                            if ( ! $result = curl_exec($ch)) {
                                $get_sms_status = json_decode(curl_error($ch));
                            } else {
                                $output = json_decode($result, true);

                                if (isset($output) && is_array($output) && array_key_exists('SMSMessageData', $output)) {
                                    if (str_contains($output['SMSMessageData']['Message'], 'Sent')) {
                                        $get_sms_status = 'Delivered|' . $output['SMSMessageData']['Recipients']['0']['messageId'];
                                    } else {
                                        $get_sms_status = $output['SMSMessageData']['Message'];
                                    }
                                } else {
                                    $get_sms_status = $result;
                                }
                            }
                            curl_close($ch);

                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_CAIHCOM:

                        $parameters = [
                            'toNumber'  => $phone,
                            'message'   => $message,
                            'requestId' => time(),
                            'sendType'  => 'S0001',
                            'token'     => $sending_server->api_token,
                        ];

                        if (isset($data['sender_id'])) {
                            $parameters['from'] = $data['sender_id'];
                        }
                        $parameters = json_encode($parameters);

                        $md5Sum = md5($parameters . $sending_server->c1);

                        try {

                            $headers = [
                                'Content-Type:application/json;charset=UTF-8',
                                'md5Sum: ' . $md5Sum,
                            ];

                            $ch = curl_init(); //open connection
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            curl_setopt($ch, CURLOPT_HEADER, false);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                            if ( ! $result = curl_exec($ch)) {
                                $get_sms_status = json_decode(curl_error($ch));
                            } else {
                                $output = json_decode($result, true);

                                if (isset($output) && is_array($output) && array_key_exists('success', $output) && array_key_exists('desc', $output)) {
                                    if ($output['success']) {
                                        $get_sms_status = 'Delivered|' . $output['messageId'];
                                    } else {
                                        $get_sms_status = $output['desc'];
                                    }
                                } else {
                                    $get_sms_status = $result;
                                }
                            }
                            curl_close($ch);

                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_KECCELSMS:

                        $parameters = [
                            'pass'   => $sending_server->password,
                            'id'     => $sending_server->application_id,
                            'from'   => $data['sender_id'],
                            'to'     => $phone,
                            'text'   => $message,
                            'dlrreq' => 1,
                        ];

                        $parameters = http_build_query($parameters);

                        try {
                            $gateway_url = $gateway_url . '?user=&' . $parameters;

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

                            $get_sms_status = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                if (is_numeric($get_sms_status)) {
                                    $get_sms_status = 'Delivered|' . $get_sms_status;
                                } else {
                                    $get_sms_status = 'Invalid gateway information';
                                }
                            }

                            curl_close($ch);

                        } catch (Exception $ex) {
                            $get_sms_status = $ex->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_JOHNSONCONNECT:

                        $parameters = [
                            'appkey'    => $sending_server->api_key,
                            'secretkey' => $sending_server->api_secret,
                            'phone'     => $phone,
                            'content'   => $message,
                        ];

                        if (isset($data['sender_id'])) {
                            $parameters['source_address'] = $data['sender_id'];
                        }

                        $parameters = http_build_query($parameters);

                        try {
                            $gateway_url = $gateway_url . '?' . $parameters;

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

                            $result = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {

                                $output = json_decode($result, true);

                                if (isset($output) && is_array($output) && array_key_exists('code', $output) && array_key_exists('result', $output)) {
                                    if ($output['code'] == 0) {
                                        $get_sms_status = 'Delivered|' . $output['messageid'];
                                    } else {
                                        $get_sms_status = $output['result'];
                                    }
                                } else {
                                    $get_sms_status = (string) $result;
                                }
                            }

                            curl_close($ch);

                        } catch (Exception $ex) {
                            $get_sms_status = $ex->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_SPEEDAMOBILE:
                        $parameters = [
                            'api_id'       => $sending_server->auth_id,
                            'api_password' => $sending_server->password,
                            'sms_type'     => $sending_server->sms_type,
                            'phonenumber'  => $phone,
                            'sender_id'    => $data['sender_id'],
                            'textmessage'  => $message,
                        ];

                        if ($sms_type == 'unicode' || $sms_type == 'arabic') {
                            $parameters['encoding'] = 'U';
                        } else {
                            $parameters['encoding'] = 'T';
                        }


                        if (isset($data['dlt_template_id'])) {
                            $parameters['template_id'] = $data['dlt_template_id'];
                        }

                        if (isset($data['dlt_entity_id'])) {
                            $parameters['pe_id'] = $data['dlt_entity_id'];
                        }

                        $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_HTTPGET, 1);

                        $get_sms_status = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status = curl_error($ch);
                        } else {

                            $get_data = json_decode($get_sms_status, true);

                            if (is_array($get_data) && array_key_exists('status', $get_data)) {
                                if ($get_data['status'] == 'S') {
                                    $get_sms_status = 'Delivered|' . $get_data['message_id'];
                                } else {
                                    $get_sms_status = $get_data['remarks'];
                                }
                            }
                        }
                        curl_close($ch);
                        break;

                    case SendingServer::TYPE_TEXT2WORLD:

                        $parameters = http_build_query([
                            'username' => $sending_server->username,
                            'password' => $sending_server->password,
                            'type'     => 'TEXT',
                            'mobile'   => $phone,
                            'message'  => $message,
                            'sender'   => $data['sender_id'],
                        ]);

                        try {

                            $sms_sent_to_user = $gateway_url . '?' . $parameters;

                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $sms_sent_to_user);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            $get_sms_status = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status  = curl_error($ch);
                                $customer_status = 'Failed';
                            } else if (substr_count(strtolower($get_sms_status), 'success') == 1) {
                                $get_sms_status = $customer_status = 'Delivered';
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status  = $e->getMessage();
                            $customer_status = 'Failed';
                        }

                        break;

                    case SendingServer::TYPE_ENABLEX:

                        $headers   = [];
                        $headers[] = 'Content-Type: application/json';
                        $headers[] = 'Authorization: Basic ' . base64_encode("$sending_server->application_id:$sending_server->api_key");

                        $parameters = [
                            'body'        => $message,
                            'type'        => 'sms',
                            'campaign_id' => $sending_server->c1,
                            'template_id' => $sending_server->c2,
                            'to'          => [
                                $phone,
                            ],
                        ];

                        if (isset($data['sender_id'])) {
                            $parameters['from'] = $data['sender_id'];
                        }

                        if ($sms_type == 'unicode') {
                            $parameters['data_coding'] = 'unicode';
                        } else {
                            $parameters['data_coding'] = 'auto';
                        }

                        try {
                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            $response = curl_exec($ch);
                            curl_close($ch);

                            $get_response = json_decode($response, true);

                            if (isset($get_response) && is_array($get_response) && array_key_exists('result', $get_response)) {
                                if ($get_response['result'] == '0') {
                                    $get_sms_status = 'Delivered|' . $get_response['job_id'];
                                } else {
                                    $get_sms_status = $get_response['desc'];
                                }
                            } else {
                                $get_sms_status = (string) $response;
                            }

                        } catch (Exception $ex) {
                            $get_sms_status = $ex->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_REALSMS:

                        $parameters = [
                            'username' => $sending_server->username,
                            'api_key'  => $sending_server->api_key,
                            'action'   => 'compose',
                            'to'       => $phone,
                            'sender'   => $data['sender_id'],
                        ];

                        if ($sms_type == 'unicode' || $sms_type == 'arabic') {
                            $parameters['message'] = $this->sms_unicode($message);
                            $parameters['unicode'] = 1;
                        } else {
                            $parameters['message'] = $message;
                            $parameters['unicode'] = 0;
                        }

                        $parameters  = http_build_query($parameters);
                        $gateway_url = $gateway_url . '?' . $parameters;

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_HTTPGET, 1);

                        $get_sms_status = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status = curl_error($ch);
                        } else {

                            $get_sms_status = trim($get_sms_status);

                            if (str_contains($get_sms_status, 'Sent')) {
                                $get_sms_status = 'Delivered';
                            }
                        }
                        curl_close($ch);
                        break;

                    case SendingServer::TYPE_SPOOFSEND:
                    case SendingServer::TYPE_ALHAJSMS:
                    case SendingServer::TYPE_SENDROIDULTIMATE:
                        $parameters = [
                            'apikey'   => $sending_server->api_key,
                            'apitoken' => $sending_server->api_token,
                            'to'       => $phone,
                            'from'     => $data['sender_id'],
                            'text'     => $message,
                        ];

                        if ($sms_type == 'unicode' || $sms_type == 'arabic') {
                            $parameters['type'] = 'unicode';
                        } else {
                            $parameters['type'] = 'sms';
                        }

                        $parameters  = http_build_query($parameters);
                        $gateway_url = $gateway_url . '?sendsms&' . $parameters;

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_HTTPGET, 1);

                        $get_sms_status = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status = curl_error($ch);
                        } else {

                            $get_data = json_decode($get_sms_status, true);

                            if (is_array($get_data) && array_key_exists('status', $get_data)) {
                                if ($get_data['status'] == 'error') {
                                    $get_sms_status = $get_data['message'];
                                } else {
                                    $get_sms_status = 'Delivered';
                                }
                            }
                        }
                        curl_close($ch);
                        break;

                    case SendingServer::TYPE_CALLR:

                        $random_data        = str_random(10);
                        $options            = new stdClass();
                        $options->user_data = $random_data;

                        $phone = str_replace(['+', '(', ')', '-', ' '], '', $phone);

                        $parameters = [
                            'to'      => '+' . $phone,
                            'body'    => $message,
                            'options' => $options,
                        ];

                        if (isset($data['sender_id'])) {
                            $parameters['from'] = $data['sender_id'];
                        }

                        try {
                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

                            $headers   = [];
                            $headers[] = 'Authorization: Basic ' . base64_encode("$sending_server->username:$sending_server->password");
                            $headers[] = 'Content-Type: application/json';
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                            $result = curl_exec($ch);
                            curl_close($ch);

                            $result = json_decode($result, true);

                            if (is_array($result) && array_key_exists('status', $result)) {

                                if ($result['status'] == 'error') {
                                    $get_sms_status = $result['data']['message'];
                                } else {
                                    $get_sms_status = 'Delivered|' . $random_data;
                                }

                            } else {
                                $get_sms_status = 'Invalid request';
                            }

                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_SKYETEL:
                        $parameters = [
                            'to'   => $phone,
                            'text' => $message,
                        ];

                        if (isset($data['sender_id'])) {
                            $gateway_url .= '?from=' . $data['sender_id'];
                        }

                        try {
                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

                            $headers   = [];
                            $headers[] = 'Authorization: Basic ' . base64_encode("$sending_server->account_sid:$sending_server->api_secret");
                            $headers[] = 'Content-Type: application/json';
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                            $result = curl_exec($ch);
                            curl_close($ch);

                            $result = json_decode($result, true);

                            if (is_array($result)) {
                                if (array_key_exists('direction', $result)) {
                                    $get_sms_status = 'Delivered';
                                } else if (array_key_exists('message', $result)) {
                                    $get_sms_status = $result['message'];
                                } else {
                                    $get_sms_status = implode(' ', $result);
                                }
                            } else {
                                $get_sms_status = 'Invalid request';
                            }

                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case 'LTR':

                        $parameters = [
                            'username' => $sending_server->username,
                            'password' => $sending_server->password,
                            'api_key'  => $sending_server->api_key,
                            'phone'    => $phone,
                            'message'  => $message,
                            'sender'   => $data['sender_id'],
                        ];

                        if ($sms_type == 'unicode') {
                            $parameters['type'] = 'Urdu';
                        } else {
                            $parameters['type'] = 'English';
                        }

                        try {

                            $sms_sent_to_user = $gateway_url . '?' . http_build_query($parameters);

                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $sms_sent_to_user);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            $get_sms_status = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                if (str_contains($get_sms_status, 'sent')) {
                                    $get_sms_status = 'Delivered';
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }

                        break;

                    case 'Bulksmsplans':

                        $parameters = [
                            'api_id'       => $sending_server->auth_id,
                            'api_password' => $sending_server->password,
                            'sms_type'     => $sending_server->route,
                            'number'       => $phone,
                            'message'      => $message,
                            'sender'       => $data['sender_id'],
                        ];

                        if ($sms_type == 'unicode') {
                            $parameters['sms_encoding'] = 'unicode';
                        } else {
                            $parameters['sms_encoding'] = 'text';
                        }

                        try {

                            $sms_sent_to_user = $gateway_url . '?' . http_build_query($parameters);

                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $sms_sent_to_user);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            $output = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $result = json_decode($output, true);

                                if (is_array($result) && array_key_exists('code', $result)) {
                                    if ($result['code'] == '200') {
                                        $get_sms_status = 'Delivered';
                                    } else {
                                        $get_sms_status = $result['message'];
                                    }
                                } else {
                                    $get_sms_status = implode(' ', $result);
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case 'Sinch':
                        $parameters = [
                            'from' => $data['sender_id'],
                            'to'   => [
                                $phone,
                            ],
                            'body' => $message,
                        ];

                        try {

                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_POST, true);

                            $headers   = [];
                            $headers[] = "Authorization: Bearer $sending_server->api_token";
                            $headers[] = 'Content-Type: application/json';
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                            $result = curl_exec($ch);
                            curl_close($ch);

                            $result = json_decode($result, true);

                            if (is_array($result) && array_key_exists('id', $result)) {
                                $get_sms_status = 'Delivered|' . $result['id'];
                            } else {
                                $get_sms_status = 'Invalid request';
                            }
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_D7NETWORKS:

                        $parameters = [
                            'messages'        => [
                                [
                                    'channel'     => 'sms',
                                    'recipients'  => [$phone],
                                    'content'     => $message,
                                    'msg_type'    => 'text',
                                    'data_coding' => 'text',
                                ],
                            ],
                            'message_globals' => [
                                'originator' => $data['sender_id'],
                                'report_url' => route('dlr.d7networks'),
                            ],
                        ];

                        try {

                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, 'https://api.d7networks.com/messages/v1/send');
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_ENCODING, '');
                            curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 0);
                            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_POST, 1);

                            $headers   = [];
                            $headers[] = "Authorization: Bearer $sending_server->api_token";
                            $headers[] = 'Content-Type: application/json';
                            $headers[] = 'Accept: application/json';
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                            $result = curl_exec($ch);
                            curl_close($ch);

                            $result = json_decode($result, true);

                            if (is_array($result) && array_key_exists('status', $result)) {
                                if ($result['status'] == 'accepted') {
                                    $get_sms_status = 'Delivered|' . $result['request_id'];
                                } else {
                                    $get_sms_status = $result['status'];
                                }
                            } else if (is_array($result) && array_key_exists('detail', $result) && array_key_exists('message', $result['detail'])) {
                                $get_sms_status = $result['detail']['message'];
                            } else if (is_array($result) && array_key_exists('title', $result)) {
                                $get_sms_status = $result['title'];
                            } else {
                                $get_sms_status = 'Invalid Request';
                            }

                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case 'CMCOM':

                        $random_data = str_random(10);

                        $parameters = [
                            'messages' => [
                                'authentication' => [
                                    'productToken' => $sending_server->api_token,
                                ],
                                'msg'            => [
                                    [
                                        'from'                        => $data['sender_id'],
                                        'body'                        => [
                                            'content' => $message,
                                            'type'    => 'auto',
                                        ],
                                        'minimumNumberOfMessageParts' => 1,
                                        'maximumNumberOfMessageParts' => 8,
                                        'to'                          => [
                                            [
                                                'number' => '+' . $phone,
                                            ],
                                        ],
                                        'allowedChannels'             => [
                                            'SMS',
                                        ],
                                        'reference'                   => $random_data,
                                    ],
                                ],
                            ],
                        ];

                        $headers = [
                            'Content-Type:application/json;charset=UTF-8',
                        ];

                        $ch = curl_init(); //open connection
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                        curl_setopt($ch, CURLOPT_HEADER, false);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                        if ( ! $result = curl_exec($ch)) {
                            $get_sms_status = json_decode(curl_error($ch));
                        } else {
                            $output = json_decode($result, true);

                            if (isset($output) && is_array($output) && array_key_exists('errorCode', $output) && array_key_exists('details', $output)) {
                                if ($output['errorCode'] == 0) {
                                    $get_sms_status = 'Delivered|' . $random_data;
                                } else {
                                    $get_sms_status = $output['details'];
                                }
                            } else {
                                $get_sms_status = (string) $output;
                            }
                        }
                        curl_close($ch);
                        break;

                    case 'PitchWink':
                        $parameters = [
                            'version'        => '4.00',
                            'credential'     => $sending_server->c1,
                            'token'          => $sending_server->api_token,
                            'function'       => 'SEND_MESSAGE',
                            'principal_user' => '',
                            'messages'       => [
                                [
                                    'id_extern'    => $data['sender_id'],
                                    'aux_user'     => uniqid(),
                                    'mobile'       => $phone,
                                    'send_project' => 'N',
                                    'message'      => $message,
                                ],
                            ],
                        ];

                        try {

                            $ch = curl_init(); //open connection
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HEADER, false);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                            if ( ! $result = curl_exec($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $output = json_decode($result, true);

                                if (isset($output) && is_array($output) && array_key_exists('returncode', $output)) {
                                    switch ($output['returncode']) {
                                        case '000':
                                            $get_sms_status = 'Delivered';
                                            break;

                                        case '001':
                                            $get_sms_status = 'Credential and/or Token invalids';
                                            break;

                                        case '002':
                                            $get_sms_status = 'API not available for Test Accounts';
                                            break;

                                        case '003':
                                            $get_sms_status = 'Account Inactive';
                                            break;

                                        case '004':
                                            $get_sms_status = 'Exceeded the limit of 20.000 messages';
                                            break;

                                        case '005':
                                            $get_sms_status = 'Wrong Version';
                                            break;

                                        case '006':
                                            $get_sms_status = 'Version is invalid';
                                            break;

                                        case '007':
                                            $get_sms_status = 'Function does not exist';
                                            break;

                                        case '008':
                                            $get_sms_status = 'Attribute invalid';
                                            break;

                                        case '009':
                                            $get_sms_status = 'Account blocked';
                                            break;

                                        case '600':
                                        case '601':
                                        case '602':
                                        case '603':
                                            $get_sms_status = 'Json is invalid';
                                            break;

                                        case '900':
                                        case '901':
                                        case '902':
                                            $get_sms_status = 'Internal Error';
                                            break;

                                        case '905':
                                            $get_sms_status = 'POST not accepted. Send again';
                                            break;
                                    }

                                } else {
                                    $get_sms_status = (string) $output;
                                }
                            }
                            curl_close($ch);

                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case 'Wavy':
                        $parameters = [
                            'destination' => $phone,
                            'messageText' => $message,
                        ];

                        $headers = [
                            "authenticationtoken: $sending_server->auth_token",
                            "username: $sending_server->username",
                            'content-type: application/json',
                        ];

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        $get_data = curl_exec($ch);

                        if (curl_error($ch)) {
                            $get_sms_status = curl_error($ch);
                        } else {

                            $get_response = json_decode($get_data, true);

                            if (isset($get_response) && is_array($get_response)) {
                                if (array_key_exists('id', $get_response)) {
                                    $get_sms_status = 'Delivered';
                                } else if (array_key_exists('errorMessage', $get_response)) {
                                    $get_sms_status = $get_response['errorMessage'];
                                }
                            } else {
                                $get_sms_status = implode(' ', $get_response);
                            }
                        }
                        curl_close($ch);
                        break;

                    case 'Solucoesdigitais':
                        $parameters = [
                            'usuario'              => $sending_server->username,
                            'senha'                => $sending_server->password,
                            'centro_custo_interno' => $sending_server->c1,
                            'id_campanha'          => str_random(10),
                            'numero'               => $phone,
                            'mensagem'             => $message,
                        ];

                        try {

                            $sms_sent_to_user = $gateway_url . '?' . http_build_query($parameters);

                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $sms_sent_to_user);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            $get_data = curl_exec($ch);

                            if (curl_error($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $get_response = json_decode($get_data, true);

                                if (isset($get_response) && is_array($get_response) && array_key_exists('status', $get_response)) {
                                    if ($get_response['status']) {
                                        $get_sms_status = 'Delivered';
                                    } else {
                                        $get_sms_status = $get_response['infomacoes'][0];
                                    }
                                } else {
                                    $get_sms_status = implode(' ', $get_response);
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case 'SmartVision':
                        $parameters = [
                            'key'      => $sending_server->api_key,
                            'senderid' => $data['sender_id'],
                            'contacts' => $phone,
                            'campaign' => '6940',
                            'routeid'  => '39',
                            'msg'      => $message,
                        ];

                        if ($sms_type == 'unicode') {
                            $parameters['type'] = 'unicode';
                        } else {
                            $parameters['type'] = 'text';
                        }

                        try {

                            $sms_sent_to_user = $gateway_url . '?' . http_build_query($parameters);

                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $sms_sent_to_user);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            $get_response = curl_exec($ch);

                            if (curl_error($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                if (substr_count($get_response, 'SMS SUBMITTED') !== 0) {
                                    $get_sms_status = 'Delivered';
                                } else {
                                    switch (trim($get_response)) {
                                        case '1002':
                                            $get_sms_status = 'Sender Id/Masking Not Found';
                                            break;
                                        case '1003':
                                            $get_sms_status = 'API Key Not Found';
                                            break;
                                        case '1004':
                                            $get_sms_status = 'SPAM Detected';
                                            break;
                                        case '1005':
                                        case '1006':
                                            $get_sms_status = 'Internal Error';
                                            break;
                                        case '1007':
                                            $get_sms_status = 'Balance Insufficient';
                                            break;
                                        case '1008':
                                            $get_sms_status = 'Message is empty';
                                            break;
                                        case '1009':
                                            $get_sms_status = 'Message Type Not Set (text/unicode)';
                                            break;
                                        case '1010':
                                            $get_sms_status = 'Invalid User & Password';
                                            break;
                                        case '1011':
                                            $get_sms_status = 'Invalid User Id';
                                            break;
                                    }
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case 'ZipComIo':

                        $parameters = [
                            'to'        => $phone,
                            'from'      => $data['sender_id'],
                            'content'   => $message,
                            'type'      => 'sms',
                            'simulated' => true,
                        ];

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_POST, 1);

                            $headers   = [];
                            $headers[] = "x-api-key: $sending_server->api_key";
                            $headers[] = 'Content-Type: application/json';
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                            $get_sms_status = curl_exec($ch);
                            curl_close($ch);

                            $get_data = json_decode($get_sms_status, true);

                            if (is_array($get_data) && array_key_exists('status', $get_data)) {
                                if ($get_data['status'] == 'Message Submitted') {
                                    $get_sms_status = 'Delivered';
                                } else {
                                    $get_sms_status = $get_data['status'];
                                }
                            }

                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case 'GlobalSMSCN':

                        $time = time();
                        $sign = md5($sending_server->api_key . $sending_server->api_secret . $time);

                        $parameters = [
                            'appId'   => $sending_server->application_id,
                            'numbers' => $phone,
                            'content' => $message,
                        ];

                        if ($data['sender_id']) {
                            $parameters['senderID'] = $data['sender_id'];
                        }

                        $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                        $headers   = [];
                        $headers[] = "Sign: $sign";
                        $headers[] = "Timestamp: $time";
                        $headers[] = "Api-Key: $sending_server->api_key";
                        $headers[] = 'Content-Type: application/json;charset=UTF-8';

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_HTTPGET, 1);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

                        $get_sms_status = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status = curl_error($ch);
                        } else {

                            $get_data = json_decode($get_sms_status, true);

                            if (is_array($get_data) && array_key_exists('status', $get_data)) {
                                $code = $get_data['status'];
                                switch ($code) {

                                    case '0':
                                        $get_sms_status = 'Delivered';
                                        break;

                                    case '-1':
                                        $get_sms_status = 'Authentication error';
                                        break;

                                    case '-2':
                                        $get_sms_status = 'IP access is limited';
                                        break;

                                    case '-3':
                                        $get_sms_status = 'Sensitive words';
                                        break;

                                    case '-4':
                                        $get_sms_status = 'SMS message is empty';
                                        break;

                                    case '-5':
                                        $get_sms_status = 'SMS message is over length';
                                        break;

                                    case '-6':
                                        $get_sms_status = 'Do not match template';
                                        break;

                                    case '-7':
                                        $get_sms_status = 'Receiver numbers over limit';
                                        break;

                                    case '-8':
                                        $get_sms_status = 'Receiver number empty';
                                        break;

                                    case '-9':
                                        $get_sms_status = 'Receiver number abnormal';
                                        break;

                                    case '-10':
                                        $get_sms_status = 'Balance is low';
                                        break;

                                    case '-11':
                                        $get_sms_status = 'Incorrect timing format';
                                        break;

                                    case '-12':
                                        $get_sms_status = 'Due to platform issue,bulk submit is fail,pls contact admin';
                                        break;

                                    case '-13':
                                        $get_sms_status = 'User locked';
                                        break;

                                    case '-16':
                                        $get_sms_status = 'Timestamp expires';
                                        break;
                                }
                            }

                        }
                        curl_close($ch);
                        break;

                    case 'Web2SMS237':

                        $curl = curl_init();

                        curl_setopt_array($curl, [
                            CURLOPT_URL            => 'https://api.web2sms237.com/token',
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_ENCODING       => '',
                            CURLOPT_MAXREDIRS      => 10,
                            CURLOPT_TIMEOUT        => 0,
                            CURLOPT_FOLLOWLOCATION => true,
                            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                            CURLOPT_CUSTOMREQUEST  => 'POST',
                            CURLOPT_HTTPHEADER     => [
                                'Authorization: Basic ' . base64_encode($sending_server->api_key . ':' . $sending_server->api_secret),
                            ],
                        ]);

                        $response = curl_exec($curl);

                        curl_close($curl);

                        $response = json_decode($response, true);

                        if (isset($response) && is_array($response) && array_key_exists('access_token', $response)) {
                            $access_token = $response['access_token'];

                            $parameters = [
                                'text'      => $message,
                                'phone'     => '+' . $phone,
                                'sender_id' => $data['sender_id'],
                                'flash'     => false,
                            ];

                            $sendSMS = json_encode($parameters);
                            $curl    = curl_init();

                            curl_setopt_array($curl, [
                                CURLOPT_URL            => $gateway_url,
                                CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_ENCODING       => '',
                                CURLOPT_MAXREDIRS      => 10,
                                CURLOPT_TIMEOUT        => 30,
                                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                                CURLOPT_CUSTOMREQUEST  => 'POST',
                                CURLOPT_POSTFIELDS     => $sendSMS,
                                CURLOPT_HTTPHEADER     => [
                                    'authorization: Bearer ' . $access_token,
                                    'content-type: application/json',
                                ],
                            ]);

                            $response = curl_exec($curl);
                            $err      = curl_error($curl);

                            curl_close($curl);

                            if ($err) {
                                $get_sms_status = $err;
                            } else {
                                $response = json_decode($response, true);

                                if (isset($response) && is_array($response)) {

                                    if (array_key_exists('id', $response)) {
                                        $get_sms_status = 'Delivered';
                                    } else if (array_key_exists('message', $response)) {
                                        $get_sms_status = $response['message'];
                                    } else {
                                        $get_sms_status = 'Failed';
                                    }
                                } else {
                                    $get_sms_status = 'Invalid Request';
                                }
                            }

                        } else {
                            $get_sms_status = 'Access token not found';
                        }

                        break;

                    case 'BongaTech':
                        $parameters = [
                            'username' => $sending_server->username,
                            'password' => $sending_server->password,
                            'phone'    => $phone,
                            'message'  => $message,
                            'sender'   => $data['sender_id'],
                        ];

                        try {

                            $sms_sent_to_user = $gateway_url . '?' . http_build_query($parameters);

                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $sms_sent_to_user);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            $get_sms_status = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $get_data = json_decode($get_sms_status, true);

                                if (is_array($get_data) && array_key_exists('status', $get_data)) {
                                    if ( ! $get_data['status']) {
                                        $get_sms_status = $get_data['message'];
                                    } else {
                                        $get_sms_status = 'Delivered';
                                    }
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case 'FloatSMS':
                        $parameters = [
                            'key'     => $sending_server->api_key,
                            'phone'   => $phone,
                            'message' => $message,
                        ];

                        try {

                            $sms_sent_to_user = $gateway_url . '?' . http_build_query($parameters);

                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $sms_sent_to_user);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            $get_sms_status = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $get_data = json_decode($get_sms_status, true);
                                if (is_array($get_data) && array_key_exists('status', $get_data)) {
                                    if ($get_data['status'] == 200) {
                                        $get_sms_status = 'Delivered';
                                    } else {
                                        $get_sms_status = $get_data['message'];
                                    }
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case 'MaisSMS':

                        $parameters = [
                            [
                                'numero'      => $phone,
                                'mensagem'    => $message,
                                'servico'     => 'short',
                                'parceiro_id' => $sending_server->c1,
                                'codificacao' => '0',
                            ],
                        ];

                        try {

                            $headers = [
                                'Content-Type:application/json',
                                'Authorization: Bearer ' . $sending_server->api_token,
                            ];

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            $response = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $get_response = json_decode($response, true);
                                if (isset($get_response) && is_array($get_response) && array_key_exists('status', $get_response)) {
                                    if ($get_response['status'] == '200') {
                                        $get_sms_status = 'Delivered';
                                    } else {
                                        $get_sms_status = 'Status Code: ' . $get_response['status'];
                                    }
                                } else {
                                    $get_sms_status = 'Authentication failed';
                                }
                            }

                            curl_close($ch);

                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_EASYSMSXYZ:

                        $parameters = [
                            'number'   => $phone,
                            'message'  => $message,
                            'schedule' => null,
                            'key'      => $sending_server->api_key,
                            'devices'  => $sending_server->device_id,
                            'type'     => 'sms',
                        ];

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_POST, true);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36');
                        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameters));
                        $response = curl_exec($ch);
                        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        if (curl_errno($ch)) {
                            $get_sms_status = curl_error($ch);
                        } else {
                            if ($httpCode == 200) {
                                $json = json_decode($response, true);

                                if ( ! $json) {
                                    if (empty($response)) {
                                        $get_sms_status = 'Missing data in request. Please provide all the required information to send messages.';
                                    } else {
                                        $get_sms_status = $response;
                                    }
                                    $customer_status = 'Failed';
                                } else {
                                    if ($json['success']) {
                                        $get_sms_status = $customer_status = 'Delivered';
                                    } else {
                                        $get_sms_status  = $json['error']['message'];
                                        $customer_status = 'Failed';
                                    }
                                }
                            } else {
                                $get_sms_status  = 'Error Code: ' . $httpCode;
                                $customer_status = 'Failed';
                            }
                        }
                        curl_close($ch);
                        break;

                    case 'Sozuri':
                        $parameters = [
                            'project' => $sending_server->project_id,
                            'from'    => $data['sender_id'],
                            'to'      => $phone,
                            'channel' => 'sms',
                            'message' => $message,
                            'type'    => 'promotional',
                        ];

                        $headers = [
                            "authorization: Bearer $sending_server->api_key",
                            'Content-Type: application/json',
                            'Accept: application/json',
                        ];

                        $ch = curl_init();

                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_POST, 1);

                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                        $result = curl_exec($ch);
                        if (curl_errno($ch)) {
                            $get_sms_status = curl_error($ch);
                        } else {
                            $response = json_decode($result, true);

                            if (is_array($response) && array_key_exists('messageData', $response) && array_key_exists('messages', $response['messageData'])) {
                                if ($response['messageData']['messages']) {
                                    $get_sms_status = 'Delivered';
                                } else {
                                    $get_sms_status = 'Unknown error';
                                }
                            } else {
                                $get_sms_status = 'Unknown Error';
                            }
                        }
                        curl_close($ch);

                        break;

                    case 'ExpertTexting':

                        $parameters = [
                            'username'   => $sending_server->username,
                            'api_key'    => $sending_server->api_key,
                            'api_secret' => $sending_server->api_secret,
                            'from'       => $data['sender_id'],
                            'to'         => $phone,
                            'text'       => $message,
                        ];

                        if ($sms_type == 'unicode') {
                            $parameters['type'] = 'unicode';
                        } else {
                            $parameters['type'] = 'text';
                        }

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_POST, true);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameters));
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            $response = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $result = json_decode($response, true);

                                if (is_array($result) && array_key_exists('Status', $result)) {
                                    if ($result['Status'] == 0) {
                                        $get_sms_status = 'Delivered';
                                    } else {
                                        $get_sms_status = $result['ErrorMessage'];
                                    }
                                } else {
                                    $get_sms_status = (string) $response;
                                }
                            }
                            curl_close($ch);

                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }

                        break;

                    case 'Ejoin':

                        $parameters = [
                            'type'     => 'send-sms',
                            'task_num' => 1,
                            'tasks'    => [
                                [
                                    'tid' => time(),
                                    'to'  => $phone,
                                    'sms' => $message,
                                ],
                            ],
                        ];

                        if ($data['sender_id']) {
                            $parameters['tasks'][0]['from'] = $data['sender_id'];
                        }

                        $headers = [
                            'Content-Type: application/json',
                        ];

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                        curl_setopt($ch, CURLOPT_URL, $gateway_url . '?username=' . $sending_server->username . '&password=' . $sending_server->password);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        $get_data = curl_exec($ch);

                        if (curl_error($ch)) {
                            $get_sms_status = curl_error($ch);
                        } else {

                            $get_response = json_decode($get_data, true);

                            if (isset($get_response) && is_array($get_response) && array_key_exists('code', $get_response) && array_key_exists('reason', $get_response)) {
                                if ($get_response['code'] == 0 || $get_response['code'] == 200) {
                                    $get_sms_status = 'Delivered';
                                } else {
                                    $get_sms_status = $get_response['reason'];
                                }
                            } else {
                                $get_sms_status = $get_response['desc'];
                            }
                        }
                        curl_close($ch);
                        break;

                    case 'BulkSMSNigeria':
                        $parameters = [
                            'api_token'     => $sending_server->api_token,
                            'gateway'       => $sending_server->c1,
                            'append_sender' => $sending_server->c2,
                            'from'          => $data['sender_id'],
                            'to'            => $phone,
                            'body'          => $message,
                        ];

                        try {

                            $headers = [
                                'Content-Type: application/json',
                                'Accept: application/json',
                            ];

                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_POST, 1);

                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                            $result = curl_exec($ch);
                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $response = json_decode($result, true);

                                if (is_array($response)) {
                                    if (array_key_exists('data', $response) && array_key_exists('status', $response['data'])) {
                                        $get_sms_status = 'Delivered';
                                    }
                                    if (array_key_exists('error', $response) && array_key_exists('message', $response['error'])) {
                                        $get_sms_status = $response['error']['message'];
                                    }
                                } else {
                                    $get_sms_status = 'Unknown Error';
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case 'SendSMSGate':

                        $parameters = http_build_query([
                            'user' => $sending_server->username,
                            'pwd'  => $sending_server->password,
                            'dadr' => $phone,
                            'text' => $message,
                            'sadr' => $data['sender_id'],
                        ]);

                        try {

                            $sms_sent_to_user = $gateway_url . '?' . $parameters;

                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $sms_sent_to_user);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            $get_sms_status = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                if (is_numeric($get_sms_status)) {
                                    $get_sms_status = 'Delivered';
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case 'Gateway360':
                        $parameters = [
                            'api_key'  => $sending_server->api_key,
                            'concat'   => 1,
                            'messages' => [
                                [
                                    'from' => $data['sender_id'],
                                    'to'   => $phone,
                                    'text' => $message,
                                ],
                            ],
                        ];

                        try {

                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_POST, 1);

                            $headers   = [];
                            $headers[] = 'Content-Type: application/json';
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                            $result = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {

                                $result = json_decode($result, true);

                                if (isset($result) && is_array($result) && array_key_exists('status', $result)) {
                                    if ($result['status'] == 'ok') {
                                        $get_sms_status = 'Delivered';
                                    } else {
                                        $get_sms_status = $result['error_msg'];
                                    }

                                } else {
                                    $get_sms_status = $result;
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case 'AjuraTech':

                        $parameters = [
                            'apikey'         => $sending_server->api_key,
                            'secretkey'      => $sending_server->api_secret,
                            'callerID'       => $data['sender_id'],
                            'toUser'         => $phone,
                            'messageContent' => $message,
                        ];

                        try {

                            $sms_sent_to_user = $gateway_url . '?' . http_build_query($parameters);

                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $sms_sent_to_user);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            $result = curl_exec($ch);

                            if (curl_error($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {

                                $result = json_decode($result, true);

                                if (isset($result) && is_array($result) && array_key_exists('Status', $result)) {
                                    if ($result['Status'] == '0') {
                                        $get_sms_status = 'Delivered';
                                    } else {
                                        $get_sms_status = $result['Text'];
                                    }

                                } else {
                                    $get_sms_status = $result;
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }

                        break;

                    case 'SMSCloudCI':

                        $parameters = [
                            'sender'     => $data['sender_id'],
                            'content'    => $message,
                            'recipients' => [$phone],
                        ];

                        try {

                            $headers = [
                                'Content-Type: application/json',
                                'cache-control: no-cache',
                                'Authorization: Bearer ' . $sending_server->api_token,
                            ];

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            $response = curl_exec($ch);
                            curl_close($ch);

                            $get_response = json_decode($response, true);

                            if (isset($get_response) && is_array($get_response)) {
                                if (array_key_exists('status', $get_response)) {
                                    if ($get_response['status'] == 200) {
                                        $get_sms_status = 'Delivered';
                                    } else {
                                        $get_sms_status = $get_response['statusMessage'];
                                    }
                                } else if (array_key_exists('id', $get_response)) {
                                    $get_sms_status = 'Delivered|' . $get_response['id'];
                                } else {
                                    $get_sms_status = (string) $response;
                                }
                            } else {
                                $get_sms_status = (string) $response;
                            }

                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case 'LifetimeSMS':

                        $parameters = [
                            'api_token'  => $sending_server->api_token,
                            'api_secret' => $sending_server->api_secret,
                            'from'       => $data['sender_id'],
                            'message'    => $message,
                            'to'         => $phone,
                        ];

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_HEADER, 0);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                            $get_sms_status = curl_exec($ch);
                            curl_close($ch);

                            if (substr_count($get_sms_status, 'OK') == 1) {
                                $get_sms_status = explode(':', $get_sms_status);
                                if (isset($get_sms_status) && is_array($get_sms_status) && array_key_exists('3', $get_sms_status)) {
                                    $get_sms_status = 'Delivered|' . trim($get_sms_status['3']);
                                } else {
                                    $get_sms_status = 'Delivered';
                                }
                            } else {
                                $get_sms_status = str_replace('ERROR:', '', $get_sms_status);
                            }

                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case 'PARATUS':

                        $parameters = [
                            'app' => 'ws',
                            'u'   => $sending_server->username,
                            'h'   => $sending_server->api_token,
                            'to'  => $phone,
                            'op'  => 'pv',
                            'msg' => $message,
                        ];

                        if (isset($data['sender_id'])) {
                            $parameters['from'] = $data['sender_id'];
                        }

                        try {
                            $sms_sent_to_user = $gateway_url . '?' . http_build_query($parameters);

                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $sms_sent_to_user);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            $result = curl_exec($ch);

                            if (curl_error($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {

                                $response = json_decode($result, true);

                                if (is_array($response) && array_key_exists('data', $response)) {
                                    $get_sms_status = 'Delivered|' . $response['data'][0]['smslog_id'];
                                } else if (is_array($response) && array_key_exists('error', $response)) {
                                    $get_sms_status = $response['error_string'];
                                } else {
                                    $get_sms_status = (string) $result;
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $exception) {
                            $get_sms_status = $exception->getMessage();
                        }
                        break;

                    case 'MOOVCI':

                        $timestamp = date('Y-m-d H:i:s');
                        $token     = md5("$sending_server->c1" . "$sending_server->api_key" . $timestamp);

                        $parameters = [
                            'recipients' => $phone,
                            'sendmode'   => 0,
                            'message'    => mb_convert_encoding($message, 'ISO-8859-1', 'UTF-8'),
                            'smstype'    => 'normal',
                            'sendername' => $data['sender_id'],
                        ];

                        $headers = [
                            'apiKey: ' . $sending_server->api_key,
                            'login: ' . $sending_server->c1,
                            'timeStamp: ' . $timestamp,
                            'token: ' . $token,
                        ];

                        try {

                            $ch = curl_init($gateway_url);
                            curl_setopt($ch, CURLOPT_FAILONERROR, false);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameters));
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            $result = curl_exec($ch);
                            curl_close($ch);

                            $response = json_decode($result, true);

                            if (is_array($response) && array_key_exists('smsResponse', $response)) {
                                $get_sms_status = 'Delivered';
                            } else {
                                $get_sms_status = array_key_first($response);
                            }

                        } catch (Exception $exception) {
                            $get_sms_status = $exception->getMessage();
                        }
                        break;

                    case 'LeTexto':

                        $parameters = [
                            'campaignType' => 'SIMPLE',
                            'sender'       => $data['sender_id'],
                            'message'      => $message,
                            'recipients'   => [['phone' => $phone]],
                        ];

                        try {

                            $headers = [
                                'Content-Type: application/json',
                                'Authorization: Bearer ' . $sending_server->api_token,
                            ];

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            $response = curl_exec($ch);
                            curl_close($ch);

                            $get_response = json_decode($response, true);

                            if (isset($get_response) && is_array($get_response)) {
                                if (array_key_exists('status', $get_response)) {
                                    if ($get_response['status'] == 200) {
                                        $get_sms_status = 'Delivered';
                                    } else {
                                        $get_sms_status = $get_response['message'];
                                    }
                                } else if (array_key_exists('id', $get_response)) {
                                    $get_sms_status = 'Delivered|' . $get_response['id'];
                                } else {
                                    $get_sms_status = (string) $response;
                                }
                            } else {
                                $get_sms_status = (string) $response;
                            }

                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case 'SMSCarrierEU':
                        $parameters = [
                            'user'       => $sending_server->username,
                            'password'   => $sending_server->password,
                            'sender'     => $data['sender_id'],
                            'recipients' => $phone,
                            'dlr'        => 0,
                        ];

                        if ($sms_type == 'unicode') {
                            $parameters['message'] = $this->sms_unicode($message);
                            $gateway_url           = 'https://smsc.i-digital-m.com/smsgw/sendunicode.php';
                        } else {
                            $parameters['message'] = $message;
                        }

                        $sending_url = $gateway_url . '?' . http_build_query($parameters);

                        try {
                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $sending_url);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            $get_sms_status = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $get_sms_status = preg_replace("/]/", '', $get_sms_status);

                                if (substr_count($get_sms_status, 'OK') == 1) {
                                    $get_sms_status = 'Delivered';
                                } else {
                                    $get_sms_status = str_replace('ERROR:', '', $get_sms_status);
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $exception) {
                            $get_sms_status = $exception->getMessage();
                        }
                        break;

                    case 'MSMPusher':
                        $parameters = [
                            'privatekey' => $sending_server->c1,
                            'publickey'  => $sending_server->c2,
                            'sender'     => $data['sender_id'],
                            'numbers'    => $phone,
                            'message'    => $message,
                        ];

                        $sending_url = $gateway_url . '?' . http_build_query($parameters);

                        try {
                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $sending_url);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            $get_sms_status = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $get_response = json_decode($get_sms_status, true);

                                if (is_array($get_response) && array_key_exists('status', $get_response) && array_key_exists('type', $get_response)) {
                                    if ($get_response['status'] == '1000') {
                                        $get_sms_status = 'Delivered';
                                    } else {
                                        $get_sms_status = $get_response['type'];
                                    }
                                } else {
                                    $get_sms_status = 'Failed';
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $exception) {
                            $get_sms_status = $exception->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_TXTRIA:
                        $parameters = [
                            'sys_id'     => $sending_server->c1,
                            'auth_token' => $sending_server->auth_token,
                            'From'       => $data['sender_id'],
                            'To'         => $phone,
                            'Body'       => urlencode($message),
                        ];

                        try {
                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);

                            $response = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $get_response = json_decode($response, true);

                                if (isset($get_response) && is_array($get_response)) {
                                    if (array_key_exists('success', $get_response) && $get_response['success'] == 1) {
                                        $get_sms_status = 'Delivered';
                                    } else if (array_key_exists('error', $get_response) && $get_response['error'] == 1) {
                                        $get_sms_status = $get_response['message'];
                                    } else {
                                        $get_sms_status = (string) $response;
                                    }
                                } else {
                                    $get_sms_status = (string) $response;
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $exception) {
                            $get_sms_status = $exception->getMessage();
                        }
                        break;

                    case 'Gatewayapi':

                        $sms_counter  = new SMSCounter();
                        $message_data = $sms_counter->count($message);

                        $parameters = [
                            'message'      => $message,
                            'sender'       => $data['sender_id'],
                            'callback_url' => route('dlr.gatewayapi'),
                            'max_parts'    => 9,
                            'recipients'   => [
                                [
                                    'msisdn' => $phone,
                                ],
                            ],
                        ];

                        if ($message_data->encoding == 'UTF16') {
                            $parameters['encoding'] = 'UCS2';
                        }

                        $headers = [
                            'Accept: application/json',
                            'Content-Type: application/json',
                        ];

                        try {
                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_USERPWD, $sending_server->api_token . ':');
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            $response = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $get_response = json_decode($response, true);

                                if (isset($get_response) && is_array($get_response)) {
                                    if (array_key_exists('ids', $get_response)) {
                                        $get_sms_status = 'Delivered|' . $get_response['ids'][0];
                                    } else {
                                        $get_sms_status = $get_response['message'];
                                    }
                                } else {
                                    $get_sms_status = (string) $response;
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $exception) {
                            $get_sms_status = $exception->getMessage();
                        }
                        break;

                    case 'CamooCM':

                        $parameters = [
                            'api_key'    => $sending_server->api_key,
                            'api_secret' => $sending_server->api_secret,
                            'from'       => $data['sender_id'],
                            'to'         => $phone,
                            'message'    => $message,
                        ];

                        $parameters  = http_build_query($parameters);
                        $gateway_url = $gateway_url . '?' . $parameters;

                        $ch = curl_init();

                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_HTTPGET, 1);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                        $get_data = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status = curl_error($ch);
                        } else {
                            $get_response = json_decode($get_data, true);
                            if (is_array($get_response) && array_key_exists('_message', $get_response)) {
                                if ($get_response['_message'] == 'succes') {
                                    $get_sms_status = 'Delivered';
                                } else {
                                    $get_sms_status = $get_response['_message'];
                                }
                            } else {
                                $get_sms_status = (string) $get_data;
                            }
                        }

                        curl_close($ch);
                        break;

                    case 'SemySMS':

                        $parameters = [
                            'phone'  => $phone,
                            'msg'    => $message,
                            'device' => $sending_server->device_id,
                            'token'  => $sending_server->api_token,
                        ];

                        try {
                            $ch = curl_init($gateway_url);
                            curl_setopt($ch, CURLOPT_POST, true);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            $response = curl_exec($ch);
                            curl_close($ch);

                            $response = json_decode($response, true);

                            if (is_array($response) && array_key_exists('code', $response)) {
                                if ($response['code'] == 0) {
                                    $get_sms_status = 'Delivered';
                                } else {
                                    $get_sms_status = $response['error'];
                                }
                            } else {
                                $get_sms_status = 'SMS Gateway provides empty response';
                            }
                        } catch (Exception $ex) {
                            $get_sms_status = $ex->getMessage();
                        }
                        break;

                    case 'BurstSMS':
                        $parameters = [
                            'to'      => $phone,
                            'message' => $message,
                        ];

                        if (isset($data['sender_id'])) {
                            $parameters['from'] = $data['sender_id'];
                        }

                        $parameters  = http_build_query($parameters);
                        $gateway_url = $gateway_url . '?' . $parameters;

                        try {
                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

                            $headers   = [];
                            $headers[] = 'Authorization: Basic ' . base64_encode("$sending_server->api_key:$sending_server->api_secret");
                            $headers[] = 'Content-Type: application/json';
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                            $result = curl_exec($ch);
                            curl_close($ch);

                            $result = json_decode($result, true);

                            if (is_array($result) && array_key_exists('error', $result) && array_key_exists('code', $result['error'])) {
                                if ($result['error']['code'] == 'SUCCESS') {
                                    $get_sms_status = 'Delivered|' . $result['message_id'];
                                } else {
                                    $get_sms_status = $result['error']['description'];
                                }
                            } else {
                                $get_sms_status = (string) $result;
                            }

                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }

                        break;

                    case 'Inteliquent':

                        $parameters = [
                            'from' => $data['sender_id'],
                            'text' => $message,
                            'to'   => [$phone],
                        ];

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'Authorization: Bearer ' . $sending_server->api_token,
                            'Content-Type: application/json',
                        ]);
                        $result = curl_exec($ch);
                        curl_close($ch);

                        $result = json_decode($result, true);

                        if (is_array($result) && array_key_exists('success', $result) && array_key_exists('detail', $result)) {
                            if ($result['success'] == 1) {
                                $get_sms_status  = 'Delivered|' . $result['result']['referenceId'];
                                $customer_status = 'Delivered';
                            } else {
                                $get_sms_status = $result['detail'];
                            }
                        } else {
                            $get_sms_status = (string) $result;
                        }
                        break;

                    case 'VisionUp':

                        $parameters = [
                            'message' => $message,
                            'phone'   => $phone,
                        ];

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'Authorization: Basic ' . base64_encode("$sending_server->username:$sending_server->password"),
                            'Content-Type: application/json',
                        ]);
                        $result = curl_exec($ch);
                        curl_close($ch);

                        $get_result = json_decode($result, true);

                        if (is_array($get_result)) {
                            if (array_key_exists('id', $get_result)) {
                                $get_sms_status = 'Delivered|' . $get_result['id'];
                            } else if (array_key_exists('message', $get_result)) {
                                $get_sms_status = $get_result['message'];
                            } else {
                                $get_sms_status = (string) $result;
                            }
                        } else {
                            $get_sms_status = (string) $result;
                        }
                        break;

                    case 'FHMCloud':

                        $parameters = [
                            'message'   => $message,
                            'recipient' => $phone,
                            'type'      => 'plain',
                        ];

                        if (isset($data['sender_id'])) {
                            $parameters['sender_id'] = $data['sender_id'];
                        }

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'Authorization: Bearer ' . $sending_server->api_key,
                            'Content-Type: application/json',
                        ]);
                        $result = curl_exec($ch);
                        if (curl_errno($ch)) {
                            $get_sms_status = curl_error($ch);
                        } else {
                            $get_result = json_decode($result, true);

                            if (is_array($get_result) && array_key_exists('status', $get_result)) {
                                if ($get_result['status'] == 'success') {
                                    $get_sms_status = 'Delivered';
                                } else if ($get_result['status'] == 'error') {
                                    $get_sms_status = $get_result['message'];
                                } else {
                                    $get_sms_status = $get_result['status'];
                                }
                            } else {
                                $get_sms_status = (string) $result;
                            }
                        }

                        curl_close($ch);
                        break;

                    case 'SMSTO':

                        $parameters = [
                            'message' => $message,
                            'to'      => $phone,
                        ];

                        if (isset($data['sender_id'])) {
                            $parameters['sender_id'] = $data['sender_id'];
                        }

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'Authorization: Bearer ' . $sending_server->api_key,
                            'Content-Type: application/json',
                            'Accept: application/json',
                        ]);
                        $result = curl_exec($ch);
                        curl_close($ch);

                        $get_result = json_decode($result, true);

                        if (is_array($get_result) && array_key_exists('success', $get_result)) {
                            if ($get_result['success']) {
                                $get_sms_status = 'Delivered|' . $get_result['message_id'];
                            } else {
                                $get_sms_status = $get_result['message'];
                            }
                        } else {
                            $get_sms_status = (string) $result;
                        }
                        break;

                    case 'TextBelt':

                        $parameters = [
                            'key'             => $sending_server->api_key,
                            'phone'           => $phone,
                            'message'         => $message,
                            'replyWebhookUrl' => route('inbound.textbelt'),
                        ];

                        try {
                            $curl = curl_init();
                            curl_setopt($curl, CURLOPT_URL, $gateway_url);
                            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
                            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($parameters));
                            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
                            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
                            $result = curl_exec($curl);
                            curl_close($curl);

                            $response = json_decode($result, true);

                            if ($response && is_array($response) && array_key_exists('success', $response)) {
                                if ($response['success']) {
                                    $get_sms_status = 'Delivered|' . $response['textId'];
                                } else {
                                    $get_sms_status = $response['error'];
                                }
                            } else {
                                $get_sms_status = (string) $result;
                            }
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case 'IntelTele':

                        $parameters = [
                            'username' => $sending_server->username,
                            'api_key'  => $sending_server->api_key,
                            'from'     => $data['sender_id'],
                            'to'       => $phone,
                            'message'  => $message,
                        ];

                        $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                        try {
                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

                            $result = curl_exec($ch);
                            curl_close($ch);

                            $result = json_decode($result, true);

                            if (is_array($result) && array_key_exists('reply', $result) && array_key_exists('0', $result['reply']) && array_key_exists('status', $result['reply'][0])) {
                                if ($result['reply'][0]['status'] == 'OK') {
                                    $get_sms_status = 'Delivered';
                                } else {
                                    $get_sms_status = $result['reply'][0]['status'];
                                }
                            } else if (is_array($result) && array_key_exists('message', $result)) {
                                $get_sms_status = $result['message'];
                            } else {
                                $get_sms_status = (string) $result;
                            }

                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case 'GatewaySa':
                    case SendingServer::TYPE_DIGINTRA:
                        $parameters = [
                            'ApiKey'        => $sending_server->api_key,
                            'ClientId'      => $sending_server->c1,
                            'SenderId'      => $data['sender_id'],
                            'MobileNumbers' => $phone,
                            'Message'       => $message,
                        ];

                        if ($sms_type == 'unicode') {
                            $parameters['Is_Unicode'] = true;
                        }

                        $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                        try {
                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

                            $headers   = [];
                            $headers[] = 'Content-Type: application/json';
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                            $response = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $result = json_decode($response, true);
                                if (is_array($result) && array_key_exists('ErrorCode', $result) && array_key_exists('ErrorDescription', $result)) {
                                    if ($result['ErrorCode'] == 0 && array_key_exists('Data', $result)) {
                                        if ($result['Data'][0]['MessageErrorCode'] == 0) {
                                            $get_sms_status = 'Delivered|' . $result['Data'][0]['MessageId'];
                                        } else {
                                            $get_sms_status = $result['Data'][0]['MessageErrorDescription'];
                                        }
                                    } else {
                                        $get_sms_status = $result['ErrorDescription'];
                                    }
                                } else {
                                    $get_sms_status = (string) $response;
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case 'OnBuka':

                        $time = time();
                        $sign = md5($sending_server->api_key . $sending_server->api_secret . $time);

                        $parameters = [
                            'appId'   => $sending_server->application_id,
                            'numbers' => $phone,
                            'content' => $message,
                        ];

                        if (isset($data['sender_id'])) {
                            $parameters['senderID'] = $data['sender_id'];
                        }

                        $gateway_url = $gateway_url . '?' . http_build_query($parameters);
                        $headers     = [];
                        $headers[]   = "Sign: $sign";
                        $headers[]   = "Timestamp: $time";
                        $headers[]   = "Api-Key: $sending_server->api_key";
                        $headers[]   = 'Content-Type: application/json;charset=UTF-8';

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

                            $get_sms_status = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {

                                $get_data = json_decode($get_sms_status, true);

                                if (is_array($get_data) && array_key_exists('status', $get_data)) {
                                    switch ($get_data['status']) {
                                        case '0':
                                            $get_sms_status = 'Delivered';
                                            break;

                                        case '-1':
                                            $get_sms_status = 'authentication error';
                                            break;

                                        case '-2':
                                            $get_sms_status = 'IP access limited';
                                            break;

                                        case '-3':
                                            $get_sms_status = 'SMS contents with sensitive characters';
                                            break;

                                        case '-4':
                                            $get_sms_status = 'SMS content is empty';
                                            break;

                                        case '-5':
                                            $get_sms_status = 'SMS content is over the length';
                                            break;

                                        case '-6':
                                            $get_sms_status = 'SMS contents are out of template';
                                            break;

                                        case '-7':
                                            $get_sms_status = 'numbers are over the limitation';
                                            break;

                                        case '-8':
                                            $get_sms_status = 'number is empty';
                                            break;

                                        case '-9':
                                            $get_sms_status = 'numbers are abnormal';
                                            break;

                                        case '-10':
                                            $get_sms_status = 'insufficient balance which unable to support the task';
                                            break;

                                        case '-11':
                                            $get_sms_status = 'incorrect timing format';
                                            break;

                                        case '-12':
                                            $get_sms_status = 'due to platform issue，bulk submission has been failed,please contact Admin';
                                            break;

                                        case '-13':
                                            $get_sms_status = 'users has been locked';
                                            break;

                                        case '-14':
                                            $get_sms_status = 'Field is empty or inquiry id is abnormal';
                                            break;

                                        case '-15':
                                            $get_sms_status = 'query too frequently';
                                            break;

                                        case '-16':
                                            $get_sms_status = 'timestamp expires';
                                            break;

                                        case '-17':
                                            $get_sms_status = 'SMS template can not be empty';
                                            break;

                                        case '-18':
                                            $get_sms_status = 'port program unusual';
                                            break;

                                        case '-19':
                                            $get_sms_status = 'Please contact the sales people to bind the route';
                                            break;
                                    }
                                }

                            }
                            curl_close($ch);
                        } catch (Exception $exception) {
                            $get_sms_status = $exception->getMessage();
                        }
                        break;

                    case 'BulkGate':

                        $parameters = [
                            'application_id'    => $sending_server->application_id,
                            'application_token' => $sending_server->api_token,
                            'number'            => $phone,
                            'text'              => $message,
                        ];

                        if (isset($data['sender_id'])) {
                            $parameters['sender_id']       = 'gText';
                            $parameters['sender_id_value'] = $data['sender_id'];
                        }

                        if ($sms_type == 'unicode') {
                            $parameters['unicode'] = true;
                        } else {
                            $parameters['unicode'] = false;
                        }

                        try {

                            $curl = curl_init();

                            curl_setopt_array($curl, [
                                CURLOPT_URL            => $gateway_url,
                                CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_SSL_VERIFYPEER => false,
                                CURLOPT_TIMEOUT        => 30,
                                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                                CURLOPT_CUSTOMREQUEST  => 'POST',
                                CURLOPT_POSTFIELDS     => json_encode($parameters),
                                CURLOPT_HTTPHEADER     => [
                                    'Content-Type: application/json',
                                    'Cache-Control: no-cache',
                                ],
                            ]);

                            $response = curl_exec($curl);

                            if ($error = curl_error($curl)) {
                                $get_sms_status = $error;
                            } else {
                                $response = json_decode($response, true);

                                if (isset($response) && is_array($response)) {
                                    if (array_key_exists('data', $response)) {
                                        $get_sms_status = 'Delivered';
                                    } else if (array_key_exists('error', $response)) {
                                        $get_sms_status = $response['error'];
                                    } else {
                                        $get_sms_status = implode(' ', $response);
                                    }
                                } else {
                                    $get_sms_status = $response;
                                }
                            }
                            curl_close($curl);
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case 'SMSVas':

                        $parameters = [
                            'Username'    => $sending_server->username,
                            'Password'    => $sending_server->password,
                            'SMSText'     => $message,
                            'SMSSender'   => $data['sender_id'],
                            'SMSReceiver' => $phone,
                            'SMSID'       => Tool::GUID(),
                            'CampaignID'  => Tool::GUID(),
                            'DLRURL'      => route('dlr.smsvas'),
                        ];

                        if ($sms_type == 'unicode') {
                            $parameters['SMSLang'] = 'A';
                        } else {
                            $parameters['SMSLang'] = 'E';
                        }

                        try {
                            $ch = curl_init();

                            $headers   = [];
                            $headers[] = 'Content-Type: application/json';

                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

                            $result = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $get_sms_status = match ($result) {
                                    '0' => 'Delivered',
                                    '-1' => 'Invalid Credentials',
                                    '-2' => 'Invalid Account IP',
                                    '-3' => 'Invalid ANI Black List',
                                    '-5' => 'Out Of Credit',
                                    '-6' => 'Database Down',
                                    '-7' => 'Inactive Account',
                                    '-11' => 'Account Is Expired',
                                    '-12' => 'SMS is Empty',
                                    '-13' => 'Invalid Sender With Connection',
                                    '-14' => 'SMS Sending Failed Try Again',
                                    '-100' => 'Other Error',
                                    '-16' => 'User Can Not Send With DLR',
                                    '-18' => 'Invalid ANI',
                                    '-19' => 'SMS ID is Exist',
                                    default => 'Failed',
                                };
                            }

                            curl_close($ch);

                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case 'IconGlobalCoUK':
                    case SendingServer::TYPE_TECHCORE:
                        $parameters = [
                            'username'    => $sending_server->username,
                            'apiId'       => $sending_server->application_id,
                            'source'      => $data['sender_id'],
                            'destination' => $phone,
                            'text'        => $message,
                            'json'        => 'true',
                        ];

                        $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                        try {
                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                                'X-Access-Token: ' . $sending_server->access_token,
                            ]);

                            $response = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $result = json_decode($response, true);
                                if (is_array($result) && array_key_exists('ErrorCode', $result) && array_key_exists('Description', $result)) {
                                    if ($result['ErrorCode'] == 0) {
                                        $get_sms_status = 'Delivered';
                                    } else {
                                        $get_sms_status = $result['Description'];
                                    }
                                } else {
                                    $get_sms_status = (string) $response;
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case 'SendPulse':
                        try {

                            $upload_path = storage_path('SendPulse/');

                            if ( ! file_exists($upload_path)) {
                                mkdir($upload_path, 0777, true);
                            }

                            $SPApiClient = new ApiClient($sending_server->c1, $sending_server->api_secret, new FileStorage($upload_path));

                            $parameters = [
                                'sender'        => $data['sender_id'],
                                'body'          => $message,
                                'transliterate' => 0,
                            ];

                            $get_data = $SPApiClient->sendSmsByList([$phone], $parameters, []);

                            if (isset($get_data->result) && $get_data->result == 1) {
                                $get_sms_status = 'Delivered';
                            } else if (isset($get_data->is_error) && $get_data->is_error) {
                                $get_sms_status = $get_data->message;
                            } else {
                                $get_sms_status = $get_data;
                            }

                        } catch (Exception $exception) {
                            $get_sms_status = $exception->getMessage();
                        }
                        break;

                    case 'SpewHub':
                        $parameters = [
                            'text'    => $message,
                            'numbers' => [$phone],
                        ];

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'X-RGM-KEY:' . $sending_server->c1,
                            'Content-Type: application/json',
                            'Accept: application/json',
                        ]);
                        $result = curl_exec($ch);
                        curl_close($ch);

                        $get_result = json_decode($result, true);

                        if (is_array($get_result) && array_key_exists('success', $get_result)) {
                            if ($get_result['success']) {
                                $get_sms_status = 'Delivered';
                            } else {
                                $get_sms_status = $get_result['message'];
                            }
                        } else if (is_array($get_result) && array_key_exists('errors', $get_result)) {
                            $get_sms_status = $get_result['message'];
                        } else {
                            $get_sms_status = $result;
                        }
                        break;

                    case 'CCSSMS':
                        $parameters = [
                            'username'        => $sending_server->username,
                            'password'        => $sending_server->password,
                            'dnis'            => $phone,
                            'ani'             => $data['sender_id'],
                            'message'         => $message,
                            'command'         => 'submit',
                            'longMessageMode' => 'split',
                        ];

                        if ($sms_type == 'unicode') {
                            $parameters['dataCoding'] = 1;
                        } else {
                            $parameters['dataCoding'] = 0;
                        }

                        $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                        try {
                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            $response = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $get_data = json_decode($response, true);

                                if (isset($get_data) && is_array($get_data)) {
                                    if (array_key_exists('message_id', $get_data)) {
                                        $get_sms_status = 'Delivered';
                                    } else {
                                        $get_sms_status = implode(' ', $get_data);
                                    }
                                } else {
                                    $get_sms_status = 'Enable your port number for outgoing and incoming from your firewall';
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case 'TeleSign':
                        $parameters = [
                            'phone_number' => $phone,
                            'message'      => $message,
                            'message_type' => 'ARN',
                        ];

                        $headers = [
                            'Content-Type: application/x-www-form-urlencoded',
                            'Authorization: Basic ' . base64_encode("$sending_server->c1:$sending_server->api_key"),
                        ];

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameters));
                        $get_data = curl_exec($ch);

                        if (curl_error($ch)) {
                            $get_sms_status = curl_error($ch);
                        } else {

                            $get_status = json_decode($get_data, true);

                            if (is_array($get_status) && array_key_exists('status', $get_status)) {
                                if (is_array($get_status['status']) && array_key_exists('description', $get_status['status']) && array_key_exists('code', $get_status['status'])) {
                                    if ($get_status['status']['code'] == '290') {
                                        $get_sms_status = 'Delivered';
                                    } else {
                                        $get_sms_status = $get_status['status']['description'];
                                    }
                                } else {
                                    $get_sms_status = 'Invalid request';
                                }
                            } else {
                                $get_sms_status = 'Unknown error';
                            }

                        }
                        curl_close($ch);
                        break;

                    case 'ClearComMX':
                        $parameters = [
                            'auth'  => $sending_server->api_token,
                            'phone' => substr($phone, -10),
                            'msg'   => $message,
                        ];

                        if (isset($data['sender_id'])) {
                            $parameters['sender'] = $data['sender_id'];
                        }

                        $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                        try {
                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            $response = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $get_data = json_decode($response, true);

                                if (isset($get_data) && is_array($get_data) && array_key_exists('status', $get_data)) {
                                    if ($get_data['status']) {
                                        $get_sms_status = 'Delivered';
                                    } else {
                                        $get_sms_status = $get_data['description'];
                                    }
                                } else {
                                    $get_sms_status = implode(' ', $get_data);
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case 'CyberGateLK':

                        $parameters = [
                            'from_number'   => $data['sender_id'],
                            'to_number'     => '+' . $phone,
                            'message'       => $message,
                            'delivery'      => 1,
                            'user_auth_key' => $sending_server->auth_key,
                        ];

                        $headers = [
                            'Content-Type:application/json',
                            'Authorization: Bearer ' . $sending_server->api_token,
                        ];

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            $response = curl_exec($ch);
                            curl_close($ch);

                            $get_response = json_decode($response, true);

                            if (is_array($get_response) && array_key_exists('status', $get_response)) {
                                if ($get_response['status'] == 'error') {
                                    $get_sms_status = $get_response['message'];
                                } else {
                                    $get_sms_status = 'Delivered';
                                }
                            } else {
                                $get_sms_status = implode(' ', $get_response);
                            }
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case 'LuxSMS':
                        $parameters = [
                            'api_id'       => $sending_server->c1,
                            'api_password' => $sending_server->password,
                            'sms_type'     => $sending_server->sms_type,
                            'sender_id'    => $data['sender_id'],
                            'phonenumber'  => $phone,
                            'textmessage ' => $message,
                        ];

                        if ($sms_type == 'unicode') {
                            $parameters['encoding'] = 'U';
                        } else {
                            $parameters['encoding'] = 'T';
                        }

                        $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                        try {
                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            $response = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $get_data = json_decode($response, true);

                                if (isset($get_data) && is_array($get_data) && array_key_exists('status', $get_data)) {
                                    if ($get_data['status'] == 'S') {
                                        $get_sms_status = 'Delivered';
                                    } else {
                                        $get_sms_status = $get_data['remarks'];
                                    }
                                } else {
                                    $get_sms_status = implode(' ', $get_data);
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_SAFARICOM:

                        $auth_url = rtrim($gateway_url, '/') . '/auth/login';

                        $login_data = [
                            'username' => $sending_server->username,
                            'password' => $sending_server->password,
                        ];

                        $ch = curl_init();

                        curl_setopt_array($ch, [
                            CURLOPT_URL            => $auth_url,
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_ENCODING       => '',
                            CURLOPT_MAXREDIRS      => 10,
                            CURLOPT_TIMEOUT        => 0,
                            CURLOPT_FOLLOWLOCATION => true,
                            CURLOPT_SSL_VERIFYHOST => false,
                            CURLOPT_SSL_VERIFYPEER => false,
                            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                            CURLOPT_CUSTOMREQUEST  => 'POST',
                            CURLOPT_POSTFIELDS     => json_encode($login_data),
                            CURLOPT_HTTPHEADER     => [
                                'Content-Type: application/json',
                                'X-Requested-With: XMLHttpRequest',
                            ],
                        ]);

                        $response = curl_exec($ch);
                        $err      = curl_error($ch);

                        curl_close($ch);

                        if ($err) {
                            $get_sms_status = $err;
                        } else {
                            $response = json_decode($response, true);

                            if (isset($response) && is_array($response) && array_key_exists('token', $response)) {
                                $access_token = $response['token'];
                                $send_sms_url = rtrim($gateway_url, '/') . '/public/CMS/bulksms';

                                $headers   = [];
                                $headers[] = "X-Authorization: Bearer $access_token";
                                $headers[] = 'Content-Type: application/json';
                                $headers[] = 'X-Requested-With: XMLHttpRequest';
                                $headers[] = 'Accept: application/json';

                                $parameters = [
                                    'timeStamp' => date('Ymd'),
                                    'dataSet'   => [
                                        [
                                            'userName'  => $sending_server->c1,
                                            'channel'   => 'sms',
                                            'packageId' => $sending_server->project_id,
                                            'oa'        => $data['sender_id'],
                                            'msisdn'    => $phone,
                                            'message'   => $message,
                                            'uniqueId'  => date('YmdHms'),
                                        ],
                                    ],
                                ];

                                $ch = curl_init($send_sms_url);
                                curl_setopt($ch, CURLOPT_POST, 1);
                                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                                $result = curl_exec($ch);
                                $err    = curl_error($ch);

                                curl_close($ch);

                                if ($err) {
                                    $get_sms_status = $err;
                                } else {

                                    $get_data = json_decode($result, true);

                                    if (isset($get_data) && is_array($get_data) && array_key_exists('status', $get_data)) {
                                        if ($get_data['status'] == 'SUCCESS' || $get_data['status'] == '200') {
                                            $get_sms_status = 'Delivered';
                                        } else {
                                            $get_sms_status = $get_data['message'];
                                        }
                                    } else {
                                        $get_sms_status = (string) $result;
                                    }
                                }
                            } else {
                                $get_sms_status = $response['msg'];
                            }
                        }
                        break;

                    case 'SMSCrab':

                        $parameters = [
                            'sender_id' => $data['sender_id'],
                            'recipient' => $phone,
                            'type'      => 'plain',
                            'message'   => $message,
                        ];

                        $headers = [
                            'Content-Type:application/json',
                            'Accept: application/json',
                            'Authorization: Bearer ' . $sending_server->api_token,
                        ];

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            $response = curl_exec($ch);
                            curl_close($ch);

                            $get_response = json_decode($response, true);

                            if (is_array($get_response) && array_key_exists('status', $get_response)) {
                                if ($get_response['status'] == 'success') {
                                    $get_sms_status = 'Delivered';
                                } else {
                                    $get_sms_status = $get_response['message'];
                                }
                            } else {
                                $get_sms_status = implode(' ', $get_response);
                            }
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_FACILITAMOVEL:

                        $parameters = [
                            'user'         => $sending_server->username,
                            'password'     => $sending_server->password,
                            'destinatario' => $phone,
                            'msg'          => $message,
                        ];

                        if (isset($data['sender_id'])) {
                            $parameters['externalkey'] = $data['sender_id'];
                        }

                        $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                        try {
                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            $response = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $get_data = explode(';', trim($response));

                                if (isset($get_data) && is_array($get_data) && array_key_exists('1', $get_data)) {
                                    if (is_numeric($get_data['1'])) {
                                        $get_sms_status = 'Delivered';
                                    } else {
                                        $get_sms_status = $get_data['1'];
                                    }
                                } else {
                                    $get_sms_status = $response;
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_SMSDELIVERER:

                        $parameters = [
                            'username' => $sending_server->username,
                            'password' => $sending_server->password,
                            'to'       => $phone,
                            'message'  => $message,
                        ];

                        $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                        try {
                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            $get_sms_status = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                if (str_contains('-', $get_sms_status)) {
                                    $get_sms_status = 'Delivered';
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_ROUNDSMS:

                        $parameters = [
                            'authkey' => $sending_server->auth_key,
                            'route'   => $sending_server->route,
                            'mobiles' => $phone,
                            'sander'  => $data['sender_id'],
                            'message' => $message,
                        ];

                        if ($sms_type == 'unicode') {
                            $parameters['type'] = '2';
                        } else {
                            $parameters['type'] = '1';
                        }

                        $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                        try {
                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            $response = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $get_response = json_decode($response, true);

                                if (is_array($get_response) && array_key_exists('error', $get_response) && array_key_exists('msg_id', $get_response)) {
                                    if ($get_response['msg_id'] != null) {
                                        $get_sms_status = 'Delivered|' . $get_response['msg_id'];
                                    } else {
                                        $get_sms_status = $get_response['error'];
                                    }
                                } else {
                                    $get_sms_status = implode(' ', $get_response);
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_YOSMS:

                        $parameters = [
                            'ybsacctno'    => $sending_server->username,
                            'password'     => $sending_server->password,
                            'origin'       => '6969',
                            'destinations' => $phone,
                            'sms_content'  => $message,
                        ];

                        $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                        try {
                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            $response = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                parse_str($response, $result);

                                if (isset($result['ybs_autocreate_status']) && ($result['ybs_autocreate_status'] == 'OK')) {
                                    $get_sms_status = 'Delivered';
                                } else {
                                    $get_sms_status = $result['ybs_autocreate_message'];
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_ALLMYSMS:

                        $parameters = [
                            'to'   => $phone,
                            'text' => $message,
                        ];

                        if (isset($data['sender_id'])) {
                            $parameters['from'] = $data['sender_id'];
                        }

                        $headers = [
                            'Content-Type:application/json',
                            'cache-control: no-cache',
                            'Authorization: Basic ' . $sending_server->auth_key,
                        ];

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            $response = curl_exec($ch);
                            curl_close($ch);

                            $get_response = json_decode($response, true);

                            if (is_array($get_response) && array_key_exists('code', $get_response) && array_key_exists('description', $get_response)) {
                                if ($get_response['code'] == '100') {
                                    $get_sms_status = 'Delivered';
                                } else {
                                    $get_sms_status = $get_response['description'];
                                }
                            } else {
                                $get_sms_status = implode(' ', $get_response);
                            }
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_ESOLUTIONS:

                        $parameters = [
                            'originator'  => $data['sender_id'],
                            'destination' => $phone,
                            'messageText' => $message,
                        ];

                        try {
                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_USERPWD, "$sending_server->username" . ':' . "$sending_server->password");

                            $headers   = [];
                            $headers[] = 'Content-Type: application/json';
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                            $result = curl_exec($ch);
                            curl_close($ch);

                            $result = json_decode($result, true);

                            if (is_array($result) && array_key_exists('status', $result)) {
                                if ($result['status'] == 'PENDING' || $result['status'] == 'SENT' || $result['status'] == 'DELIVER') {
                                    $get_sms_status = 'Delivered|' . $result['messageId'];
                                } else {
                                    $get_sms_status = $result['status'];
                                }
                            } else {
                                $get_sms_status = 'Invalid request';
                            }

                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_SEMAPHORE:

                        $parameters = [
                            'apikey'  => $sending_server->api_key,
                            'number'  => $phone,
                            'message' => $message,
                        ];

                        if (isset($data['sender_id'])) {
                            $parameters['sendername'] = $data['sender_id'];
                        }

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameters));
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            $response = curl_exec($ch);
                            curl_close($ch);

                            $get_response = json_decode($response, true);

                            if (is_array($get_response) && array_key_exists('0', $get_response) && array_key_exists('status', $get_response['0'])) {
                                if ($get_response[0]['status'] == 'Queued' || $get_response[0]['status'] == 'Pending' || $get_response[0]['status'] == 'Sent') {
                                    $get_sms_status = 'Delivered';
                                } else {
                                    $get_sms_status = $get_response[0]['status'];
                                }
                            } else {
                                $get_sms_status = $response;
                            }
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }

                        break;

                    case SendingServer::TYPE_ESTORESMS:

                        $parameters = [
                            'username'  => $sending_server->username,
                            'password'  => $sending_server->password,
                            'sender'    => $data['sender_id'],
                            'recipient' => $phone,
                            'message'   => $message,
                        ];

                        $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                        try {
                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            $response = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {

                                if (str_contains(trim($response), 'OK')) {
                                    $get_sms_status = 'Delivered';
                                } else {
                                    $get_sms_status = match (trim($response)) {
                                        '-2904' => 'SMS sending failed',
                                        '-2905' => 'Invalid username/password combination',
                                        '-2906' => 'Credit exhausted',
                                        '-2907' => 'Gateway unavailable',
                                        '-2908' => 'Invalid schedule date format',
                                        '-2909' => 'Unable to schedule',
                                        '-2910' => 'Username is empty',
                                        '-2911' => 'Password is empty',
                                        '-2912' => 'Recipient is empty',
                                        '-2913' => 'Message is empty',
                                        '-2914' => 'Sender is empty',
                                        '-2915' => 'One or more required fields are empty',
                                        '-2916' => 'Blocked message content',
                                        '-2917' => 'Blocked sender ID',
                                        default => 'Failed',
                                    };
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }

                        break;

                    case SendingServer::TYPE_GOIP:

                        $context = stream_context_create([
                            'http' => [
                                'header' => 'Authorization: Basic ' . base64_encode("$sending_server->username:$sending_server->password"),
                            ],
                        ]);

                        $message = urlencode($message);

                        try {
                            $get_sms_status = file_get_contents($gateway_url . "?u=$sending_server->username&p=$sending_server->password&l=1&n=$phone&m=$message",
                                false, $context);

                            if (str_contains($get_sms_status, 'Sending')) {
                                $get_sms_status = 'Delivered';
                            }
                        } catch (Exception $ex) {
                            $get_sms_status = $ex->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_MAILJET:

                        $parameters = [
                            'From' => $data['sender_id'],
                            'To'   => '+' . $phone,
                            'Text' => $message,
                        ];

                        $headers = [
                            'Content-Type:application/json',
                            'Accept: application/json',
                            'Authorization: Bearer ' . $sending_server->api_token,
                        ];

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            $response = curl_exec($ch);
                            curl_close($ch);

                            $get_response = json_decode($response, true);

                            if (is_array($get_response)) {
                                if (array_key_exists('ErrorMessage', $get_response)) {
                                    $get_sms_status = $get_response['ErrorMessage'];
                                } else {
                                    $get_sms_status = 'Delivered';
                                }
                            } else {
                                $get_sms_status = implode(' ', $get_response);
                            }
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }

                        break;

                    case SendingServer::TYPE_ADVANCEMSGSYS:

                        $type = '3';
                        $dcs  = '0';
                        $esm  = '0';

                        $countch   = strlen($message);
                        $arabiclen = $this->strlen_utf8($message);
                        $fixarabic = $countch - $arabiclen;

                        if ($fixarabic != 0) {
                            $type           = '2';
                            $dcs            = '8';
                            $convertMessage = $this->utf16urlencode($message);
                        } else {
                            $convertMessage = $message;
                        }

                        if ($countch > 160 and $fixarabic == 0) {
                            $esm  = '64';
                            $type = '3';
                        }

                        if ($fixarabic != 0 and $countch > 280) {
                            $esm = '64';
                        }

                        $parameters = [
                            'user'       => $sending_server->username,
                            'pass'       => $sending_server->password,
                            'mno'        => $phone,
                            'type'       => $type,
                            'dcs'        => $dcs,
                            'text'       => $convertMessage,
                            'sid'        => $data['sender_id'],
                            'esm'        => $esm,
                            'respformat' => 'json',
                        ];

                        $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                        try {
                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            $headers   = [];
                            $headers[] = 'Authorization: Basic ' . base64_encode("$sending_server->username:$sending_server->password");
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                            $response = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $get_response = json_decode($response, true);

                                if (is_array($get_response) && array_key_exists('Response', $get_response)) {

                                    if (str_contains($get_response['Response']['0'], 'ERROR')) {
                                        $get_sms_status = $get_response['Response']['0'];
                                    } else {
                                        $get_sms_status = 'Delivered|' . $get_response['Response']['0'];
                                    }

                                } else {
                                    $get_sms_status = $response;
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }

                        break;

                    case SendingServer::TYPE_UIPAPP:

                        $parameters = [
                            'user_token' => $sending_server->user_token,
                            'origin'     => $data['sender_id'],
                            'numbers'    => ['+' . $phone],
                            'message'    => $message,
                        ];

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 50);
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_HEADER, 0);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            $response = curl_exec($ch);
                            curl_close($ch);

                            $get_response = json_decode($response, true);

                            if (is_array($get_response) && array_key_exists('status', $get_response)) {
                                if ($get_response ['status'] == 'error') {
                                    $get_sms_status  = $get_response['message'];
                                    $customer_status = __('locale.labels.failed');
                                } else {
                                    $get_sms_status = $customer_status = 'Delivered';
                                }
                            } else {
                                $get_sms_status = $response;
                            }

                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }

                        break;

                    case SendingServer::TYPE_SMSFRL:

                        $parameters = [
                            'receipts' => $phone,
                            'text'     => $message,
                        ];

                        $headers = [
                            'Content-Type:application/json',
                            'Accept: application/json',
                            'Authorization: Bearer ' . $sending_server->api_token,
                        ];

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            $response = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $get_response = json_decode($response, true);

                                if (is_array($get_response)) {
                                    if (array_key_exists('status', $get_response) && array_key_exists('id', $get_response)) {
                                        $get_sms_status = 'Delivered|' . $get_response['id'];
                                    } else if (array_key_exists('error', $get_response)) {
                                        $get_sms_status = $get_response['error'];
                                    } else {
                                        $get_sms_status = implode(' ', $get_response);
                                    }
                                } else {
                                    $get_sms_status = 'Invalid request';
                                }
                            }

                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_IMARTGROUP:

                        $parameters = [
                            'key'      => $sending_server->api_key,
                            'contacts' => $phone,
                            'senderid' => $data['sender_id'],
                            'msg'      => $message,
                            'type'     => 'text',
                            'routeid'  => $sending_server->route,
                            'campaign' => $sending_server->c1,
                        ];

                        try {
                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameters));
                            $response = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status  = curl_error($ch);
                                $customer_status = __('locale.labels.failed');
                            } else {

                                if (str_contains($response, 'SMS-SHOOT-ID')) {
                                    $parts           = explode('/', $response);
                                    $shootId         = end($parts);
                                    $get_sms_status  = 'Delivered|' . $shootId;
                                    $customer_status = 'Delivered';
                                } else {
                                    $get_sms_status  = $response;
                                    $customer_status = 'Failed';
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status = $customer_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_GOSMSFUN:

                        $parameters = [
                            'phone'   => $phone,
                            'sender'  => $data['sender_id'],
                            'message' => $message,
                        ];

                        $headers = [
                            'Content-Type:application/json',
                            'Accept: application/json',
                            'Token: ' . $sending_server->api_token,
                        ];

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            $response = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $get_response = json_decode($response, true);

                                if (is_array($get_response)) {
                                    if (array_key_exists('code', $get_response[0]) && array_key_exists('return', $get_response[0])) {
                                        if ($get_response[0]['code'] == '200') {
                                            $get_sms_status = 'Delivered';
                                        } else {
                                            $get_sms_status = $get_response[0]['return'];
                                        }
                                    }
                                } else {
                                    $get_sms_status = 'Invalid request';
                                }
                            }

                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_TEXT_CALIBUR:

                        $parameters = [
                            'to'   => '+' . $phone,
                            'from' => $data['sender_id'],
                            'body' => $message,
                        ];

                        $headers = [
                            'Authorization: Basic ' . $sending_server->api_key,
                        ];

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            $response = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $get_response = json_decode($response, true);

                                if (is_array($get_response)) {
                                    if (array_key_exists('status', $get_response) && array_key_exists('sid', $get_response)) {
                                        $get_sms_status = 'Delivered';
                                    } else if (array_key_exists('error', $get_response)) {
                                        $get_sms_status = $get_response['error'];
                                    } else {
                                        $get_sms_status = implode(' ', $get_response);
                                    }
                                } else {
                                    $get_sms_status = 'Invalid request';
                                }
                            }

                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_ARKESEL:

                        $parameters = [
                            'action'       => 'send-sms',
                            'api_key'      => $sending_server->api_key,
                            'to'           => $phone,
                            'from'         => $data['sender_id'],
                            'sms'          => $message,
                            'callback_url' => route('dlr.arkesel'),
                        ];

                        $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                        try {
                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            $response = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {

                                $get_response = json_decode($response, true);

                                if (is_array($get_response) && array_key_exists('code', $get_response)) {
                                    if ($get_response['code'] == 'ok') {
                                        $get_sms_status = 'Delivered';
                                    } else {
                                        $get_sms_status = $get_response['message'];
                                    }
                                } else {
                                    $get_sms_status = $response;
                                }
                            }
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_SAVEWEBHOSTNET:

                        $parameters = [
                            'message'   => $message,
                            'sender_id' => $data['sender_id'],
                            'recipient' => $phone,
                            'type'      => 'plain', // possible value: plain, mms, voice, whatsapp, default plain
                        ];

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'Authorization: Bearer ' . $sending_server->api_token,
                            'Content-Type: application/json',
                            'Accept: application/json',
                        ]);

                        $response = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status = curl_error($ch);
                        } else {

                            $get_response = json_decode($response, true);

                            if (is_array($get_response) && array_key_exists('status', $get_response)) {
                                if ($get_response['status'] == 'success') {
                                    $get_sms_status = 'Delivered';
                                } else {
                                    $get_sms_status = $get_response['message'];
                                }
                            } else {
                                $get_sms_status = $response;
                            }
                        }

                        curl_close($ch);

                        break;

                    case SendingServer::TYPE_FAST2SMS:

                        $phone = preg_replace('/^\+?91|\|91|\D/', '', ($phone));

                        $parameters = [
                            'message' => $message,
                            'numbers' => $phone,
                        ];

                        if (isset($data['dlt_template_id'])) {
                            $parameters['template_id'] = $data['dlt_template_id'];
                        }

                        if (isset($data['dlt_entity_id'])) {
                            $parameters['entity_id'] = $data['dlt_entity_id'];
                            $parameters['route']     = 'dlt_manual';
                            $parameters['sender_id'] = $data['sender_id'];
                        } else {
                            $parameters['route'] = 'q';
                        }

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'Authorization: ' . $sending_server->api_key,
                            'Content-Type: application/json',
                            'Accept: application/json',
                        ]);

                        $response = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status = curl_error($ch);
                        } else {

                            $get_response = json_decode($response, true);
                            if (is_array($get_response) && array_key_exists('return', $get_response)) {
                                if ($get_response['return'] === true) {
                                    $get_sms_status = 'Delivered';
                                } else {
                                    $get_sms_status = $get_response['message'];
                                }
                            } else {
                                $get_sms_status = $response;
                            }
                        }

                        curl_close($ch);
                        break;

                    case SendingServer::TYPE_MSG91:

                        $parameters = [
                            'flow_id'   => $sending_server->c1,
                            'short_url' => 1,
                            'mobiles'   => $phone,
                        ];
                        if (isset($data['sender_id'])) {
                            $parameters['sender'] = $data['sender_id'];
                        }

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'authkey: ' . $sending_server->auth_key,
                            'Content-Type: application/json',
                        ]);

                        $response = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status = curl_error($ch);
                        } else {

                            $get_response = json_decode($response, true);
                            if (is_array($get_response) && array_key_exists('type', $get_response)) {
                                if ($get_response['type'] == 'success') {
                                    $get_sms_status = 'Delivered';
                                } else {
                                    $get_sms_status = $get_response['message'];
                                }
                            } else {
                                $get_sms_status = $response;
                            }
                        }

                        curl_close($ch);
                        break;

                    case SendingServer::TYPE_TELEAPI:

                        $parameters = [
                            'source'      => $data['sender_id'],
                            'destination' => $phone,
                            'message'     => $message,
                        ];

                        $gateway_url .= '?token=' . $sending_server->api_token;

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameters));

                        $response = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status = curl_error($ch);
                        } else {

                            $get_response = json_decode($response, true);

                            if (is_array($get_response) && array_key_exists('status', $get_response)) {
                                if ($get_response['status'] == 'success') {
                                    $get_sms_status = 'Delivered';
                                } else {
                                    $get_sms_status = $get_response['data'];
                                }
                            } else {
                                $get_sms_status = $response;
                            }
                        }

                        curl_close($ch);
                        break;

                    case SendingServer::TYPE_BUDGETSMS:

                        $parameters = [
                            'username' => $sending_server->username,
                            'userid'   => $sending_server->c1,
                            'handle'   => $sending_server->c2,
                            'to'       => $phone,
                            'msg'      => $message,
                            'from'     => $data['sender_id'],
                        ];

                        $gateway_url .= '?' . http_build_query($parameters);

                        try {
                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            $response = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $result = explode(' ', $response);

                                if (isset($result) && is_array($result) && count($result) > 0) {

                                    if ($result['0'] == 'OK') {
                                        $get_sms_status = 'Delivered|' . $result['1'];
                                    } else {
                                        $get_sms_status = match ($result['1']) {
                                            '1001' => 'Not enough credits to send messages',
                                            '1002' => 'Identification failed. Wrong credentials',
                                            '1003' => 'Account not active, contact BudgetSMS',
                                            '1004' => 'This IP address is not added to this account. No access to the API',
                                            '1005' => 'No handle provided',
                                            '1006' => 'No UserID provided',
                                            '1007' => 'No Username provided',
                                            '2001' => 'SMS message text is empty',
                                            '2002' => 'SMS numeric senderid can be max. 16 numbers',
                                            '2003' => 'SMS alphanumeric sender can be max. 11 characters',
                                            '2004' => 'SMS senderid is empty or invalid',
                                            '2005' => 'Destination number is too short',
                                            '2006' => 'Destination is not numeric',
                                            '2007' => 'Destination is empty',
                                            '2008' => 'SMS text is not OK',
                                            '2009' => 'Parameter issue',
                                            '2010' => 'Destination number is invalidly formatted',
                                            '2011' => 'Destination is invalid',
                                            '2012' => 'SMS message text is too long',
                                            '2013' => 'SMS message is invalid',
                                            '2014' => 'SMS CustomID is used before',
                                            '2015' => 'Charset problem',
                                            '2016' => 'Invalid UTF-8 encoding',
                                            '2017' => 'Invalid SMSid',
                                            '3001' => 'No route to destination. Contact BudgetSMS for possible solutions',
                                            '3002' => 'No routes are setup. Contact BudgetSMS for a route setup',
                                            '3003' => 'Invalid destination. Check international mobile number formatting',
                                            '4001' => 'System error, related to customID',
                                            '4002' => 'System error, temporary issue. Try resubmitting in 2 to 3 minutes',
                                            '4003' => 'System error, temporary issue',
                                            '4004' => 'System error, temporary issue. Contact BudgetSMS',
                                            '4005' => 'System error, permanent',
                                            '4006' => 'Gateway not reachable',
                                            '4007' => 'System error, contact BudgetSMS',
                                            '5001' => 'Send error, Contact BudgetSMS with the send details',
                                            '5002' => 'Wrong SMS type',
                                            '5003' => 'Wrong operator',
                                            '7001' => 'No HLR provider present, Contact BudgetSMS',
                                            '7002' => 'Unexpected results from HLR provider',
                                            '7003' => 'Bad number format',
                                            '7901' => 'Unexpected error. Contact BudgetSMS',
                                            '7903', '7902' => 'HLR provider error. Contact BudgetSMS',
                                            default => 'Unknown error',
                                        };
                                    }
                                } else {
                                    $get_sms_status = 'Invalid request';
                                }
                            }
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }

                        break;

                    case SendingServer::TYPE_OZONEDESK:

                        $parameters = [
                            'api_key'   => $sending_server->api_key,
                            'user_id'   => $sending_server->c1,
                            'to'        => $phone,
                            'message'   => $message,
                            'sender_id' => $data['sender_id'],
                        ];

                        $gateway_url .= '?' . http_build_query($parameters);

                        try {
                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            $response = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {

                                $get_response = json_decode($response, true);

                                if (is_array($get_response) && array_key_exists('status', $get_response)) {
                                    if ($get_response['status'] == 'success') {
                                        $get_sms_status = 'Delivered';
                                    } else {
                                        $get_sms_status = $get_response['msg'][0];
                                    }
                                } else {
                                    $get_sms_status = $response;
                                }
                            }
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_SKEBBY:

                        $phone      = '+' . $phone;
                        $parameters = [
                            'message_type' => $sending_server->c1,
                            'message'      => $message,
                            'recipient'    => ['+393471234567'],
                        ];

                        if (isset($data['sender_id'])) {
                            $parameters['sender'] = $data['sender_id'];
                        }

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'user_key: ' . $sending_server->api_key,
                            'Access_token: ' . $sending_server->access_token,
                            'Content-Type: application/json',
                        ]);

                        $response = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status = curl_error($ch);
                        } else {

                            $get_response = json_decode($response, true);
                            if (is_array($get_response) && array_key_exists('result', $get_response)) {
                                if ($get_response['result'] == 'OK') {
                                    $get_sms_status = 'Delivered';
                                } else {
                                    $get_sms_status = $get_response['result'];
                                }
                            } else {
                                $get_sms_status = $response;
                            }
                        }

                        curl_close($ch);
                        break;

                    case SendingServer::TYPE_NIMBUZ:

                        $parameters = [
                            'Authorization' => [
                                'User' => $sending_server->username,
                                'Key'  => $sending_server->api_key,
                            ],
                            'Data'          => [
                                'Sender'      => $data['sender_id'],
                                'Message'     => $message,
                                'Flash'       => 0,
                                'ReferenceId' => str_random(10),
                                'Mobile'      => [$phone],
                            ],
                        ];

                        if (isset($data['dlt_template_id'])) {
                            $parameters['Data']['TemplateId'] = $data['dlt_template_id'];
                        }
                        if (isset($data['dlt_entity_id'])) {
                            $parameters['Data']['EntityId'] = $data['dlt_entity_id'];
                        }

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));

                        $response = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status = curl_error($ch);
                        } else {

                            $get_response = json_decode($response, true);

                            if (is_array($get_response) && array_key_exists('Status', $get_response)) {
                                if ($get_response['Status'] == 'OK') {
                                    $get_sms_status = 'Delivered|' . $get_response['Response']['MessageId'];
                                } else {
                                    $get_sms_status = $get_response['Response']['Message'];
                                }
                            } else {
                                $get_sms_status = $response;
                            }
                        }

                        curl_close($ch);
                        break;

                    case SendingServer::TYPE_MOBITECH:

                        $parameters = [
                            'mobile'        => '+' . $phone,
                            'response_type' => 'json',
                            'sender_name'   => $data['sender_id'],
                            'service_id'    => 0,
                            'message'       => $message,
                        ];

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'h_api_key:' . $sending_server->api_key,
                            'Content-Type: application/json',
                        ]);

                        $response = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status = curl_error($ch);
                        } else {

                            $get_response = json_decode($response, true);

                            if (is_array($get_response) && array_key_exists('0', $get_response) && array_key_exists('status_code', $get_response[0]) && array_key_exists('status_desc', $get_response[0])) {
                                if ($get_response[0]['status_code'] == '1000') {
                                    $get_sms_status = 'Delivered';
                                } else {
                                    $get_sms_status = $get_response[0]['status_desc'];
                                }
                            } else {
                                $get_sms_status = $response;
                            }
                        }
                        curl_close($ch);
                        break;

                    case SendingServer::TYPE_HOSTPINNACLE:

                        $parameters = [
                            'userid'         => $sending_server->username,
                            'password'       => $sending_server->password,
                            'output'         => 'json',
                            'senderid'       => $data['sender_id'],
                            'mobile'         => $phone,
                            'duplicatecheck' => 'true',
                            'msgType'        => 'text',
                            'msg'            => $message,
                            'sendMethod'     => 'quick',
                        ];

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameters));
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            "apikey: $sending_server->api_key",
                            'cache-control: no-cache',
                            'content-type: application/x-www-form-urlencoded',
                        ]);

                        $response = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status = curl_error($ch);
                        } else {

                            $get_response = json_decode($response, true);

                            if (is_array($get_response) && array_key_exists('status', $get_response) && array_key_exists('reason', $get_response)) {
                                if ($get_response['status'] == 'success') {
                                    $get_sms_status = 'Delivered';
                                } else {
                                    $get_sms_status = $get_response['reason'];
                                }
                            } else {
                                $get_sms_status = $response;
                            }
                        }
                        curl_close($ch);
                        break;

                    case SendingServer::TYPE_LANKABELL:

                        $parameters = [
                            'phoneNumber' => $phone,
                            'smsMessage'  => $message,
                        ];

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameters));
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            "Authorization: $sending_server->api_key",
                        ]);

                        $response = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status = curl_error($ch);
                        } else {

                            $get_data = json_decode($response, true);

                            if (is_array($get_data) && array_key_exists('Status', $get_data)) {
                                if ($get_data['Status'] == '200') {
                                    $get_sms_status = 'Delivered';
                                } else {
                                    $get_sms_status = $get_data['Data'];
                                }
                            } else {
                                $get_sms_status = 'Failed';
                            }
                        }
                        curl_close($ch);
                        break;

                    case SendingServer::TYPE_ZORRA:
                        $auth_url = rtrim($gateway_url, '/') . '/auth/login';

                        $login_data = [
                            'email'    => $sending_server->username,
                            'password' => $sending_server->password,
                        ];

                        $ch = curl_init();

                        curl_setopt($ch, CURLOPT_URL, $auth_url . '?' . http_build_query($login_data));
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_POST, 1);

                        $headers   = [];
                        $headers[] = 'Accept: application/json';
                        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                        $result = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status = curl_error($ch);
                        } else {
                            $response = json_decode($result, true);
                            if (isset($response) && is_array($response) && array_key_exists('access_token', $response)) {
                                $access_token = $response['access_token'];
                                $send_sms_url = rtrim($gateway_url, '/') . '/v2/mailing/send';

                                $headers   = [];
                                $headers[] = "Authorization: bearer $access_token";
                                $headers[] = 'Content-Type: application/json';
                                $headers[] = 'Accept: application/json';

                                $parameters = [
                                    'type'       => 'sms',
                                    'sender'     => $data['sender_id'],
                                    'body'       => $message,
                                    'recipients' => [$phone],
                                ];

                                $ch = curl_init($send_sms_url);
                                curl_setopt($ch, CURLOPT_POST, 1);
                                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                                $result = curl_exec($ch);
                                $err    = curl_error($ch);

                                curl_close($ch);

                                if ($err) {
                                    $get_sms_status = $err;
                                } else {

                                    $get_data = json_decode($result, true);

                                    if (is_array($get_data) && array_key_exists('success', $get_data)) {
                                        if ($get_data['success']) {
                                            $get_sms_status = 'Delivered';
                                        } else if (array_key_exists('errors', $get_data)) {
                                            $get_sms_status = $get_data['errors'][0];
                                        } else {
                                            $get_sms_status = $get_data['errorCode'];
                                        }
                                    } else {
                                        $get_sms_status = (string) $result;
                                    }
                                }
                            } else if (array_key_exists('errorCode', $response)) {
                                $get_sms_status = $response['errorCode'];
                            } else {
                                $get_sms_status = $response['error'];
                            }
                        }
                        curl_close($ch);
                        break;

                    case SendingServer::TYPE_HOTMOBILE:

                        $parameters = [
                            'usr'    => $sending_server->username,
                            'pwd'    => $sending_server->password,
                            'number' => $phone,
                            'msg'    => $message,
                        ];

                        if (isset($data['sender_id'])) {
                            $parameters['sender'] = $data['sender_id'];
                        }

                        $gateway_url .= '?' . http_build_query($parameters);

                        try {
                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

                            $response = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $get_sms_status = match ($response) {
                                    '-1' => 'Erro de Envios - Instabilidade do sistema.',
                                    '-2' => 'Sem Credito',
                                    '-5' => 'Login ou Senha Invalidos',
                                    '-7' => 'Mensagem Invalida',
                                    '-8' => 'Remetente Invalido',
                                    '-9' => 'Numero do GSM no formato invalido',
                                    '-13' => 'Numero do GSM invalido',
                                    '-20' => 'Servico fora do ar',
                                    '-30' => 'Data de Agendamento invalida',
                                    default => 'Delivered',
                                };
                            }
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }

                        curl_close($ch);
                        break;

                    case SendingServer::TYPE_YUPCHAT:

                        $message_id = uniqid();

                        $parameters = [
                            'messages' => [
                                [
                                    'to'     => $phone,
                                    'body'   => $message,
                                    'ext_id' => $message_id,
                                ],
                            ],
                        ];

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_USERPWD, $sending_server->c1 . ':' . $sending_server->api_token);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                                'Content-Type: application/json',
                            ]);

                            $response = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {

                                $get_response = json_decode($response, true);

                                if (is_array($get_response)) {
                                    if (array_key_exists('error', $get_response)) {
                                        $get_sms_status = $get_response['error'];
                                    } else {
                                        $get_sms_status = 'Delivered';
                                    }
                                } else {
                                    $get_sms_status = $response;
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_8x8:

                        $parameters = [
                            'encoding'    => 'AUTO',
                            'track'       => 'None',
                            'text'        => $message,
                            'destination' => $phone,
                        ];

                        if (isset($data['sender_id'])) {
                            $parameters['source'] = $data['sender_id'];
                        }

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));

                            $headers   = [];
                            $headers[] = 'Accept: application/json';
                            $headers[] = 'Authorization: Bearer ' . $sending_server->api_token;
                            $headers[] = 'Content-Type: application/json';
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                            $response = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {

                                $get_response = json_decode($response, true);

                                if (is_array($get_response)) {
                                    if (array_key_exists('umid', $get_response)) {
                                        $get_sms_status = 'Delivered';
                                    } else {
                                        $get_sms_status = $get_response['message'];
                                    }
                                } else {
                                    $get_sms_status = $response;
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_FONOIP:

                        $parameters = [
                            'custom_id' => uniqid(),
                            'message'   => $message,
                            'number'    => $phone,
                        ];

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_USERPWD, 'api:' . $sending_server->api_key);

                            $headers   = [];
                            $headers[] = 'Accept: application/json';
                            $headers[] = 'Content-Type: application/json';
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                            $response = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {

                                $get_response = json_decode($response, true);

                                if (is_array($get_response) && array_key_exists('error', $get_response)) {
                                    if ($get_response['error'] == null) {
                                        $get_sms_status = 'Delivered';
                                    } else {
                                        $get_sms_status = $get_response['error'];
                                    }
                                } else {
                                    $get_sms_status = $response;
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_QOOLIZE:

                        $parameters = [
                            'username' => $sending_server->username,
                            'password' => $sending_server->password,
                            'from'     => $data['sender_id'],
                            'to'       => $phone,
                            'text'     => $message,
                        ];

                        $sending_url = $gateway_url . '?' . http_build_query($parameters);

                        try {

                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $sending_url);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            $get_sms_status = curl_exec($ch);
                            curl_close($ch);
                            $get_sms_status = trim(strip_tags($get_sms_status));

                            if (substr_count($get_sms_status, 'code:0')) {
                                $get_sms_status = 'Delivered';
                            }

                        } catch (Exception $exception) {
                            $get_sms_status = $exception->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_EBULKSMS:

                        $parameters = [
                            'SMS' => [
                                'auth'       => [
                                    'username' => $sending_server->username,
                                    'apikey'   => $sending_server->api_key,
                                ],
                                'message'    => [
                                    'sender'      => $data['sender_id'],
                                    'messagetext' => $message,
                                    'flash'       => 0,
                                ],
                                'recipients' => [
                                    'gsm' => [
                                        [
                                            'msidn' => $phone,
                                            'msgid' => substr(uniqid('int_'), 0, 30),
                                        ],
                                    ],
                                ],
                            ],
                        ];

                        try {
                            $curl = curl_init();
                            curl_setopt($curl, CURLOPT_URL, $gateway_url);
                            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
                            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
                            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);

                            $headers   = [];
                            $headers[] = 'Content-Type: application/json';
                            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

                            $result = curl_exec($curl);
                            curl_close($curl);

                            $response = json_decode($result, true);

                            if ($response && is_array($response) && array_key_exists('response', $response) && array_key_exists('status', $response['response'])) {
                                if ($response['response']['status'] == 'STATUS_STRING' || $response['response']['status'] == 'SUCCESS') {
                                    $get_sms_status = 'Delivered';
                                } else {
                                    $get_sms_status = $response['response']['status'];
                                }
                            } else {
                                $get_sms_status = $result;
                            }
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }

                        break;

                    case SendingServer::TYPE_CLICKSEND:

                        $sender_id = str_replace(['(', ')', '+', '-', ' '], '', $data['sender_id']);
                        $phone     = '+' . str_replace(['(', ')', '+', '-', ' '], '', $phone);

                        if (is_numeric($sender_id)) {
                            $sender_id = '+' . $sender_id;
                        } else {
                            $sender_id = $data['sender_id'];
                        }

                        $config = Configuration::getDefaultConfiguration()
                            ->setUsername($sending_server->username)
                            ->setPassword($sending_server->api_key);

                        $apiInstance = new SMSApi(new \GuzzleHttp\Client(), $config);
                        $msg         = new SmsMessage();
                        $msg->setSource($sender_id);
                        $msg->setBody($message);
                        $msg->setFrom($sender_id);
                        $msg->setTo($phone);

                        $sms_messages = new SmsMessageCollection();
                        $sms_messages->setMessages([$msg]);


                        try {

                            $result       = $apiInstance->smsSendPost($sms_messages);
                            $get_response = json_decode($result, true);

                            if (is_array($get_response) && array_key_exists('http_code', $get_response) && array_key_exists('response_msg', $get_response)) {
                                if ($get_response['http_code'] == 200) {
                                    $get_sms_status = $customer_status = 'Delivered';
                                } else {
                                    $get_sms_status  = $get_response['response_msg'];
                                    $customer_status = 'Rejected';
                                }
                            } else {
                                $get_sms_status  = $result;
                                $customer_status = 'Rejected';
                            }

                        } catch (ApiException $e) {
                            $getData      = $e->getResponseBody();
                            $get_response = json_decode($getData, true);

                            if (is_array($get_response) && array_key_exists('http_code', $get_response) && array_key_exists('response_msg', $get_response)) {
                                if ($get_response['http_code'] == 200) {
                                    $get_sms_status = $customer_status = 'Delivered';
                                } else {
                                    $get_sms_status  = $get_response['response_msg'];
                                    $customer_status = 'Rejected';
                                }
                            } else {
                                $get_sms_status  = $getData;
                                $customer_status = 'Rejected';
                            }
                        }
                        break;

                    case SendingServer::TYPE_ALIBABACLOUDSMS:
                        try {
                            AlibabaCloud::accessKeyClient($sending_server->access_key, $sending_server->secret_access)
                                ->regionId($sending_server->region)
                                ->asDefaultClient();

                            $result = AlibabaCloud::rpc()
                                ->product('Dysmsapi')
                                ->version('2018-05-01')
                                ->action('SendMessageToGlobe')
                                ->method('POST')
                                ->host($gateway_url)
                                ->options([
                                    'query' => [
                                        'RegionId' => $sending_server->region,
                                        'To'       => $phone,
                                        'Message'  => $message,
                                        'From'     => $data['sender_id'],
                                    ],
                                ])
                                ->request();

                            if (isset($result->ResponseCode) && isset($result->ResponseDescription)) {
                                if ($result->ResponseCode == 'OK') {
                                    $get_sms_status = 'Delivered';
                                } else {
                                    $get_sms_status = $result->ResponseDescription;
                                }
                            } else {
                                $get_sms_status = 'Invalid Request';
                            }

                        } catch (ClientException|ServerException $e) {
                            $get_sms_status = $e->getErrorMessage();
                        }
                        break;

                    case SendingServer::TYPE_SMSMODE:
                        $parameters = [
                            'recipient' => [
                                'to' => $phone,
                            ],
                            'body'      => [
                                'text' => $message,
                            ],
                        ];

                        if (isset($data['sender_id'])) {
                            $parameters['from'] = $data['sender_id'];
                        }

                        if ($sms_type == 'unicode') {
                            $parameters['body']['encoding'] = 'UNICODE';
                        }

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_POST, 1);

                            $headers   = [];
                            $headers[] = "X-Api-Key: $sending_server->api_key";
                            $headers[] = 'Accept: application/json';
                            $headers[] = 'Content-Type: application/json';
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                            $get_sms_status = curl_exec($ch);
                            curl_close($ch);

                            $get_data = json_decode($get_sms_status, true);

                            if (is_array($get_data) && array_key_exists('status', $get_data) && array_key_exists('messageId', $get_data)) {
                                if ($get_data['status']['value'] == 'ENROUTE') {
                                    $get_sms_status = 'Delivered|' . $get_data['messageId'];
                                } else {
                                    $get_sms_status = $get_data['status']['value'];
                                }
                            }

                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_ORANGE:

                        $ch = curl_init();

                        curl_setopt($ch, CURLOPT_URL, 'https://api.orange.com/oauth/v3/token');
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials');

                        $headers   = [];
                        $headers[] = 'Authorization: ' . $sending_server->c1;
                        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
                        $headers[] = 'Accept: application/json';
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                        $response = curl_exec($ch);
                        if (curl_errno($ch)) {
                            $get_sms_status = curl_error($ch);
                        } else {
                            $response = json_decode($response, true);

                            if ( ! empty($response['access_token'])) {
                                $senderAddress = $sending_server->c2;

                                $parameters = [
                                    'outboundSMSMessageRequest' => [
                                        'address'                => 'tel:+' . $phone,
                                        'senderAddress'          => $senderAddress,
                                        'outboundSMSTextMessage' => [
                                            'message' => $message,
                                        ],
                                    ],
                                ];

                                if (isset($data['sender_id'])) {
                                    $parameters['outboundSMSMessageRequest']['senderName'] = urlencode($data['sender_id']);
                                }

                                $curl = curl_init();

                                curl_setopt($curl, CURLOPT_URL, "https://api.orange.com/smsmessaging/v1/outbound/$senderAddress/requests");
                                curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
                                curl_setopt($curl, CURLOPT_POST, 1);
                                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($parameters));

                                $headers   = [];
                                $headers[] = 'Authorization: Bearer ' . $response['access_token'];
                                $headers[] = 'Content-Type: application/json';
                                curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

                                $result = curl_exec($curl);
                                if (curl_errno($curl)) {
                                    $get_sms_status = curl_error($curl);
                                } else {
                                    $get_data = json_decode($result, true);

                                    if (isset($get_data) && is_array($get_data) && array_key_exists('requestError', $get_data)) {
                                        if (array_key_exists('policyException', $get_data['requestError'])) {
                                            $get_sms_status = $get_data['requestError']['policyException']['text'];
                                        } else {
                                            $get_sms_status = $get_data['requestError']['serviceException']['text'];
                                        }
                                    } else {
                                        $get_sms_status = 'Delivered';
                                    }

                                }
                                curl_close($curl);
                            } else {
                                $get_sms_status = $response['error'];
                            }
                        }
                        curl_close($ch);

                        break;

                    case SendingServer::TYPE_MMSCONSOLE:

                        $parameters = [
                            'email' => $sending_server->c1,
                            'token' => $sending_server->api_token,
                            'num'   => '+' . $phone,
                            'text'  => $message,
                            'type'  => 'SMS',
                        ];

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            $response = curl_exec($ch);
                            curl_close($ch);

                            $get_data = json_decode($response, true);

                            if (is_array($get_data)) {
                                if (array_key_exists('id_campagne', $get_data)) {
                                    $get_sms_status = 'Delivered|' . $get_data['id_campagne'];
                                } else if (array_key_exists('error', $get_data)) {
                                    $get_sms_status = $get_data['error'];
                                } else {
                                    $get_sms_status = 'Failure';
                                }
                            } else {
                                $get_sms_status = $response;
                            }
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_BMSGLOBAL:

                        $parameters = [
                            'token'    => $sending_server->api_token,
                            'sender'   => $data['sender_id'],
                            'receiver' => $phone,
                            'msgtext'  => $message,
                        ];

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            $response = curl_exec($ch);
                            curl_close($ch);

                            if ($response == 'Success') {
                                $get_sms_status = 'Delivered';
                            } else {
                                $get_sms_status = 'Failed';
                            }

                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_GBESTSMS:

                        $parameters = [
                            'username'  => $sending_server->username,
                            'password'  => $sending_server->password,
                            'sender'    => $data['sender_id'],
                            'recipient' => $phone,
                            'message'   => $message,
                            'comm'      => 'spc_api',
                        ];

                        $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                        try {
                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            $response = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {

                                if (substr_count($response, 'OK')) {
                                    $get_sms_status = 'Delivered';
                                } else {
                                    $get_sms_status = match (trim($response)) {
                                        '-2904' => 'SMS sending failed',
                                        '-2905' => 'Invalid username/password combination',
                                        '-2906' => 'Credit exhausted',
                                        '-2907' => 'Gateway unavailable',
                                        '-2908' => 'Invalid schedule date format',
                                        '-2909' => 'Unable to schedule',
                                        '-2910' => 'Username is empty',
                                        '-2911' => 'Password is empty',
                                        '-2912' => 'Recipient is empty',
                                        '-2913' => 'Message is empty',
                                        '-2914' => 'Sender is empty',
                                        '-2915' => 'One or more required fields are empty',
                                        '-2916' => 'Blocked message content',
                                        '-2917' => 'Blocked sender ID',
                                        default => 'Failed',
                                    };
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_SILVERSTREET:

                        $parameters = [
                            'username'    => $sending_server->username,
                            'password'    => $sending_server->password,
                            'destination' => $phone,
                            'body'        => $message,
                            'sender'      => $data['sender_id'],
                        ];

                        if ($sms_type == 'unicode') {
                            $parameters['bodytype'] = 4;
                        }

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameters));
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        $result = curl_exec($ch);

                        $get_sms_status = match ($result) {
                            '01' => 'Delivered',
                            '100' => 'Parameter(s) are missing.',
                            '110' => 'Bad combination of parameters.',
                            '120' => 'Invalid parameter(s).',
                            '130' => 'Insufficient credits for the account.',
                            default => 'Invalid request',
                        };
                        curl_close($ch);
                        break;

                    case SendingServer::TYPE_GLINTSMS:

                        $parameters = [
                            'action'  => 'send-sms',
                            'api_key' => $sending_server->api_key,
                            'to'      => $phone,
                            'sms'     => $message,
                            'from'    => $data['sender_id'],
                        ];

                        if ($sms_type == 'unicode') {
                            $parameters['unicode'] = 1;
                        }

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameters));
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        $result = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status = curl_error($ch);
                        } else {
                            $get_data = json_decode($result, true);

                            if (isset($get_data) && is_array($get_data) && array_key_exists('code', $get_data) && array_key_exists('message', $get_data)) {
                                if ($get_data['code'] == 'ok') {
                                    $get_sms_status = 'Delivered';
                                } else {
                                    $get_sms_status = $get_data['message'];
                                }
                            } else {
                                $get_sms_status = 'Failed';
                            }
                        }
                        curl_close($ch);
                        break;

                    case SendingServer::TYPE_DATAGIFTING:

                        $parameters = [
                            'token'   => $sending_server->api_token,
                            'from'    => $data['sender_id'],
                            'to'      => $phone,
                            'message' => $message,
                        ];

                        $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                        try {
                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            $result = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {

                                $get_data = json_decode($result, true);

                                if (isset($get_data) && is_array($get_data) && array_key_exists('code', $get_data) && array_key_exists('desc', $get_data)) {
                                    if ($get_data['code'] == '200') {
                                        $get_sms_status = 'Delivered';
                                    } else {
                                        $get_sms_status = $get_data['desc'];
                                    }
                                } else {
                                    $get_sms_status = 'Failed';
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_SMSHTTPREVE:

                        $parameters = [
                            'apikey'         => $sending_server->api_key,
                            'secretkey'      => $sending_server->api_secret,
                            'callerID'       => $data['sender_id'],
                            'toUser'         => $phone,
                            'messageContent' => $message,
                        ];

                        $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                        try {
                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            $result = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {

                                $get_data = json_decode($result, true);

                                if (isset($get_data) && is_array($get_data) && array_key_exists('Status', $get_data) && array_key_exists('Text', $get_data)) {
                                    if ($get_data['Status'] == '0') {
                                        $get_sms_status = 'Delivered';
                                    } else {
                                        $get_sms_status = $get_data['Text'];
                                    }
                                } else {
                                    $get_sms_status = 'Failed';
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_BULKSMSPROVIDERNG:

                        $parameters = [
                            'username' => $sending_server->username,
                            'password' => $sending_server->password,
                            'sender'   => $data['sender_id'],
                            'mobiles'  => $phone,
                            'message'  => $message,
                            'type'     => 'text',
                        ];

                        $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                        try {
                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/96.0.4664.45 Safari/537.36');

                            $result = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {

                                $get_data = json_decode($result, true);

                                if (isset($get_data) && is_array($get_data)) {
                                    if (array_key_exists('status', $get_data) && $get_data['status'] == 'OK') {
                                        $get_sms_status = 'Delivered';
                                    } else if (array_key_exists('error', $get_data)) {
                                        $get_sms_status = $get_data['error'];
                                    } else {
                                        $get_sms_status = 'Failed';
                                    }
                                } else {
                                    $get_sms_status = 'Failed';
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_OZONESMS:

                        $parameters = [
                            'user'     => $sending_server->username,
                            'password' => $sending_server->password,
                            'channel'  => $sending_server->sms_type,
                            'route'    => $sending_server->route,
                            'senderid' => $data['sender_id'],
                            'number'   => $phone,
                            'text'     => $message,
                            'flashsms' => '0',
                        ];

                        if ($sms_type == 'unicode') {
                            $parameters['DCS'] = 8;
                        } else {
                            $parameters['DCS'] = 0;
                        }

                        if (isset($data['dlt_entity_id'])) {
                            $parameters['PEID'] = $data['dlt_entity_id'];
                        }

                        if (isset($data['dlt_template_id'])) {
                            $parameters['DLTTemplateId'] = $data['dlt_template_id'];
                        }

                        $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                        try {
                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            $result = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {

                                $get_data = json_decode($result, true);

                                if (isset($get_data) && is_array($get_data) && array_key_exists('ErrorCode', $get_data) && array_key_exists('ErrorMessage', $get_data)) {
                                    if ($get_data['ErrorCode'] == '000') {
                                        $get_sms_status = 'Delivered';
                                    } else {
                                        $get_sms_status = $get_data['ErrorMessage'];
                                    }
                                } else {
                                    $get_sms_status = $result;
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_NIGERIABULKSMS:

                        $parameters = [
                            'username' => $sending_server->username,
                            'password' => $sending_server->password,
                            'sender'   => $data['sender_id'],
                            'mobiles'  => $phone,
                            'message'  => $message,
                        ];

                        $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                        try {
                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            $result = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {

                                $get_data = json_decode($result, true);

                                if (isset($get_data) && is_array($get_data)) {
                                    if (array_key_exists('status', $get_data) && $get_data['status'] == 'OK') {
                                        $get_sms_status = 'Delivered';
                                    } else if (array_key_exists('error', $get_data)) {
                                        $get_sms_status = $get_data['error'];
                                    } else {
                                        $get_sms_status = 'Failed';
                                    }
                                } else {
                                    $get_sms_status = 'Failed';
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_SMSAPI:

                        $parameters = [
                            'from'    => $data['sender_id'],
                            'to'      => $phone,
                            'message' => $message,
                            'format'  => 'json',
                        ];

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                            $headers   = [];
                            $headers[] = 'Authorization: Bearer ' . $sending_server->api_token;
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            $response = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {

                                $get_data = json_decode($response, true);

                                if (isset($get_data) && is_array($get_data)) {
                                    if (array_key_exists('count', $get_data)) {
                                        $get_sms_status = 'Delivered';
                                    } else if (array_key_exists('error', $get_data)) {
                                        $get_sms_status = $get_data['message'];
                                    } else {
                                        $get_sms_status = $response;
                                    }
                                } else {
                                    $get_sms_status = 'Failed';
                                }
                            }
                            curl_close($ch);

                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_SMSAPIONLINE:

                        $parameters = [
                            'token'    => $sending_server->api_token,
                            'senderID' => $data['sender_id'],
                            'phone'    => $phone,
                            'text'     => $message,
                        ];

                        $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                        try {
                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            $result = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {

                                $get_data = json_decode($result, true);

                                if (isset($get_data) && is_array($get_data)) {
                                    if (array_key_exists('status', $get_data) && $get_data['status'] == 'OK') {
                                        $get_sms_status = 'Delivered';
                                    } else if (array_key_exists('error', $get_data)) {
                                        $get_sms_status = $get_data['error'];
                                    } else {
                                        $get_sms_status = 'Failed';
                                    }
                                } else {
                                    $get_sms_status = 'Failed';
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_TERMII:
                        $parameters = [
                            'api_key' => $sending_server->api_key,
                            'to'      => $phone,
                            'from'    => $data['sender_id'],
                            'sms'     => $message,
                            'channel' => $sending_server->c1,
                            'type'    => 'plain',
                        ];

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            $headers   = [];
                            $headers[] = 'Accept: application/json';
                            $headers[] = 'Content-Type: application/json';
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                            $response = curl_exec($ch);
                            curl_close($ch);

                            $get_data = json_decode($response, true);

                            if (is_array($get_data)) {
                                if (array_key_exists('message_id', $get_data)) {
                                    $get_sms_status = 'Delivered|' . $get_data['message_id'];
                                } else if (array_key_exists('message', $get_data)) {
                                    $get_sms_status = $get_data['message'];
                                } else {
                                    $get_sms_status = 'Failed';
                                }
                            } else {
                                $get_sms_status = $response;
                            }
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_VOXIMPLANT:
                        $parameters = [
                            'account_id'  => $sending_server->c1,
                            'api_key'     => $sending_server->api_key,
                            'destination' => $phone,
                            'sms_body'    => $message,
                            'source'      => $data['sender_id'],
                        ];

                        $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_HTTPGET, 1);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

                        $get_sms_status = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status = curl_error($ch);
                        } else {

                            $get_data = json_decode($get_sms_status, true);

                            if (is_array($get_data)) {
                                if (array_key_exists('message_id', $get_data)) {
                                    $get_sms_status = 'Delivered|' . $get_data['message_id'];
                                } else if (array_key_exists('error', $get_data)) {
                                    $get_sms_status = $get_data['error']['msg'];
                                } else {
                                    $get_sms_status = 'Failed';
                                }
                            }

                        }
                        curl_close($ch);
                        break;

                    case SendingServer::TYPE_CLIQSMS:
                        $parameters = [
                            'user'     => $sending_server->username,
                            'password' => $sending_server->password,
                            'mobile'   => $phone,
                            'message'  => $message,
                            'senderid' => $data['sender_id'],
                            'dnd'      => $sending_server->c1,
                        ];

                        if ($sms_type == 'unicode') {
                            $parameters['unicode'] = 1;
                        }

                        $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_HTTPGET, 1);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

                        $get_sms_status = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status = curl_error($ch);
                        } else {
                            if (substr_count($get_sms_status, 'SUCCESS')) {
                                $get_sms_status = 'Delivered';
                            } else {
                                $get_sms_status = str_replace('ERROR:', '', $get_sms_status);
                            }
                        }
                        curl_close($ch);
                        break;

                    case SendingServer::TYPE_SMSVEND:

                        $parameters = [
                            'email'       => $sending_server->c1,
                            'password'    => $sending_server->password,
                            'recipients'  => $phone,
                            'message'     => $message,
                            'sender_name' => $data['sender_id'],
                        ];

                        try {

                            $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

                            $get_sms_status = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                if (substr_count($get_sms_status, 'Ok')) {
                                    $get_sms_status = 'Delivered';
                                }
                            }
                            curl_close($ch);

                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_PMCSMS:

                        $parameters = [
                            'api_key'   => $sending_server->api_key,
                            'route'     => $sending_server->route,
                            'recipient' => $phone,
                            'message'   => $message,
                            'sender'    => $data['sender_id'],
                        ];

                        try {

                            $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

                            $get_sms_status = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                if (substr_count($get_sms_status, 'OK')) {
                                    $get_sms_status = 'Delivered';
                                }
                            }
                            curl_close($ch);

                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_JUICYSIMS:
                        $parameters = [
                            'api_key' => $sending_server->api_key,
                            'mobile'  => $phone,
                            'sms'     => $message,
                            'source'  => $data['sender_id'],
                        ];

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameters));
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        $result = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status = curl_error($ch);
                        } else {
                            $get_data = json_decode($result, true);

                            if (isset($get_data) && is_array($get_data) && array_key_exists('status', $get_data)) {
                                if ($get_data['status'] == '200' || $get_data['status'] == '201') {
                                    $get_sms_status = 'Delivered';
                                } else {
                                    $get_sms_status = $get_data['error'];
                                }
                            } else {
                                $get_sms_status = 'Failed';
                            }
                        }
                        curl_close($ch);
                        break;

                    case SendingServer::TYPE_MOBILESMSNG:
                    case SendingServer::TYPE_SMSAFRICANG:

                        $parameters = [
                            'user'     => $sending_server->username,
                            'pass'     => $sending_server->password,
                            'receiver' => $phone,
                            'message'  => $message,
                            'sender'   => $data['sender_id'],
                        ];

                        if (strlen($message) > 160) {
                            $parameters['type'] = 'longSMS';
                        }

                        try {

                            $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

                            $get_sms_status = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                if ($get_sms_status > 0) {
                                    $get_sms_status = 'Delivered';
                                } else {
                                    $get_sms_status = match ($get_sms_status) {
                                        '-100' => 'Empty Username',
                                        '-200' => 'Empty Password',
                                        '-300' => 'Empty Sender ID',
                                        '-400' => 'Sender ID greater than 14 characters',
                                        '-500' => 'Invalid receiver phone number',
                                        '-600' => 'Invalid username or password',
                                        '-700' => 'Empty message',
                                        '-800' => 'Insufficient credit',
                                        '-900' => 'Account deactivated',
                                        default => 'Invalid request',
                                    };
                                }
                            }
                            curl_close($ch);

                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_BSGWORLD:

                        $parameters = [
                            'destination' => 'phone',
                            'originator'  => $data['sender_id'],
                            'body'        => $message,
                            'msisdn'      => $phone,
                            'reference'   => str_random(10),
                        ];

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);

                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'Content-Type: application/json',
                            "X-API-KEY:$sending_server->api_key",
                        ]);

                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

                        // DATA ARRAY
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        $response = curl_exec($ch);

                        if ($response === false) {
                            $get_sms_status = curl_error($ch);
                        } else {
                            $get_data = json_decode($response, true);

                            if (isset($get_data) && is_array($get_data) && array_key_exists('error', $get_data)) {
                                if ($get_data['error'] == 0) {
                                    $get_sms_status = 'Delivered';
                                } else {
                                    $get_sms_status = $get_data['errorDescription'];
                                }
                            } else if (isset($get_data) && is_array($get_data) && array_key_exists('result', $get_data)) {
                                if ($get_data['result']['error'] == 0) {
                                    $get_sms_status = 'Delivered';
                                } else {
                                    $get_sms_status = $get_data['result']['errorDescription'];
                                }
                            } else {
                                $get_sms_status = 'Unknown error';
                            }
                        }
                        curl_close($ch);
                        break;

                    case SendingServer::TYPE_SNAPISMS:

                        $parameters = [
                            'message'   => $message,
                            'sender_id' => $data['sender_id'],
                            'phone'     => $phone,
                        ];

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'Authorization: Bearer ' . $sending_server->api_key,
                            'Content-Type: application/json',
                            'Accept: application/json',
                        ]);

                        $response = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status = curl_error($ch);
                        } else {

                            $get_response = json_decode($response, true);

                            if (is_array($get_response) && array_key_exists('success', $get_response)) {
                                if ($get_response['success']) {
                                    $get_sms_status = 'Delivered';
                                }
                            } else if (array_key_exists('message', $get_response)) {
                                $get_sms_status = $get_response['message'];
                            } else {
                                $get_sms_status = $response;
                            }
                        }

                        curl_close($ch);
                        break;

                    case SendingServer::TYPE_SMSEXPERIENCE:
                        $parameters = [
                            'username'  => $sending_server->username,
                            'password'  => $sending_server->password,
                            'recipient' => $phone,
                            'message'   => $message,
                            'sender'    => $data['sender_id'],
                        ];

                        $sending_url = $gateway_url . '?' . http_build_query($parameters);

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $sending_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);

                            $get_sms_status = curl_exec($ch);

                            curl_close($ch);

                            if (str_contains($get_sms_status, 'TG00')) {
                                $get_sms_status = 'Delivered';
                            }
                        } catch (Exception $ex) {
                            $get_sms_status = $ex->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_CHALLENGESMS:
                        $parameters = [
                            'secret'  => $sending_server->api_secret,
                            'mode'    => $sending_server->c1,
                            'phone'   => '+' . $phone,
                            'message' => $message,
                        ];

                        if (isset($sending_server->device_id)) {
                            $parameters['device'] = $sending_server->device_id;
                        }

                        if (isset($sending_server->c2)) {
                            $parameters['gateway'] = $sending_server->c2;
                        }

                        if (isset($sending_server->c3)) {
                            $parameters['sim'] = $sending_server->c3;
                        }

                        if (isset($sending_server->c4)) {
                            $parameters['priority'] = $sending_server->c4;
                        }

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                            curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            $result = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $get_data = json_decode($result, true);

                                if (isset($get_data) && is_array($get_data) && array_key_exists('status', $get_data)) {
                                    if ($get_data['status'] == '200') {
                                        $get_sms_status = 'Delivered';
                                    } else {
                                        $get_sms_status = $get_data['message'];
                                    }
                                } else {
                                    $get_sms_status = 'Failed';
                                }
                            }
                            curl_close($ch);

                        } catch (Exception $ex) {
                            $get_sms_status = $ex->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_BLACKSMS:
                    case SendingServer::TYPE_ULTIMATESMS:
                        $parameters = [
                            'api_token' => $sending_server->api_token,
                            'message'   => $message,
                            'recipient' => $phone,
                            'type'      => 'plain',
                        ];

                        if (isset($data['sender_id'])) {
                            $parameters['sender_id'] = $data['sender_id'];
                        }


                        $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_HTTPGET, 1);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

                        $result = curl_exec($ch);


                        if (curl_errno($ch)) {
                            $get_sms_status = curl_error($ch);
                        } else {
                            $get_data = json_decode($result, true);
                            if (isset($get_data) && is_array($get_data) && array_key_exists('status', $get_data)) {
                                if ($get_data['status'] == 'success') {
                                    $get_sms_status = 'Delivered';
                                } else {
                                    $get_sms_status = $get_data['message'];
                                }
                            } else {
                                $get_sms_status = 'Failed';
                            }
                        }

                        curl_close($ch);
                        break;

                    case SendingServer::TYPE_MOCEANAPI:
                        $parameters = [
                            'mocean-api-key'     => $sending_server->api_key,
                            'mocean-api-secret'  => $sending_server->api_secret,
                            'mocean-from'        => $data['sender_id'],
                            'mocean-to'          => $phone,
                            'mocean-text'        => $message,
                            'mocean-dlr-mask'    => 1,
                            'mocean-dlr-url'     => route('dlr.moceanapi'),
                            'mocean-resp-format' => 'json',
                        ];

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_POST, count($parameters));
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                        $result = curl_exec($ch);

                        if ($result === false) {
                            $get_sms_status = curl_error($ch);
                        } else {
                            $json_result = json_decode($result, true);

                            if (isset($json_result) && is_array($json_result)) {

                                if (array_key_exists('messages', $json_result) && isset($json_result['messages']['0']['status'])) {
                                    if ($json_result['messages']['0']['status'] == 0) {
                                        $get_sms_status = 'Delivered|' . $json_result['messages']['0']['msgid'];
                                    } else {
                                        $get_sms_status = $json_result['messages']['0']['err_msg'];
                                    }
                                } else {
                                    $get_sms_status = $json_result['err_msg'];
                                }

                            } else {
                                $get_sms_status = 'Invalid request';
                            }
                        }
                        curl_close($ch);
                        break;


                    case SendingServer::TYPE_SMSURWAY:

                        $authData = [
                            'email'    => $sending_server->c1,
                            'password' => $sending_server->password,
                        ];

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $sending_server->api_link);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($authData));
                            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                                'Content-Type: application/json',
                                'Accept: application/json',
                            ]);
                            $result = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status  = curl_error($ch);
                                $customer_status = 'Failed';
                            } else {
                                $get_data = json_decode($result, true);

                                if (isset($get_data) && is_array($get_data) && array_key_exists('token', $get_data)) {

                                    $parameters = [
                                        'sender_channel' => strtolower($data['sender_id']),
                                        'to'             => $phone,
                                        'msg'            => $message,
                                    ];


                                    $curl = curl_init();
                                    curl_setopt($curl, CURLOPT_URL, $sending_server->otp_api_link);
                                    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                                    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 0);
                                    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
                                    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($parameters));
                                    curl_setopt($curl, CURLOPT_HTTPHEADER, [
                                        'Authorization: Bearer ' . $get_data['token'],
                                        'Content-Type: application/json',
                                        'Accept: application/json',
                                    ]);
                                    $response = curl_exec($curl);

                                    if (curl_errno($curl)) {
                                        $get_sms_status  = curl_error($curl);
                                        $customer_status = 'Failed';
                                    } else {
                                        $smsData = json_decode($response, true);

                                        if (isset($smsData) && is_array($smsData) && array_key_exists('success', $smsData) && array_key_exists('message', $smsData)) {
                                            if ($smsData['success']) {
                                                $get_sms_status = $customer_status = 'Delivered';
                                            } else {
                                                $get_sms_status  = $smsData['message'];
                                                $customer_status = 'Failed';
                                            }

                                        } else {
                                            $get_sms_status  = $response;
                                            $customer_status = 'Failed';
                                        }
                                    }
                                    curl_close($curl);
                                } else {
                                    $get_sms_status  = $result;
                                    $customer_status = 'Failed';
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $exception) {
                            $get_sms_status  = $exception->getMessage();
                            $customer_status = 'Failed';
                        }
                        break;

                    case SendingServer::TYPE_SMARTSMSSOLUTIONS:

                        $parameters = [
                            'token'   => $sending_server->api_token,
                            'routing' => $sending_server->route,
                            'sender'  => $data['sender_id'],
                            'to'      => $phone,
                            'message' => $message,
                        ];

                        if ($sms_type == 'unicode') {
                            $parameters['type'] = 2;
                        } else {
                            $parameters['type'] = 0;
                        }

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);
                        $result = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status = curl_error($ch);
                        } else {
                            $get_data = json_decode($result, true);
                            if (isset($get_data) && is_array($get_data)) {
                                if (array_key_exists('code', $get_data) && $get_data['code'] == '1000') {
                                    $get_sms_status = 'Delivered';
                                } else if (array_key_exists('comment', $get_data)) {
                                    $get_sms_status = $get_data['comment'];
                                } else {
                                    $get_sms_status = $result;
                                }
                            } else {
                                $get_sms_status = 'Failed';
                            }
                        }
                        curl_close($ch);
                        break;

                    case SendingServer::TYPE_VOICEANDTEXT:

                        $parameters = [
                            'username' => $sending_server->username,
                            'password' => $sending_server->password,
                            'sender'   => $data['sender_id'],
                            'mobiles'  => $phone,
                            'message'  => $message,
                        ];

                        $sending_url = $gateway_url . '?' . http_build_query($parameters);

                        try {
                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $sending_url);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            $get_sms_status = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $get_sms_status = trim($get_sms_status);

                                $get_sms_status = match ($get_sms_status) {
                                    '1700' => 'This service has been temporarily disabled by the system administrator',
                                    '1701' => 'Delivered',
                                    '1702' => 'Invalid Access',
                                    '1703' => 'Invalid mobile(s) in recipient',
                                    '1704' => 'Invalid sender identity',
                                    '1705' => 'Numeric sender not allowed',
                                    '1706' => 'Message content is required',
                                    '1707' => 'Unauthorized user account',
                                    '1708' => 'This account has been disabled',
                                    '1709' => 'The system is currently unavailable',
                                    '1710' => 'The system has a pending service update.',
                                    '1711', '1719' => 'Insufficient balance to complete this request',
                                    '1712' => 'The sender id used has been blocked',
                                    '1713' => 'Message filtered by network provider',
                                    '1714' => 'This IP address has been blocked.',
                                    '1715' => 'Invalid mobile(s) in request. Improper formatting',
                                    '1716' => 'Invalid mobile(s) in request',
                                    '1717' => 'Account pricing not configured correctly',
                                    '1718' => 'System pricing not configured correctly',
                                    '1720' => 'Insufficient reseller balance',
                                    '1721' => 'Insufficient sub reseller balance',
                                    '1722' => 'Insufficient service allocation',
                                    '1723' => 'Insufficient reseller resource allocation',
                                    '1724' => 'Insufficient sub reseller resource allocation',
                                    '1725' => 'Application error',
                                    '1766' => 'Maximum recipients reached',
                                    '1786' => 'Message content exceeded',
                                    '1787' => 'Broadcast has not been provided',
                                    '1788' => 'Robocall has been disabled',
                                    default => 'Invalid request',
                                };

                            }
                            curl_close($ch);
                        } catch (Exception $exception) {
                            $get_sms_status = $exception->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_ETROSS:

                        $parameters = [
                            'user'     => $sending_server->username,
                            'password' => $sending_server->password,
                            'taskCnt'  => 1,
                            'tasks'    => [
                                [
                                    'tid'             => time(),
                                    'mode'            => 0,
                                    'from'            => $data['sender_id'],
                                    'to'              => $phone,
                                    'smsFormat'       => 1,
                                    'chset'           => 0,
                                    'content/profile' => $message,
                                ],
                            ],
                        ];

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                                'Content-Type: application/json',
                            ]);
                            $result = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $get_data = json_decode($result, true);
                                if (isset($get_data) && is_array($get_data) && array_key_exists('result', $get_data)) {
                                    if ($get_data['result'] == 'success') {
                                        $get_sms_status = 'Delivered';
                                    } else {
                                        $get_sms_status = $get_data['result'];
                                    }
                                } else {
                                    $get_sms_status = $result;
                                }
                            }

                            curl_close($ch);
                        } catch (Exception $exception) {
                            $get_sms_status = $exception->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_DINSTAR:

                        $parameters = [
                            'text'  => $message,
                            'param' => [
                                [
                                    'number' => $phone,
                                ],
                            ],
                        ];

                        if (isset($sending_server->port)) {
                            $parameters['port'] = [$sending_server->port];
                        }

                        if ($sms_type == 'unicode') {
                            $parameters['encoding'] = 'unicode';
                        }

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            curl_setopt($ch, CURLOPT_USERPWD, "$sending_server->username" . ':' . "$sending_server->password");
                            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                                'Content-Type: application/json',
                            ]);
                            $result = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $get_data = json_decode($result, true);
                                if (isset($get_data) && is_array($get_data) && array_key_exists('error_code', $get_data)) {
                                    $get_sms_status = match ($get_data['error_code']) {
                                        202 => 'Delivered',
                                        400 => 'Request format is not valid',
                                        413 => 'the count of telephone numbers is more than 128 or text content is over 1500 bytes',
                                        500 => 'other errors',
                                        550 => 'No available port for sending sms',
                                        default => 'Invalid Request'
                                    };
                                } else {
                                    $get_sms_status = $result;
                                }
                            }

                            curl_close($ch);
                        } catch (Exception $exception) {
                            $get_sms_status = $exception->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_SIMPLETEXTING:

                        if (strlen($phone) >= 10) {
                            $phone = substr($phone, -10);
                        }

                        $parameters = [
                            'contactPhone' => $phone,
                            'mode'         => 'AUTO',
                            'text'         => $message,
                        ];

                        if (isset($data['sender_id'])) {
                            $sender_id = $data['sender_id'];
                            if (strlen($sender_id) >= 10) {
                                $sender_id = substr($sender_id, -10);
                            }
                            $parameters['accountPhone'] = $sender_id;
                        }

                        $ch = curl_init();

                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));

                        $headers   = [];
                        $headers[] = 'Authorization: Bearer ' . $sending_server->api_token;
                        $headers[] = 'Content-Type: application/json';
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                        $result = curl_exec($ch);
                        if (curl_errno($ch)) {
                            $get_sms_status = curl_error($ch);
                        } else {
                            $get_data = json_decode($result, true);

                            if (isset($get_data) && is_array($get_data)) {
                                if (array_key_exists('id', $get_data)) {
                                    $get_sms_status = 'Delivered|' . $get_data['id'];
                                } else if (array_key_exists('details', $get_data)) {
                                    $get_sms_status = $get_data['details'];
                                } else {
                                    $get_sms_status = 'Failed';
                                }
                            } else {
                                $get_sms_status = 'Failed';
                            }
                        }
                        curl_close($ch);
                        break;

                    case SendingServer::TYPE_WAVIX:

                        $parameters = [
                            'from'         => $data['sender_id'],
                            'to'           => $phone,
                            'message_body' => [
                                'text' => $message,
                            ],
                        ];

                        $sending_url = $gateway_url . '?appid=' . $sending_server->application_id;
                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $sending_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                                'Content-Type: application/json',
                            ]);
                            $result = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $get_data = json_decode($result, true);
                                if (isset($get_data) && is_array($get_data)) {
                                    if (array_key_exists('message_id', $get_data)) {
                                        $get_sms_status = 'Delivered|' . $get_data['message_id'];
                                    } else if (array_key_exists('error', $get_data) && $get_data['error']) {
                                        $get_sms_status = $get_data['message'];
                                    } else {
                                        $get_sms_status = $result;
                                    }
                                } else {
                                    $get_sms_status = $result;
                                }
                            }

                            curl_close($ch);
                        } catch (Exception $exception) {
                            $get_sms_status = $exception->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_UIPSMS:

                        $parameters = json_encode([
                            'user_token' => $sending_server->api_token,
                            'origin'     => $data['sender_id'],
                            'message'    => $message,
                            'numbers'    => [$phone],
                        ]);

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                            curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);
                            curl_setopt($ch, CURLOPT_HEADER, 0);
                            $result = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $get_data = json_decode($result, true);
                                if (isset($get_data) && is_array($get_data)) {
                                    if (array_key_exists('status', $get_data) && $get_data['status'] == 'successful') {
                                        $get_sms_status = 'Delivered';
                                    } else {
                                        $get_sms_status = $get_data['message'];
                                    }
                                } else {
                                    $get_sms_status = 'Submission Failed';
                                }
                            }

                            curl_close($ch);
                        } catch (Exception $exception) {
                            $get_sms_status = $exception->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_DIAFAAN:

                        $parameters = [
                            'username'    => $sending_server->username,
                            'password'    => $sending_server->password,
                            'priority'    => $sending_server->c1,
                            'to'          => $phone,
                            'messagetype' => 'sms.automatic',
                            'message'     => $message,
                        ];

                        if (isset($data['sender_id'])) {
                            $parameters['from'] = $data['sender_id'];
                        }

                        $sending_url = $gateway_url . '/http/send-message?' . http_build_query($parameters);

                        try {
                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $sending_url);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            $get_sms_status = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                if (str_contains($get_sms_status, 'OK')) {
                                    $get_sms_status = 'Delivered|' . trim(str_replace('OK: ', '', $get_sms_status));
                                } else if (str_contains($get_sms_status, 'STATUS:100')) {
                                    $get_data = explode('|', $get_sms_status);
                                    if (is_array($get_data) && array_key_exists(1, $get_data)) {
                                        $get_sms_status = 'Delivered|' . $get_data['1'];
                                    } else {
                                        $get_sms_status = 'Delivered';
                                    }
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $exception) {
                            $get_sms_status = $exception->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_LAFRICAMOBILE:

                        $parameters = [
                            'accountid' => $sending_server->c1,
                            'password'  => $sending_server->password,
                            'to'        => $phone,
                            'sender'    => $data['sender_id'],
                            'text'      => $message,
                        ];

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                                'Content-Type: application/json',
                            ]);

                            $get_sms_status = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                if ( ! str_contains(trim($get_sms_status), ' ')) {
                                    $get_sms_status = 'Delivered';
                                }
                            }

                            curl_close($ch);
                        } catch (Exception $exception) {
                            $get_sms_status = $exception->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_SMSAERO:

                        $parameters = [
                            'number'    => $phone,
                            'sign'      => $data['sender_id'],
                            'text'      => $message,
                            'shortLink' => 1,
                        ];

                        $sending_url = 'https://' . $sending_server->c1 . ':' . $sending_server->api_key . '@' . $gateway_url . '?' . http_build_query($parameters);

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $sending_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                                'Content-Type: application/json',
                            ]);

                            $result = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $get_data = json_decode($result, true);
                                if (isset($get_data) && is_array($get_data)) {
                                    if (array_key_exists('success', $get_data) && $get_data['success']) {
                                        $get_sms_status = 'Delivered';
                                    } else {
                                        $get_sms_status = $get_data['message'];
                                    }
                                } else {
                                    $get_sms_status = 'Submission Failed';
                                }
                            }

                            curl_close($ch);
                        } catch (Exception $ex) {
                            $get_sms_status = $ex->getMessage();
                        }

                        break;

                    case SendingServer::TYPE_TEXTGRID:
                        $parameters = [
                            'from' => $data['sender_id'],
                            'to'   => $phone,
                            'body' => $message,
                        ];

                        try {
                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

                            $headers   = [];
                            $headers[] = 'Authorization: Basic ' . base64_encode("$sending_server->account_sid:$sending_server->auth_token");
                            $headers[] = 'Content-Type: application/json';
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                            $result = curl_exec($ch);
                            curl_close($ch);

                            $result = json_decode($result, true);

                            if (is_array($result) && array_key_exists('status', $result)) {
                                if ($result['status'] == 'sent') {
                                    $get_sms_status = 'Delivered';
                                } else if ($result['status'] == 'failed') {
                                    $get_sms_status = $result['error_message'];
                                } else {
                                    $get_sms_status = $result['status'];
                                }
                            } else {
                                $get_sms_status = 'Failed';
                            }

                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_TOPYING:
                        $parameters = [
                            'account'  => $sending_server->c1,
                            'password' => $sending_server->password,
                            'numbers'  => $phone,
                            'content'  => $message,
                            'smstype'  => 0,
                        ];

                        if (isset($data['sender_id'])) {
                            $parameters['sender'] = $data['sender_id'];
                        }

                        $sending_url = $gateway_url . '?' . http_build_query($parameters);

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $sending_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);

                            $result = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $get_data = json_decode($result, true);

                                if (is_array($get_data) && array_key_exists('status', $get_data)) {

                                    if ($get_data['status'] == '0' && array_key_exists('array', $get_data) && array_key_exists('0', $get_data['array'])) {
                                        $get_sms_status = 'Delivered|' . $get_data['array']['0']['1'];
                                    } else {
                                        $get_sms_status = $get_data['desc'];
                                    }
                                } else {
                                    $get_sms_status = 'Failed';
                                }
                            }

                            curl_close($ch);

                        } catch (Exception $ex) {
                            $get_sms_status = $ex->getMessage();
                        }

                        break;

                    case SendingServer::TYPE_MASCOM:

                        $convertedPhone = substr($phone, 3);

                        $parameters = [
                            'api_key'       => $sending_server->api_key,
                            'country_code'  => '267',
                            'phone_numbers' => [$convertedPhone],
                            'sender_id'     => $data['sender_id'],
                            'message'       => $message,
                        ];

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                                'Content-Type: application/json',
                            ]);
                            $result = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $get_data = json_decode($result, true);

                                if (isset($get_data) && is_array($get_data) && array_key_exists('success', $get_data)) {
                                    if ($get_data['success']) {
                                        $get_sms_status = 'Delivered';
                                    } else {
                                        $get_sms_status = $get_data['error'][0];
                                    }
                                } else {
                                    $get_sms_status = $result;
                                }
                            }

                            curl_close($ch);
                        } catch (Exception $exception) {
                            $get_sms_status = $exception->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_MP:
                        $parameters = [
                            'username' => $sending_server->username,
                            'password' => $sending_server->password,
                            'to'       => $phone,
                            'from'     => $data['sender_id'],
                            'text'     => $message,
                        ];

                        $sending_url = $gateway_url . '?' . http_build_query($parameters);

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $sending_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);

                            $get_sms_status = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {

                                // Define the regular expression pattern to match the 'id' value
                                $pattern = '/\bid:([0-9a-f-]+)\b/';

                                // Perform the regular expression match
                                if (preg_match($pattern, $get_sms_status, $matches)) {
                                    $get_sms_status = 'Delivered|' . $matches[1];
                                } else {
                                    $get_sms_status = 'Failed';
                                }
                            }

                            curl_close($ch);

                        } catch (Exception $ex) {
                            $get_sms_status = $ex->getMessage();
                        }

                        break;

                    case SendingServer::TYPE_BROADBASED:

                        $parameters = [
                            'senderId'           => $data['sender_id'],
                            'userId'             => $sending_server->c1,
                            'messageType'        => $sending_server->sms_type,
                            'recipients'         => [$phone],
                            'recipientInputMode' => 'manual_input',
                            'message'            => $message,
                            'deliveryReportUrl'  => route('dlr.broadbased'),
                        ];

                        try {

                            $headers = [
                                'Content-Type:application/json',
                                'Authorization: Bearer ' . $sending_server->api_secret,
                            ];

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            $response = curl_exec($ch);
                            curl_close($ch);

                            $get_response = json_decode($response, true);

                            if (isset($get_response) && is_array($get_response) && array_key_exists('status', $get_response) && array_key_exists('message', $get_response)) {
                                if ($get_response['status'] == '200' && array_key_exists('data', $get_response)) {
                                    $get_sms_status = 'Delivered|' . $get_response['data']['batchId'];
                                } else {
                                    $get_sms_status = $get_response['message'];
                                }
                            } else {
                                $get_sms_status = 'Unknown error';
                            }

                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_MOVIDER:

                        $parameters = [
                            'api_key'    => $sending_server->api_key,
                            'api_secret' => $sending_server->api_secret,
                            'to'         => $phone,
                            'text'       => $message,
                        ];

                        if (isset($data['sender_id'])) {
                            $parameters['from'] = $data['sender_id'];
                        }

                        try {
                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameters));

                            $headers   = [];
                            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
                            $headers[] = 'cache-control: no-cache';
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                            $result = curl_exec($ch);
                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $response = json_decode($result, true);

                                if (isset($response) && is_array($response)) {
                                    if (array_key_exists('remaining_balance', $response)) {
                                        $get_sms_status = 'Delivered';
                                    } else if (array_key_exists('error', $response)) {
                                        $get_sms_status = $response['error']['description'];
                                    } else {
                                        $get_sms_status = 'Invalid request';
                                    }
                                } else {
                                    $get_sms_status = 'Invalid request';
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_ZENDER:

                        $parameters = [
                            'secret'  => $sending_server->api_secret,
                            'mode'    => $sending_server->sms_type,
                            'phone'   => $phone,
                            'message' => $message,
                        ];

                        if ($sending_server->sms_type == 'devices') {
                            $parameters['device'] = $sending_server->device_id;
                            $parameters['sim']    = $sending_server->c2;
                        } else {
                            $parameters['gateway'] = $sending_server->c1;
                        }

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);
                        $result = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status = curl_error($ch);
                        } else {
                            $get_data = json_decode($result, true);
                            if (isset($get_data) && is_array($get_data) && array_key_exists('status', $get_data) && array_key_exists('message', $get_data)) {
                                if ($get_data['status'] == 200) {
                                    $get_sms_status = 'Delivered';
                                } else {
                                    $get_sms_status = $get_data['message'];
                                }
                            } else {
                                $get_sms_status = 'Failed';
                            }
                        }
                        curl_close($ch);
                        break;

                    case SendingServer::TYPE_EKOSMS:

                        $parameters = [
                            'username' => $sending_server->username,
                            'password' => $sending_server->password,
                            'msisdn'   => '+' . $phone,
                            'msg'      => $message,
                        ];

                        if (isset($data['sender_id'])) {
                            $parameters['sender'] = $data['sender_id'];
                        }

                        if ($sms_type == 'unicode') {
                            $parameters['allowunicode'] = true;
                        }

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameters));
                        $result = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status = curl_error($ch);
                        } else {
                            $get_data = json_decode($result, true);

                            if (isset($get_data) && is_array($get_data) && array_key_exists('data', $get_data) && array_key_exists('code', $get_data)) {
                                if ($get_data['code'] == 1002 && $get_data['data'] != null) {
                                    $get_sms_status = 'Delivered';
                                } else {
                                    $get_sms_status = $get_data['message'];
                                }
                            } else {
                                $get_sms_status = 'Failed';
                            }
                        }
                        curl_close($ch);
                        break;

                    case SendingServer::TYPE_ALDEAMO:

                        $convertedPhone = substr($phone, 2);

                        $parameters = [
                            'country'      => $sending_server->c1,
                            'message'      => $message,
                            'adresseeList' => [
                                [
                                    'mobile' => $convertedPhone,
                                ],
                            ],
                        ];

                        try {
                            $ch      = curl_init();
                            $headers = [
                                'Content-Type:application/json',
                                'Authorization:Basic ' . base64_encode("$sending_server->username:$sending_server->password"),
                            ];
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            $response = curl_exec($ch);
                            curl_close($ch);

                            $get_data = json_decode($response, true);

                            if (isset($get_data) && is_array($get_data) && array_key_exists('status', $get_data)) {
                                if ($get_data['status'] == '1') {
                                    $get_sms_status = 'Delivered';
                                } else {
                                    $get_sms_status = $get_data['reason'];
                                }
                            } else {
                                $get_sms_status = 'Failed';
                            }
                        } catch (Exception $exception) {
                            $get_sms_status = $exception->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_TONKRA:

                        $parameters = [
                            'client'   => $sending_server->c1,
                            'password' => $sending_server->password,
                            'from'     => $data['sender_id'],
                            'phone'    => $phone,
                            'text'     => $message,
                        ];

                        $sending_url = $gateway_url . '?' . http_build_query($parameters);

                        try {
                            $response = simplexml_load_file($sending_url);

                            if (isset($response->sms)) {
                                $get_sms_status = 'Delivered';
                            } else if (isset($response->error)) {
                                $get_sms_status = (string) $response->error;
                            } else {
                                $get_sms_status = 'Failed';
                            }

                        } catch (Exception $exception) {
                            $get_sms_status = $exception->getMessage();
                        }

                        break;

                    case SendingServer::TYPE_SMSDENVER:

                        $parameters = [
                            'auth'     => $sending_server->auth_token,
                            'receiver' => $phone,
                            'sender'   => $data['sender_id'],
                            'text'     => $message,
                        ];

                        if ($sms_type == 'unicode') {
                            $parameters['dcs'] = 'UCS';
                        } else {
                            $parameters['dcs'] = 'SMS';
                        }

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                                'Content-Type: application/json',
                            ]);
                            $result = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $get_data = json_decode($result, true);

                                if (isset($get_data) && is_array($get_data)) {
                                    if (array_key_exists('msgId', $get_data)) {
                                        $get_sms_status = 'Delivered|' . $get_data['msgId'];
                                    } else if (array_key_exists('error', $get_data)) {
                                        $get_sms_status = $get_data['error']['message'];
                                    } else {
                                        $get_sms_status = 'Failed';
                                    }
                                } else {
                                    $get_sms_status = $result;
                                }
                            }

                            curl_close($ch);
                        } catch (Exception $exception) {
                            $get_sms_status = $exception->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_WAUSMS:

                        $parameters = [
                            'to'   => [$phone],
                            'from' => $data['sender_id'],
                            'text' => $message,
                        ];

                        $headers = [
                            'Content-Type:application/json',
                            'Authorization:Basic ' . base64_encode("$sending_server->username:$sending_server->password"),
                        ];

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            $result = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $get_data = json_decode($result, true);

                                if (isset($get_data) && is_array($get_data)) {
                                    if (array_key_exists('error', $get_data) && array_key_exists('description', $get_data['error'])) {
                                        $get_sms_status = $get_data['error']['description'];
                                    } else {
                                        $get_sms_status = 'Delivered';
                                    }
                                } else {
                                    $get_sms_status = $result;
                                }
                            }

                            curl_close($ch);
                        } catch (Exception $exception) {
                            $get_sms_status = $exception->getMessage();
                        }
                        break;


                    case SendingServer::TYPE_DIDWW:

                        $parameters = [
                            'data' => [
                                'type'       => 'outbound_messages',
                                'attributes' => [
                                    'destination' => $phone,
                                    'content'     => $message,
                                ],
                            ],
                        ];

                        if (isset($data['sender_id'])) {
                            $parameters['data']['attributes']['source'] = $data['sender_id'];
                        }

                        try {
                            $ch      = curl_init();
                            $headers = [
                                'Content-Type: application/vnd.api+json',
                                'Authorization:Basic ' . base64_encode("$sending_server->username:$sending_server->password"),
                            ];
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            $response = curl_exec($ch);
                            curl_close($ch);

                            $get_data = json_decode($response, true);

                            if (is_array($get_data)) {
                                if (array_key_exists('data', $get_data)) {
                                    $get_sms_status = 'Delivered';
                                } else if (array_key_exists('errors', $get_data) && array_key_exists('0', $get_data['errors'])) {
                                    $get_sms_status = $get_data['errors']['0']['detail'];
                                } else {
                                    $get_sms_status = 'Failed';
                                }
                            } else {
                                $get_sms_status = $response;
                            }
                        } catch (Exception $exception) {
                            $get_sms_status = $exception->getMessage();
                        }
                        break;


                    case SendingServer::TYPE_4JAWALY:

                        $parameters = [
                            "messages" => [
                                [
                                    "text"    => $message,
                                    "numbers" => [$phone],
                                    "sender"  => $data['sender_id'],
                                ],
                            ],
                        ];


                        try {
                            $ch      = curl_init();
                            $headers = [
                                "Accept: application/json",
                                "Content-Type: application/json",
                                'Authorization:Basic ' . base64_encode("$sending_server->api_key:$sending_server->api_secret"),
                            ];
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            $response    = curl_exec($ch);
                            $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                            curl_close($ch);

                            $response_json = json_decode($response, true);

                            if ($status_code == 200) {
                                $get_sms_status = $response_json["messages"][0]["err_text"] ?? 'Delivered|' . $response_json["job_id"];
                            } else if ($status_code == 400) {
                                $get_sms_status = $response_json["message"];
                            } else if ($status_code == 422) {
                                $get_sms_status = "The message text is empty";
                            } else {
                                $get_sms_status = "Blocked by Cloudflare: $status_code";
                            }
                        } catch (Exception $exception) {
                            $get_sms_status = $exception->getMessage();
                        }
                        break;


                    case SendingServer::TYPE_SMSGATEWAYHUB:


                        $parameters = [
                            'User'     => $sending_server->username,
                            'Password' => $sending_server->password,
                            'channel'  => $sending_server->sms_type,
                            'senderid' => $data['sender_id'],
                            'number'   => $phone,
                            'text'     => $message,
                            'flashsms' => '0',
                        ];

                        if ($sms_type == 'unicode') {
                            $parameters['DCS'] = 8;
                        } else {
                            $parameters['DCS'] = 0;
                        }

                        if (isset($sending_server->route)) {
                            $parameters['route'] = $sending_server->route;
                        }

                        if (isset($data['dlt_entity_id'])) {
                            $parameters['EntityId'] = $data['dlt_entity_id'];
                        }

                        if (isset($data['dlt_template_id'])) {
                            $parameters['dlttemplateid'] = $data['dlt_template_id'];
                        }

                        $headers = [
                            "Accept: application/json",
                            "Content-Type: application/json",
                        ];

                        $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                        try {
                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            $result = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {

                                $get_data = json_decode($result, true);

                                if (isset($get_data) && is_array($get_data) && array_key_exists('ErrorCode', $get_data) && array_key_exists('ErrorMessage', $get_data)) {
                                    if ($get_data['ErrorCode'] == '000') {
                                        $get_sms_status = 'Delivered';
                                    } else {
                                        $get_sms_status = $get_data['ErrorMessage'];
                                    }
                                } else {
                                    $get_sms_status = $result;
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_SLINGTRUECALLER:
                    case SendingServer::TYPE_SLING:

                        $parameters = [
                            'message' => $message,
                            'sender'  => $data['sender_id'],
                            'to'      => $phone,
                        ];

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'Authorization: Bearer ' . $sending_server->api_token,
                            'Content-Type: application/json',
                            'Accept: application/json',
                        ]);

                        $response = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status  = curl_error($ch);
                            $customer_status = 'Rejected';
                        } else {

                            $get_response = json_decode($response, true);

                            if (is_array($get_response) && array_key_exists('status', $get_response)) {
                                if ($get_response['status'] == 'success') {
                                    $get_sms_status = $customer_status = 'Delivered';
                                } else {
                                    $get_sms_status  = $get_response['details'];
                                    $customer_status = 'Rejected';
                                }

                            } else {
                                $get_sms_status = $response;
                            }
                        }

                        curl_close($ch);
                        break;

                    case SendingServer::TYPE_SLEENGSHORT:

                        $parameters = [
                            'msg'        => $message,
                            'sender_id'  => $data['sender_id'],
                            'recipients' => $phone,
                        ];

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'x-api-key: ' . $sending_server->api_key,
                            'Content-Type: application/json',
                            'Accept: application/json',
                            "cache-control: no-cache",
                        ]);

                        $response = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status  = curl_error($ch);
                            $customer_status = 'Rejected';
                        } else {

                            $get_response = json_decode($response, true);

                            if (is_array($get_response) && array_key_exists('status', $get_response)) {
                                if ($get_response['status'] == 'ok') {
                                    $get_sms_status = $customer_status = 'Delivered';
                                } else {
                                    $get_sms_status  = $get_response['status'];
                                    $customer_status = 'Rejected';
                                }

                            } else {
                                $get_sms_status = $response;
                            }
                        }
                        curl_close($ch);
                        break;

                    case SendingServer::TYPE_EMISRI:

                        $parameters = [
                            'username'    => $sending_server->username,
                            'apiId'       => $sending_server->api_key,
                            'text'        => $message,
                            'source'      => $data['sender_id'],
                            'destination' => $phone,
                            'json'        => true,
                        ];


                        $headers = [
                            "Accept: application/json",
                            "Content-Type: application/json",
                            'X-Access-Token: ' . $sending_server->api_token,
                        ];

                        $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                        try {
                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            $get_sms_status = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status  = curl_error($ch);
                                $customer_status = 'Rejected';
                            } else {
                                if ($get_sms_status == 'MESSAGE_SUCCESS') {
                                    $get_sms_status = $customer_status = 'Delivered';
                                } else {
                                    $customer_status = 'Rejected';
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status  = $e->getMessage();
                            $customer_status = 'Rejected';
                        }
                        break;

                    case SendingServer::TYPE_MTN:

                        $params = [
                            'grant_type' => 'client_credentials',
                        ];

                        $curl = curl_init();

                        curl_setopt_array($curl, [
                            CURLOPT_URL            => 'https://api.mtn.com/v1/oauth/access_token/accesstoken',
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_ENCODING       => "",
                            CURLOPT_MAXREDIRS      => 10,
                            CURLOPT_TIMEOUT        => 30,
                            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                            CURLOPT_CUSTOMREQUEST  => "POST",
                            CURLOPT_POSTFIELDS     => http_build_query($params),
                            CURLOPT_HTTPHEADER     => [
                                'Authorization: Basic ' . base64_encode($sending_server->c1 . ':' . $sending_server->api_secret),
                            ],
                        ]);

                        $response = curl_exec($curl);
                        $err      = curl_error($curl);

                        curl_close($curl);

                        if ($err) {
                            $get_sms_status  = $err;
                            $customer_status = 'Rejected';
                        } else {
                            $response = json_decode($response, true);

                            if (isset($response) && is_array($response) && array_key_exists('access_token', $response)) {
                                $access_token = $response['access_token'];

                                $parameters = [
                                    "senderAddress"          => $data['sender_id'],
                                    "receiverAddress"        => [$phone],
                                    "message"                => $message,
                                    "clientCorrelatorId"     => $sending_server->c2,
                                    "serviceCode"            => $sending_server->c3, // or "131"
                                    "requestDeliveryReceipt" => false,
                                ];

                                $sendSMS = json_encode($parameters);
                                $curl    = curl_init();

                                curl_setopt_array($curl, [
                                    CURLOPT_URL            => $gateway_url,
                                    CURLOPT_RETURNTRANSFER => true,
                                    CURLOPT_ENCODING       => '',
                                    CURLOPT_MAXREDIRS      => 10,
                                    CURLOPT_TIMEOUT        => 30,
                                    CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                                    CURLOPT_CUSTOMREQUEST  => 'POST',
                                    CURLOPT_POSTFIELDS     => $sendSMS,
                                    CURLOPT_HTTPHEADER     => [
                                        'Authorization: Bearer ' . $access_token,
                                        'content-type: application/json',
                                    ],
                                ]);

                                $response = curl_exec($curl);
                                $err      = curl_error($curl);

                                curl_close($curl);

                                if ($err) {
                                    $get_sms_status  = $err;
                                    $customer_status = 'Rejected';
                                } else {
                                    $response = json_decode($response, true);

                                    if (isset($response) && is_array($response) && array_key_exists('statusCode', $response) && array_key_exists('statusMessage', $response)) {
                                        if ($response['statusCode'] == '0000') {
                                            $get_sms_status = $customer_status = 'Delivered';
                                        } else {
                                            $get_sms_status  = $response['statusMessage'];
                                            $customer_status = 'Failed';
                                        }
                                    } else {
                                        $get_sms_status  = 'Invalid Request';
                                        $customer_status = 'Rejected';
                                    }
                                }

                            } else {
                                $get_sms_status  = 'Access token not found';
                                $customer_status = 'Rejected';
                            }
                        }

                        break;


                    case SendingServer::TYPE_MOBILETEXTALERTS:
                        $parameters = [
                            'subscribers' => [
                                $phone,
                            ],
                            'message'     => $message,
                        ];

                        try {

                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_POST, true);

                            $headers   = [];
                            $headers[] = "Authorization: Bearer $sending_server->api_token";
                            $headers[] = 'Content-Type: application/json';
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                            $result = curl_exec($ch);
                            curl_close($ch);

                            $result = json_decode($result, true);

                            if (is_array($result) && array_key_exists('data', $result)) {
                                $get_sms_status = $customer_status = 'Delivered';
                            } else {
                                $get_sms_status  = $result['message'];
                                $customer_status = 'Rejected';
                            }
                        } catch (Exception $e) {
                            $get_sms_status  = $e->getMessage();
                            $customer_status = 'Rejected';
                        }
                        break;


                    case SendingServer::TYPE_MSEGAT:

                        $parameters = [
                            'userName'   => $sending_server->username,
                            'apiKey'     => $sending_server->api_key,
                            'numbers'    => $phone,
                            'userSender' => $data['sender_id'],
                            'msg'        => $message,
                        ];

                        if ($sms_type == 'unicode') {
                            $parameters['msgEncoding'] = 'UTF8';
                        } else {
                            $parameters['msgEncoding'] = 'windows-1256';
                        }

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'Content-Type: application/json',
                            'Accept: application/json',
                            "cache-control: no-cache",
                        ]);

                        $response = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status  = curl_error($ch);
                            $customer_status = 'Rejected';
                        } else {

                            $get_response = json_decode($response, true);

                            if (is_array($get_response) && array_key_exists('code', $get_response)) {
                                if ($get_response['code'] == '1' || $get_response['code'] == 'M0000') {
                                    $get_sms_status = $customer_status = 'Delivered';
                                } else {
                                    $get_sms_status  = $get_response['message'];
                                    $customer_status = 'Rejected';
                                }

                            } else {
                                $get_sms_status = $response;
                            }
                        }
                        curl_close($ch);
                        break;


                    case SendingServer::TYPE_LABSMOBILE:

                        $phone = str_replace(['+', '(', ')', '-', ' '], '', $phone);

                        $parameters = [
                            'recipient' => [
                                [
                                    'msisdn' => $phone,
                                ],
                            ],
                            'message'   => $message,
                        ];

                        if (isset($data['sender_id'])) {
                            $parameters['tpoa'] = $data['sender_id'];
                        }

                        if ($sms_type == 'unicode') {
                            $parameters['ucs2'] = 1;
                        }

                        try {
                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

                            $headers   = [];
                            $headers[] = 'Authorization: Basic ' . base64_encode("$sending_server->username:$sending_server->api_token");
                            $headers[] = 'Content-Type: application/json';
                            $headers[] = 'Cache-Control: no-cache';
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                            $result = curl_exec($ch);
                            curl_close($ch);

                            $result = json_decode($result, true);

                            if (is_array($result) && array_key_exists('code', $result)) {
                                if ($result['code'] == 0) {
                                    $get_sms_status = $customer_status = 'Delivered';
                                } else {
                                    $get_sms_status  = $result['message'];
                                    $customer_status = 'Rejected';
                                }
                            } else {
                                $get_sms_status  = 'Invalid request';
                                $customer_status = 'Rejected';
                            }

                        } catch (Exception $e) {
                            $get_sms_status  = $e->getMessage();
                            $customer_status = 'Rejected';
                        }
                        break;

                    case SendingServer::TYPE_SMSLIVE247:

                        $parameters = [
                            'senderID'     => $data['sender_id'],
                            'mobileNumber' => $phone,
                            'messageText'  => $message,
                        ];

                        $headers = [
                            "Authorization: $sending_server->api_key",
                            'Content-Type: application/json',
                            'Accept: application/json',
                        ];

                        $ch = curl_init();

                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_POST, 1);

                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                        $result = curl_exec($ch);
                        if (curl_errno($ch)) {
                            $get_sms_status  = curl_error($ch);
                            $customer_status = 'Rejected';
                        } else {
                            $response = json_decode($result, true);

                            if (is_array($response) && array_key_exists('messageID', $response)) {
                                $get_sms_status = $customer_status = 'Delivered';
                            } else {
                                $get_sms_status  = 'Unknown Error';
                                $customer_status = 'Failed';
                            }
                        }
                        curl_close($ch);
                        break;

                    case SendingServer::TYPE_LINKMOBILITY:

                        $curl = curl_init();

                        curl_setopt_array($curl, [
                            CURLOPT_URL            => $gateway_url . '/auth/token',
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_ENCODING       => '',
                            CURLOPT_MAXREDIRS      => 10,
                            CURLOPT_TIMEOUT        => 30,
                            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                            CURLOPT_CUSTOMREQUEST  => 'POST',
                            CURLOPT_POSTFIELDS     => "grant_type=client_credentials&client_id=$sending_server->c1&client_secret=$sending_server->api_secret",
                            CURLOPT_HTTPHEADER     => [
                                'content-type: application/x-www-form-urlencoded',
                            ],
                        ]);

                        $response = curl_exec($curl);
                        $err      = curl_error($curl);

                        curl_close($curl);

                        if ($err) {
                            $get_sms_status  = $err;
                            $customer_status = 'Rejected';
                        } else {
                            $response = json_decode($response, true);

                            if (isset($response) && is_array($response) && array_key_exists('access_token', $response)) {
                                $access_token = $response['access_token'];

                                $parameters = [
                                    'source'            => $data['sender_id'],
                                    'destination'       => '+' . $phone,
                                    'userData'          => $message,
                                    'platformId'        => $sending_server->c2,
                                    'platformPartnerId' => $sending_server->c3,
                                ];

                                $sendSMS = json_encode($parameters);
                                $curl    = curl_init();

                                curl_setopt_array($curl, [
                                    CURLOPT_URL            => $gateway_url . '/sms/send',
                                    CURLOPT_RETURNTRANSFER => true,
                                    CURLOPT_ENCODING       => '',
                                    CURLOPT_MAXREDIRS      => 10,
                                    CURLOPT_TIMEOUT        => 30,
                                    CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                                    CURLOPT_CUSTOMREQUEST  => 'POST',
                                    CURLOPT_POSTFIELDS     => $sendSMS,
                                    CURLOPT_HTTPHEADER     => [
                                        'Authorization: Bearer ' . $access_token,
                                        'content-type: application/json',
                                    ],
                                ]);

                                $response = curl_exec($curl);
                                $err      = curl_error($curl);

                                curl_close($curl);

                                if ($err) {
                                    $get_sms_status  = $err;
                                    $customer_status = 'Rejected';
                                } else {
                                    $get_data = json_decode($response, true);

                                    if (isset($get_data) && is_array($get_data) && array_key_exists('resultCode', $get_data)) {
                                        if ($get_data['resultCode'] == 1005 || $get_data['resultCode'] == 1007) {
                                            $get_sms_status = $customer_status = 'Delivered';
                                        } else {
                                            $get_sms_status = $customer_status = $get_data['description'];
                                        }
                                    } else {
                                        $get_sms_status  = 'Unknown Error';
                                        $customer_status = 'Rejected';
                                    }

                                }
                            } else {
                                $get_sms_status  = 'Access token not found';
                                $customer_status = 'Rejected';
                            }
                        }

                        break;

                    case SendingServer::TYPE_SMSCOUNTRY:

                        $parameters = [
                            "Text"     => "$message",
                            "Number"   => "$phone",
                            "SenderId" => "{$data['sender_id']}",
                            "Tool"     => "API",
                        ];

                        try {
                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

                            $headers   = [];
                            $headers[] = 'Authorization: Basic ' . base64_encode("$sending_server->auth_key:$sending_server->auth_token");
                            $headers[] = 'Content-Type: application/json';
                            $headers[] = 'Cache-Control: no-cache';
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                            $result = curl_exec($ch);

                            curl_close($ch);

                            $result = json_decode($result, true);

                            if (is_array($result) && array_key_exists('Success', $result) && array_key_exists('Message', $result)) {
                                if ($result['Success']) {
                                    $get_sms_status = $customer_status = 'Delivered|' . $result['MessageUUID'];
                                } else {
                                    $get_sms_status  = $result['Message'];
                                    $customer_status = 'Rejected';
                                }
                            } else {
                                $get_sms_status  = 'Invalid request';
                                $customer_status = 'Rejected';
                            }

                        } catch (Exception $e) {
                            $get_sms_status  = $e->getMessage();
                            $customer_status = 'Rejected';
                        }
                        break;

                    case SendingServer::TYPE_TEXTBEE:

                        $gateway_url = $gateway_url . "/gateway/devices/$sending_server->device_id/sendSMS";

                        $parameters = [
                            'recipients' => ['+' . $phone],
                            'message'    => $message,
                        ];

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'x-api-key: ' . $sending_server->api_key,
                            'Content-Type: application/json',
                            'Accept: application/json',
                        ]);

                        $result = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status  = curl_error($ch);
                            $customer_status = 'Rejected';
                        } else {
                            $response = json_decode($result, true);

                            if (is_array($response)) {
                                if (array_key_exists('data', $response)) {
                                    $get_sms_status = $customer_status = 'Delivered';
                                } else if (array_key_exists('error', $response)) {
                                    $get_sms_status  = $response['error'];
                                    $customer_status = 'Rejected';
                                } else {
                                    $get_sms_status = $customer_status = 'Rejected';
                                }
                            } else {
                                $get_sms_status  = 'Unknown Error';
                                $customer_status = 'Failed';
                            }
                        }
                        curl_close($ch);

                        break;


                    case SendingServer::TYPE_800COM:

                        $parameters = [
                            'recipient' => '+' . $phone,
                            'sender'    => '+' . $data['sender_id'],
                            'message'   => $message,
                        ];

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'Authorization: Bearer ' . $sending_server->api_token,
                            'Content-Type: application/json',
                            'Accept: application/json',
                        ]);

                        $response = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status  = curl_error($ch);
                            $customer_status = 'Rejected';
                        } else {

                            $get_response = json_decode($response, true);

                            if (is_array($get_response)) {

                                if (array_key_exists('data', $get_response)) {
                                    $get_sms_status = $customer_status = 'Delivered';
                                } else if (array_key_exists('errors', $get_response) && array_key_exists('message', $get_response)) {
                                    $get_sms_status  = $get_response['message'];
                                    $customer_status = 'Rejected';
                                } else {
                                    $get_sms_status = $customer_status = 'Rejected';
                                }

                            } else {
                                $get_sms_status  = $response;
                                $customer_status = 'Rejected';
                            }
                        }

                        curl_close($ch);
                        break;


                    case SendingServer::TYPE_PHONECOM:

                        $parameters = [
                            'to'   => [
                                $phone,
                            ],
                            'from' => $data['sender_id'],
                            'text' => $message,
                        ];

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'Authorization: Bearer ' . $sending_server->api_token,
                            'Content-Type: application/json',
                            'Accept: application/json',
                        ]);

                        $response = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status  = curl_error($ch);
                            $customer_status = 'Rejected';
                        } else {

                            $get_response = json_decode($response, true);

                            if (is_array($get_response)) {

                                if (array_key_exists('error_description', $get_response)) {
                                    $get_sms_status  = $get_response['error_description'];
                                    $customer_status = 'Rejected';
                                } else {
                                    $get_sms_status = $customer_status = 'Delivered';
                                }

                            } else {
                                $get_sms_status  = $response;
                                $customer_status = 'Rejected';
                            }
                        }

                        curl_close($ch);
                        break;


                    case SendingServer::TYPE_AFFILIATESMS:

                        $parameters = [
                            'originator'  => $data['sender_id'],
                            'destination' => 'phone',
                            'msisdn'      => $phone,
                            'body'        => $message,
                            'reference'   => uniqid(),
                        ];

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_ENCODING, '');
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'X-API-KEY: ' . $sending_server->api_token,
                            'Content-Type: application/json',
                            'Accept: application/json',
                        ]);

                        $response = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status  = curl_error($ch);
                            $customer_status = 'Rejected';
                        } else {

                            $get_response = json_decode($response, true);

                            if (is_array($get_response)) {

                                if (array_key_exists('error', $get_response)) {
                                    $get_sms_status  = $get_response['errorDescription'];
                                    $customer_status = 'Rejected';
                                } else {
                                    $get_sms_status = $customer_status = 'Delivered';
                                }

                            } else {
                                $get_sms_status = $response;
                            }
                        }

                        curl_close($ch);
                        break;

                    case SendingServer::TYPE_SMSEEDGE:

                        $parameters = [
                            'api_key'       => $sending_server->api_key,
                            'from'          => $data['sender_id'],
                            'to'            => $phone,
                            'text'          => $message,
                            'shorten_url'   => 1,
                            'smart_routing' => 0,
                            'transactional' => 0,
                        ];

                        $sending_url = $gateway_url . '?' . http_build_query($parameters);

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $sending_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'accept: application/json',
                        ]);

                        $response = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status  = curl_error($ch);
                            $customer_status = 'Rejected';
                        } else {

                            $get_response = json_decode($response, true);

                            if (is_array($get_response) && array_key_exists('0', $get_response) && array_key_exists('success', $get_response['0'])) {

                                if ($get_response['0']['success']) {
                                    $get_sms_status = $customer_status = 'Delivered';
                                } else {
                                    $get_sms_status  = $get_response[0]['errors'][0];
                                    $customer_status = 'Rejected';
                                }

                            } else {
                                $get_sms_status  = $response;
                                $customer_status = 'Rejected';
                            }
                        }

                        curl_close($ch);
                        break;

                    case SendingServer::TYPE_DOTGO:

                        $unique_id = uniqid();

                        $parameters = [
                            'id'           => $unique_id,
                            'to'           => [$phone],
                            'body'         => $message,
                            'callback_url' => route('dlr.dotgo'),
                        ];

                        if (isset($data['sender_id'])) {

                            $sender_id = str_replace(['(', ')', '+', '-', ' '], '', $data['sender_id']);

                            if (is_numeric($sender_id)) {
                                $parameters['from'] = $sender_id;
                            } else {
                                $parameters['sender_mask'] = $data['sender_id'];
                            }
                        }

                        if ($sms_type == 'unicode') {
                            $parameters['unicode'] = 1;
                        }


                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_ENCODING, '');
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'Authorization: ' . $sending_server->api_token,
                            'Content-Type: application/json',
                            'Accept: application/json',
                        ]);

                        $response = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status  = curl_error($ch);
                            $customer_status = 'Rejected';
                        } else {

                            $get_response = json_decode($response, true);

                            if (is_array($get_response) && array_key_exists('status', $get_response)) {

                                if ($get_response['status'] == 'ok' || $get_response['status'] == 'accepted') {
                                    $get_sms_status  = 'Delivered|' . $unique_id;
                                    $customer_status = 'Delivered';
                                } else {
                                    $get_sms_status  = $get_response['error_reason'];
                                    $customer_status = 'Rejected';
                                }

                            } else {
                                $get_sms_status = $response;
                            }
                        }

                        curl_close($ch);

                        break;

                    case SendingServer::TYPE_TELUCOMAPIS:

                        $parameters = [
                            'token'        => $sending_server->api_token,
                            'from'         => $data['sender_id'],
                            'to'           => $phone,
                            'message_text' => $message,
                        ];

                        $headers = [
                            "Accept: application/json",
                            "Content-Type: application/json",
                        ];

                        $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                        try {
                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            $result = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status  = curl_error($ch);
                                $customer_status = 'Rejected';
                            } else {

                                $response = json_decode($result, true);


                                if (json_last_error() == JSON_ERROR_NONE) {
                                    if (isset($response) && is_array($response) && array_key_exists('status', $response) && array_key_exists('status-details', $response)) {
                                        if ($response['status'] == 'success') {
                                            $get_sms_status = $customer_status = 'Delivered';
                                        } else {
                                            $get_sms_status  = $response['status-details'];
                                            $customer_status = 'Failed';
                                        }
                                    } else {
                                        $get_sms_status  = $result;
                                        $customer_status = 'Failed';
                                    }
                                } else {
                                    $get_sms_status  = trim($result);
                                    $customer_status = 'Failed';
                                }

                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status  = $e->getMessage();
                            $customer_status = 'Rejected';
                        }
                        break;

                    case SendingServer::TYPE_NOTIFYRE:

                        $parameters = [
                            'From'               => '+' . $data['sender_id'],
                            'Body'               => $message,
                            'Recipients'         => [
                                [
                                    'type'  => "mobile_number",
                                    'value' => '+' . $phone,
                                ],
                            ],
                            'AddUnsubscribeLink' => true,
                        ];

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                            curl_setopt($ch, CURLOPT_ENCODING, '');
                            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                                'x-api-token: ' . $sending_server->api_token,
                                'Content-Type: application/json',
                                'Accept: application/json',
                            ]);

                            $response = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status  = curl_error($ch);
                                $customer_status = 'Rejected';
                            } else {

                                $get_response = json_decode($response, true);

                                if (is_array($get_response) && array_key_exists('success', $get_response) && array_key_exists('message', $get_response)) {
                                    if ($get_response['success']) {
                                        $get_sms_status = $customer_status = 'Delivered';
                                    } else {
                                        $get_sms_status  = $get_response['message'];
                                        $customer_status = 'Failed';
                                    }
                                }

                            }

                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status  = $e->getMessage();
                            $customer_status = 'Rejected';
                        }
                        break;

                    case SendingServer::TYPE_NOBELSMS:

                        $parameters = [
                            'to'       => $phone,
                            'from'     => $data['sender_id'],
                            'message'  => $message,
                            'username' => $sending_server->username,
                            'password' => $sending_server->password,
                        ];

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                            curl_setopt($ch, CURLOPT_POST, true);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                            $result = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status  = curl_error($ch);
                                $customer_status = 'Rejected';
                            } else {

                                $get_data = json_decode($result, true);

                                if (isset($get_data) && is_array($get_data)) {
                                    if (array_key_exists('error_message', $get_data)) {
                                        $get_sms_status  = $get_data['error_message'];
                                        $customer_status = 'Failed';
                                    } else {
                                        $get_sms_status = $customer_status = 'Delivered';
                                    }
                                } else {
                                    $get_sms_status = $result;
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $ex) {
                            $get_sms_status = $ex->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_FORDEMO:
                        $get_sms_status = $customer_status = 'Delivered';
                        break;


                    case SendingServer::TYPE_SKYELINE:


                        $parameters = [
                            'username'   => $sending_server->username,
                            'password'   => $sending_server->password,
                            'port'       => $sending_server->port,
                            'recipients' => $phone,
                            'sms'        => $message,
                        ];


                        try {

                            $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

                            $result = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status  = curl_error($ch);
                                $customer_status = 'Rejected';
                            } else {

                                $get_data = json_decode($result, true);

                                if (isset($get_data) && is_array($get_data) && array_key_exists('code', $get_data) && array_key_exists('reason', $get_data)) {
                                    if ($get_data['code'] == 0) {
                                        $get_sms_status = $customer_status = 'Delivered';
                                    } else {
                                        $get_sms_status  = $get_data['reason'];
                                        $customer_status = 'Failed';
                                    }

                                } else {
                                    $get_sms_status  = $result;
                                    $customer_status = 'Rejected';
                                }
                            }
                            curl_close($ch);

                        } catch (Exception $e) {
                            $get_sms_status  = $e->getMessage();
                            $customer_status = 'Rejected';
                        }
                        break;

                    case SendingServer::TYPE_OURSMS:
                        $parameters = [
                            'token' => $sending_server->api_token,
                            'src'   => $data['sender_id'],
                            'dests' => $phone,
                            'body'  => $message,
                        ];

                        $response = Http::asForm()->post($gateway_url, $parameters);

                        if ($response->successful()) {
                            $get_sms_status = $customer_status = 'Delivered';
                        } else {
                            $get_sms_status  = $response->json()['message'];
                            $customer_status = 'Failed';
                        }
                        break;


                    case SendingServer::TYPE_BROADNET:

                        $parameters = [
                            'user' => $sending_server->username,
                            'pass' => $sending_server->password,
                            'sid'  => $data['sender_id'],
                            'mno'  => $phone,
                            'text' => $message,
                        ];

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                            curl_setopt($ch, CURLOPT_POST, true);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameters));
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                            $result = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status  = curl_error($ch);
                                $customer_status = 'Rejected';
                            } else {

                                $get_data = json_decode($result, true);

                                if (isset($get_data) && is_array($get_data) && array_key_exists('success', $get_data) && array_key_exists('message', $get_data)) {
                                    if ($get_data['success'] == "true") {
                                        $get_sms_status = $customer_status = 'Delivered';
                                    } else {
                                        $get_sms_status  = $get_data['message'];
                                        $customer_status = 'Failed';
                                    }
                                } else {
                                    $get_sms_status  = $result;
                                    $customer_status = 'Rejected';
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $ex) {
                            $get_sms_status  = $ex->getMessage();
                            $customer_status = 'Rejected';
                        }
                        break;


                    case SendingServer::TYPE_SEVENIO:

                        $parameters = [
                            'to'   => $phone,
                            'text' => $message,
                        ];

                        if (isset($data['sender_id'])) {
                            $parameters['from'] = $data['sender_id'];
                        }

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'x-api-key: ' . $sending_server->api_key,
                            'Content-Type: application/json',
                            'Accept: application/json',
                        ]);

                        $response = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status  = curl_error($ch);
                            $customer_status = 'Rejected';
                        } else {

                            $get_data = json_decode($response, true);

                            if (isset($get_data) && is_array($get_data) && array_key_exists('success', $get_data) && $get_data['success'] == "100") {
                                $get_sms_status = $customer_status = 'Delivered';
                            } else {

                                match (trim($response)) {
                                    '100' => $get_sms_status = $customer_status = 'Delivered',
                                    '101' => $get_sms_status = 'Sending to at least one recipient failed.',
                                    '201' => $get_sms_status = 'Sender invalid. A maximum of 11 alphanumeric or 16 numeric characters are permitted.',
                                    '202' => $get_sms_status = 'The recipient number is invalid.',
                                    '401' => $get_sms_status = 'Parameter text is too long',
                                    '402' => $get_sms_status = 'This SMS has already been sent within the last 180 seconds. You can deactivate this block in your account settings.',
                                    '403' => $get_sms_status = 'Maximum limit per day reached for this recipient number.',
                                    '500' => $get_sms_status = 'Account has too little credit to send this SMS.',
                                    '600' => $get_sms_status = 'An error has occurred during sending.',
                                    '900' => $get_sms_status = 'Authentication has failed.Invalid API key.',
                                    '901' => $get_sms_status = 'Verification of the signing hash failed.',
                                    '902' => $get_sms_status = 'The API key does not have access rights to this endpoint.',
                                    '903' => $get_sms_status = 'The IP from which the request was executed is not included.',
                                    default => $get_sms_status = $response
                                };
                            }

                        }
                        curl_close($ch);

                        break;


                    case SendingServer::TYPE_MNOTIFY:

                        $gateway_url = $gateway_url . '?key=' . $sending_server->api_key;

                        $parameters = [
                            'recipient' => [$phone],
                            'sender'    => $data['sender_id'],
                            'message'   => $message,
                        ];

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_ENCODING, '');
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'Content-Type: application/json',
                        ]);

                        $response = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status  = curl_error($ch);
                            $customer_status = 'Rejected';
                        } else {

                            $get_data = json_decode($response, true);

                            if (isset($get_data) && is_array($get_data)) {
                                if (array_key_exists('status', $get_data) && $get_data['status'] == 'success') {
                                    $get_sms_status = $customer_status = 'Delivered';
                                } else if (array_key_exists('error', $get_data)) {
                                    $get_sms_status  = $get_data['error'];
                                    $customer_status = 'Rejected';
                                } else {
                                    $get_sms_status  = $get_data['message'];
                                    $customer_status = 'Rejected';
                                }
                            } else {
                                $get_sms_status  = 'Provide empty response. Please contact with gateway provider.';
                                $customer_status = 'Rejected';
                            }
                        }

                        curl_close($ch);
                        break;

                    case SendingServer::TYPE_HUBTEL:


                        $parameters = [
                            "from"    => $data['sender_id'],
                            "to"      => $phone,
                            "content" => $message,

                        ];

                        $headers = [
                            'Content-Type: application/json',
                            'Authorization:Basic ' . base64_encode("$sending_server->username:$sending_server->password"),
                        ];

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        $response = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status  = curl_error($ch);
                            $customer_status = 'Rejected';
                        } else {

                            $get_data = json_decode($response, true);

                            if (isset($get_data) && is_array($get_data) && array_key_exists('responseCode', $get_data) && array_key_exists('message', $get_data)) {
                                if ($get_data['responseCode'] == 201) {
                                    $get_sms_status = $customer_status = 'Delivered';
                                } else {
                                    $get_sms_status  = $get_data['message'];
                                    $customer_status = 'Rejected';
                                }
                            } else {
                                $get_sms_status  = 'Provide empty response. Please contact with gateway provider.';
                                $customer_status = 'Rejected';
                            }
                        }

                        curl_close($ch);
                        break;


                    case SendingServer::TYPE_BEENET:


                        $parameters = [
                            'username' => $sending_server->username,
                            'password' => $sending_server->password,
                            'sender'   => $data['sender_id'],
                            'mobile'   => $phone,
                            'message'  => $message,
                        ];

                        if ($sms_type == 'unicode') {
                            $parameters['type'] = 'TEXT';
                        } else {
                            $parameters['type'] = 'UNICODE';
                        }


                        try {

                            $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

                            $get_sms_status = curl_exec($ch);


                            if (curl_errno($ch)) {
                                $get_sms_status  = curl_error($ch);
                                $customer_status = 'Rejected';
                            } else {
                                if (str_contains($get_sms_status, 'SUCCESS')) {
                                    $get_sms_status = $customer_status = 'Delivered';
                                } else {
                                    $customer_status = 'Failed';
                                }
                            }
                            curl_close($ch);

                        } catch (Exception $e) {
                            $get_sms_status  = $e->getMessage();
                            $customer_status = 'Rejected';
                        }
                        break;

                    case SendingServer::TYPE_SMSWORLDPRO:


                        $parameters = [
                            'api_key'   => $sending_server->api_key,
                            'api_token' => $sending_server->api_token,
                            'api_id'    => $sending_server->c1,
                            'sender_id' => $data['sender_id'],
                            'number'    => $phone,
                            'msj'       => $message,
                        ];


                        try {

                            $gateway_url = $gateway_url . '?envioApi&' . http_build_query($parameters);

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

                            $result = curl_exec($ch);


                            if (curl_errno($ch)) {
                                $get_sms_status  = curl_error($ch);
                                $customer_status = 'Rejected';
                            } else {

                                $get_data = json_decode($result, true);

                                if (isset($get_data) && is_array($get_data) && array_key_exists('ok', $get_data) && array_key_exists('msj', $get_data)) {
                                    if ($get_data['ok'] == "true") {
                                        $get_sms_status = $customer_status = 'Delivered';
                                    } else {
                                        $get_sms_status  = $get_data['msj'];
                                        $customer_status = 'Failed';
                                    }
                                } else {
                                    $get_sms_status  = $result;
                                    $customer_status = 'Rejected';
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status  = $e->getMessage();
                            $customer_status = 'Rejected';
                        }
                        break;

                    case SendingServer::TYPE_UNITEL:


                        $params = [
                            'grant_type' => 'client_credentials',
                        ];

                        $curl = curl_init();

                        curl_setopt_array($curl, [
                            CURLOPT_URL            => $auth_url,
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_ENCODING       => "",
                            CURLOPT_MAXREDIRS      => 10,
                            CURLOPT_SSL_VERIFYHOST => 0,
                            CURLOPT_SSL_VERIFYPEER => 0,
                            CURLOPT_TIMEOUT        => 30,
                            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                            CURLOPT_CUSTOMREQUEST  => "POST",
                            CURLOPT_POSTFIELDS     => http_build_query($params),
                            CURLOPT_HTTPHEADER     => [
                                'Authorization: Basic ' . base64_encode($sending_server->api_key . ':' . $sending_server->api_secret),
                            ],
                        ]);

                        $response = curl_exec($curl);
                        $err      = curl_error($curl);

                        curl_close($curl);

                        if ($err) {
                            $get_sms_status  = $err;
                            $customer_status = 'Rejected';
                        } else {
                            $response = json_decode($response, true);

                            if (isset($response) && is_array($response) && array_key_exists('access_token', $response)) {
                                $access_token = $response['access_token'];

                                $parameters = [
                                    'event-id'     => $sending_server->c1,
                                    'event-key'    => $phone,
                                    'event-params' => [
                                        'GPSPONE' => $message,
                                    ],
                                ];


                                $sendSMS = json_encode($parameters);
                                $curl    = curl_init();

                                curl_setopt_array($curl, [
                                    CURLOPT_URL            => $gateway_url,
                                    CURLOPT_RETURNTRANSFER => true,
                                    CURLOPT_ENCODING       => '',
                                    CURLOPT_MAXREDIRS      => 10,
                                    CURLOPT_TIMEOUT        => 30,
                                    CURLOPT_SSL_VERIFYHOST => 0,
                                    CURLOPT_SSL_VERIFYPEER => 0,
                                    CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                                    CURLOPT_CUSTOMREQUEST  => 'POST',
                                    CURLOPT_POSTFIELDS     => $sendSMS,
                                    CURLOPT_HTTPHEADER     => [
                                        'Authorization: Bearer ' . $access_token,
                                        'content-type: application/json',
                                    ],
                                ]);

                                $response = curl_exec($curl);
                                $err      = curl_error($curl);

                                curl_close($curl);

                                if ($err) {
                                    $get_sms_status  = $err;
                                    $customer_status = 'Rejected';
                                } else {
                                    $response = json_decode($response, true);

                                    if (isset($response) && is_array($response)) {
                                        if (array_key_exists('transaction-id', $response)) {
                                            $get_sms_status = $customer_status = 'Delivered';
                                        } else if (array_key_exists('description', $response)) {
                                            $get_sms_status  = $response['description'];
                                            $customer_status = 'Failed';
                                        } else {
                                            $get_sms_status  = 'Invalid Request';
                                            $customer_status = 'Rejected';
                                        }
                                    } else {
                                        $get_sms_status  = 'Invalid Request';
                                        $customer_status = 'Rejected';
                                    }
                                }

                            } else {
                                $get_sms_status  = 'Access token not found';
                                $customer_status = 'Rejected';
                            }
                        }

                        break;


                    case SendingServer::TYPE_TURKEYSMS:


                        $parameters = [
                            'api_key'       => $sending_server->api_key,
                            'title'         => $data['sender_id'],
                            'mobile'        => $phone,
                            'text'          => $message,
                            'report'        => 1,
                            'lang'          => 1,
                            'content_type'  => 1,
                            'response_type' => 'json',

                        ];


                        try {

                            $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

                            $response = curl_exec($ch);


                            if (curl_errno($ch)) {
                                $get_sms_status  = curl_error($ch);
                                $customer_status = 'Rejected';
                            } else {

                                $get_data = json_decode($response, true);

                                if (isset($get_data) && is_array($get_data) && array_key_exists('result', $get_data) && array_key_exists('result_message', $get_data)) {
                                    if ($get_data['result']) {
                                        $get_sms_status = $customer_status = 'Delivered';
                                    } else {
                                        $get_sms_status  = $get_data['result_message'];
                                        $customer_status = 'Failed';
                                    }

                                } else {
                                    $get_sms_status  = 'Provide empty response. Please contact with gateway provider.';
                                    $customer_status = 'Rejected';
                                }
                            }
                            curl_close($ch);

                        } catch (Exception $e) {
                            $get_sms_status  = $e->getMessage();
                            $customer_status = 'Rejected';
                        }
                        break;


                    case SendingServer::TYPE_ESENDEX:
                        $parameters = [
                            'username'  => $sending_server->username,
                            'password'  => $sending_server->password,
                            'account'   => $sending_server->account_sid,
                            'recipient' => $phone,
                            'body'      => $message,
                            'plaintext' => 1,
                        ];

                        $sending_url = $gateway_url . '?' . http_build_query($parameters);

                        try {

                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $sending_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

                            $get_sms_status = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status  = curl_error($ch);
                                $customer_status = 'Rejected';
                            } else {

                                if (substr_count($get_sms_status, 'OK') == 1) {
                                    $get_sms_status = $customer_status = 'Delivered';
                                } else {

                                    $lines = explode("\n", $get_sms_status);

// Initialize an empty array to store the key-value pairs
                                    $output = [];

// Loop through each line and split by '='
                                    foreach ($lines as $line) {
                                        $parts = explode('=', $line, 2);
                                        if (count($parts) == 2) {
                                            $output[$parts[0]] = $parts[1];
                                        }
                                    }

// Access the 'Message' key and decode the value
                                    $get_sms_status  = urldecode($output['Message']);
                                    $customer_status = 'Failed';
                                }
                            }
                            curl_close($ch);

                        } catch (Exception $exception) {
                            $get_sms_status  = $exception->getMessage();
                            $customer_status = 'Failed';
                        }
                        break;

                    case SendingServer::TYPE_RUBSMSRU:

                        $login_data = [
                            'username' => $sending_server->username,
                            'password' => $sending_server->password,
                        ];

                        $ch = curl_init();

                        curl_setopt_array($ch, [
                            CURLOPT_URL            => $auth_url,
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_ENCODING       => '',
                            CURLOPT_MAXREDIRS      => 10,
                            CURLOPT_TIMEOUT        => 0,
                            CURLOPT_FOLLOWLOCATION => true,
                            CURLOPT_SSL_VERIFYHOST => false,
                            CURLOPT_SSL_VERIFYPEER => false,
                            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                            CURLOPT_CUSTOMREQUEST  => 'POST',
                            CURLOPT_POSTFIELDS     => json_encode($login_data),
                            CURLOPT_HTTPHEADER     => [
                                'Content-Type: application/json',
                                'Accept: application/json',
                            ],
                        ]);

                        $response = curl_exec($ch);
                        $err      = curl_error($ch);

                        curl_close($ch);

                        if ($err) {
                            $get_sms_status = $err;
                        } else {
                            $response = json_decode($response, true);

                            if (isset($response) && is_array($response) && array_key_exists('access', $response)) {
                                $access_token = $response['access'];

                                $headers   = [];
                                $headers[] = "Authorization: Bearer $access_token";
                                $headers[] = 'Content-Type: application/json';
                                $headers[] = 'Accept: application/json';

                                $parameters = [
                                    'company'           => $sending_server->c1,
                                    'priority'          => 1,
                                    'instantly_start'   => true,
                                    'distribution_data' => [
                                        [
                                            'text'  => $message,
                                            'phone' => $phone,
                                        ],
                                    ],
                                ];

                                $ch = curl_init();
                                curl_setopt($ch, CURLOPT_URL, $gateway_url);
                                curl_setopt($ch, CURLOPT_POST, 1);
                                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                                $result = curl_exec($ch);
                                $err    = curl_error($ch);

                                curl_close($ch);

                                if ($err) {
                                    $get_sms_status = $err;
                                } else {

                                    $get_data = json_decode($result, true);

                                    if (isset($get_data) && is_array($get_data)) {
                                        if (array_key_exists('status', $get_data) && $get_data['status'] == 'Success') {
                                            $get_sms_status = $customer_status = 'Delivered';
                                        } else if (array_key_exists('distribution_data', $get_data)) {
                                            $get_sms_status  = $get_data['distribution_data'][0];
                                            $customer_status = 'Failed';
                                        } else if (array_key_exists('detail', $get_data)) {
                                            $get_sms_status  = $get_data['detail'];
                                            $customer_status = 'Failed';
                                        } else {
                                            $get_sms_status = $customer_status = 'Failed';
                                        }
                                    } else {
                                        $get_sms_status = $customer_status = 'Failed';
                                    }
                                }
                            } else {
                                $get_sms_status = $response['detail'];
                            }
                        }
                        break;

                    case SendingServer::TYPE_SMSHUBAO:
                        $parameters = [
                            'auth_id'    => $sending_server->auth_id,
                            'secret_key' => $sending_server->api_secret,
                            'from'       => $data['sender_id'],
                            'to'         => $phone,
                            'message'    => $message,
                        ];

                        $sending_url = $gateway_url . '?' . http_build_query($parameters);

                        try {

                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $sending_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

                            $response = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status  = curl_error($ch);
                                $customer_status = 'Rejected';
                            } else {

                                $get_data = json_decode($response, true);

                                if (isset($get_data) && is_array($get_data) && array_key_exists('status', $get_data) && array_key_exists('data', $get_data)) {
                                    if ($get_data['status'] == '200') {
                                        $get_sms_status = $customer_status = 'Delivered';
                                    } else {
                                        $get_sms_status  = $get_data['data'];
                                        $customer_status = 'Rejected';
                                    }
                                } else {
                                    $get_sms_status  = $response;
                                    $customer_status = 'Rejected';
                                }
                            }
                            curl_close($ch);

                        } catch (Exception $exception) {
                            $get_sms_status  = $exception->getMessage();
                            $customer_status = 'Failed';
                        }
                        break;

                    case SendingServer::TYPE_TEXTBACK:
                        $parameters = [
                            'messageType' => 'SMS',
                            'to'          => $phone,
                            'text'        => $message,
                        ];

                        $headers = [
                            'token: ' . $sending_server->api_key,
                            'secret: ' . $sending_server->api_key,
                            'accept: application/json',
                            'Content-Type: application/json',
                        ];


                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                            curl_setopt($ch, CURLOPT_ENCODING, '');
                            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                            $response = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status  = curl_error($ch);
                                $customer_status = 'Rejected';
                            } else {

                                $get_response = json_decode($response, true);

                                if (is_array($get_response)) {
                                    if (array_key_exists('sent', $get_response) && array_key_exists('externalId', $get_response)) {
                                        $get_sms_status  = 'Delivered|' . $get_response['externalId'];
                                        $customer_status = 'Delivered';
                                    } else if (array_key_exists('REASON', $get_response)) {
                                        $get_sms_status  = $get_response['REASON'];
                                        $customer_status = 'Failed';
                                    } else {
                                        $get_sms_status  = $response;
                                        $customer_status = 'Failed';
                                    }
                                }

                            }

                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status  = $e->getMessage();
                            $customer_status = 'Rejected';
                        }
                        break;


                    case SendingServer::TYPE_OMBALA:


                        $phoneUtil = PhoneNumberUtil::getInstance();

                        try {
                            $phoneNumberObject = $phoneUtil->parse('+' . $phone);

                            if ($phoneNumberObject->isItalianLeadingZero()) {
                                $nationalNumber = '0' . $phoneNumberObject->getNationalNumber();
                            } else {
                                $nationalNumber = $phoneNumberObject->getNationalNumber();
                            }

                            $parameters = [
                                'to'      => $nationalNumber,
                                'from'    => $data['sender_id'],
                                'message' => $message,
                            ];

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                                'Authorization: Token ' . $sending_server->api_token,
                                'Content-Type: application/json',
                                'Accept: application/json',
                            ]);

                            $response = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status  = curl_error($ch);
                                $customer_status = 'Rejected';
                            } else {

                                $get_response = json_decode($response, true);

                                if (is_array($get_response)) {

                                    if (array_key_exists('id', $get_response)) {
                                        $get_sms_status = $customer_status = 'Delivered';
                                    } else if (array_key_exists('errors', $get_response) && array_key_exists('message', $get_response['errors'])) {
                                        $get_sms_status  = $get_response['errors']['message'];
                                        $customer_status = 'Rejected';
                                    } else {
                                        $get_sms_status = $customer_status = 'Rejected';
                                    }

                                } else {
                                    $get_sms_status  = $response;
                                    $customer_status = 'Rejected';
                                }
                            }

                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status  = $e->getMessage();
                            $customer_status = 'Rejected';
                        }
                        break;

                    case SendingServer::TYPE_TEXTHUB:

                        $parameters = [
                            'api_key' => $sending_server->api_key,
                            'text'    => $message,
                            'number'  => $phone,
                        ];

                        $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_HTTPGET, 1);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

                        $result = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status  = curl_error($ch);
                            $customer_status = 'Failed';
                        } else {
                            $get_data = json_decode($result, true);

                            if (isset($get_data) && is_array($get_data) && array_key_exists('response', $get_data)) {
                                if (array_key_exists('id', $get_data)) {
                                    $get_sms_status  = 'Delivered|' . $get_data['id'];
                                    $customer_status = 'Delivered';
                                } else {
                                    $get_sms_status  = $get_data['response'];
                                    $customer_status = 'Failed';
                                }
                            } else {
                                $get_sms_status  = $result;
                                $customer_status = 'Failed';
                            }
                        }

                        curl_close($ch);
                        break;


                    case SendingServer::TYPE_FASTSMS101:

                        $parameters = [
                            'User'    => $sending_server->username,
                            'Pass'    => $sending_server->password,
                            'Numbers' => [$phone],
                            'Message' => $message,
                        ];


                        if (isset($data['sender_id'])) {
                            $parameters['From'] = $data['sender_id'];
                        }

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_ENCODING, '');
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'Content-Type: application/json',
                        ]);

                        $response = curl_exec($ch);


                        if (curl_errno($ch)) {
                            $get_sms_status  = curl_error($ch);
                            $customer_status = 'Rejected';
                        } else {

                            $get_data = json_decode($response, true);

                            if (isset($get_data) && is_array($get_data) && array_key_exists('Status', $get_data)) {
                                if ($get_data['Status'] == 'OK') {
                                    $get_sms_status = $customer_status = 'Delivered';
                                } else {
                                    $get_sms_status  = $get_data['Status'];
                                    $customer_status = 'Rejected';
                                }
                            } else {
                                $get_sms_status  = 'Provide empty response. Please contact with gateway provider.';
                                $customer_status = 'Rejected';
                            }
                        }

                        curl_close($ch);
                        break;


                    case SendingServer::TYPE_MOZESMS:

                        $parameters = [
                            'to'      => $phone,
                            'from'    => $data['sender_id'],
                            'message' => $message,
                        ];

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'Authorization: Bearer ' . $sending_server->api_token,
                        ]);

                        $response = curl_exec($ch);


                        if (curl_errno($ch)) {
                            $get_sms_status  = curl_error($ch);
                            $customer_status = 'Rejected';
                        } else {

                            $jsonStrings  = preg_split('/}\s*{/', trim($response), -1, PREG_SPLIT_NO_EMPTY);
                            $jsonStrings  = array_map(fn($j, $i) => ($i == 0 ? $j . '}' : '{' . $j), $jsonStrings, array_keys($jsonStrings));
                            $get_response = json_decode($jsonStrings[0], true);


                            if ($get_response == null) {
                                $get_response = json_decode($response, true);
                            }

                            if (is_array($get_response)) {

                                if (array_key_exists('success', $get_response) && $get_response['success']) {
                                    $get_sms_status = $customer_status = 'Delivered';
                                } else if (array_key_exists('status', $get_response) && $get_response['status'] == 'ERROR') {
                                    $get_sms_status  = $get_response['description'];
                                    $customer_status = 'Rejected';
                                } else {
                                    $get_sms_status = $customer_status = 'Rejected';
                                }

                            } else {
                                $get_sms_status  = $response;
                                $customer_status = 'Rejected';
                            }
                        }

                        curl_close($ch);
                        break;


                    case SendingServer::TYPE_MESSAGECENTRAL:

                        $phoneUtil = PhoneNumberUtil::getInstance();
                        try {
                            $phoneNumberObject = $phoneUtil->parse('+' . $phone);
                            $countryCode       = $phoneNumberObject->getCountryCode();

                            if ($phoneNumberObject->isItalianLeadingZero()) {
                                $nationalNumber = '0' . $phoneNumberObject->getNationalNumber();
                            } else {
                                $nationalNumber = $phoneNumberObject->getNationalNumber();
                            }


                            $params = [
                                'customerId' => $sending_server->c1,
                                'key'        => base64_encode($sending_server->password),
                                'scope'      => 'NEW',
                                'country'    => $countryCode,
                                'email'      => $sending_server->c2,
                            ];

                            $token_url = $sending_server->auth_link . '?' . http_build_query($params);
// Initialize cURL session
                            $ch = curl_init();

// Set cURL options
                            curl_setopt($ch, CURLOPT_URL, $token_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                                'accept: */*',
                            ]);

// Execute request and capture response
                            $response = curl_exec($ch);
// Check for errors
                            if (curl_errno($ch)) {
                                $get_sms_status  = curl_error($ch);
                                $customer_status = 'Rejected';
                            } else {

                                $auth_response = json_decode($response, true);

                                if (is_array($auth_response) && array_key_exists('token', $auth_response)) {

                                    $parameters = [
                                        'countryCode'  => $countryCode,
                                        'customerId'   => $sending_server->c1,
                                        'senderId'     => $data['sender_id'],
                                        'type'         => 'SMS',
                                        'flowType'     => 'SMS',
                                        'mobileNumber' => $nationalNumber,
                                        'message'      => $message,
                                    ];

                                    $headers = [
                                        'Content-Type:application/json',
                                        'authToken: ' . $auth_response['token'],
                                    ];

                                    $sending_url = $gateway_url . '?' . http_build_query($parameters);

                                    $ch = curl_init();
                                    curl_setopt($ch, CURLOPT_URL, $sending_url);
                                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                                    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                                    curl_setopt($ch, CURLOPT_POST, 1);
                                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                    $response = curl_exec($ch);
                                    curl_close($ch);

                                    $get_response = json_decode($response, true);

                                    if (is_array($get_response) && array_key_exists('responseCode', $get_response)) {
                                        $get_sms_status = match ((string) $get_response['responseCode']) {
                                            '200' => 'Delivered',
                                            '400' => 'Bad Request',
                                            '409' => 'Duplicate Resource',
                                            '500' => 'Internal Server Error',
                                            '501' => 'Invalid Customer ID',
                                            '503' => 'Service Unavailable',
                                            '504' => 'Gateway Timeout',
                                            '505' => 'Invalid Verification ID',
                                            '506' => 'Request already exists',
                                            '511' => 'Invalid country code',
                                            '700' => 'Verification failed',
                                            '702' => 'Wrong OTP Provided',
                                            '703' => 'Already verified',
                                            '705' => 'Verification expired',
                                            '800' => 'Maximum limit exceeded',
                                            '429' => 'Too Many Requests',
                                            default => 'Invalid request',
                                        };
                                    } else {
                                        $get_sms_status = implode(' ', $get_response);
                                    }
                                }
                            }
// Close cURL session
                            curl_close($ch);

                        } catch (NumberParseException $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;


                    case SendingServer::TYPE_PHDSMS:

                        $parameters = [
                            'sp_id'    => $sending_server->c1,
                            'password' => $sending_server->password,
                            'mobile'   => $phone,
                            'ext'      => $data['sender_id'],
                            'content'  => $message,
                        ];

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);

                        $response = curl_exec($ch);


                        if (curl_errno($ch)) {
                            $get_sms_status  = curl_error($ch);
                            $customer_status = 'Rejected';
                        } else {

                            $get_response = json_decode($response, true);

                            if (is_array($get_response) && array_key_exists('code', $get_response) && array_key_exists('msg', $get_response)) {
                                if ($get_response['code'] == '0') {
                                    $get_sms_status  = 'Delivered|' . $get_response['msg_id'];
                                    $customer_status = 'Delivered';
                                } else {
                                    $get_sms_status  = $get_response['msg'];
                                    $customer_status = 'Failed';
                                }
                            } else {
                                $get_sms_status  = $response;
                                $customer_status = 'Failed';
                            }

                        }

                        curl_close($ch);

                        break;


                    case SendingServer::TYPE_MDSMS:

                        $parameters = [
                            'username'           => $sending_server->username,
                            'password'           => $sending_server->password,
                            'originatingAddress' => $data['sender_id'],
                            'destinatingAddress' => $phone,
                            'sms'                => $message,
                            'returnMode'         => 1,
                            'type'               => 1,
                        ];

                        $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_HTTPGET, 1);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

                        $get_sms_status = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status  = curl_error($ch);
                            $customer_status = 'Failed';
                        } else {

                            if ($get_sms_status > 0) {
                                $get_sms_status = $customer_status = 'Delivered';
                            } else {

                                $get_sms_status = match ($get_sms_status) {
                                    '-1' => 'Wrong Destination Address',
                                    '-2' => 'Account Information/username/IP/password incorrect.',
                                    '-3' => 'Content ID incorrect',
                                    '-4' => 'Destination Number not all correct',
                                    '-7' => 'Destination Number Block by OFTA',
                                    '-8' => 'Wrong Origination Address',
                                    '-100' => 'Internal Error, please contact support.',
                                    default => 'Unknown error code',
                                };

                                $customer_status = 'Failed';
                            }

                        }
                        curl_close($ch);
                        break;


                    case SendingServer::TYPE_DECISIONTELECOM:

                        $parameters = [
                            'phone'           => $phone,
                            'text'            => $message,
                            'sender'          => $data['sender_id'],
                            'validity_period' => 120,
                        ];

                        $headers = [
                            'Content-Type:application/json',
                            'Authorization:Basic ' . $sending_server->api_key,
                        ];

                        try {
                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                            $result = curl_exec($ch);
                            curl_close($ch);

                            $result = json_decode($result, true);

                            if (is_array($result) && array_key_exists('message_data', $result)) {
                                $get_sms_status  = 'Delivered|' . $result['message_data']['0']['message_id'];
                                $customer_status = 'Delivered';
                            } else {
                                $get_sms_status = $customer_status = 'Failed';
                            }

                        } catch (Exception $e) {
                            $get_sms_status  = $e->getMessage();
                            $customer_status = 'Failed';
                        }
                        break;


                    case SendingServer::TYPE_MESSAGINGSERVICECOTZ:


                        $parameters = [
                            "from"      => $data['sender_id'],
                            "to"        => $phone,
                            "text"      => $message,
                            'reference' => uniqid(),
                        ];

                        $headers = [
                            'Content-Type: application/json',
                            'Accept: application/json',
                            'Authorization:Basic ' . base64_encode("$sending_server->username:$sending_server->password"),
                        ];

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        $response = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status  = curl_error($ch);
                            $customer_status = 'Rejected';
                        } else {

                            $get_data = json_decode($response, true);

                            if (isset($get_data) && is_array($get_data)) {
                                if (array_key_exists('success', $get_data) && array_key_exists('message', $get_data) && ! $get_data['success']) {
                                    $get_sms_status  = $get_data['message'];
                                    $customer_status = 'Rejected';
                                } else {
                                    $get_sms_status = $customer_status = 'Delivered';
                                }
                            } else {
                                $get_sms_status  = 'Provide empty response. Please contact with gateway provider.';
                                $customer_status = 'Rejected';
                            }
                        }

                        curl_close($ch);
                        break;

                    case SendingServer::TYPE_CLOSUM:


                        $parameters = [
                            "sender"    => $data['sender_id'],
                            "recipient" => $phone,
                            "message"   => $message,
                        ];

                        $headers = [
                            'Content-Type: application/json',
                            'Accept: application/json',
                        ];

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url . '?api-key=' . $sending_server->api_key);
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        $response = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status  = curl_error($ch);
                            $customer_status = 'Rejected';
                        } else {

                            $get_data = json_decode($response, true);

                            if (isset($get_data) && is_array($get_data) && array_key_exists('status', $get_data)) {
                                if ($get_data['status'] && array_key_exists('message', $get_data)) {
                                    $get_sms_status  = 'Delivered|' . $get_data['message']['msgid'];
                                    $customer_status = "Delivered";
                                } else if (array_key_exists('message', $get_data)) {
                                    $get_sms_status  = $get_data['message']['0'];
                                    $customer_status = "Failed";
                                } else {
                                    $get_sms_status  = $get_data['errors'][0];
                                    $customer_status = 'Failed';
                                }
                            } else {
                                $get_sms_status  = 'Provide empty response. Please contact with gateway provider.';
                                $customer_status = 'Rejected';
                            }
                        }

                        curl_close($ch);
                        break;


                    case SendingServer::TYPE_EGOSMS:

                        $parameters =
                            [
                                'method'   => 'SendSms',
                                'userdata' => [
                                    'username' => $sending_server->username, // Egosms Username
                                    'password' => $sending_server->password, // Egosms Password
                                ],
                                'msgdata'  => [
                                    [
                                        'number'   => $phone,
                                        'message'  => $message,
                                        'senderid' => $data['sender_id'],
                                    ],
                                ],
                            ];


                        $headers = [
                            'Content-Type: application/json',
                            'Accept: application/json',
                        ];

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        $response = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status  = curl_error($ch);
                            $customer_status = 'Rejected';
                        } else {

                            $get_data = json_decode($response, true);

                            if (isset($get_data) && is_array($get_data) && array_key_exists('Status', $get_data)) {
                                if ($get_data['Status'] == 'OK') {
                                    $get_sms_status = $customer_status = 'Delivered';
                                } else if (array_key_exists('Message', $get_data)) {
                                    $get_sms_status  = $get_data['Message'];
                                    $customer_status = "Failed";
                                } else {
                                    $get_sms_status = $customer_status = 'Failed';
                                }
                            } else {
                                $get_sms_status  = 'Provide empty response. Please contact with gateway provider.';
                                $customer_status = 'Rejected';
                            }
                        }

                        curl_close($ch);
                        break;

                    case SendingServer::TYPE_ANKAREXIO:
                        $parameters = [
                            'token'           => $sending_server->api_token,
                            "send"            => "bulk",
                            'to'              => $phone,
                            'message_content' => $message,
                            'sender_id'       => $data['sender_id'],
                        ];


                        if ($sms_type == 'unicode') {
                            $parameters['unicode'] = true;
                        } else {
                            $parameters['unicode'] = false;
                        }


                        $headers = [
                            'accept: application/json',
                            'Content-Type: application/json',
                            'User-Agent: Ankarex-Rest-0.22',
                        ];


                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                            curl_setopt($ch, CURLOPT_ENCODING, '');
                            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                            $response = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status  = curl_error($ch);
                                $customer_status = 'Rejected';
                            } else {

                                $get_response = json_decode($response, true);

                                if (is_array($get_response)) {
                                    if (array_key_exists('campaign_id', $get_response)) {
                                        $get_sms_status  = 'Delivered|' . $get_response['campaign_id'];
                                        $customer_status = 'Delivered';
                                    } else if (array_key_exists('info', $get_response)) {
                                        $get_sms_status  = $get_response['info'];
                                        $customer_status = 'Failed';
                                    } else {
                                        $get_sms_status  = $response;
                                        $customer_status = 'Failed';
                                    }
                                }

                            }

                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status  = $e->getMessage();
                            $customer_status = 'Rejected';
                        }

                        break;


                    case SendingServer::TYPE_FORTYTWO:

                        $sms_counter  = new SMSCounter();
                        $message_data = $sms_counter->count($message);

                        $parameters = [
                            "destinations" => [
                                [
                                    "number" => $phone,
                                ],
                            ],
                            "sms_content"  => [
                                "message" => $message,
                            ],
                            'callback_url' => route('dlr.fortytwo'),
                        ];

                        // Add sender ID if provided
                        if ($data['sender_id']) {
                            $data["sms_content"]["sender_id"] = $data['sender_id'];
                        }

                        if ($message_data->encoding == 'UTF16') {
                            $parameters['sms_content']['encoding'] = 'UCS2';
                        }

                        $headers = [
                            "Authorization: Token " . $sending_server->api_key,
                            "Content-Type: application/json; charset=utf-8",
                        ];

                        try {
                            $ch = curl_init();
                            curl_setopt_array($ch, [
                                CURLOPT_URL            => $gateway_url,
                                CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_ENCODING       => "",
                                CURLOPT_MAXREDIRS      => 10,
                                CURLOPT_TIMEOUT        => 30,
                                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                                CURLOPT_CUSTOMREQUEST  => "POST",
                                CURLOPT_POSTFIELDS     => json_encode($parameters),
                                CURLOPT_HTTPHEADER     => $headers,
                            ]);

                            // Execute the request
                            $response = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                $get_response = json_decode($response, true);

                                if (isset($get_response) && is_array($get_response)) {
                                    if (array_key_exists('result_info', $get_response) && $get_response['result_info']['status_code'] == 200) {
                                        $message_id      = $get_response['api_job_id'];
                                        $get_sms_status  = 'Delivered|' . $message_id;
                                        $customer_status = 'Delivered';
                                    } else if (array_key_exists('error', $get_response) && $get_response['error'] == 1) {
                                        $get_sms_status  = $get_response['message'];
                                        $customer_status = 'Failed';
                                    } else {
                                        $get_sms_status  = $get_response['message'];
                                        $customer_status = 'Failed';
                                    }
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $exception) {
                            $get_sms_status  = $exception->getMessage();
                            $customer_status = 'Failed';
                        }
                        break;


                    case SendingServer::TYPE_HOLLYRENEW:

                        $parameters = [
                            'token'    => $sending_server->api_token,
                            'servicio' => $data['sender_id'],
                            'numero'   => $phone,
                            'msj'      => $message,
                        ];

                        $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);

                        $get_sms_status = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status  = curl_error($ch);
                            $customer_status = 'Failed';
                        } else {
                            if (str_contains($get_sms_status, 'Enviado')) {
                                // action to perform if it is sent
                                $get_sms_status = $customer_status = 'Delivered';
                            } else {
                                $customer_status = 'Failed';
                            }
                        }
                        curl_close($ch);
                        break;


                    case SendingServer::TYPE_NEXAH:


                        $parameters = [
                            'user'     => $sending_server->username,
                            'password' => $sending_server->password,
                            'sms'      => $message,
                            'mobiles'  => $phone,
                            'senderid' => $data['sender_id'],
                        ];

                        $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_HTTPGET, 1);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

                        $result = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status  = curl_error($ch);
                            $customer_status = 'Failed';
                        } else {
                            $get_data = json_decode($result, true);

                            if (isset($get_data) && is_array($get_data) && array_key_exists('responsecode', $get_data) && array_key_exists('responsemessage', $get_data)) {
                                if ($get_data['responsecode'] == '1') {
                                    $get_sms_status = $customer_status = 'Delivered';
                                } else {
                                    $get_sms_status  = $get_data['responsemessage'];
                                    $customer_status = 'Failed';
                                }
                            } else {
                                $get_sms_status  = $get_data;
                                $customer_status = 'Failed';
                            }
                        }

                        curl_close($ch);
                        break;

                    case SendingServer::TYPE_GENNET:

                        $parameters = [
                            'api_key'  => $sending_server->api_key,
                            'senderid' => $data['sender_id'],
                            'type'     => 'text',
                            'numbers'  => $phone,
                            'msg'      => $message,
                        ];

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_ENCODING, '');
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'Content-Type: application/json',
                            'Accept: application/json',
                        ]);

                        $response = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status  = curl_error($ch);
                            $customer_status = 'Rejected';
                        } else {

                            $get_response = json_decode($response, true);

                            if (is_array($get_response) && array_key_exists('error', $get_response)) {
                                if ( ! $get_response['error']) {
                                    $get_sms_status = $customer_status = 'Delivered';
                                } else {
                                    $get_sms_status  = $get_response['error_message'];
                                    $customer_status = 'Failed';
                                }
                            }
                        }

                        curl_close($ch);

                        break;

                    case SendingServer::TYPE_DURBAR:

                        $parameters = [
                            'userId'                        => $sending_server->c1,
                            'password'                      => $sending_server->password,
                            'commaSeperatedReceiverNumbers' => $phone,
                            'smsText'                       => $message,
                        ];

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);

                        $response = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status  = curl_error($ch);
                            $customer_status = 'Rejected';
                        } else {

                            $get_response = json_decode($response, true);

                            if (is_array($get_response) && array_key_exists('isError', $get_response) && array_key_exists('message', $get_response)) {
                                if ( ! $get_response['isError']) {
                                    $get_sms_status = $customer_status = 'Delivered';
                                } else {
                                    $get_sms_status  = $get_response['message'];
                                    $customer_status = 'Failed';
                                }
                            } else {
                                $get_sms_status  = $response;
                                $customer_status = 'Failed';
                            }

                        }

                        curl_close($ch);

                        break;

                    case SendingServer::TYPE_SMSCOUNTRY_STANDARD:
                        $parameters = [
                            'user'         => $sending_server->username,
                            'passwd'       => $sending_server->password,
                            'sid'          => $data['sender_id'],
                            'mobilenumber' => $phone,
                            'message'      => $message,
                            'DR'           => 'N',
                        ];

                        if ($sms_type == 'unicode') {
                            $parameters['mtype'] = 'LNG';
                        } else {
                            $parameters['mtype'] = 'N';
                        }

                        $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                        try {
                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            $get_sms_status = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status = curl_error($ch);
                            } else {
                                if (substr_count($get_sms_status, 'sent') == 1) {
                                    $get_sms_status = 'Delivered';
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $e) {
                            $get_sms_status = $e->getMessage();
                        }
                        break;

                    case SendingServer::TYPE_EASIFY:

                        $phoneUtil = PhoneNumberUtil::getInstance();
                        try {
                            $phoneNumberObject = $phoneUtil->parse('+' . $phone);
                            $countryCode       = $phoneNumberObject->getCountryCode();

                            if ($phoneNumberObject->isItalianLeadingZero()) {
                                $nationalNumber = '0' . $phoneNumberObject->getNationalNumber();
                            } else {
                                $nationalNumber = $phoneNumberObject->getNationalNumber();
                            }

                            $parameters = [
                                'from_number'  => $data['sender_id'],
                                'to_number'    => $nationalNumber,
                                'message'      => $message,
                                'country_code' => $countryCode,
                            ];

                            $headers = [
                                'Content-Type:application/json',
                                'Accept: application/json',
                                'Authorization: Bearer ' . $sending_server->api_token,
                            ];

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            $response = curl_exec($ch);
                            curl_close($ch);

                            $get_response = json_decode($response, true);

                            if (is_array($get_response) && array_key_exists('status', $get_response) && array_key_exists('message', $get_response)) {
                                if ($get_response['status']) {
                                    $get_sms_status = $customer_status = 'Delivered';
                                } else {
                                    $get_sms_status  = $get_response['message'];
                                    $customer_status = 'Failed';
                                }
                            } else {
                                $get_sms_status  = implode(' ', $get_response);
                                $customer_status = 'Failed';
                            }

                        } catch (NumberParseException $e) {
                            $get_sms_status = $customer_status = $e->getMessage();
                        }

                        break;


                    case SendingServer::TYPE_KUDISMS:

                        $parameters = [
                            'token'      => $sending_server->api_token,
                            'message'    => $message,
                            'recipients' => $phone,
                            'senderID'   => $data['sender_id'],
                            'gateway'    => 2,
                        ];

                        $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_HTTPGET, 1);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

                        $result = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status  = curl_error($ch);
                            $customer_status = 'Failed';
                        } else {
                            $get_data = json_decode($result, true);

                            if (isset($get_data) && is_array($get_data) && array_key_exists('status', $get_data) && array_key_exists('msg', $get_data)) {
                                if ($get_data['status'] == 'success') {
                                    $get_sms_status = $customer_status = 'Delivered';
                                } else {
                                    $get_sms_status  = $get_data['msg'];
                                    $customer_status = 'Failed';
                                }
                            } else {
                                $get_sms_status  = $get_data;
                                $customer_status = 'Failed';
                            }
                        }

                        curl_close($ch);
                        break;


                    case SendingServer::TYPE_ADVANSYSTELECOM:

                        $parameters = [
                            'PhoneNumbers' => [$phone],
                            'Message'      => $message,
                            'SenderName'   => $data['sender_id'],
                            'RequestID'    => time(),
                        ];

                        $headers = [
                            'Content-Type:application/json',
                            'Authorization:' . $sending_server->api_token,
                        ];

                        try {
                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                            $result = curl_exec($ch);
                            curl_close($ch);

                            if ($result == '"1"') {
                                $get_sms_status = $customer_status = 'Delivered';
                            } else {
                                $result = json_decode($result, true);

                                if (is_array($result) && array_key_exists('Message', $result)) {

                                    if ($result['Message'] == '1') {
                                        $get_sms_status = $customer_status = 'Delivered';
                                    } else {
                                        $get_sms_status  = match ($result['Message']) {
                                            '-1' => 'Invalid authorization token.',
                                            '-2' => 'Empty mobile number.',
                                            '-3' => 'Empty message.',
                                            '-4' => 'Invalid sender.',
                                            '-5' => 'No credit available for account.',
                                            default => 'Unknown status code.',
                                        };
                                        $customer_status = 'Failed';
                                    }
                                } else {
                                    $get_sms_status = $customer_status = 'Failed';
                                }
                            }

                        } catch (Exception $e) {
                            $get_sms_status  = $e->getMessage();
                            $customer_status = 'Failed';
                        }
                        break;


                    case SendingServer::TYPE_HTTPSMS:


                        $parameters = [
                            'to'         => $phone,
                            'from'       => $data['sender_id'],
                            'content'    => $message,
                            'request_id' => uniqid(),
                            'encrypted'  => false,
                        ];

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_POST, 1);

                            $headers   = [];
                            $headers[] = "x-api-key: $sending_server->api_key";
                            $headers[] = 'Content-Type: application/json';
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                            $get_sms_status = curl_exec($ch);
                            curl_close($ch);

                            $get_data = json_decode($get_sms_status, true);

                            if (is_array($get_data) && array_key_exists('status', $get_data) && array_key_exists('message', $get_data)) {
                                if ($get_data['status'] == 'success') {
                                    $get_sms_status = $customer_status = 'Delivered';
                                } else {
                                    $get_sms_status  = $get_data['message'];
                                    $customer_status = 'Failed';
                                }
                            }

                        } catch (Exception $e) {
                            $get_sms_status  = $e->getMessage();
                            $customer_status = 'Failed';
                        }
                        break;


                    case SendingServer::TYPE_BULKSMSNG:

                        $parameters = [
                            'email'     => $sending_server->c1,
                            'password'  => $sending_server->password,
                            'senderid'  => $data['sender_id'],
                            'recipient' => $phone,
                            'message'   => $message,
                        ];

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

                        $response = curl_exec($ch);

                        dump($response);

                        if (curl_errno($ch)) {
                            $get_sms_status  = curl_error($ch);
                            $customer_status = 'Rejected';
                        } else {

                            $get_response = json_decode($response, true);
                            dd($get_response);

                            if (is_array($get_response) && array_key_exists('isError', $get_response) && array_key_exists('message', $get_response)) {
                                if ( ! $get_response['isError']) {
                                    $get_sms_status = $customer_status = 'Delivered';
                                } else {
                                    $get_sms_status  = $get_response['message'];
                                    $customer_status = 'Failed';
                                }
                            } else {
                                $get_sms_status  = $response;
                                $customer_status = 'Failed';
                            }

                        }

                        curl_close($ch);

                        break;


                    case SendingServer::TYPE_MONTYMOBILE:

                        $parameters = [
                            'username'    => $sending_server->username,
                            'password'    => $sending_server->password,
                            'text'        => $message,
                            'destination' => $phone,
                            'source'      => $data['sender_id'],
                        ];


                        if ($sms_type == 'unicode') {
                            $parameters['dataCoding'] = 8;
                        } else {
                            $parameters['dataCoding'] = 0;
                        }

                        $qs = http_build_query($parameters);


                        $url = $gateway_url . '?' . $qs;


                        $curl = curl_init();

                        curl_setopt_array($curl, [
                            CURLOPT_URL            => $url,
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_ENCODING       => '',
                            CURLOPT_MAXREDIRS      => 10,
                            CURLOPT_TIMEOUT        => 30,
                            CURLOPT_FOLLOWLOCATION => true,
                            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                            CURLOPT_CUSTOMREQUEST  => 'GET',
                        ]);


                        $result = curl_exec($curl);

                        if (curl_errno($curl)) {
                            $get_sms_status  = curl_error($curl);
                            $customer_status = 'Failed';
                        } else {
                            $get_data = json_decode($result, true);

                            if (isset($get_data) && is_array($get_data) && array_key_exists('ErrorCode', $get_data) && array_key_exists('ErrorDescription', $get_data)) {
                                if ($get_data['ErrorCode'] == '0') {
                                    $get_sms_status = $customer_status = 'Delivered';
                                } else {
                                    $get_sms_status  = $get_data['ErrorDescription'];
                                    $customer_status = 'Failed';
                                }
                            } else {
                                $get_sms_status  = $get_data;
                                $customer_status = 'Failed';
                            }
                        }

                        curl_close($curl);

                        break;

                    case SendingServer::TYPE_ITEXMO:

                        $parameters = [
                            'Email'      => $sending_server->c1,
                            'Password'   => $sending_server->password,
                            'ApiCode'    => $sending_server->c2,
                            'Recipients' => [$phone],
                            'Message'    => $message,
                        ];

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                            curl_setopt($ch, CURLOPT_POST, true);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameters));
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                            $result = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $get_sms_status  = curl_error($ch);
                                $customer_status = 'Rejected';
                            } else {

                                $get_data = json_decode($result, true);

                                if (isset($get_data) && is_array($get_data) && array_key_exists('Error', $get_data)) {
                                    if ( ! $get_data['Error']) {
                                        $get_sms_status  = 'Delivered|' . $get_data['ReferenceId'];
                                        $customer_status = 'Delivered';
                                    } else {
                                        $get_sms_status  = $get_data['Message'];
                                        $customer_status = 'Failed';
                                    }
                                } else {
                                    $get_sms_status  = $result;
                                    $customer_status = 'Rejected';
                                }
                            }
                            curl_close($ch);
                        } catch (Exception $ex) {
                            $get_sms_status  = $ex->getMessage();
                            $customer_status = 'Rejected';
                        }
                        break;

                    case SendingServer::TYPE_MESSAGGIO:


                        $content = [
                            [
                                'type' => 'text',
                                'text' => $message,
                            ],
                        ];

                        $parameters = [
                            'recipients' => [
                                ['phone' => $phone],
                            ],
                            'channels'   => ['sms'],
                            'sms'        => [
                                'from'    => $data['sender_id'],
                                'content' => $content,
                            ],

                        ];

                        try {

                            $headers = [
                                'Content-Type:application/json',
                                'Messaggio-Login: ' . $sending_server->api_key,
                            ];

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $gateway_url);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            $response = curl_exec($ch);
                            curl_close($ch);

                            $get_response = json_decode($response, true);

                            if (isset($get_response) && is_array($get_response)) {
                                if (array_key_exists('messages', $get_response) && array_key_exists('0', $get_response['messages']) && array_key_exists('message_id', $get_response['messages'][0])) {
                                    $get_sms_status  = 'Delivered|' . $get_response['messages'][0]['message_id'];
                                    $customer_status = 'Delivered';
                                } else if (array_key_exists('detail', $get_response)) {
                                    $get_sms_status  = $get_response['detail'];
                                    $customer_status = 'Undelivered';
                                } else {
                                    $get_sms_status  = (string) $response;
                                    $customer_status = 'Undelivered';
                                }
                            } else {
                                $get_sms_status  = 'Unknown error';
                                $customer_status = 'Failed';
                            }

                        } catch (Exception $e) {
                            $get_sms_status  = $e->getMessage();
                            $customer_status = 'Failed';
                        }
                        break;


                    case SendingServer::TYPE_SMSALA:

                        $parameters = [
                            'apiToken'           => $sending_server->api_token,
                            'messageType'        => $sending_server->sms_type,
                            'messageEncoding'    => 0,
                            'messageText'        => $message,
                            'destinationAddress' => $phone,
                            'sourceAddress'      => $data['sender_id'],
                            'callBackUrl'        => route('dlr.smsala'),
                        ];


                        $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_HTTPGET, 1);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

                        $result = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status  = curl_error($ch);
                            $customer_status = 'Failed';
                        } else {
                            $get_data = json_decode($result, true)[0];

                            if (array_key_exists('Status', $get_data) && array_key_exists('Remarks', $get_data)) {
                                if ($get_data['Status'] == 'Success') {
                                    $get_sms_status  = 'Delivered|' . $get_data['MessageId'];
                                    $customer_status = 'Delivered';
                                } else {
                                    $get_sms_status  = $get_data['Remarks'];
                                    $customer_status = 'Failed';
                                }
                            } else {
                                $get_sms_status  = $get_data;
                                $customer_status = 'Failed';
                            }
                        }

                        curl_close($ch);
                        break;


                    case SendingServer::TYPE_MOBISHASTRA:

                        $parameters = [
                            'user'   => $sending_server->username,
                            'pwd'    => $sending_server->password,
                            'number' => $phone,
                            'sender' => $data['sender_id'],
                            'msg'    => $message,
                        ];


                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'Content-Type: application/json',
                            'Accept: application/json',
                            "cache-control: no-cache",
                        ]);

                        $response = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status  = curl_error($ch);
                            $customer_status = 'Rejected';
                        } else {

                            $get_data = json_decode($response, true)[0];

                            if (array_key_exists('msg_id', $get_data) && array_key_exists('str_response', $get_data)) {
                                if ( ! empty($get_data['msg_id'])) {
                                    $get_sms_status  = 'Delivered|' . $get_data['msg_id'];
                                    $customer_status = 'Delivered';
                                } else {
                                    $get_sms_status  = $get_data['str_response'];
                                    $customer_status = 'Failed';
                                }
                            } else {
                                $get_sms_status  = $get_data;
                                $customer_status = 'Failed';
                            }
                        }
                        curl_close($ch);
                        break;


                    default:
                        $get_sms_status = $customer_status = __('locale.sending_servers.sending_server_not_found');
                        break;
                }
            }

            $cost = substr_count($get_sms_status, 'Delivered') == 1 ? $data['cost'] : '0';

            if ( ! isset($customer_status)) {
                $customer_status = $get_sms_status;
            }

            $customer_status = substr_count($customer_status, 'Delivered') == 1 ? 'Delivered' : 'Failed';


            $reportsData = [
                'user_id'           => $data['user_id'],
                'to'                => str_replace(['(', ')', '+', '-', ' '], '', $phone),
                'message'           => $message,
                'sms_type'          => $data['sms_type'],
                'status'            => $get_sms_status,
                'customer_status'   => $customer_status,
                'sms_count'         => $data['sms_count'],
                'cost'              => $cost,
                'sending_server_id' => $sending_server->id,
            ];

            if (isset($data['sender_id'])) {
                $reportsData['from'] = $data['sender_id'];
            }

            if (isset($data['campaign_id'])) {
                $reportsData['campaign_id'] = $data['campaign_id'];
            }

            if (isset($data['automation_id'])) {
                $reportsData['automation_id'] = $data['automation_id'];
            }

            if (isset($data['api_key'])) {
                $reportsData['api_key']   = $data['api_key'];
                $reportsData['direction'] = Reports::DIRECTION_API;
            } else {
                $reportsData['direction'] = Reports::DIRECTION_OUTGOING;
            }

            $status = Reports::create($reportsData);

            if ($status) {
                // RFC-005 Milestone 5 §F — transient, non-persisted
                // outcome marker for the two Twilio/TwilioCopilot case
                // blocks above, opt-in only via
                // $data['m5_conversations_usage_tracking']. Never written
                // to $reportsData/the reports table; Reports persistence
                // is otherwise completely unchanged.
                if (isset($m5Outcome)) {
                    $status->m5_outcome = $m5Outcome;
                }

                return $status;
            }

            return __('locale.exceptions.something_went_wrong');

        }

        /**
         * make normal message to unicode message
         */
        private function sms_unicode($message): string
        {
            $hex1 = '';
            if (function_exists('iconv')) {
                $latin = @iconv('UTF−8', 'ISO−8859−1', $message);
                if (strcmp($latin, $message)) {
                    $arr  = unpack('H*hex', @iconv('UTF-8', 'UCS-2BE', $message));
                    $hex1 = strtoupper($arr['hex']);
                }
                if ($hex1 == '') {
                    $hex2 = '';
                    for ($i = 0; $i < strlen($message); $i++) {
                        $hex = dechex(ord($message[$i]));
                        $len = strlen($hex);
                        $add = 4 - $len;
                        if ($len < 4) {
                            for ($j = 0; $j < $add; $j++) {
                                $hex = '0' . $hex;
                            }
                        }
                        $hex2 .= $hex;
                    }

                    return $hex2;
                } else {
                    return $hex1;
                }
            } else {
                return 'failed';
            }
        }

        public
        function strlen_utf8($str): int
        {
            $i     = 0;
            $count = 0;
            $len   = strlen($str);
            while ($i < $len) {
                $chr = ord($str[$i]);
                $count++;
                $i++;
                if ($i >= $len) {
                    break;
                }

                if ($chr & 0x80) {
                    $chr <<= 1;
                    while ($chr & 0x80) {
                        $i++;
                        $chr <<= 1;
                    }
                }
            }

            return $count;
        }

        public
        function utf16urlencode($str): string
        {
            $str = mb_convert_encoding($str, 'UTF-16', 'UTF-8');
            $out = '';
            for ($i = 0; $i < mb_strlen($str, 'UTF-16'); $i++) {
                $out .= bin2hex(mb_substr($str, $i, 1, 'UTF-16'));
            }

            return $out;
        }

        /**
         * send voice message
         *
         *
         * @return array|Application|Translator|string|null
         *
         * @throws Exception
         */
        public
        function sendVoiceSMS($data)
        {
            $phone          = $data['phone'];
            $sending_server = $data['sending_server'];
            $gateway_name   = $data['sending_server']->settings;
            $language       = $data['language'];
            $gender         = $data['gender'];
            $gateway_url    = isset($data['sending_server']->voice_api_link) ? $sending_server->voice_api_link : $sending_server->api_link;
            $message        = null;
            $media_url      = null;

            if (isset($data['message'])) {
                $message = $data['message'];
            }

            if (isset($data['media_url'])) {
                $media_url = $data['media_url'];
            }

            switch ($gateway_name) {

                case SendingServer::TYPE_TWILIO:

                    try {
                        $client = new Client($sending_server->account_sid, $sending_server->auth_token);

                        $response = new VoiceResponse();

                        if ($gender == 'male') {
                            $voice = 'man';
                        } else {
                            $voice = 'woman';
                        }

                        $response->say($message, ['voice' => $voice, 'language' => $language]);

                        $get_response = $client->calls->create($phone, $data['sender_id'], [
                            'twiml' => $response,
                        ]);

                        if ($get_response->status == 'queued') {
                            $get_sms_status = $customer_status = 'Delivered';
                        } else {
                            $get_sms_status  = $get_response->status . '|' . $get_response->sid;
                            $customer_status = ucfirst($get_response->status);
                        }

                    } catch (ConfigurationException|TwilioException $e) {
                        $get_sms_status  = $e->getMessage();
                        $customer_status = 'Rejected';
                    }
                    break;

                case SendingServer::TYPE_PLIVO:

                    $client = new RestClient($sending_server->auth_id, $sending_server->auth_token);
                    try {
                        $client->calls->create(
                            $data['sender_id'],
                            [$phone],
                            Tool::createVoiceFile($message, SendingServer::TYPE_PLIVO),
                            [
                                'answer_method' => 'GET',
                            ]
                        );

                        $get_sms_status = $customer_status = 'Delivered';

                    } catch (PlivoResponseException $e) {
                        $get_sms_status  = $e->getMessage();
                        $customer_status = 'Rejected';
                    }

                    break;

                case SendingServer::TYPE_INFOBIP:

                    $parameters = [
                        'text'     => $message,
                        'language' => $data['language'],
                        'voice'    => [
                            'gender' => $data['gender'],
                        ],
                        'from'     => $data['sender_id'],
                        'to'       => $phone,
                    ];
                    try {
                        $curl = curl_init();

                        $header = [
                            "Authorization: App $sending_server->api_key",
                            'Content-Type: application/json',
                            'Accept: application/json',
                        ];

                        curl_setopt_array($curl, [
                            CURLOPT_URL            => $gateway_url,
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_ENCODING       => '',
                            CURLOPT_MAXREDIRS      => 10,
                            CURLOPT_TIMEOUT        => 0,
                            CURLOPT_FOLLOWLOCATION => true,
                            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                            CURLOPT_CUSTOMREQUEST  => 'POST',
                            CURLOPT_POSTFIELDS     => json_encode($parameters),
                            CURLOPT_HTTPHEADER     => $header,
                        ]);

                        // response of the POST request
                        $response = curl_exec($curl);
                        $get_data = json_decode($response, true);
                        curl_close($curl);

                        if (is_array($get_data)) {
                            if (array_key_exists('messages', $get_data)) {
                                foreach ($get_data['messages'] as $msg) {
                                    if ($msg['status']['name'] == 'MESSAGE_ACCEPTED' || $msg['status']['name'] == 'PENDING_ENROUTE' || $msg['status']['name'] == 'PENDING_ACCEPTED') {
                                        $get_sms_status  = 'Delivered|' . $msg['messageId'];
                                        $customer_status = "Delivered";
                                    } else {
                                        $get_sms_status  = $msg['status']['description'];
                                        $customer_status = "Undelivered";
                                    }
                                }
                            } else if (array_key_exists('requestError', $get_data)) {
                                foreach ($get_data['requestError'] as $msg) {
                                    $get_sms_status = $msg['text'];
                                }
                                $customer_status = "Undelivered";
                            } else {
                                $get_sms_status  = 'Unknown error';
                                $customer_status = 'Failed';
                            }
                        } else {
                            $get_sms_status  = 'Unknown error';
                            $customer_status = 'Failed';
                        }

                    } catch (Exception $exception) {
                        $get_sms_status  = $exception->getMessage();
                        $customer_status = 'Failed';
                    }
                    break;

                case SendingServer::TYPE_MESSAGEBIRD:
                    $parameters = [
                        'destination' => $phone,
                        'source'      => $data['sender_id'],
                        'callFlow'    => [
                            'title' => config('app.name') . '_' . now() . '_flow',
                            'steps' => [
                                [
                                    'action'  => 'say',
                                    'options' => [
                                        'payload'  => $message,
                                        'language' => $data['language'],
                                        'voice'    => $data['gender'],
                                    ],
                                ],
                            ],
                        ],
                    ];

                    $ch = curl_init();

                    curl_setopt($ch, CURLOPT_URL, $gateway_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                    curl_setopt($ch, CURLOPT_POST, 1);

                    $headers   = [];
                    $headers[] = "Authorization: AccessKey $sending_server->api_key";
                    $headers[] = 'Content-Type: application/x-www-form-urlencoded';
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                    $result = curl_exec($ch);
                    if (curl_errno($ch)) {
                        $get_sms_status = curl_error($ch);
                    } else {

                        $response = json_decode($result, true);

                        if (is_array($response) && array_key_exists('data', $response)) {
                            $get_sms_status  = 'Delivered|' . $response['data'][0]['id'];
                            $customer_status = 'Delivered';
                        } else if (is_array($response) && array_key_exists('errors', $response)) {
                            $get_sms_status  = $response['errors'][0]['message'];
                            $customer_status = 'Undelivered';
                        } else {
                            $get_sms_status  = 'Unknown Error';
                            $customer_status = 'Failed';
                        }
                    }
                    curl_close($ch);

                    break;

                case SendingServer::TYPE_BIRD:

                    $sender_id = str_replace(['(', ')', '+', '-', ' '], '', $data['sender_id']);
                    $phone     = '+' . str_replace(['(', ')', '+', '-', ' '], '', $phone);

                    if (is_numeric($sender_id)) {
                        $sender_id = '+' . $sender_id;
                    } else {
                        $sender_id = $data['sender_id'];
                    }

                    $parameters = [
                        'to'       => $phone,
                        'from'     => $sender_id,
                        "callFlow" => [
                            [
                                "options" => [
                                    "text"   => $message,
                                    "locale" => $data['language'],
                                    "voice"  => $data['gender'],
                                ],
                                "command" => "say",
                            ],
                        ],
                    ];

                    $ch = curl_init();

                    curl_setopt($ch, CURLOPT_URL, $gateway_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                    curl_setopt($ch, CURLOPT_POST, 1);

                    $headers   = [];
                    $headers[] = "Authorization: Bearer $sending_server->access_key";
                    $headers[] = 'Content-Type: application/json';
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                    $result = curl_exec($ch);
                    if (curl_errno($ch)) {
                        $get_sms_status = curl_error($ch);
                    } else {

                        $response = json_decode($result, true);

                        if (is_array($response) && array_key_exists('id', $response)) {
                            $get_sms_status  = 'Delivered|' . $response['id'];
                            $customer_status = 'Delivered';
                        } else if (is_array($response) && array_key_exists('message', $response)) {
                            $get_sms_status  = $response['message'];
                            $customer_status = 'Undelivered';
                        } else {
                            $get_sms_status  = 'Unknown Error';
                            $customer_status = 'Failed';
                        }
                    }
                    curl_close($ch);

                    break;

                case SendingServer::TYPE_SIGNALWIRE:

                    $parameters = [
                        'From' => '+' . $data['sender_id'],
                        'Url'  => Tool::createVoiceFile($message, 'Twilio'),
                        'To'   => '+' . $phone,
                    ];

                    $sending_url = $gateway_url . "/api/laml/2010-04-01/Accounts/$sending_server->project_id/Calls.json";

                    $ch = curl_init();

                    curl_setopt($ch, CURLOPT_URL, $sending_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameters));
                    curl_setopt($ch, CURLOPT_USERPWD, "$sending_server->project_id" . ':' . "$sending_server->api_token");

                    $get_response = curl_exec($ch);
                    if (curl_errno($ch)) {
                        $get_sms_status = curl_error($ch);
                    } else {

                        $result = json_decode($get_response, true);

                        if (isset($result) && is_array($result) && array_key_exists('status', $result) && array_key_exists('error_code', $result)) {
                            if ($result['status'] == 'queued' && $result['error_code'] === null) {
                                $get_sms_status  = 'Delivered|' . $result['sid'];
                                $customer_status = 'Delivered';
                            } else {
                                $get_sms_status  = $result['error_message'];
                                $customer_status = 'Undelivered';
                            }
                        } else if (isset($result) && is_array($result) && array_key_exists('status', $result) && array_key_exists('message', $result)) {
                            $get_sms_status  = $result['message'];
                            $customer_status = 'Undelivered';
                        } else {
                            $get_sms_status  = $get_response;
                            $customer_status = 'Failed';
                        }

                        if ($get_sms_status === null) {
                            $get_sms_status  = 'Check your settings';
                            $customer_status = 'Failed';
                        }
                    }
                    curl_close($ch);
                    break;

                case SendingServer::TYPE_BULKSMSPROVIDERNG:

                    $parameters = [
                        'username' => $sending_server->username,
                        'password' => $sending_server->password,
                        'sender'   => $data['sender_id'],
                        'mobiles'  => $phone,
                        'message'  => $message,
                        'type'     => 'call',
                    ];

                    $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                    try {
                        $ch = curl_init();

                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_HTTPGET, 1);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/96.0.4664.45 Safari/537.36');
                        $result = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status = curl_error($ch);
                        } else {

                            $get_data = json_decode($result, true);

                            if (isset($get_data) && is_array($get_data)) {
                                if (array_key_exists('status', $get_data) && $get_data['status'] == 'OK') {
                                    $get_sms_status = $customer_status = 'Delivered';
                                } else if (array_key_exists('error', $get_data)) {
                                    $get_sms_status  = $get_data['error'];
                                    $customer_status = 'Undelivered';
                                } else {
                                    $get_sms_status = $customer_status = 'Failed';
                                }
                            } else {
                                $get_sms_status = $customer_status = 'Failed';
                            }
                        }
                        curl_close($ch);
                    } catch (Exception $e) {
                        $get_sms_status  = $e->getMessage();
                        $customer_status = 'Failed';
                    }
                    break;

                case SendingServer::TYPE_TELNYX:

                    $parameters = [
                        'To'   => '+' . $phone,
                        'From' => $data['sender_id'],
                        'Url'  => Tool::createVoiceFile($message, 'Twilio'),
                    ];

                    $ch = curl_init();

                    curl_setopt($ch, CURLOPT_URL, $gateway_url . '/' . $sending_server->c2);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameters));

                    $headers   = [];
                    $headers[] = 'Authorization: Bearer ' . $sending_server->api_key;
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                    $result = curl_exec($ch);
                    if (curl_errno($ch)) {
                        $get_sms_status = curl_error($ch);
                    } else {
                        $get_data = json_decode($result, true);

                        if (isset($get_data) && is_array($get_data)) {
                            if (array_key_exists('status', $get_data) && $get_data['status'] == 'queued') {
                                $get_sms_status = $customer_status = 'Delivered';
                            } else if (array_key_exists('errors', $get_data) && array_key_exists('0', $get_data['errors'])) {
                                $get_sms_status  = $get_data['errors'][0]['detail'];
                                $customer_status = 'Undelivered';
                            } else {
                                $get_sms_status = $customer_status = 'Failed';
                            }
                        } else {
                            $get_sms_status = $customer_status = 'Failed';
                        }
                    }
                    curl_close($ch);
                    break;

                case SendingServer::TYPE_AIRTELINDIA:
                    $voiceText = "<speak><voice language='$language' gender='$gender'>$message</voice></speak>";

                    $parameters = [
                        'callFlowId'            => $sending_server->c1,
                        'customerId'            => $sending_server->c2,
                        'callFlowConfiguration' => [
                            'initiateCall_1'          => [
                                'callerId'        => $data['sender_id'],
                                'mergingStrategy' => 'SEQUENTIAL',
                                'participants'    => [
                                    [
                                        'participantAddress' => $phone,
                                        'maxRetries'         => 1,
                                        'maxTime'            => 0,
                                    ],
                                ],
                            ],
                            'textToSpeech_vernacular' => [
                                'ttsVendor' => 'GOOGLE',
                                'textType'  => 'Ssml',
                                'text'      => $voiceText,
                            ],
                        ],
                        'callType'              => 'OUTBOUND',
                    ];

                    $ch = curl_init();

                    curl_setopt($ch, CURLOPT_URL, $gateway_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                    $headers = [
                        'Content-Type:application/json',
                        'Authorization:Basic ' . base64_encode("$sending_server->username:$sending_server->password"),
                    ];
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                    $result = curl_exec($ch);
                    if (curl_errno($ch)) {
                        $get_sms_status = curl_error($ch);
                    } else {

                        $get_data = json_decode($result, true);

                        if (isset($get_data) && is_array($get_data)) {
                            if (array_key_exists('status', $get_data) && $get_data['status'] == 'success') {
                                $get_sms_status  = 'Delivered|' . $get_data['correlationId'];
                                $customer_status = 'Delivered';
                            } else if (array_key_exists('errorMessage', $get_data)) {
                                $get_sms_status  = $get_data['errorMessage'];
                                $customer_status = 'Rejected';
                            } else if (array_key_exists('message', $get_data)) {
                                $get_sms_status  = $get_data['message'];
                                $customer_status = 'Undelivered';
                            } else {
                                $get_sms_status = $customer_status = 'Failed';
                            }
                        } else {
                            $get_sms_status = $customer_status = 'Failed';
                        }
                    }
                    curl_close($ch);
                    break;

                case SendingServer::TYPE_AUDIENCEONE:

                    $parameters = [
                        'api_key'   => $sending_server->api_key,
                        'phonebook' => $sending_server->c1,
                        'number'    => $phone,
                        'tts'       => $message,
                    ];

                    $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                    try {
                        $ch = curl_init();

                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_HTTPGET, 1);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        $result = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status  = curl_error($ch);
                            $customer_status = 'Rejected';
                        } else {
                            $get_sms_status  = substr_count($result, '200') == 1 ? 'Delivered' : $result;
                            $customer_status = $get_sms_status == 'Delivered' ? 'Delivered' : 'Undelivered';
                        }
                        curl_close($ch);
                    } catch (Exception $e) {
                        $get_sms_status  = $e->getMessage();
                        $customer_status = 'Failed';
                    }
                    break;

                case SendingServer::TYPE_ALDEAMO:

                    $convertedPhone = substr($phone, 2);

                    $parameters = [
                        'country'      => $sending_server->c1,
                        'message'      => $message,
                        'type'         => 'TTS',
                        'paramsTTS'    => [
                            'language' => $language,
                            'voice'    => $language . '-Standard-A',
                        ],
                        'adresseeList' => [
                            [
                                'mobile' => $convertedPhone,
                            ],
                        ],
                    ];

                    try {
                        $ch      = curl_init();
                        $headers = [
                            'Content-Type:application/json',
                            'Authorization:Basic ' . base64_encode("$sending_server->username:$sending_server->password"),
                        ];
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        $response = curl_exec($ch);
                        curl_close($ch);

                        $get_data = json_decode($response, true);


                        if (isset($get_data) && is_array($get_data) && array_key_exists('status', $get_data)) {
                            if ($get_data['status'] == 'SUCCESS') {
                                $get_sms_status = $customer_status = 'Delivered';
                            } else {
                                $get_sms_status  = $get_data['reason'];
                                $customer_status = 'Undelivered';
                            }
                        } else {
                            $get_sms_status = $customer_status = 'Failed';
                        }
                    } catch (Exception $exception) {
                        $get_sms_status  = $exception->getMessage();
                        $customer_status = 'Failed';
                    }
                    break;

                case SendingServer::TYPE_VOICEANDTEXT:

                    $parameters = [
                        'username' => $sending_server->username,
                        'password' => $sending_server->password,
                        'phone'    => $phone,
                        'filename' => $media_url,
                    ];

                    $sending_url = $gateway_url . '?' . http_build_query($parameters);

                    try {
                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $sending_url);
                        curl_setopt($ch, CURLOPT_HTTPGET, 1);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        $get_sms_status = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status  = curl_error($ch);
                            $customer_status = 'Failed';
                        } else {
                            $get_sms_status = $customer_status = trim($get_sms_status);
                            if (str_contains($get_sms_status, 'Call Attempt in Queue')) {
                                $get_sms_status = $customer_status = 'Delivered';
                            }

                        }
                        curl_close($ch);
                    } catch (Exception $exception) {
                        $get_sms_status  = $exception->getMessage();
                        $customer_status = 'Failed';
                    }
                    break;

                case SendingServer::TYPE_SMARTSMSSOLUTIONS:

                    $parameters = [
                        'token' => $sending_server->api_token,
                        'phone' => $phone,
                        'otp'   => $message,
                    ];

                    if (isset($sending_server->c1)) {
                        $parameters['class'] = $sending_server->c1;
                    }


                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $gateway_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);
                    $result = curl_exec($ch);

                    if (curl_errno($ch)) {
                        $get_sms_status  = curl_error($ch);
                        $customer_status = 'Failed';
                    } else {
                        $get_data = json_decode($result, true);
                        if (isset($get_data) && is_array($get_data)) {
                            if (array_key_exists('success', $get_data) && $get_data['success'] === true) {
                                $get_sms_status = $customer_status = 'Delivered';
                            } else if (array_key_exists('comment', $get_data)) {
                                $get_sms_status  = $get_data['comment'];
                                $customer_status = 'Undelivered';
                            } else {
                                $get_sms_status  = $result;
                                $customer_status = 'Failed';
                            }
                        } else {
                            $get_sms_status = $customer_status = 'Failed';
                        }
                    }
                    curl_close($ch);
                    break;

                case SendingServer::TYPE_FORDEMO:
                    $get_sms_status = $customer_status = 'Delivered';
                    break;

                case SendingServer::TYPE_ARKESEL:

                    $filename = public_path('mms/' . basename($media_url));

                    $parameters = [
                        'recipients[0]' => $phone,
                        'voice_file'    => new CURLFile($filename),
                        'callback_url'  => route('dlr.arkesel'),
                    ];

                    $ch = curl_init();

                    curl_setopt($ch, CURLOPT_URL, $gateway_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);

                    $headers   = [];
                    $headers[] = 'api-key: ' . $sending_server->api_key;
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                    $result = curl_exec($ch);

                    if (curl_errno($ch)) {
                        $get_sms_status = curl_error($ch);
                    } else {
                        $get_data = json_decode($result, true);

                        if (isset($get_data) && is_array($get_data) && array_key_exists('status', $get_data) && array_key_exists('message', $get_data)) {
                            if ($get_data['status'] == 'success') {
                                $get_sms_status = $customer_status = 'Delivered';
                            } else {
                                $get_sms_status  = $get_data['message'];
                                $customer_status = 'Failed';
                            }
                        } else {
                            $get_sms_status = $customer_status = 'Failed';
                        }
                    }
                    curl_close($ch);

                    break;

                case SendingServer::TYPE_TERMII:
                    $parameters = [
                        'api_key' => $sending_server->api_key,
                        'to'      => $phone,
                        'from'    => $data['sender_id'],
                        'sms'     => $message,
                        'channel' => 'voice',
                        'type'    => 'voice',
                    ];

                    try {

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        $headers   = [];
                        $headers[] = 'Accept: application/json';
                        $headers[] = 'Content-Type: application/json';
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                        $response = curl_exec($ch);
                        curl_close($ch);

                        $get_data = json_decode($response, true);

                        if (is_array($get_data)) {
                            if (array_key_exists('message_id', $get_data)) {
                                $get_sms_status = 'Delivered|' . $get_data['message_id'];
                            } else if (array_key_exists('message', $get_data)) {
                                $get_sms_status = $get_data['message'];
                            } else {
                                $get_sms_status = 'Failed';
                            }
                        } else {
                            $get_sms_status = $response;
                        }
                    } catch (Exception $e) {
                        $get_sms_status = $e->getMessage();
                    }
                    break;

                case SendingServer::TYPE_DOTGO:

                    $unique_id = uniqid();

                    $parameters = [
                        'id'        => $unique_id,
                        'recipient' => [$phone],
                        'media_url' => $media_url,
                    ];

                    if (isset($data['sender_id'])) {

                        $sender_id               = str_replace(['(', ')', '+', '-', ' '], '', $data['sender_id']);
                        $parameters['caller_id'] = $sender_id;
                    }


                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $gateway_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                    curl_setopt($ch, CURLOPT_ENCODING, '');
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Authorization: ' . $sending_server->api_token,
                        'Content-Type: application/json',
                        'Accept: application/json',
                    ]);

                    $response = curl_exec($ch);

                    if (curl_errno($ch)) {
                        $get_sms_status  = curl_error($ch);
                        $customer_status = 'Rejected';
                    } else {

                        $get_response = json_decode($response, true);

                        if (is_array($get_response) && array_key_exists('status', $get_response)) {

                            if ($get_response['status'] == 'ok' || $get_response['status'] == 'accepted') {
                                $get_sms_status  = 'Delivered|' . $unique_id;
                                $customer_status = 'Delivered';
                            } else {
                                $get_sms_status  = $get_response['error_reason'];
                                $customer_status = 'Rejected';
                            }

                        } else {
                            $get_sms_status  = $response;
                            $customer_status = 'Failed';
                        }
                    }

                    curl_close($ch);

                    break;

                case SendingServer::TYPE_ULTIMATESMS:
                    $parameters = [
                        'api_token' => $sending_server->api_token,
                        'message'   => $message,
                        'recipient' => $phone,
                        'type'      => 'voice',
                        'language'  => $language,
                        'gender'    => $gender,
                    ];

                    if (isset($data['sender_id'])) {
                        $parameters['sender_id'] = $data['sender_id'];
                    }


                    $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $gateway_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_HTTPGET, 1);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

                    $result = curl_exec($ch);


                    if (curl_errno($ch)) {
                        $get_sms_status = curl_error($ch);
                    } else {
                        $get_data = json_decode($result, true);
                        if (isset($get_data) && is_array($get_data) && array_key_exists('status', $get_data)) {
                            if ($get_data['status'] == 'success') {
                                $get_sms_status = 'Delivered';
                            } else {
                                $get_sms_status = $get_data['message'];
                            }
                        } else {
                            $get_sms_status = 'Failed';
                        }
                    }

                    curl_close($ch);
                    break;

                default:
                    $get_sms_status  = __('locale.sending_servers.sending_server_not_found');
                    $customer_status = 'Failed';
                    break;
            }

            $cost = substr_count($get_sms_status, 'Delivered') == 1 ? $data['cost'] : '0';

            $reportsData = [
                'user_id'           => $data['user_id'],
                'to'                => $phone,
                'message'           => $message,
                'sms_type'          => 'voice',
                'status'            => $get_sms_status,
                'customer_status'   => $customer_status,
                'sms_count'         => $data['sms_count'],
                'cost'              => $cost,
                'sending_server_id' => $sending_server->id,
            ];

            if (isset($data['sender_id'])) {
                $reportsData['from'] = $data['sender_id'];
            }

            if (isset($data['campaign_id'])) {
                $reportsData['campaign_id'] = $data['campaign_id'];
            }

            if (isset($data['automation_id'])) {
                $reportsData['automation_id'] = $data['automation_id'];
            }

            if (isset($data['api_key'])) {
                $reportsData['api_key']   = $data['api_key'];
                $reportsData['direction'] = Reports::DIRECTION_API;
            } else {
                $reportsData['direction'] = Reports::DIRECTION_OUTGOING;
            }

            $status = Reports::create($reportsData);

            if ($status) {
                return $status;
            }

            return __('locale.exceptions.something_went_wrong');

        }

        /**
         * send mms message
         *
         *
         * @return array|Application|Translator|string|null
         */
        public
        function sendMMS($data)
        {
            $phone          = str_replace(['+', '(', ')', '-', ' '], '', $data['phone']);
            $sending_server = $data['sending_server'];
            $gateway_name   = $data['sending_server']->settings;
            $media_url      = $data['media_url'];
            $gateway_url    = $sending_server->mms_api_link ?? $sending_server->api_link;
            $message        = null;

            if (isset($data['message'])) {
                $message = $data['message'];
            }

            switch ($gateway_name) {

                case SendingServer::TYPE_TWILIO:

                    try {
                        $client = new Client($sending_server->account_sid, $sending_server->auth_token);

                        $get_response = $client->messages->create($phone, [
                            'from'     => $data['sender_id'],
                            'body'     => $message,
                            'mediaUrl' => $media_url,
                        ]);

                        if ($get_response->status == 'queued' || $get_response->status == 'accepted') {
                            $get_sms_status  = 'Delivered|' . $get_response->sid;
                            $customer_status = 'Delivered';
                        } else {
                            $get_sms_status  = $get_response->status . '|' . $get_response->sid;
                            $customer_status = ucfirst($get_response->status);
                        }

                    } catch (ConfigurationException|TwilioException $e) {
                        $get_sms_status  = $e->getMessage();
                        $customer_status = 'Failed';
                    }
                    break;

                case SendingServer::TYPE_TEXTLOCAL:

                    $parameters = [
                        'apikey'  => $sending_server->api_key,
                        'numbers' => $phone,
                        'url'     => $media_url,
                        'message' => $message,
                    ];

                    if (isset($data['sender_id'])) {
                        $parameters['sender'] = $data['sender_id'];
                    }

                    try {
                        $ch = curl_init($gateway_url);
                        curl_setopt($ch, CURLOPT_POST, true);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        $response = curl_exec($ch);
                        $err      = curl_error($ch);
                        curl_close($ch);

                        if ($err) {
                            $get_sms_status  = $err;
                            $customer_status = 'Rejected';
                        } else {
                            $get_data = json_decode($response, true);

                            if (isset($get_data) && is_array($get_data) && array_key_exists('status', $get_data)) {
                                if ($get_data['status'] == 'failure') {
                                    foreach ($get_data['errors'] as $err) {
                                        $get_sms_status = $err['message'];
                                    }
                                    $customer_status = 'Undelivered';
                                } else if ($get_data['status'] == 'success') {
                                    $get_sms_status = $customer_status = 'Delivered';
                                } else {
                                    $get_sms_status  = $response;
                                    $customer_status = 'Failed';
                                }
                            } else {
                                $get_sms_status  = $response;
                                $customer_status = 'Failed';
                            }
                        }
                    } catch (Exception $exception) {
                        $get_sms_status  = $exception->getMessage();
                        $customer_status = 'Failed';
                    }
                    break;

                case SendingServer::TYPE_PLIVO:
                    $parameters = json_encode([
                        'src'        => $data['sender_id'],
                        'dst'        => $phone,
                        'text'       => $message,
                        'type'       => 'mms',
                        'media_urls' => [
                            $media_url,
                        ],
                    ]);

                    $ch = curl_init();

                    curl_setopt($ch, CURLOPT_URL, "https://api.plivo.com/v1/Account/$sending_server->auth_id/Message/");
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_USERPWD, "$sending_server->auth_id" . ':' . "$sending_server->auth_token");

                    $headers   = [];
                    $headers[] = 'Content-Type: application/json';
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                    $result = curl_exec($ch);
                    if (curl_errno($ch)) {
                        $get_sms_status = curl_error($ch);
                    } else {
                        $response = json_decode($result, true);

                        if (json_last_error() == JSON_ERROR_NONE) {
                            if (isset($response) && is_array($response) && array_key_exists('message', $response)) {
                                if (substr_count($response['message'], 'queued')) {
                                    $get_sms_status = $customer_status = 'Delivered';
                                } else {
                                    $get_sms_status  = $response['message'];
                                    $customer_status = 'Undelivered';
                                }
                            } else if (isset($response) && is_array($response) && array_key_exists('error', $response)) {
                                $get_sms_status  = $response['error'];
                                $customer_status = 'Undelivered';
                            } else {
                                $get_sms_status  = trim($result);
                                $customer_status = 'Failed';
                            }
                        } else {
                            $get_sms_status  = trim($result);
                            $customer_status = 'Failed';
                        }
                    }
                    curl_close($ch);

                    break;

                case SendingServer::TYPE_PLIVOPOWERPACK:
                    $parameters = json_encode([
                        'powerpack_uuid' => $data['sender_id'],
                        'dst'            => $phone,
                        'text'           => $message,
                        'type'           => 'mms',
                        'media_urls'     => [
                            $media_url,
                        ],
                    ]);

                    $ch = curl_init();

                    curl_setopt($ch, CURLOPT_URL, "https://api.plivo.com/v1/Account/$sending_server->auth_id/Message/");
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_USERPWD, "$sending_server->auth_id" . ':' . "$sending_server->auth_token");

                    $headers   = [];
                    $headers[] = 'Content-Type: application/json';
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                    $result = curl_exec($ch);
                    if (curl_errno($ch)) {
                        $get_sms_status = curl_error($ch);
                    } else {
                        $response = json_decode($result, true);

                        if (json_last_error() == JSON_ERROR_NONE) {
                            if (isset($response) && is_array($response) && array_key_exists('message', $response)) {
                                if (substr_count($response['message'], 'queued')) {
                                    $get_sms_status = $customer_status = 'Delivered';
                                } else {
                                    $get_sms_status  = $response['message'];
                                    $customer_status = 'Undelivered';
                                }
                            } else if (isset($response) && is_array($response) && array_key_exists('error', $response)) {
                                $get_sms_status  = $response['error'];
                                $customer_status = 'Undelivered';
                            } else {
                                $get_sms_status  = trim($result);
                                $customer_status = 'Failed';
                            }
                        } else {
                            $get_sms_status  = trim($result);
                            $customer_status = 'Failed';
                        }
                    }
                    curl_close($ch);

                    break;

                case SendingServer::TYPE_SMSGLOBAL:

                    $parameters = [
                        'user'        => $sending_server->username,
                        'password'    => $sending_server->password,
                        'from'        => $data['sender_id'],
                        'number'      => $phone,
                        'message'     => $message,
                        'attachmentx' => $media_url,
                        'typex'       => image_type_to_mime_type(exif_imagetype($media_url)),
                        'namex'       => basename($media_url),
                    ];

                    $sending_url = $gateway_url . '?' . http_build_query($parameters);

                    try {

                        $ch = curl_init();

                        curl_setopt($ch, CURLOPT_URL, $sending_url);
                        curl_setopt($ch, CURLOPT_HTTPGET, 1);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        $get_sms_status = curl_exec($ch);
                        curl_close($ch);

                        if (substr_count($get_sms_status, 'SUCCESS')) {
                            $get_sms_status = $customer_status = 'Delivered';
                        } else {
                            $get_sms_status  = str_replace('ERROR:', '', $get_sms_status);
                            $customer_status = 'Failed';
                        }

                    } catch (Exception $exception) {
                        $get_sms_status  = $exception->getMessage();
                        $customer_status = 'Failed';
                    }
                    break;

                case SendingServer::TYPE_MESSAGEBIRD:
                    $parameters = [
                        'recipients' => $data['phone'],
                        'originator' => $data['sender_id'],
                        'body'       => $message,
                        'mediaUrls'  => [$media_url],
                    ];

                    $ch = curl_init();

                    curl_setopt($ch, CURLOPT_URL, $gateway_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                    curl_setopt($ch, CURLOPT_POST, 1);

                    $headers   = [];
                    $headers[] = "Authorization: AccessKey $sending_server->api_key";
                    $headers[] = 'Content-Type: application/x-www-form-urlencoded';
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                    $result = curl_exec($ch);
                    if (curl_errno($ch)) {
                        $get_sms_status  = curl_error($ch);
                        $customer_status = 'Failed';
                    } else {
                        $response = json_decode($result, true);

                        if (is_array($response) && array_key_exists('id', $response)) {
                            $get_sms_status  = 'Delivered|' . $response['id'];
                            $customer_status = 'Delivered';
                        } else if (is_array($response) && array_key_exists('errors', $response)) {
                            $get_sms_status  = $response['errors'][0]['description'];
                            $customer_status = 'Undelivered';
                        } else {
                            $get_sms_status  = 'Unknown Error';
                            $customer_status = 'Failed';
                        }
                    }
                    curl_close($ch);

                    break;

                case SendingServer::TYPE_SIGNALWIRE:

                    $parameters = [
                        'From'     => '+' . $data['sender_id'],
                        'Body'     => $message,
                        'MediaUrl' => $media_url,
                        'To'       => '+' . $phone,
                    ];

                    $sending_url = $gateway_url . "/api/laml/2010-04-01/Accounts/$sending_server->project_id/Messages.json";

                    $ch = curl_init();

                    curl_setopt($ch, CURLOPT_URL, $sending_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameters));
                    curl_setopt($ch, CURLOPT_USERPWD, "$sending_server->project_id" . ':' . "$sending_server->api_token");

                    $get_response = curl_exec($ch);
                    if (curl_errno($ch)) {
                        $get_sms_status  = curl_error($ch);
                        $customer_status = 'Failed';
                    } else {

                        $result = json_decode($get_response, true);

                        if (isset($result) && is_array($result) && array_key_exists('status', $result) && array_key_exists('error_code', $result)) {
                            if ($result['status'] == 'queued' && $result['error_code'] === null) {
                                $get_sms_status  = 'Delivered|' . $result['sid'];
                                $customer_status = 'Delivered';
                            } else {
                                $get_sms_status  = $result['error_message'];
                                $customer_status = 'Rejected';
                            }
                        } else if (isset($result) && is_array($result) && array_key_exists('status', $result) && array_key_exists('message', $result)) {
                            $get_sms_status  = $result['message'];
                            $customer_status = 'Rejected';
                        } else {
                            $get_sms_status  = $get_response;
                            $customer_status = 'Failed';
                        }

                        if ($get_sms_status === null) {
                            $get_sms_status  = 'Check your settings';
                            $customer_status = 'Rejected';
                        }
                    }
                    curl_close($ch);

                    break;

                case SendingServer::TYPE_TELNYX:
                    $parameters = [
                        'to'         => '+' . $phone,
                        'text'       => $message,
                        'subject'    => 'Picture',
                        'media_urls' => [$media_url],
                    ];

                    if (is_numeric($data['sender_id'])) {
                        $parameters['from'] = '+' . $data['sender_id'];
                    } else {
                        $parameters['from']                 = $data['sender_id'];
                        $parameters['messaging_profile_id'] = $sending_server->c1;
                    }

                    try {

                        $headers = [
                            'Content-Type:application/json',
                            'Authorization: Bearer ' . $sending_server->api_key,
                        ];

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        $response = curl_exec($ch);
                        curl_close($ch);

                        $get_response = json_decode($response, true);

                        if (isset($get_response) && is_array($get_response)) {
                            if (array_key_exists('data', $get_response) && array_key_exists('to', $get_response['data']) && $get_response['data']['to'][0]['status'] == 'queued') {
                                $get_sms_status = $customer_status = 'Delivered';
                            } else if (array_key_exists('errors', $get_response)) {
                                $get_sms_status  = $get_response['errors'][0]['detail'];
                                $customer_status = 'Undelivered';
                            } else {
                                $get_sms_status  = (string) $response;
                                $customer_status = 'Failed';
                            }
                        } else {
                            $get_sms_status  = 'Unknown error';
                            $customer_status = 'Failed';
                        }

                    } catch (Exception $e) {
                        $get_sms_status  = $e->getMessage();
                        $customer_status = 'Failed';
                    }
                    break;

                case SendingServer::TYPE_TELNYXNUMBERPOOL:
                    $parameters = [
                        'to'                   => '+' . $phone,
                        'text'                 => $message,
                        'messaging_profile_id' => $sending_server->c1,
                        'subject'              => 'Picture',
                        'media_urls'           => [$media_url],
                    ];

                    try {

                        $headers = [
                            'Content-Type:application/json',
                            'Authorization: Bearer ' . $sending_server->api_key,
                        ];

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        $response = curl_exec($ch);
                        curl_close($ch);

                        $get_response = json_decode($response, true);

                        if (isset($get_response) && is_array($get_response)) {
                            if (array_key_exists('data', $get_response) && array_key_exists('to', $get_response['data']) && $get_response['data']['to'][0]['status'] == 'queued') {
                                $get_sms_status = $customer_status = 'Delivered';
                            } else if (array_key_exists('errors', $get_response)) {
                                $get_sms_status  = $get_response['errors'][0]['detail'];
                                $customer_status = 'Rejected';
                            } else {
                                $get_sms_status  = (string) $response;
                                $customer_status = 'Failed';
                            }
                        } else {
                            $get_sms_status  = 'Unknown error';
                            $customer_status = 'Failed';
                        }

                    } catch (Exception $e) {
                        $get_sms_status  = $e->getMessage();
                        $customer_status = 'Failed';
                    }
                    break;

                case SendingServer::TYPE_BANDWIDTH:

                    $sender_id = str_replace(['+', '(', ')', '-', ' '], '', $data['sender_id']);

                    $parameters = [
                        'from'          => $sender_id,
                        'to'            => [$phone],
                        'text'          => $message,
                        'applicationId' => $sending_server->application_id,
                        'media'         => [$media_url],
                    ];

                    try {

                        $ch = curl_init();

                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_USERPWD, $sending_server->api_token . ':' . $sending_server->api_secret);

                        $headers   = [];
                        $headers[] = 'Content-Type: application/json';
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                        $result = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status = curl_error($ch);
                        } else {

                            $result = json_decode($result, true);

                            if (isset($result) && is_array($result)) {
                                if (array_key_exists('id', $result)) {
                                    $get_sms_status  = 'Delivered|' . $result['id'];
                                    $customer_status = 'Delivered';
                                } else if (array_key_exists('error', $result)) {
                                    $get_sms_status  = $result['error'];
                                    $customer_status = 'Undelivered';
                                } else if (array_key_exists('fieldErrors', $result)) {
                                    $get_sms_status  = $result['fieldErrors'][0]['fieldName'] . ' ' . $result['fieldErrors'][0]['description'];
                                    $customer_status = 'Rejected';
                                } else {
                                    $get_sms_status  = implode(' ', $result);
                                    $customer_status = 'Failed';
                                }
                            } else {
                                $get_sms_status  = $result;
                                $customer_status = 'Failed';
                            }
                        }
                        curl_close($ch);
                    } catch (Exception $e) {
                        $get_sms_status  = $e->getMessage();
                        $customer_status = 'Failed';
                    }
                    break;

                case SendingServer::TYPE_FLOWROUTE:
                    $phone     = str_replace(['+', '(', ')', '-', ' '], '', $phone);
                    $sender_id = str_replace(['+', '(', ')', '-', ' '], '', $data['sender_id']);

                    $sms = [
                        'from'       => $sender_id,
                        'to'         => $phone,
                        'body'       => $message,
                        'is_mms'     => true,
                        'media_urls' => [
                            $media_url,
                        ],
                    ];

                    try {

                        $headers   = [];
                        $headers[] = 'Content-Type: application/vnd.api+json';

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($sms, JSON_UNESCAPED_SLASHES));
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_USERPWD, $sending_server->access_key . ':' . $sending_server->api_secret);

                        $response = curl_exec($ch);
                        curl_close($ch);

                        $get_response = json_decode($response, true);

                        if (isset($get_response) && is_array($get_response)) {
                            if (array_key_exists('data', $get_response)) {
                                $get_sms_status = $customer_status = 'Delivered';
                            } else if (array_key_exists('errors', $get_response)) {
                                $get_sms_status  = $get_response['errors'][0]['detail'];
                                $customer_status = 'Undelivered';
                            } else {
                                $get_sms_status  = 'Invalid request';
                                $customer_status = 'Rejected';
                            }
                        } else {
                            $get_sms_status  = 'Invalid request';
                            $customer_status = 'Rejected';
                        }

                    } catch (Exception $ex) {
                        $get_sms_status  = $ex->getMessage();
                        $customer_status = 'Failed';
                    }

                    break;

                case SendingServer::TYPE_SKYETEL:
                    $parameters = [
                        'to'    => $phone,
                        'text'  => $message,
                        'media' => [
                            $media_url,
                        ],
                    ];

                    if (isset($data['sender_id'])) {
                        $gateway_url .= '?from=' . $data['sender_id'];
                    }

                    try {
                        $ch = curl_init();

                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

                        $headers   = [];
                        $headers[] = 'Authorization: Basic ' . base64_encode("$sending_server->account_sid:$sending_server->api_secret");
                        $headers[] = 'Content-Type: application/json';
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                        $result = curl_exec($ch);
                        curl_close($ch);

                        $result = json_decode($result, true);

                        if (is_array($result)) {
                            if (array_key_exists('direction', $result)) {
                                $get_sms_status = $customer_status = 'Delivered';
                            } else if (array_key_exists('message', $result)) {
                                $get_sms_status  = $result['message'];
                                $customer_status = 'Undelivered';
                            } else {
                                $get_sms_status  = implode(' ', $result);
                                $customer_status = 'Failed';
                            }
                        } else {
                            $get_sms_status  = 'Invalid request';
                            $customer_status = 'Failed';
                        }

                    } catch (Exception $e) {
                        $get_sms_status  = $e->getMessage();
                        $customer_status = 'Failed';
                    }
                    break;

                case SendingServer::TYPE_TXTRIA:
                    $parameters = [
                        'sys_id'     => $sending_server->c1,
                        'auth_token' => $sending_server->auth_token,
                        'From'       => $data['sender_id'],
                        'To'         => $phone,
                        'FileName0'  => basename($media_url),
                        'MediaUrl0'  => base64_encode(file_get_contents($media_url)),
                    ];
                    if ($message != null) {
                        $parameters['Body'] = urlencode($message);
                    }

                    try {
                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);
                        $response = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status = curl_error($ch);
                        } else {
                            $get_response = json_decode($response, true);

                            if (isset($get_response) && is_array($get_response)) {
                                if (array_key_exists('success', $get_response) && $get_response['success'] == 1) {
                                    $get_sms_status = $customer_status = 'Delivered';
                                } else if (array_key_exists('error', $get_response) && $get_response['error'] == 1) {
                                    $get_sms_status  = $get_response['message'];
                                    $customer_status = 'Undelivered';
                                } else {
                                    $get_sms_status  = (string) $response;
                                    $customer_status = 'Failed';
                                }
                            } else {
                                $get_sms_status  = (string) $response;
                                $customer_status = 'Failed';
                            }
                        }
                        curl_close($ch);
                    } catch (Exception $exception) {
                        $get_sms_status  = $exception->getMessage();
                        $customer_status = 'Failed';
                    }
                    break;

                case SendingServer::TYPE_TELEAPI:

                    $parameters = [
                        'source'      => $data['sender_id'],
                        'destination' => $phone,
                        'file_url'    => urlencode($media_url),
                    ];

                    $gateway_url = $gateway_url . '?token=' . $sending_server->api_token;

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $gateway_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameters));

                    $response = curl_exec($ch);

                    if (curl_errno($ch)) {
                        $get_sms_status  = curl_error($ch);
                        $customer_status = 'Failed';
                    } else {

                        $get_response = json_decode($response, true);

                        if (is_array($get_response) && array_key_exists('status', $get_response)) {
                            if ($get_response['status'] == 'success') {
                                $get_sms_status = $customer_status = 'Delivered';
                            } else {
                                $get_sms_status  = $get_response['data'];
                                $customer_status = 'Undelivered';
                            }
                        } else {
                            $get_sms_status  = $response;
                            $customer_status = 'Failed';
                        }
                    }

                    curl_close($ch);
                    break;

                case SendingServer::TYPE_MMSCONSOLE:

                    $parameters = [
                        'email' => $sending_server->c1,
                        'token' => $sending_server->api_token,
                        'num'   => '+' . $phone,
                        'text'  => $message,
                        'type'  => 'MMS',
                        'img'   => $media_url,
                    ];

                    try {

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        $response = curl_exec($ch);
                        curl_close($ch);

                        $get_data = json_decode($response, true);

                        if (is_array($get_data)) {
                            if (array_key_exists('id_campagne', $get_data)) {
                                $get_sms_status  = 'Delivered|' . $get_data['id_campagne'];
                                $customer_status = 'Delivered';
                            } else if (array_key_exists('error', $get_data)) {
                                $get_sms_status  = $get_data['error'];
                                $customer_status = 'Undelivered';
                            } else {
                                $get_sms_status  = 'Failure';
                                $customer_status = 'Failed';
                            }
                        } else {
                            $get_sms_status  = $response;
                            $customer_status = 'Failed';
                        }
                    } catch (Exception $e) {
                        $get_sms_status  = $e->getMessage();
                        $customer_status = 'Failed';
                    }
                    break;

                case SendingServer::TYPE_BMSGLOBAL:

                    $parameters = [
                        'token'    => $sending_server->api_token,
                        'sender'   => $data['sender_id'],
                        'receiver' => $phone,
                        'msgtext'  => $message,
                        'mediaurl' => $media_url,
                    ];

                    try {

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        $response = curl_exec($ch);
                        curl_close($ch);

                        if ($response == 'Success') {
                            $get_sms_status = $customer_status = 'Delivered';
                        } else {
                            $get_sms_status = $customer_status = 'Failed';
                        }

                    } catch (Exception $e) {
                        $get_sms_status  = $e->getMessage();
                        $customer_status = 'Failed';
                    }
                    break;

                case SendingServer::TYPE_WAVIX:

                    $parameters = [
                        'from'         => $data['sender_id'],
                        'to'           => $phone,
                        'message_body' => [
                            'text'  => $message,
                            'media' => [
                                $media_url,
                            ],
                        ],
                    ];

                    $sending_url = $gateway_url . '?appid=' . $sending_server->application_id;
                    try {

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $sending_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'Content-Type: application/json',
                        ]);
                        $result = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status  = curl_error($ch);
                            $customer_status = 'Failed';
                        } else {
                            $get_data = json_decode($result, true);
                            if (isset($get_data) && is_array($get_data)) {
                                if (array_key_exists('message_id', $get_data)) {
                                    $get_sms_status  = 'Delivered|' . $get_data['message_id'];
                                    $customer_status = 'Delivered';
                                } else if (array_key_exists('error', $get_data) && $get_data['error']) {
                                    $get_sms_status  = $get_data['message'];
                                    $customer_status = 'Undelivered';
                                } else {
                                    $get_sms_status  = $result;
                                    $customer_status = 'Failed';
                                }
                            } else {
                                $get_sms_status  = $result;
                                $customer_status = 'Failed';
                            }
                        }

                        curl_close($ch);
                    } catch (Exception $exception) {
                        $get_sms_status  = $exception->getMessage();
                        $customer_status = 'Failed';
                    }
                    break;

                case SendingServer::TYPE_TEXTGRID:
                    $parameters = [
                        'from'     => $data['sender_id'],
                        'to'       => $phone,
                        'body'     => $message,
                        'mediaUrl' => [$media_url],
                    ];

                    try {
                        $ch = curl_init();

                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

                        $headers   = [];
                        $headers[] = 'Authorization: Basic ' . base64_encode("$sending_server->account_sid:$sending_server->auth_token");
                        $headers[] = 'Content-Type: application/json';
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                        $result = curl_exec($ch);
                        curl_close($ch);

                        $result = json_decode($result, true);

                        if (is_array($result) && array_key_exists('status', $result)) {
                            if ($result['status'] == 'sent') {
                                $get_sms_status = $customer_status = 'Delivered';
                            } else if ($result['status'] == 'failed') {
                                $get_sms_status  = $result['error_message'];
                                $customer_status = 'Failed';
                            } else {
                                $get_sms_status  = $result['status'];
                                $customer_status = 'Failed';
                            }
                        } else {
                            $get_sms_status = $customer_status = 'Failed';
                        }

                    } catch (Exception $e) {
                        $get_sms_status  = $e->getMessage();
                        $customer_status = 'Failed';
                    }
                    break;

                case SendingServer::TYPE_800COM:

                    $parameters = [
                        'recipient' => '+' . $phone,
                        'sender'    => '+' . $data['sender_id'],
                        'message'   => $message,
                        'url'       => $media_url,
                    ];

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $gateway_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Authorization: Bearer ' . $sending_server->api_token,
                        'Content-Type: application/json',
                        'Accept: application/json',
                    ]);

                    $response = curl_exec($ch);

                    if (curl_errno($ch)) {
                        $get_sms_status  = curl_error($ch);
                        $customer_status = 'Rejected';
                    } else {

                        $get_response = json_decode($response, true);

                        if (is_array($get_response)) {

                            if (array_key_exists('data', $get_response)) {
                                $get_sms_status = $customer_status = 'Delivered';
                            } else if (array_key_exists('errors', $get_response) && array_key_exists('message', $get_response)) {
                                $get_sms_status  = $get_response['message'];
                                $customer_status = 'Rejected';
                            } else {
                                $get_sms_status = $customer_status = 'Rejected';
                            }

                        } else {
                            $get_sms_status  = $response;
                            $customer_status = 'Rejected';
                        }
                    }

                    curl_close($ch);
                    break;

                case SendingServer::TYPE_CLICKSEND:

                    $sender_id = str_replace(['(', ')', '+', '-', ' '], '', $data['sender_id']);
                    $phone     = '+' . str_replace(['(', ')', '+', '-', ' '], '', $phone);

                    if (is_numeric($sender_id)) {
                        $sender_id = '+' . $sender_id;
                    } else {
                        $sender_id = $data['sender_id'];
                    }

                    $config = Configuration::getDefaultConfiguration()
                        ->setUsername($sending_server->username)
                        ->setPassword($sending_server->api_key);

                    $apiInstance = new MMSApi(new \GuzzleHttp\Client(), $config);
                    $msg         = new MmsMessage();
                    $msg->setSource($sender_id);
                    $msg->setFrom($sender_id);
                    $msg->setBody($message);
                    $msg->setSubject(basename($media_url));
                    $msg->setTo($phone);

                    $sms_messages = new MmsMessageCollection();
                    $sms_messages->setMediaFile($media_url);
                    $sms_messages->setMessages([$msg]);


                    try {

                        $result       = $apiInstance->mmsSendPost($sms_messages);
                        $get_response = json_decode($result, true);

                        if (is_array($get_response) && array_key_exists('http_code', $get_response) && array_key_exists('response_msg', $get_response)) {
                            if ($get_response['http_code'] == 200) {
                                $get_sms_status = $customer_status = 'Delivered';
                            } else {
                                $get_sms_status  = $get_response['response_msg'];
                                $customer_status = 'Rejected';
                            }
                        } else {
                            $get_sms_status  = $result;
                            $customer_status = 'Rejected';
                        }

                    } catch (ApiException $e) {
                        $getData      = $e->getResponseBody();
                        $get_response = json_decode($getData, true);

                        if (is_array($get_response) && array_key_exists('http_code', $get_response) && array_key_exists('response_msg', $get_response)) {
                            if ($get_response['http_code'] == 200) {
                                $get_sms_status = $customer_status = 'Delivered';
                            } else {
                                $get_sms_status  = $get_response['response_msg'];
                                $customer_status = 'Rejected';
                            }
                        } else {
                            $get_sms_status  = $getData;
                            $customer_status = 'Rejected';
                        }
                    }
                    break;

                case SendingServer::TYPE_TELUCOMAPIS:

                    $parameters = [
                        'token'        => $sending_server->api_token,
                        'from'         => $data['sender_id'],
                        'to'           => $phone,
                        'message_text' => $message,
                        'image_url'    => $media_url,
                    ];

                    $headers = [
                        "Accept: application/json",
                        "Content-Type: application/json",
                    ];

                    $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                    try {
                        $ch = curl_init();

                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_HTTPGET, 1);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                        $result = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status  = curl_error($ch);
                            $customer_status = 'Rejected';
                        } else {

                            $response = json_decode($result, true);

                            if (json_last_error() == JSON_ERROR_NONE) {
                                if (isset($response) && is_array($response) && array_key_exists('status', $response) && array_key_exists('status-details', $response)) {
                                    if ($response['status'] == 'success') {
                                        $get_sms_status = $customer_status = 'Delivered';
                                    } else {
                                        $get_sms_status  = $response['status-details'];
                                        $customer_status = 'Failed';
                                    }
                                } else {
                                    $get_sms_status  = $result;
                                    $customer_status = 'Failed';
                                }
                            } else {
                                $get_sms_status  = trim($result);
                                $customer_status = 'Failed';
                            }

                        }
                        curl_close($ch);
                    } catch (Exception $e) {
                        $get_sms_status  = $e->getMessage();
                        $customer_status = 'Rejected';
                    }
                    break;

                case SendingServer::TYPE_EASYSMSXYZ:

                    $parameters = [
                        'number'      => $phone,
                        'message'     => $message,
                        'schedule'    => null,
                        'key'         => $sending_server->api_key,
                        'devices'     => $sending_server->device_id,
                        'type'        => 'mms',
                        'attachments' => $media_url,
                    ];

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $gateway_url);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36');
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameters));
                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    if (curl_errno($ch)) {
                        $get_sms_status  = curl_error($ch);
                        $customer_status = 'Failed';
                    } else {
                        if ($httpCode == 200) {
                            $json = json_decode($response, true);

                            if ( ! $json) {
                                if (empty($response)) {
                                    $get_sms_status = 'Missing data in request. Please provide all the required information to send messages.';
                                } else {
                                    $get_sms_status = $response;
                                }
                                $customer_status = 'Failed';
                            } else {
                                if ($json['success']) {
                                    $get_sms_status = $customer_status = 'Delivered';
                                } else {
                                    $get_sms_status  = $json['error']['message'];
                                    $customer_status = 'Failed';
                                }
                            }
                        } else {
                            $get_sms_status  = 'Error Code: ' . $httpCode;
                            $customer_status = 'Failed';
                        }
                    }
                    curl_close($ch);
                    break;


                case SendingServer::TYPE_FORDEMO:
                    $get_sms_status = $customer_status = "Delivered";
                    break;


                case SendingServer::TYPE_ULTIMATESMS:
                    $parameters = [
                        'api_token' => $sending_server->api_token,
                        'message'   => $message,
                        'recipient' => $phone,
                        'type'      => 'mms',
                        'media_url' => $media_url,
                    ];

                    if (isset($data['sender_id'])) {
                        $parameters['sender_id'] = $data['sender_id'];
                    }


                    $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $gateway_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_HTTPGET, 1);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

                    $result = curl_exec($ch);


                    if (curl_errno($ch)) {
                        $get_sms_status = curl_error($ch);
                    } else {
                        $get_data = json_decode($result, true);
                        if (isset($get_data) && is_array($get_data) && array_key_exists('status', $get_data)) {
                            if ($get_data['status'] == 'success') {
                                $get_sms_status = 'Delivered';
                            } else {
                                $get_sms_status = $get_data['message'];
                            }
                        } else {
                            $get_sms_status = 'Failed';
                        }
                    }

                    curl_close($ch);
                    break;


                default:
                    $get_sms_status  = __('locale.sending_servers.sending_server_not_found');
                    $customer_status = 'Failed';
                    break;
            }

            $cost = substr_count($get_sms_status, 'Delivered') == 1 ? $data['cost'] : '0';

            $reportsData = [
                'user_id'           => $data['user_id'],
                'to'                => $phone,
                'message'           => $message,
                'sms_type'          => 'mms',
                'status'            => $get_sms_status,
                'customer_status'   => $customer_status,
                'sms_count'         => $data['sms_count'],
                'cost'              => $cost,
                'sending_server_id' => $sending_server->id,
                'media_url'         => $media_url,
            ];

            if (isset($data['sender_id'])) {
                $reportsData['from'] = $data['sender_id'];
            }

            if (isset($data['campaign_id'])) {
                $reportsData['campaign_id'] = $data['campaign_id'];
            }

            if (isset($data['automation_id'])) {
                $reportsData['automation_id'] = $data['automation_id'];
            }

            if (isset($data['api_key'])) {
                $reportsData['api_key']   = $data['api_key'];
                $reportsData['direction'] = Reports::DIRECTION_API;
            } else {
                $reportsData['direction'] = Reports::DIRECTION_OUTGOING;
            }

            $status = Reports::create($reportsData);

            if ($status) {
                return $status;
            }

            return __('locale.exceptions.something_went_wrong');

        }

        /**
         * send whatsapp message
         *
         *
         * @return array|Application|Translator|string|null
         */
        public
        function sendWhatsApp($data)
        {
            $phone          = $data['phone'];
            $sending_server = $data['sending_server'];
            $gateway_name   = $data['sending_server']->settings;
            $message        = $data['message'];
            $gateway_url    = $sending_server->whatsapp_api_link ?? $sending_server->api_link;
            $media_url      = null;

            if (isset($data['media_url'])) {
                $media_url = $data['media_url'];
            }

            switch ($gateway_name) {

                case SendingServer::TYPE_TWILIO:

                    $parameters = [
                        'from' => 'whatsapp:' . $data['sender_id'],
                        'body' => $message,
                    ];

                    if ($media_url != null) {
                        $parameters['mediaUrl'] = $media_url;
                    }

                    try {
                        $client = new Client($sending_server->account_sid, $sending_server->auth_token);

                        $get_response = $client->messages->create(
                            'whatsapp:' . $phone, $parameters
                        );

                        if ($get_response->status == 'queued' || $get_response->status == 'accepted') {
                            $get_sms_status = 'Delivered|' . $get_response->sid;
                        } else {
                            $get_sms_status = $get_response->status . '|' . $get_response->sid;
                        }

                    } catch (ConfigurationException|TwilioException $e) {
                        $get_sms_status = $e->getMessage();
                    }
                    break;

                case SendingServer::TYPE_MESSAGEBIRD:
                    $parameters = [
                        'to'      => $data['phone'],
                        'from'    => $data['sender_id'],
                        'type'    => 'text',
                        'content' => [
                            'text' => $message,
                        ],
                    ];

                    $ch = curl_init();

                    curl_setopt($ch, CURLOPT_URL, $gateway_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                    curl_setopt($ch, CURLOPT_POST, 1);

                    $headers   = [];
                    $headers[] = "Authorization: AccessKey $sending_server->api_key";
                    $headers[] = 'Content-Type: application/x-www-form-urlencoded';
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                    $result = curl_exec($ch);
                    if (curl_errno($ch)) {
                        $get_sms_status = curl_error($ch);
                    } else {
                        $response = json_decode($result, true);

                        if (is_array($response) && array_key_exists('id', $response)) {
                            $get_sms_status = 'Delivered|' . $response['id'];
                        } else if (is_array($response) && array_key_exists('errors', $response)) {
                            $get_sms_status = $response['errors'][0]['description'];
                        } else {
                            $get_sms_status = 'Unknown Error';
                        }
                    }
                    curl_close($ch);

                    break;

                case SendingServer::TYPE_WHATSAPPCHATAPI:

                    $parameters = [
                        'phone' => $phone,
                        'body'  => $message,
                    ];

                    if ($media_url != null) {
                        $parameters['filename'] = basename(parse_url($media_url)['path']);
                    }

                    $json = json_encode($parameters);

                    $url     = $gateway_url . '/message?token=' . $sending_server->api_token;
                    $options = stream_context_create([
                        'http' => [
                            'method'  => 'POST',
                            'header'  => 'Content-type: application/json',
                            'content' => $json,
                        ],
                    ]);

                    try {
                        $result = file_get_contents($url, false, $options);

                        $json_array[] = [];
                        $json_array   = json_decode($result, true);

                        if (isset($json_array) && is_array($json_array) && array_key_exists('sent', $json_array)) {
                            if ($json_array['sent']) {
                                $get_sms_status = 'Delivered|' . $json_array['id'];
                            } else {
                                $get_sms_status = $json_array['message'];
                            }
                        } else {
                            $get_sms_status = 'Invalid request';
                        }

                    } catch (Exception $ex) {
                        $get_sms_status = $ex->getMessage();
                    }

                    break;

                case SendingServer::TYPE_WHATSENDER:

                    if ($media_url != null) {

                        $file = [
                            'url' => $media_url,
                        ];

                        $curl = curl_init();

                        curl_setopt_array($curl, [
                            CURLOPT_URL            => 'https://api.whatsender.io/v1/files',
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_ENCODING       => '',
                            CURLOPT_MAXREDIRS      => 10,
                            CURLOPT_TIMEOUT        => 30,
                            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                            CURLOPT_CUSTOMREQUEST  => 'POST',
                            CURLOPT_POSTFIELDS     => json_encode($file),
                            CURLOPT_HTTPHEADER     => [
                                'Content-Type: application/json',
                                "Token: $sending_server->api_token",
                            ],
                        ]);

                        $response       = curl_exec($curl);
                        $err            = curl_error($curl);
                        $get_sms_status = 'Invalid request';

                        curl_close($curl);

                        if ($err) {
                            $get_sms_status = $err;
                        } else {
                            $get_data = json_decode($response, true);

                            if (is_array($get_data)) {

                                $file_id = null;

                                if (array_key_exists('meta', $get_data) && array_key_exists('file', $get_data['meta'])) {
                                    $file_id = $get_data['meta']['file'];
                                } else if (array_key_exists('0', $get_data) && array_key_exists('id', $get_data[0])) {
                                    $file_id = $get_data[0]['id'];
                                } else {
                                    $get_sms_status = $get_data['message'];
                                }

                                if ($file_id) {

                                    $parameters = [
                                        'phone'   => '+' . $phone,
                                        'message' => $message,
                                        'device'  => $sending_server->device_id,
                                        'media'   => [
                                            'file' => $file_id,
                                        ],
                                    ];

                                    $ch = curl_init();

                                    curl_setopt_array($ch, [
                                        CURLOPT_URL            => $gateway_url,
                                        CURLOPT_RETURNTRANSFER => true,
                                        CURLOPT_ENCODING       => '',
                                        CURLOPT_MAXREDIRS      => 10,
                                        CURLOPT_TIMEOUT        => 30,
                                        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                                        CURLOPT_CUSTOMREQUEST  => 'POST',
                                        CURLOPT_POSTFIELDS     => json_encode($parameters),
                                        CURLOPT_HTTPHEADER     => [
                                            'Content-Type: application/json',
                                            "Token: $sending_server->api_token",
                                        ],
                                    ]);

                                    $response = curl_exec($ch);
                                    $err      = curl_error($ch);

                                    curl_close($ch);

                                    if ($err) {
                                        $get_sms_status = $err;
                                    } else {
                                        $get_data = json_decode($response, true);
                                        if (is_array($get_data) && array_key_exists('status', $get_data)) {
                                            if ($get_data['status'] == 'queued') {
                                                $get_sms_status = 'Delivered|' . $get_data['id'];
                                            } else {
                                                $get_sms_status = $get_data['message'];
                                            }
                                        }
                                    }
                                } else {
                                    $get_sms_status = $get_data['message'];
                                }
                            }
                        }
                    } else {
                        $parameters = [
                            'phone'   => '+' . $phone,
                            'device'  => $sending_server->device_id,
                            'message' => $message,
                        ];

                        $curl = curl_init();

                        curl_setopt_array($curl, [
                            CURLOPT_URL            => $gateway_url,
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_ENCODING       => '',
                            CURLOPT_MAXREDIRS      => 10,
                            CURLOPT_TIMEOUT        => 30,
                            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                            CURLOPT_CUSTOMREQUEST  => 'POST',
                            CURLOPT_POSTFIELDS     => json_encode($parameters),
                            CURLOPT_HTTPHEADER     => [
                                'Content-Type: application/json',
                                "Token: $sending_server->api_token",
                            ],
                        ]);

                        $response = curl_exec($curl);
                        $err      = curl_error($curl);

                        curl_close($curl);

                        if ($err) {
                            $get_sms_status = $err;
                        } else {
                            $get_data = json_decode($response, true);
                            if (is_array($get_data) && array_key_exists('status', $get_data)) {
                                if ($get_data['status'] == 'queued') {
                                    $get_sms_status = 'Delivered|' . $get_data['id'];
                                } else {
                                    $get_sms_status = $get_data['message'];
                                }
                            }
                        }

                    }

                    break;

                case SendingServer::TYPE_WAAPI:
                    $parameters = [
                        'instance_key' => $sending_server->c1,
                        'jid'          => $phone,
                    ];

                    if ($media_url != null) {
                        $parameters['imageUrl'] = $media_url;
                        $parameters['caption']  = $message;
                        $gateway_url            = 'https://waapi.net/api/sendImageUrl';
                    } else {
                        $parameters['message'] = $message;
                    }

                    $ch = curl_init();

                    curl_setopt($ch, CURLOPT_URL, $gateway_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

                    $headers   = [];
                    $headers[] = 'Content-Type: application/json';

                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                    $result = curl_exec($ch);

                    if (curl_errno($ch)) {
                        $get_sms_status = curl_error($ch);
                    } else {
                        $response = json_decode($result, true);

                        if (is_array($response) && array_key_exists('success', $response)) {
                            if ($response['success']) {
                                $get_sms_status = 'Delivered';
                            } else {
                                $get_sms_status = $response['message'];
                            }
                        } else {
                            $get_sms_status = 'Invalid request';
                        }
                    }
                    curl_close($ch);

                    break;

                case SendingServer::TYPE_YOOAPI:

                    $parameters = [
                        'client_id' => $sending_server->c1,
                        'instance'  => $sending_server->c2,
                        'number'    => $phone,
                        'message'   => $message,
                        'type'      => 'text',
                    ];

                    $ch = curl_init();

                    curl_setopt($ch, CURLOPT_URL, $gateway_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                    curl_setopt($ch, CURLOPT_POST, 1);

                    $headers   = [];
                    $headers[] = 'Content-Type: application/json';
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                    $result = curl_exec($ch);

                    if (curl_errno($ch)) {
                        $get_sms_status = curl_error($ch);
                    } else {
                        $response = json_decode($result, true);

                        if (is_array($response) && array_key_exists('status', $response)) {

                            if ( ! $response['status']) {
                                $get_sms_status = $response['message'];
                            } else {
                                $get_sms_status = 'Delivered';
                            }

                        } else {
                            $get_sms_status = (string) $result;
                        }
                    }
                    curl_close($ch);
                    break;

                case 'Xmsway':

                    $parameters = [
                        'token' => $sending_server->api_token,
                        'no'    => $phone,
                        'text'  => $message,
                    ];

                    $sending_url = $gateway_url . '?' . http_build_query($parameters);

                    try {

                        $ch = curl_init();

                        curl_setopt($ch, CURLOPT_URL, $sending_url);
                        curl_setopt($ch, CURLOPT_HTTPGET, 1);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        $get_sms_status = curl_exec($ch);
                        curl_close($ch);

                        if (substr_count($get_sms_status, 'ok') == 1) {
                            $get_sms_status = 'Delivered';
                        }
                    } catch (Exception $exception) {
                        $get_sms_status = $exception->getMessage();
                    }
                    break;

                case 'MidasAppBr':
                    $parameters = [
                        'api_key' => $sending_server->api_key,
                        'sender'  => $data['sender_id'],
                        'number'  => $phone,
                        'message' => $message,
                        'footer'  => $sending_server->c1,
                    ];

                    if (isset($sending_server->c2)) {
                        $parameters['template1'] = $sending_server->c2;
                    }

                    if (isset($sending_server->c3)) {
                        $parameters['template2'] = $sending_server->c3;
                    }

                    if (isset($sending_server->c4)) {
                        $parameters['button1'] = $sending_server->c4;
                    }

                    if (isset($sending_server->c5)) {
                        $parameters['button2'] = $sending_server->c5;
                    }

                    if (isset($sending_server->c6)) {
                        $parameters['button3'] = $sending_server->c6;
                    }

                    if (isset($sending_server->c7)) {
                        $parameters['url'] = $sending_server->c7;
                    }

                    $headers = [
                        'Content-Type:application/json',
                    ];

                    try {

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        $response = curl_exec($ch);
                        curl_close($ch);

                        $get_response = json_decode($response, true);

                        if (is_array($get_response) && array_key_exists('status', $get_response)) {
                            if ( ! $get_response['status']) {
                                $get_sms_status = $get_response['msg'];
                            } else {
                                $get_sms_status = 'Delivered';
                            }
                        } else {
                            $get_sms_status = (string) $get_response;
                        }
                    } catch (Exception $e) {
                        $get_sms_status = $e->getMessage();
                    }
                    break;

                case SendingServer::TYPE_GUPSHUPIO:

                    $sender_id = $data['sender_id'];

                    $headers   = [];
                    $headers[] = 'Accept: application/json';
                    $headers[] = 'Content-Type: application/x-www-form-urlencoded';
                    $headers[] = 'Apikey: ' . $sending_server->api_key;

                    try {

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, "message={\"type\":\"text\",\"text\":\"$message\"}&source=$sender_id&destination=$phone&src.name=$sending_server->c1&channel=whatsapp");
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        $response = curl_exec($ch);
                        curl_close($ch);

                        $get_response = json_decode($response, true);

                        if (is_array($get_response) && array_key_exists('status', $get_response)) {
                            if ($get_response['status'] == 'submitted') {
                                $get_sms_status = 'Delivered|' . $get_response['messageId'];
                            } else {
                                $get_sms_status = $get_response['message'];
                            }
                        } else {
                            $get_sms_status = (string) $response;
                        }
                    } catch (Exception $e) {
                        $get_sms_status = $e->getMessage();
                    }
                    break;

                case SendingServer::TYPE_WHATSAPP:
                    $parameters = [
                        'messaging_product' => 'whatsapp',
                        'to'                => $phone,
                        'text'              => [
                            'preview_url' => true,
                            'body'        => $message,
                        ],
                    ];

                    if (isset($media_url)) {
                        $parameters['type']  = 'image';
                        $parameters['image'] = [
                            'link' => $media_url,
                        ];
                    }

                    $ch = curl_init();

                    curl_setopt($ch, CURLOPT_URL, $gateway_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                    curl_setopt($ch, CURLOPT_POST, 1);

                    $headers   = [];
                    $headers[] = 'Content-Type: application/json';
                    $headers[] = 'Authorization: Bearer ' . $sending_server->access_token;

                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                    $result = curl_exec($ch);

                    if (curl_errno($ch)) {
                        $get_sms_status = curl_error($ch);
                    } else {
                        $response = json_decode($result, true);

                        if (is_array($response)) {
                            if (array_key_exists('error', $response)) {
                                $get_sms_status = $response['error']['message'];
                            } else {
                                $get_sms_status = 'Delivered';
                            }

                        } else {
                            $get_sms_status = 'Invalid request';
                        }
                    }
                    curl_close($ch);

                    break;

                case SendingServer::TYPE_BULKREPLY:
                    $parameters = [
                        'api_key' => $sending_server->api_key,
                        'number'  => $phone,
                        'message' => $message,
                        'sender'  => $data['sender_id'],
                    ];

                    if ($media_url != null) {
                        $parameters['url']  = $media_url;
                        $parameters['type'] = 'image';
                    }

                    $ch = curl_init();

                    curl_setopt($ch, CURLOPT_URL, $gateway_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                    curl_setopt($ch, CURLOPT_POST, 1);

                    $headers   = [];
                    $headers[] = 'Content-Type: application/json';

                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                    $result = curl_exec($ch);

                    if (curl_errno($ch)) {
                        $get_sms_status = curl_error($ch);
                    } else {
                        $response = json_decode($result, true);

                        if (is_array($response) && array_key_exists('status', $response)) {
                            if ($response['status']) {
                                $get_sms_status = 'Delivered';
                            } else {
                                $get_sms_status = $response['msg'];
                            }
                        } else {
                            $get_sms_status = 'Invalid request';
                        }
                    }
                    curl_close($ch);

                    break;

                case SendingServer::TYPE_BULkSMS4BTC:
                    $phone = '+' . str_replace(['(', ')', '+', '-', ' '], '', $phone);

                    $parameters = [
                        'numbers'  => $phone,
                        'message'  => $message,
                        'variable' => $sending_server->c1,
                    ];
                    //
                    //                    if (isset($sending_server->c2)) {
                    //                        $parameters['button1'] = $sending_server->c4;
                    //                    }
                    //
                    //                    if (isset($sending_server->c5)) {
                    //                        $parameters['button2'] = $sending_server->c5;
                    //                    }
                    //
                    //                    if (isset($sending_server->c6)) {
                    //                        $parameters['button3'] = $sending_server->c6;
                    //                    }
                    //
                    //                    if (isset($sending_server->c7)) {
                    //                        $parameters['url'] = $sending_server->c7;
                    //                    }

                    $gateway_url .= '?API=' . $sending_server->api_key;

                    try {

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        $response = curl_exec($ch);
                        curl_close($ch);

                        $get_response = json_decode($response, true);

                        if (is_array($get_response) && array_key_exists('status', $get_response)) {
                            if ($get_response['status'] == 200) {
                                $get_sms_status = 'Delivered';
                            } else {
                                $get_sms_status = $get_response['message'];
                            }
                        } else {
                            $get_sms_status = (string) $get_response;
                        }
                    } catch (Exception $e) {
                        $get_sms_status = $e->getMessage();
                    }
                    break;

                case SendingServer::TYPE_PICKYASSIST:
                    $parameters = [
                        'token'         => $sending_server->api_token,
                        'application'   => $sending_server->application_id,
                        'priority'      => 0,
                        'sleep'         => 0,
                        'globalmessage' => $message,
                        'data'          => [
                            [
                                'number' => $phone,
                                'message',
                            ],
                        ],
                    ];

                    if (isset($media_url)) {
                        $parameters['globalmedia'] = $media_url;
                    }

                    $JSON_DATA = json_encode($parameters);

                    try {

                        $ch = curl_init($gateway_url);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $JSON_DATA);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                                'Content-Type: application/json',
                                'Content-Length: ' . strlen($JSON_DATA)]
                        );

                        $response = curl_exec($ch);

                        curl_close($ch);

                        $get_response = json_decode($response, true);

                        if (is_array($get_response) && array_key_exists('status', $get_response)) {
                            if ($get_response['status'] == 100) {
                                $get_sms_status = 'Delivered';
                            } else {
                                $get_sms_status = $get_response['message'];
                            }
                        } else {
                            $get_sms_status = (string) $get_response;
                        }
                    } catch (Exception $e) {
                        $get_sms_status = $e->getMessage();
                    }
                    break;

                case SendingServer::TYPE_WAZONE:
                    $parameters = [
                        'token'    => $sending_server->api_token,
                        'receiver' => $phone,
                        'msgtext'  => $message,
                        'sender'   => $data['sender_id'],
                    ];

                    if ($media_url != null) {
                        $parameters['mediaurl'] = $media_url;
                    }

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $gateway_url);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameters));
                    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
                    $result = curl_exec($ch);

                    if (curl_errno($ch)) {
                        $get_sms_status = curl_error($ch);
                    } else {
                        $response = json_decode($result, true);

                        if (is_array($response) && array_key_exists('success', $response)) {
                            if ($response['success']) {
                                $get_sms_status = 'Delivered';
                            } else {
                                $get_sms_status = $response['response'];
                            }
                        } else {
                            $get_sms_status = 'Invalid request';
                        }
                    }
                    curl_close($ch);

                    break;

                case SendingServer::TYPE_WHATSAPPBYTEMPLATE:

                    $language = ! isset($data['language']) ? 'en_US' : $data['language'];

                    $parameters = [
                        'messaging_product' => 'whatsapp',
                        'type'              => 'template',
                        'to'                => $phone,
                        'template'          => [
                            'name'     => $message,
                            'language' => [
                                'code' => $language,
                            ],
                        ],
                    ];

                    $ch = curl_init();

                    curl_setopt($ch, CURLOPT_URL, $gateway_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                    curl_setopt($ch, CURLOPT_POST, 1);

                    $headers   = [];
                    $headers[] = 'Content-Type: application/json';
                    $headers[] = 'Authorization: Bearer ' . $sending_server->access_token;

                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                    $result = curl_exec($ch);

                    if (curl_errno($ch)) {
                        $get_sms_status = curl_error($ch);
                    } else {
                        $response = json_decode($result, true);

                        if (is_array($response)) {
                            if (array_key_exists('error', $response)) {
                                $get_sms_status = $response['error']['message'];
                            } else {
                                $get_sms_status = 'Delivered';
                            }

                        } else {
                            $get_sms_status = 'Invalid request';
                        }
                    }
                    curl_close($ch);

                    break;

                case SendingServer::TYPE_BMSGLOBAL:

                    $parameters = [
                        'token'    => $sending_server->api_token,
                        'sender'   => $data['sender_id'],
                        'receiver' => $phone,
                        'msgtext'  => $message,
                    ];

                    if ($media_url != null) {
                        $parameters['mediaurl'] = $media_url;
                    }

                    try {

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        $response = curl_exec($ch);
                        curl_close($ch);

                        if ($response == 'Success') {
                            $get_sms_status = 'Delivered';
                        } else {
                            $get_sms_status = 'Failed';
                        }

                    } catch (Exception $e) {
                        $get_sms_status = $e->getMessage();
                    }
                    break;

                case SendingServer::TYPE_TERMII:
                    $parameters = [
                        'api_key' => $sending_server->api_key,
                        'to'      => $phone,
                        'from'    => $data['sender_id'],
                        'sms'     => $message,
                        'channel' => 'whatsapp',
                        'type'    => 'plain',
                    ];

                    if ($media_url != null) {
                        $parameters['media'] = [
                            'url' => $media_url,
                        ];
                    }

                    try {

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                        $headers   = [];
                        $headers[] = 'Accept: application/json';
                        $headers[] = 'Content-Type: application/json';
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                        $response = curl_exec($ch);
                        curl_close($ch);

                        $get_data = json_decode($response, true);

                        if (is_array($get_data)) {
                            if (array_key_exists('message_id', $get_data)) {
                                $get_sms_status = 'Delivered|' . $get_data['message_id'];
                            } else if (array_key_exists('message', $get_data)) {
                                $get_sms_status = $get_data['message'];
                            } else {
                                $get_sms_status = 'Failed';
                            }
                        } else {
                            $get_sms_status = $response;
                        }
                    } catch (Exception $e) {
                        $get_sms_status = $e->getMessage();
                    }
                    break;

                case SendingServer::TYPE_WA2SALES:
                    $parameters = [
                        'token'    => $sending_server->api_token,
                        'sender'   => $data['sender_id'],
                        'receiver' => $phone,
                        'msgtext'  => $message,
                    ];

                    if (isset($sending_server->c1)) {
                        $parameters['footer'] = $sending_server->c1;
                    }

                    if (isset($sending_server->c2)) {
                        $parameters['buttonType1'] = 'callButton';
                        $parameters['buttonText1'] = $sending_server->c2;
                    }

                    if (isset($sending_server->c3)) {
                        $parameters['buttonValue1'] = $sending_server->c3;
                    }

                    if (isset($sending_server->c4)) {
                        $parameters['buttonType2'] = 'urlButton';
                        $parameters['buttonText2'] = $sending_server->c4;
                    }

                    if (isset($sending_server->c5)) {
                        $parameters['buttonValue2'] = $sending_server->c5;
                    }

                    if (isset($sending_server->c6)) {
                        $parameters['buttonType3'] = 'quickReplyButton';
                        $parameters['buttonText3'] = $sending_server->c6;
                    }

                    if (isset($sending_server->c7)) {
                        $parameters['buttonValue3'] = $sending_server->c7;
                    }

                    if ($media_url != null) {
                        $parameters['mediaurl'] = $media_url;
                    }

                    try {

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        $get_sms_status = curl_exec($ch);
                        curl_close($ch);

                        if ($get_sms_status == 'Success') {
                            $get_sms_status = 'Delivered';
                        }

                    } catch (Exception $e) {
                        $get_sms_status = $e->getMessage();
                    }
                    break;

                case SendingServer::TYPE_INTERAKT:

                    $language = ! isset($data['language']) ? 'en' : $data['language'];

                    $phoneUtil = PhoneNumberUtil::getInstance();
                    try {
                        $phoneNumberObject = $phoneUtil->parse('+' . $phone);
                        $countryCode       = $phoneNumberObject->getCountryCode();

                        if ($phoneNumberObject->isItalianLeadingZero()) {
                            $nationalNumber = '0' . $phoneNumberObject->getNationalNumber();
                        } else {
                            $nationalNumber = $phoneNumberObject->getNationalNumber();
                        }

                        $parameters = [
                            'countryCode' => $countryCode,
                            'phoneNumber' => $nationalNumber,
                            'type'        => 'Template',
                            'template'    => [
                                'name'         => $message,
                                'languageCode' => $language,
                            ],
                        ];

                        $headers = [
                            'Content-Type:application/json',
                            'Authorization: Basic ' . $sending_server->api_key,
                        ];

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        $response = curl_exec($ch);
                        curl_close($ch);

                        $get_response = json_decode($response, true);

                        if (is_array($get_response) && array_key_exists('result', $get_response) && array_key_exists('message', $get_response)) {
                            if ($get_response['result']) {
                                $get_sms_status = 'Delivered';
                            } else {
                                $get_sms_status = $get_response['message'];
                            }
                        } else {
                            $get_sms_status = implode(' ', $get_response);
                        }

                    } catch (NumberParseException $e) {
                        $get_sms_status = $e->getMessage();
                    }
                    break;

                case SendingServer::TYPE_GUPSHUPIOTEMPLATE:

                    $parameters = [
                        'channel'     => 'whatsapp',
                        'source'      => $data['sender_id'],
                        'destination' => $phone,
                        'src.name'    => $sending_server->c1,
                        'template'    => [
                            'id'     => $message,
                            'params' => [],
                        ],
                    ];

                    $headers   = [];
                    $headers[] = 'Accept: application/json';
                    $headers[] = 'Content-Type: application/x-www-form-urlencoded';
                    $headers[] = 'Apikey: ' . $sending_server->api_key;

                    try {

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameters));
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        $response = curl_exec($ch);
                        curl_close($ch);

                        $get_response = json_decode($response, true);

                        if (is_array($get_response) && array_key_exists('status', $get_response)) {
                            if ($get_response['status'] == 'submitted') {
                                $get_sms_status = 'Delivered|' . $get_response['messageId'];
                            } else {
                                $get_sms_status = $get_response['message'];
                            }
                        } else {
                            $get_sms_status = (string) $response;
                        }
                    } catch (Exception $e) {
                        $get_sms_status = $e->getMessage();
                    }
                    break;

                case SendingServer::TYPE_360DIALOG:
                    $parameters = [
                        'messaging_product' => 'whatsapp',
                        'recipient_type'    => 'individual',
                        'to'                => $phone,
                    ];

                    if (isset($media_url)) {
                        $parameters['type']  = 'image';
                        $parameters['image'] = [
                            'link' => $media_url,
                        ];
                    } else {
                        $parameters['type'] = 'text';
                        $parameters['text'] = [
                            'preview_url' => true,
                            'body'        => $message,
                        ];
                    }

                    $ch = curl_init();

                    curl_setopt($ch, CURLOPT_URL, $gateway_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                    curl_setopt($ch, CURLOPT_POST, 1);

                    $headers   = [];
                    $headers[] = 'Content-Type: application/json';
                    $headers[] = 'D360-Api-Key: ' . $sending_server->api_key;

                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                    $result = curl_exec($ch);

                    if (curl_errno($ch)) {
                        $get_sms_status = curl_error($ch);
                    } else {
                        $response = json_decode($result, true);

                        if (is_array($response)) {
                            if (array_key_exists('error', $response)) {
                                $get_sms_status = $response['error']['message'];
                            } else {
                                $get_sms_status = 'Delivered';
                            }

                        } else {
                            $get_sms_status = 'Invalid request';
                        }
                    }
                    curl_close($ch);

                    break;

                case SendingServer::TYPE_WHGATE:
                    $parameters = [
                        'instance_id'  => $sending_server->c1,
                        'access_token' => $sending_server->access_token,
                        'number'       => $phone,
                        'message'      => $message,
                    ];

                    if ($media_url != null) {
                        $parameters['media_url'] = $media_url;
                        $parameters['filename']  = basename($media_url);
                        $parameters['type']      = 'media';
                    } else {
                        $parameters['type'] = 'whatsapp';
                    }

                    $sending_url = $gateway_url . '?' . http_build_query($parameters);

                    try {

                        $ch = curl_init();

                        curl_setopt($ch, CURLOPT_URL, $sending_url);
                        curl_setopt($ch, CURLOPT_HTTPGET, 1);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                        $result = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status = curl_error($ch);
                        } else {

                            $response = json_decode($result, true);
                            if (is_array($response) && array_key_exists('status', $response)) {
                                $get_sms_status = $response['message'];
                            } else {
                                $get_sms_status = 'Delivered';
                            }
                        }
                        curl_close($ch);
                    } catch (Exception $exception) {
                        $get_sms_status = $exception->getMessage();
                    }

                    break;

                case SendingServer::TYPE_WAWHGATE:
                    $parameters = [
                        'appkey'  => $sending_server->api_key,
                        'authkey' => $sending_server->auth_key,
                        'to'      => $phone,
                        'message' => $message,
                    ];

                    if ($media_url != null) {
                        $parameters['file'] = $media_url;
                    }

                    try {

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        $result = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status = curl_error($ch);
                        } else {
                            $response = json_decode($result, true);

                            if (is_array($response)) {
                                if (array_key_exists('error', $response)) {
                                    $get_sms_status = $response['error'];
                                } else if (array_key_exists('message_status', $response)) {
                                    $get_sms_status = 'Delivered';
                                } else {
                                    $get_sms_status = 'Invalid request';
                                }

                            } else {
                                $get_sms_status = 'Invalid request';
                            }
                        }
                        curl_close($ch);

                    } catch (Exception $e) {
                        $get_sms_status = $e->getMessage();
                    }
                    break;

                case SendingServer::TYPE_ULTRAMSG:
                    $ultraMsg = new WhatsAppApi($sending_server->access_token, $sending_server->c1);

                    if ($media_url) {
                        $response = $ultraMsg->sendImageMessage($phone, $media_url, $message);
                    } else {
                        $response = $ultraMsg->sendChatMessage($phone, $message);
                    }

                    if (is_array($response)) {
                        if (array_key_exists('sent', $response) && $response['sent']) {
                            $get_sms_status = 'Delivered';
                        } else if (array_key_exists('Error', $response)) {
                            $get_sms_status = $response['Error'];
                        } else {
                            $get_sms_status = 'Invalid request';
                        }

                    } else {
                        $get_sms_status = 'Invalid request';
                    }
                    break;

                case SendingServer::TYPE_WHATAPICLOUD:
                    $parameters = [
                        'typing_time' => 0,
                        'to'          => $phone,
                        'body'        => $message,
                    ];

                    if ($media_url != null) {
                        $parameters['media'] = $media_url;
                    }

                    try {

                        $ch = curl_init();

                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_POST, 1);

                        $headers   = [];
                        $headers[] = 'Accept: application/json';
                        $headers[] = 'Content-Type: application/json';
                        $headers[] = 'Authorization: Bearer ' . $sending_server->api_token;

                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                        $result = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status = curl_error($ch);
                        } else {
                            $response = json_decode($result, true);

                            if (is_array($response)) {
                                if (array_key_exists('error', $response)) {
                                    $get_sms_status = $response['error']['message'];
                                } else if (array_key_exists('sent', $response) && ($response['sent'] == 'true')) {
                                    $get_sms_status = 'Delivered';
                                } else {
                                    $get_sms_status = 'Failed';
                                }

                            } else {
                                $get_sms_status = 'Invalid request';
                            }
                        }
                        curl_close($ch);

                    } catch (Exception $e) {
                        $get_sms_status = $e->getMessage();
                    }
                    break;

                case SendingServer::TYPE_EBULKSMS:

                    $parameters = [
                        'WA' => [
                            'auth'       => [
                                'username' => $sending_server->username,
                                'apikey'   => $sending_server->api_key,
                            ],
                            'message'    => [
                                'subject'     => $data['sender_id'],
                                'messagetext' => $message,
                            ],
                            'recipients' => [
                                $phone,
                            ],
                        ],
                    ];

                    try {
                        $curl = curl_init();
                        curl_setopt($curl, CURLOPT_URL, $gateway_url);
                        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
                        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);

                        $headers   = [];
                        $headers[] = 'Content-Type: application/json';
                        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

                        $result = curl_exec($curl);
                        curl_close($curl);

                        $response = json_decode($result, true);

                        if ($response && is_array($response) && array_key_exists('response', $response) && array_key_exists('status', $response['response'])) {
                            if ($response['response']['status'] == 'STATUS_STRING' || $response['response']['status'] == 'SUCCESS') {
                                $get_sms_status = 'Delivered';
                            } else {
                                $get_sms_status = $response['response']['status'];
                            }
                        } else {
                            $get_sms_status = $result;
                        }
                    } catch (Exception $e) {
                        $get_sms_status = $e->getMessage();
                    }
                    break;

                case SendingServer::TYPE_TWILIO_WHATSAPP_BY_TEMPLATE:

                    $parameters = [
                        'from'                => 'whatsapp:' . $data['sender_id'],
                        'contentSid'          => $message,
                        'messagingServiceSid' => $sending_server->c1,
                    ];

                    try {
                        $client = new Client($sending_server->account_sid, $sending_server->auth_token);

                        $get_response = $client->messages->create(
                            'whatsapp:' . $phone, $parameters
                        );

                        if ($get_response->status == 'queued' || $get_response->status == 'accepted') {
                            $get_sms_status = 'Delivered|' . $get_response->sid;
                        } else {
                            $get_sms_status = $get_response->status . '|' . $get_response->sid;
                        }

                    } catch (ConfigurationException|TwilioException $e) {
                        $get_sms_status = $e->getMessage();
                    }
                    break;


                case SendingServer::TYPE_FORDEMO:
                    $get_sms_status = "Delivered";
                    break;


                case SendingServer::TYPE_ULTIMATESMS:
                    $parameters = [
                        'api_token' => $sending_server->api_token,
                        'message'   => $message,
                        'recipient' => $phone,
                        'type'      => 'whatsapp',
                    ];

                    if (isset($data['sender_id'])) {
                        $parameters['sender_id'] = $data['sender_id'];
                    }

                    if (isset($media_url)) {
                        $parameters['media_url'] = $media_url;
                    }


                    $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $gateway_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_HTTPGET, 1);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

                    $result = curl_exec($ch);


                    if (curl_errno($ch)) {
                        $get_sms_status = curl_error($ch);
                    } else {
                        $get_data = json_decode($result, true);
                        if (isset($get_data) && is_array($get_data) && array_key_exists('status', $get_data)) {
                            if ($get_data['status'] == 'success') {
                                $get_sms_status = 'Delivered';
                            } else {
                                $get_sms_status = $get_data['message'];
                            }
                        } else {
                            $get_sms_status = 'Failed';
                        }
                    }

                    curl_close($ch);
                    break;

                case SendingServer::TYPE_EVOLUTIONAPI:

                    $parameters = [
                        'text'   => $message,
                        'number' => $phone,
                    ];

                    $ch = curl_init();

                    curl_setopt($ch, CURLOPT_URL, $gateway_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                    curl_setopt($ch, CURLOPT_POST, 1);

                    $headers   = [];
                    $headers[] = 'Content-Type: application/json';
                    $headers[] = 'apikey: ' . $sending_server->api_key;

                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                    $result = curl_exec($ch);

                    if (curl_errno($ch)) {
                        $get_sms_status = curl_error($ch);
                    } else {
                        $response = json_decode($result, true);

                        if (is_array($response)) {
                            if (array_key_exists('error', $response)) {
                                $get_sms_status = $response['error'];
                            } else {
                                $get_sms_status = 'Delivered';
                            }

                        } else {
                            $get_sms_status = 'Invalid request';
                        }
                    }
                    curl_close($ch);
                    break;

                default:
                    $get_sms_status = __('locale.sending_servers.sending_server_not_found');
                    break;
            }

            $cost            = substr_count($get_sms_status, 'Delivered') == 1 ? $data['cost'] : '0';
            $customer_status = substr_count($get_sms_status, 'Delivered') == 1 ? 'Delivered' : 'Failed';

            $reportsData = [
                'user_id'           => $data['user_id'],
                'to'                => $phone,
                'message'           => $message,
                'sms_type'          => 'whatsapp',
                'status'            => $get_sms_status,
                'customer_status'   => $customer_status,
                'media_url'         => $media_url,
                'sms_count'         => $data['sms_count'],
                'cost'              => $cost,
                'sending_server_id' => $sending_server->id,
            ];

            if (isset($data['sender_id'])) {
                $reportsData['from'] = $data['sender_id'];
            }

            if (isset($data['campaign_id'])) {
                $reportsData['campaign_id'] = $data['campaign_id'];
            }

            if (isset($data['automation_id'])) {
                $reportsData['automation_id'] = $data['automation_id'];
            }

            if (isset($data['api_key'])) {
                $reportsData['api_key']   = $data['api_key'];
                $reportsData['direction'] = Reports::DIRECTION_API;
            } else {
                $reportsData['direction'] = Reports::DIRECTION_OUTGOING;
            }

            $status = Reports::create($reportsData);

            if ($status) {
                return $status;
            }

            return __('locale.exceptions.something_went_wrong');

        }

        /**
         * send viber message
         *
         *
         * @return array|Application|Translator|string|null
         */
        public
        function sendViber($data)
        {
            $phone          = $data['phone'];
            $sending_server = $data['sending_server'];
            $gateway_name   = $data['sending_server']->settings;
            $message        = $data['message'];
            $media_url      = null;
            $gateway_url    = $sending_server->viber_api_link ?? $sending_server->api_link;

            if (isset($data['media_url'])) {
                $media_url = $data['media_url'];
            }

            switch ($gateway_name) {

                case SendingServer::TYPE_MESSAGGIO:

                    if ($media_url != null) {
                        $content = [
                            [
                                'type'  => 'image',
                                'image' => ['url' => $media_url],
                            ],
                            [
                                'type' => 'text',
                                'text' => $message,
                            ],
                        ];
                    } else {
                        $content = [
                            [
                                'type' => 'text',
                                'text' => $message,
                            ],
                        ];
                    }

                    $parameters = [
                        'recipients' => [
                            ['phone' => $phone],
                        ],
                        'channels'   => ['viber'],
                        'viber'      => [
                            'from'    => $data['sender_id'],
                            'label'   => $sending_server->sms_type,
                            'content' => $content,
                        ],

                    ];

                    try {

                        $headers = [
                            'Content-Type:application/json',
                            'Messaggio-Login: ' . $sending_server->api_key,
                        ];

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        $response = curl_exec($ch);
                        curl_close($ch);

                        $get_response = json_decode($response, true);

                        if (isset($get_response) && is_array($get_response)) {
                            if (array_key_exists('messages', $get_response) && array_key_exists('0', $get_response['messages']) && array_key_exists('message_id', $get_response['messages'][0])) {
                                $get_sms_status  = 'Delivered|' . $get_response['messages'][0]['message_id'];
                                $customer_status = 'Delivered';
                            } else if (array_key_exists('detail', $get_response)) {
                                $get_sms_status  = $get_response['detail'];
                                $customer_status = 'Undelivered';
                            } else {
                                $get_sms_status  = (string) $response;
                                $customer_status = 'Undelivered';
                            }
                        } else {
                            $get_sms_status  = 'Unknown error';
                            $customer_status = 'Failed';
                        }

                    } catch (Exception $e) {
                        $get_sms_status  = $e->getMessage();
                        $customer_status = 'Failed';
                    }
                    break;

                case SendingServer::TYPE_FORTYTWO:
                    $parameters = [
                        "destinations" => [
                            [
                                "number" => $phone,
                            ],
                        ],
                        "im_content"   => [
                            [
                                "channel" => "VIBER",
                                "content" => $message,
                            ],
                        ],

                    ];

                    if (isset($data['btn_text']) && isset($data['btn_url'])) {
                        $parameters["im_content"][0]["actions"] = [
                            [
                                "title"        => $data['btn_text'],
                                "target_url"   => $data['btn_url'],
                                "track_clicks" => true,
                            ],
                        ];
                    }

                    // Add sender ID if provided
                    if ($data['sender_id']) {
                        $parameters["im_content"][0]["sender_id"] = $data['sender_id'];
                    }

                    // Add image if provided
                    if ($media_url) {
                        $parameters["im_content"][0]["images"] = [
                            [
                                "url" => $media_url,
                            ],
                        ];
                    }
                    try {
                        $headers = [
                            "Content-Type: application/json; charset=utf-8",
                            "Authorization: Token " . $sending_server->api_key,
                        ];

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        $response = curl_exec($ch);
                        curl_close($ch);

                        $get_response = json_decode($response, true);

                        if (isset($get_response) && is_array($get_response)) {
                            if (array_key_exists('result_info', $get_response) && $get_response['result_info']['status_code'] == 200) {
                                $get_sms_status = $customer_status = 'Delivered';
                            } else if (array_key_exists('error', $get_response) && $get_response['error'] == 1) {
                                $get_sms_status  = $get_response['message'];
                                $customer_status = 'Undelivered';
                            } else {
                                $customer_status = 'failed';
                                $get_sms_status  = $get_response['message'];
                            }
                        } else {
                            $get_sms_status  = 'Unknown error';
                            $customer_status = 'Failed';
                        }

                    } catch (Exception $e) {
                        $get_sms_status  = $e->getMessage();
                        $customer_status = 'Failed';
                    }
                    break;

                case SendingServer::TYPE_FORDEMO:
                    $get_sms_status = $customer_status = "Delivered";
                    break;

                case SendingServer::TYPE_ULTIMATESMS:
                    $parameters = [
                        'api_token' => $sending_server->api_token,
                        'message'   => $message,
                        'recipient' => $phone,
                        'type'      => 'viber',
                    ];

                    if (isset($data['sender_id'])) {
                        $parameters['sender_id'] = $data['sender_id'];
                    }

                    if (isset($media_url)) {
                        $parameters['media_url'] = $media_url;
                    }


                    $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $gateway_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_HTTPGET, 1);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

                    $result = curl_exec($ch);


                    if (curl_errno($ch)) {
                        $get_sms_status = curl_error($ch);
                    } else {
                        $get_data = json_decode($result, true);
                        if (isset($get_data) && is_array($get_data) && array_key_exists('status', $get_data)) {
                            if ($get_data['status'] == 'success') {
                                $get_sms_status = 'Delivered';
                            } else {
                                $get_sms_status = $get_data['message'];
                            }
                        } else {
                            $get_sms_status = 'Failed';
                        }
                    }

                    curl_close($ch);
                    break;


                default:
                    $get_sms_status = $customer_status = __('locale.sending_servers.sending_server_not_found');
                    break;
            }

            $cost = substr_count($get_sms_status, 'Delivered') == 1 ? $data['cost'] : '0';

            $reportsData = [
                'user_id'           => $data['user_id'],
                'to'                => $phone,
                'message'           => $message,
                'sms_type'          => 'viber',
                'status'            => $get_sms_status,
                'customer_status'   => $customer_status,
                'media_url'         => $media_url,
                'sms_count'         => $data['sms_count'],
                'cost'              => $cost,
                'sending_server_id' => $sending_server->id,
            ];

            if (isset($data['sender_id'])) {
                $reportsData['from'] = $data['sender_id'];
            }

            if (isset($data['campaign_id'])) {
                $reportsData['campaign_id'] = $data['campaign_id'];
            }

            if (isset($data['automation_id'])) {
                $reportsData['automation_id'] = $data['automation_id'];
            }

            if (isset($data['api_key'])) {
                $reportsData['api_key']   = $data['api_key'];
                $reportsData['direction'] = Reports::DIRECTION_API;
            } else {
                $reportsData['direction'] = Reports::DIRECTION_OUTGOING;
            }

            $status = Reports::create($reportsData);

            if ($status) {
                return $status;
            }

            return __('locale.exceptions.something_went_wrong');

        }

        /**
         * send OTP message
         *
         *
         * @return array|Application|Translator|string|null
         */
        public
        function sendOTP($data)
        {
            $phone          = $data['phone'];
            $sending_server = $data['sending_server'];
            $gateway_name   = $data['sending_server']->settings;
            $message        = $data['message'];
            $gateway_url    = $sending_server->otp_api_link ?? $sending_server->api_link;

            switch ($gateway_name) {

                case SendingServer::TYPE_MSG91:

                    $parameters = [
                        'template_id' => $sending_server->c2,
                        'mobile'      => $phone,
                    ];

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $gateway_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'authkey: ' . $sending_server->auth_key,
                        'Content-Type: application/json',
                        'accept: application/json',
                    ]);

                    $response = curl_exec($ch);

                    if (curl_errno($ch)) {
                        $get_sms_status  = curl_error($ch);
                        $customer_status = 'Failed';
                    } else {

                        $get_response = json_decode($response, true);
                        if (is_array($get_response) && array_key_exists('type', $get_response)) {
                            if ($get_response['type'] == 'success') {
                                $get_sms_status = $customer_status = 'Delivered';
                            } else {
                                $get_sms_status  = $get_response['message'];
                                $customer_status = 'Undelivered';
                            }
                        } else {
                            $get_sms_status  = $response;
                            $customer_status = 'Failed';
                        }
                    }

                    curl_close($ch);
                    break;

                case SendingServer::TYPE_SMARTSMSSOLUTIONS:

                    $parameters = [
                        'token'         => $sending_server->api_token,
                        'phone'         => $phone,
                        'otp'           => $message,
                        'app_name_code' => $sending_server->application_id,
                    ];

                    if (isset($data['sender_id'])) {
                        $parameters['sender'] = $data['sender_id'];
                    }

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $gateway_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);
                    $result = curl_exec($ch);

                    if (curl_errno($ch)) {
                        $get_sms_status  = curl_error($ch);
                        $customer_status = 'Failed';
                    } else {
                        $get_data = json_decode($result, true);
                        if (isset($get_data) && is_array($get_data)) {
                            if (array_key_exists('success', $get_data) && $get_data['success'] === true) {
                                $get_sms_status = $customer_status = 'Delivered';
                            } else if (array_key_exists('comment', $get_data)) {
                                $get_sms_status  = $get_data['comment'];
                                $customer_status = 'Undelivered';
                            } else {
                                $get_sms_status  = $result;
                                $customer_status = 'Failed';
                            }
                        } else {
                            $get_sms_status = $customer_status = 'Failed';
                        }
                    }
                    curl_close($ch);
                    break;

                case SendingServer::TYPE_VOICEANDTEXT:

                    $parameters = [
                        'username'   => $sending_server->username,
                        'apiadmin'   => $sending_server->password,
                        'admintoken' => $sending_server->api_token,
                        'sessionid'  => time(),
                        'phone'      => $phone,
                        'otpval'     => $message,
                    ];

                    $sending_url = $gateway_url . '?' . http_build_query($parameters);

                    try {
                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $sending_url);
                        curl_setopt($ch, CURLOPT_HTTPGET, 1);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        $get_sms_status = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status  = curl_error($ch);
                            $customer_status = 'Failed';
                        } else {
                            $get_sms_status = trim($get_sms_status);

                            $get_sms_status = match (true) {
                                str_contains($get_sms_status, 'EC:000') => 'Delivered',
                                str_contains($get_sms_status, 'EC:001') => 'Authentication Error',
                                str_contains($get_sms_status, 'EC:002') => 'Invalid User Account Requested',
                                str_contains($get_sms_status, 'EC:003') => 'Session-ID is Missing',
                                str_contains($get_sms_status, 'EC:004') => 'Subscriber MSISDN is Missing',
                                str_contains($get_sms_status, 'EC:005') => 'OTP Value is Missing',
                                str_contains($get_sms_status, 'EC:008') => 'Internal Server Error',
                                str_contains($get_sms_status, 'EC:009') => 'Insufficient User Credit!',
                                default => 'Invalid request',
                            };

                            $customer_status = $get_sms_status === 'Delivered' ? 'Delivered' : 'Failed';
                        }
                        curl_close($ch);
                    } catch (Exception $exception) {
                        $get_sms_status  = $exception->getMessage();
                        $customer_status = 'Failed';
                    }
                    break;

                case SendingServer::TYPE_FAST2SMS:

                    $parameters = [
                        'variables_values' => $message,
                        'numbers'          => $phone,
                        'route'            => 'otp',
                    ];

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $gateway_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Authorization: ' . $sending_server->api_key,
                        'Content-Type: application/json',
                        'Accept: application/json',
                    ]);

                    $response = curl_exec($ch);

                    if (curl_errno($ch)) {
                        $get_sms_status  = curl_error($ch);
                        $customer_status = 'Failed';
                    } else {

                        $get_response = json_decode($response, true);
                        if (is_array($get_response) && array_key_exists('return', $get_response)) {
                            if ($get_response['return'] === true) {
                                $get_sms_status = $customer_status = 'Delivered';
                            } else {
                                $get_sms_status  = $get_response['message'];
                                $customer_status = 'Undelivered';
                            }
                        } else {
                            $get_sms_status  = $response;
                            $customer_status = 'Failed';
                        }
                    }

                    curl_close($ch);
                    break;

                case SendingServer::TYPE_SMSURWAY:

                    $authData = [
                        'email'    => $sending_server->c1,
                        'password' => $sending_server->password,
                    ];

                    try {

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $sending_server->api_link);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($authData));
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'Content-Type: application/json',
                            'Accept: application/json',
                        ]);
                        $result = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status  = curl_error($ch);
                            $customer_status = 'Failed';
                        } else {
                            $get_data = json_decode($result, true);

                            if (isset($get_data) && is_array($get_data) && array_key_exists('token', $get_data)) {

                                $parameters = [
                                    'from' => $data['sender_id'],
                                    'to'   => $phone,
                                    'msg'  => $message,
                                ];


                                $curl = curl_init();
                                curl_setopt($curl, CURLOPT_URL, $sending_server->otp_api_link);
                                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                                curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 0);
                                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
                                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($parameters));
                                curl_setopt($curl, CURLOPT_HTTPHEADER, [
                                    'Authorization: Bearer ' . $get_data['token'],
                                    'Content-Type: application/json',
                                    'Accept: application/json',
                                ]);
                                $response = curl_exec($curl);

                                if (curl_errno($curl)) {
                                    $get_sms_status  = curl_error($curl);
                                    $customer_status = 'Failed';
                                } else {
                                    $smsData = json_decode($response, true);

                                    if (isset($smsData) && is_array($smsData) && array_key_exists('success', $smsData) && array_key_exists('message', $smsData)) {
                                        if ($smsData['success']) {
                                            $get_sms_status = $customer_status = 'Delivered';
                                        } else {
                                            $get_sms_status  = $smsData['message'];
                                            $customer_status = 'Failed';
                                        }

                                    } else {
                                        $get_sms_status  = $response;
                                        $customer_status = 'Failed';
                                    }
                                }
                                curl_close($curl);
                            } else {
                                $get_sms_status  = $result;
                                $customer_status = 'Failed';
                            }
                        }
                        curl_close($ch);
                    } catch (Exception $exception) {
                        $get_sms_status  = $exception->getMessage();
                        $customer_status = 'Failed';
                    }
                    break;

                case SendingServer::TYPE_TURKEYSMS:


                    $parameters = [
                        'api_key'       => $sending_server->api_key,
                        'title'         => $data['sender_id'],
                        'mobile'        => $phone,
                        'digits'        => $message,
                        'report'        => 1,
                        'lang'          => 1,
                        'content_type'  => 1,
                        'response_type' => 'json',

                    ];


                    try {

                        $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_HTTPGET, 1);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

                        $response = curl_exec($ch);


                        if (curl_errno($ch)) {
                            $get_sms_status  = curl_error($ch);
                            $customer_status = 'Rejected';
                        } else {

                            $get_data = json_decode($response, true);

                            if (isset($get_data) && is_array($get_data) && array_key_exists('result', $get_data) && array_key_exists('result_message', $get_data)) {
                                if ($get_data['result']) {
                                    $get_sms_status = $customer_status = 'Delivered';
                                } else {
                                    $get_sms_status  = $get_data['result_message'];
                                    $customer_status = 'Failed';
                                }

                            } else {
                                $get_sms_status  = 'Provide empty response. Please contact with gateway provider.';
                                $customer_status = 'Rejected';
                            }
                        }
                        curl_close($ch);

                    } catch (Exception $e) {
                        $get_sms_status  = $e->getMessage();
                        $customer_status = 'Rejected';
                    }
                    break;

                case SendingServer::TYPE_DIAFAAN:

                    $parameters = [
                        'username'    => $sending_server->username,
                        'password'    => $sending_server->password,
                        'priority'    => $sending_server->c2,
                        'to'          => $phone,
                        'messagetype' => 'sms.automatic',
                        'message'     => $message,
                    ];

                    if (isset($data['sender_id'])) {
                        $parameters['from'] = $data['sender_id'];
                    }

                    $sending_url = $gateway_url . '/http/send-message?' . http_build_query($parameters);

                    try {
                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $sending_url);
                        curl_setopt($ch, CURLOPT_HTTPGET, 1);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        $get_sms_status = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status  = curl_error($ch);
                            $customer_status = 'Failed';
                        } else {
                            if (str_contains($get_sms_status, 'OK')) {
                                $get_sms_status = $customer_status = 'Delivered|' . trim(str_replace('OK: ', '', $get_sms_status));
                            } else if (str_contains($get_sms_status, 'STATUS:100')) {
                                $get_data = explode('|', $get_sms_status);
                                if (is_array($get_data) && array_key_exists(1, $get_data)) {
                                    $get_sms_status = $customer_status = 'Delivered|' . $get_data['1'];
                                } else {
                                    $get_sms_status = $customer_status = 'Delivered';
                                }
                            }
                        }
                        curl_close($ch);
                    } catch (Exception $exception) {
                        $get_sms_status  = $exception->getMessage();
                        $customer_status = 'Failed';
                    }
                    break;

                case SendingServer::TYPE_VERIFYSMSURWAY:

                    $authData = [
                        'email'    => $sending_server->c1,
                        'password' => $sending_server->password,
                    ];

                    try {

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $sending_server->auth_link);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($authData));
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'Content-Type: application/x-www-form-urlencoded',
                            'Accept: application/json',
                        ]);
                        $result = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status  = curl_error($ch);
                            $customer_status = 'Failed';
                        } else {
                            $get_data = json_decode($result, true);

                            if (isset($get_data) && is_array($get_data) && array_key_exists('token', $get_data)) {

                                $parameters = [
                                    'to'       => $phone,
                                    'otp_code' => $message,
                                ];

                                $curl = curl_init();
                                curl_setopt($curl, CURLOPT_URL, $sending_server->otp_api_link);
                                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                                curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 0);
                                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
                                curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($parameters));
                                curl_setopt($curl, CURLOPT_HTTPHEADER, [
                                    'Authorization: Bearer ' . $get_data['token'],
                                    'Accept: application/json',
                                ]);
                                $response = curl_exec($curl);


                                if (curl_errno($curl)) {
                                    $get_sms_status  = curl_error($curl);
                                    $customer_status = 'Failed';
                                } else {
                                    $smsData = json_decode($response, true);

                                    if (isset($smsData) && is_array($smsData) && array_key_exists('success', $smsData) && array_key_exists('message', $smsData)) {
                                        if ($smsData['success']) {
                                            $get_sms_status = $customer_status = 'Delivered';
                                        } else {
                                            $get_sms_status  = $smsData['message'];
                                            $customer_status = 'Failed';
                                        }

                                    } else if (array_key_exists('message', $smsData)) {
                                        $get_sms_status  = $smsData['message'];
                                        $customer_status = 'Failed';
                                    } else {
                                        $get_sms_status  = $response;
                                        $customer_status = 'Failed';
                                    }
                                }
                                curl_close($curl);
                            } else {
                                $get_sms_status  = $result;
                                $customer_status = 'Failed';
                            }
                        }
                        curl_close($ch);
                    } catch (Exception $exception) {
                        $get_sms_status  = $exception->getMessage();
                        $customer_status = 'Failed';
                    }
                    break;

                case SendingServer::TYPE_FORDEMO:
                    $get_sms_status = $customer_status = "Delivered";
                    break;

                case SendingServer::TYPE_ULTIMATESMS:
                    $parameters = [
                        'api_token' => $sending_server->api_token,
                        'message'   => $message,
                        'recipient' => $phone,
                        'type'      => 'otp',
                    ];

                    if (isset($data['sender_id'])) {
                        $parameters['sender_id'] = $data['sender_id'];
                    }

                    $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $gateway_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_HTTPGET, 1);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

                    $result = curl_exec($ch);


                    if (curl_errno($ch)) {
                        $get_sms_status = curl_error($ch);
                    } else {
                        $get_data = json_decode($result, true);
                        if (isset($get_data) && is_array($get_data) && array_key_exists('status', $get_data)) {
                            if ($get_data['status'] == 'success') {
                                $get_sms_status = 'Delivered';
                            } else {
                                $get_sms_status = $get_data['message'];
                            }
                        } else {
                            $get_sms_status = 'Failed';
                        }
                    }

                    curl_close($ch);
                    break;

                case SendingServer::TYPE_ITEXMO:

                    $parameters = [
                        'Email'      => $sending_server->c1,
                        'Password'   => $sending_server->password,
                        'ApiCode'    => $sending_server->c2,
                        'Recipients' => [$phone],
                        'Message'    => $message,
                    ];

                    try {

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                        curl_setopt($ch, CURLOPT_POST, true);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameters));
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                        $result = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $get_sms_status  = curl_error($ch);
                            $customer_status = 'Rejected';
                        } else {

                            $get_data = json_decode($result, true);

                            if (isset($get_data) && is_array($get_data) && array_key_exists('Error', $get_data)) {
                                if ( ! $get_data['Error']) {
                                    $get_sms_status  = 'Delivered|' . $get_data['ReferenceId'];
                                    $customer_status = 'Delivered';
                                } else {
                                    $get_sms_status  = $get_data['Message'];
                                    $customer_status = 'Failed';
                                }
                            } else {
                                $get_sms_status  = $result;
                                $customer_status = 'Rejected';
                            }
                        }
                        curl_close($ch);
                    } catch (Exception $ex) {
                        $get_sms_status  = $ex->getMessage();
                        $customer_status = 'Rejected';
                    }
                    break;

                case SendingServer::TYPE_KUDISMS:

                    $parameters = [
                        'token'        => $sending_server->api_token,
                        'appnamecode'  => $sending_server->c1,
                        'templatecode' => $sending_server->c2,
                        'senderID'     => $data['sender_id'],
                        'recipients'   => $phone,
                        'otp'          => $message,
                    ];

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $gateway_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));

                    $response = curl_exec($ch);

                    if (curl_errno($ch)) {
                        $get_sms_status  = curl_error($ch);
                        $customer_status = 'Failed';
                    } else {

                        $get_data = json_decode($response, true);

                        if (isset($get_data) && is_array($get_data) && array_key_exists('status', $get_data) && array_key_exists('msg', $get_data)) {
                            if ($get_data['status'] == 'success') {
                                $get_sms_status = $customer_status = 'Delivered';
                            } else {
                                $get_sms_status  = $get_data['msg'];
                                $customer_status = 'Failed';
                            }
                        } else {
                            $get_sms_status  = $get_data;
                            $customer_status = 'Failed';
                        }
                    }

                    curl_close($ch);
                    break;

                case SendingServer::TYPE_TERMII:
                    $parameters = [
                        'api_key'      => $sending_server->api_key,
                        'phone_number' => $phone,
                        'code'         => $message,
                    ];

                    try {

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway_url);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        $headers   = [];
                        $headers[] = 'Accept: application/json';
                        $headers[] = 'Content-Type: application/json';
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                        $response = curl_exec($ch);
                        curl_close($ch);

                        $get_data = json_decode($response, true);

                        if (is_array($get_data)) {
                            if (array_key_exists('message_id', $get_data)) {
                                $get_sms_status  = 'Delivered|' . $get_data['message_id'];
                                $customer_status = 'Delivered';
                            } else if (array_key_exists('message', $get_data)) {
                                $get_sms_status  = $get_data['message'];
                                $customer_status = 'Failed';
                            } else {
                                $get_sms_status = $customer_status = 'Failed';
                            }
                        } else {
                            $get_sms_status  = $response;
                            $customer_status = 'Failed';
                        }
                    } catch (Exception $e) {
                        $get_sms_status = $customer_status = $e->getMessage();
                    }
                    break;


                case SendingServer::TYPE_SMSALA:

                    $parameters = [
                        'apiToken'           => $sending_server->api_token,
                        'messageType'        => 3,
                        'messageEncoding'    => 0,
                        'messageText'        => $message,
                        'destinationAddress' => $phone,
                        'sourceAddress'      => $data['sender_id'],
                        'callBackUrl'        => route('dlr.smsala'),
                    ];


                    $gateway_url = $gateway_url . '?' . http_build_query($parameters);

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $gateway_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_HTTPGET, 1);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

                    $result = curl_exec($ch);

                    if (curl_errno($ch)) {
                        $get_sms_status  = curl_error($ch);
                        $customer_status = 'Failed';
                    } else {
                        $get_data = json_decode($result, true)[0];

                        if (array_key_exists('Status', $get_data) && array_key_exists('Remarks', $get_data)) {
                            if ($get_data['Status'] == 'Success') {
                                $get_sms_status  = 'Delivered|' . $get_data['MessageId'];
                                $customer_status = 'Delivered';
                            } else {
                                $get_sms_status  = $get_data['Remarks'];
                                $customer_status = 'Failed';
                            }
                        } else {
                            $get_sms_status  = $get_data;
                            $customer_status = 'Failed';
                        }
                    }

                    curl_close($ch);
                    break;


                default:
                    $get_sms_status  = __('locale.sending_servers.sending_server_not_found');
                    $customer_status = 'Failed';
                    break;
            }

            $cost = substr_count($get_sms_status, 'Delivered') == 1 ? $data['cost'] : '0';


            if ( ! isset($customer_status)) {
                $customer_status = $get_sms_status;
            }

            $customer_status = substr_count($customer_status, 'Delivered') == 1 ? 'Delivered' : 'Failed';

            $reportsData = [
                'user_id'           => $data['user_id'],
                'to'                => $phone,
                'message'           => $message,
                'sms_type'          => 'otp',
                'status'            => $get_sms_status,
                'customer_status'   => $customer_status,
                'sms_count'         => $data['sms_count'],
                'cost'              => $cost,
                'sending_server_id' => $sending_server->id,
            ];

            if (isset($data['sender_id'])) {
                $reportsData['from'] = $data['sender_id'];
            }

            if (isset($data['campaign_id'])) {
                $reportsData['campaign_id'] = $data['campaign_id'];
            }

            if (isset($data['automation_id'])) {
                $reportsData['automation_id'] = $data['automation_id'];
            }

            if (isset($data['api_key'])) {
                $reportsData['api_key']   = $data['api_key'];
                $reportsData['direction'] = Reports::DIRECTION_API;
            } else {
                $reportsData['direction'] = Reports::DIRECTION_OUTGOING;
            }

            $status = Reports::create($reportsData);

            if ($status) {
                return $status;
            }

            return __('locale.exceptions.something_went_wrong');

        }

    }

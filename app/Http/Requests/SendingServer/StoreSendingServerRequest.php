<?php

    namespace App\Http\Requests\SendingServer;

    use App\Models\SendingServer;
    use Illuminate\Foundation\Http\FormRequest;

    class StoreSendingServerRequest extends FormRequest
    {
        /**
         * Determine if the user is authorized to make this request.
         *
         * @return bool
         */
        public function authorize(): bool
        {
            return true;
        }

        /**
         * Get the validation rules that apply to the request.
         *
         * @return array
         */
        public function rules(): array
        {
            $type = $this->input('settings');

            $rules = [
                'name'        => 'required',
                'settings'    => 'required',
                'quota_value' => 'required|numeric',
                'quota_base'  => 'required|numeric',
                'quota_unit'  => 'required',
            ];

            switch ($type) {
                case SendingServer::TYPE_TWILIO:
                case SendingServer::TYPE_TWILIOCOPILOT:
                    $rules['account_sid'] = 'required';
                    $rules['auth_token']  = 'required';
                    break;

                case SendingServer::TYPE_CLICKATELLTOUCH:
                case SendingServer::TYPE_TEXTLOCAL:
                case SendingServer::TYPE_TYNTEC:
                case SendingServer::TYPE_INFOBIP:
                    $rules['api_link'] = 'required|url';
                    $rules['api_key']  = 'required';
                    break;

                case SendingServer::TYPE_CLICKATELLCENTRAL:
                    $rules['api_link'] = 'required|url';
                    $rules['api_key']  = 'required';
                    $rules['username'] = 'required';
                    $rules['password'] = 'required';
                    break;

                case SendingServer::TYPE_ROUTEMOBILE:
                case SendingServer::TYPE_SMSGLOBAL:
                case SendingServer::TYPE_BULKSMS:
                case SendingServer::TYPE_1S2U:
                    $rules['api_link'] = 'required|url';
                    $rules['username'] = 'required';
                    $rules['password'] = 'required';
                    break;

                case 'msg91':
                    $rules['api_link']     = 'required|url';
                    $rules['auth_key']     = 'required';
                    $rules['route']        = 'required';
                    $rules['country_code'] = 'required';
                    break;

                case SendingServer::TYPE_PLIVO:
                case SendingServer::TYPE_PLIVOPOWERPACK:
                    $rules['auth_id']    = 'required';
                    $rules['auth_token'] = 'required';
                    break;

                case SendingServer::TYPE_KARIXIO:
                    $rules['api_link']   = 'required|url';
                    $rules['auth_id']    = 'required';
                    $rules['auth_token'] = 'required';
                    break;

                case SendingServer::TYPE_VONAGE:
                    $rules['api_link']   = 'required|url';
                    $rules['api_key']    = 'required';
                    $rules['api_secret'] = 'required';
                    break;

                case SendingServer::TYPE_AMAZONSNS:
                    $rules['access_key']    = 'required';
                    $rules['secret_access'] = 'required';
                    $rules['region']        = 'required';
                    $rules['sms_type']      = 'required';
                    break;

                case SendingServer::TYPE_WHATSAPPCHATAPI:
                    $rules['api_link']  = 'required|url';
                    $rules['api_token'] = 'required';
                    break;

                case SendingServer::TYPE_SIGNALWIRE:
                    $rules['api_link']   = 'required|url';
                    $rules['api_token']  = 'required';
                    $rules['project_id'] = 'required';
                    break;

                case SendingServer::TYPE_BANDWIDTH:
                    $rules['api_link']       = 'required|url';
                    $rules['api_token']      = 'required';
                    $rules['api_secret']     = 'required';
                    $rules['application_id'] = 'required';
                    break;

                case SendingServer::TYPE_SMPP:
                    $rules['api_link']        = 'required';
                    $rules['username']        = 'required';
                    $rules['password']        = 'required';
                    $rules['port']            = 'required';
                    $rules['source_addr_ton'] = 'required';
                    $rules['source_addr_npi'] = 'required';
                    $rules['dest_addr_ton']   = 'required';
                    $rules['dest_addr_npi']   = 'required';
                    $rules['c2']              = 'required';
                    break;

            }

            return $rules;
        }

    }

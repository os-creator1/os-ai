<?php

namespace kashem\licenseChecker;

use App\Models\AppConfig;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Validator;

class ProductVerifyController extends Controller
{
    public function verifyPurchaseCode()
    {

        $pageConfigs = [
                'bodyClass' => "bg-full-screen-image",
                'blankPage' => true,
        ];

        return view('licenseChecker::verify-purchase-code', compact('pageConfigs'));
    }

    public function postVerifyPurchaseCode(Request $request)
    {

        $v = Validator::make($request->all(), [
                'purchase_code' => 'required|string|min:15', 'application_url' => 'required',
        ]);

        if ($v->fails()) {
            return redirect('verify-purchase-code')->withErrors($v->errors());
        }

        $purchase_code = $request->input('purchase_code');
        $domain_name   = $request->input('application_url');

        $input = trim($domain_name, '/');
        if ( ! preg_match('#^http(s)?://#', $input)) {
            $input = 'http://'.$input;
        }

        $urlParts    = parse_url($input);
        $domain_name = preg_replace('/^www\./', '', $urlParts['host']);

        $post_data = [
                'purchase_code' => $purchase_code,
                'domain'        => $domain_name,
        ];


        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://ultimatesms.codeglen.com/verify/');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $data = curl_exec($ch);
        curl_close($ch);

        $get_data = json_decode($data, true);

        if (is_array($get_data) && array_key_exists('status', $get_data)) {
            if ($get_data['status'] == 'success') {
                AppConfig::where('setting', 'license')->update(['value' => $purchase_code]);
                AppConfig::where('setting', 'license_type')->update(['value' => $get_data['license_type']]);
                AppConfig::where('setting', 'valid_domain')->update(['value' => 'yes']);

                return redirect()->route('admin.home')->with([
                        'message' => $get_data['msg'],
                ]);

            }

            return redirect('verify-purchase-code')->with([
                    'message' => $get_data['msg'],
                    'status'  => 'error',
            ]);
        }

        return redirect('verify-purchase-code')->with([
                'message' => 'Invalid request',
                'status'  => 'error',
        ]);

    }
}

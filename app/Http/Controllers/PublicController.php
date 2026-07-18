<?php

    namespace App\Http\Controllers;

    use App\Models\AppConfig;

    class PublicController extends Controller
    {

        public function termsOfUse()
        {
            $termsOfUse     = AppConfig::where('setting', 'terms_of_use')->first();
            $termsOfUseData = empty($termsOfUse) ? null : $termsOfUse->value;

            return view('auth.termsOfUses', compact('termsOfUseData'));

        }

        public function privacyPolicy()
        {

            $privacyPolicy     = AppConfig::where('setting', 'privacy_policy')->first();
            $privacyPolicyData = empty($privacyPolicy) ? null : $privacyPolicy->value;


            return view('auth.privacyPolicy', compact('privacyPolicyData'));

        }

    }

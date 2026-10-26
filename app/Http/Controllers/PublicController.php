<?php

    namespace App\Http\Controllers;

    use App\Models\AppConfig;

    class PublicController extends Controller
    {

        public function termsOfUse()
        {
            // On the MotionGrove marketing host the legacy Terms URL points to the canonical Terms & Conditions.
            if (app(\App\Library\Marketing\MarketingSite::class)->servesRequest(request())) {
                return redirect()->to(app(\App\Library\Legal\LegalDocuments::class)->url('terms-of-service'), 301);
            }

            $termsOfUse     = AppConfig::where('setting', 'terms_of_use')->first();
            $termsOfUseData = empty($termsOfUse) ? null : $termsOfUse->value;

            return view('auth.termsOfUses', compact('termsOfUseData'));

        }

        public function privacyPolicy()
        {
            // The Legal Center owns this URL on the marketing host; every other host keeps the legacy page.
            if (app(\App\Library\Marketing\MarketingSite::class)->servesRequest(request())) {
                return app(\App\Http\Controllers\Public\LegalController::class)->show('privacy-policy');
            }

            $privacyPolicy     = AppConfig::where('setting', 'privacy_policy')->first();
            $privacyPolicyData = empty($privacyPolicy) ? null : $privacyPolicy->value;


            return view('auth.privacyPolicy', compact('privacyPolicyData'));

        }

    }

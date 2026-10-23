<?php

    namespace App\Http\Controllers;

    use App\Library\Legal\LegalRegistry;
    use App\Library\Marketing\MarketingSite;
    use App\Models\AppConfig;

    class PublicController extends Controller
    {

        public function termsOfUse()
        {
            // The legacy URL keeps working: once the new Terms are live it redirects to the single canonical
            // page; until then (or on a custom domain) the existing operative page answers unchanged.
            if (app(MarketingSite::class)->servesRequest(request()) && ($doc = app(LegalRegistry::class)->visible('terms-of-service'))) {
                return redirect()->to(app(LegalRegistry::class)->url('terms-of-service'), $doc->isPublished() ? 301 : 302);
            }

            $termsOfUse     = AppConfig::where('setting', 'terms_of_use')->first();
            $termsOfUseData = empty($termsOfUse) ? null : $termsOfUse->value;

            return view('auth.termsOfUses', compact('termsOfUseData'));

        }

        public function privacyPolicy()
        {
            // Same URI, same route: the Legal Center's Privacy Policy replaces the stored page only once approved.
            if (app(MarketingSite::class)->servesRequest(request()) && app(LegalRegistry::class)->visible('privacy-policy')) {
                return app(\App\Http\Controllers\Public\LegalController::class)->show(request(), 'privacy-policy');
            }

            $privacyPolicy     = AppConfig::where('setting', 'privacy_policy')->first();
            $privacyPolicyData = empty($privacyPolicy) ? null : $privacyPolicy->value;


            return view('auth.privacyPolicy', compact('privacyPolicyData'));

        }

    }

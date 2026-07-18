<?php

    namespace App\Http\Controllers\Admin;

    use App\Models\AppConfig;
    use App\Models\Country;
    use Illuminate\Http\Request;

    class TaxController extends AdminBaseController
    {

        public function settings(Request $request)
        {

            $this->authorize('manage tax');

            if ($request->isMethod('post')) {

                if (config('app.stage') == 'demo') {
                    return response()->json(['status' => 'error', 'message' => 'Sorry! This option is not available in demo mode']);
                }

                AppConfig::setTaxSettings($request->tax);

                return response()->json(['status' => 'success', 'message' => __('locale.tax.tax_settings_updated')]);
            }

            $breadcrumbs = [
                ['link' => url(config('app.admin_path') . "/dashboard"), 'name' => __('locale.menu.Dashboard')],
                ['link' => url(config('app.admin_path') . "/dashboard"), 'name' => __('locale.menu.Plan')],
                ['name' => __('locale.menu.Tax Settings')],
            ];


            return view('admin.taxes.settings', compact('breadcrumbs'));

        }


        public function addTax(Request $request)
        {

            if (config('app.stage') == 'demo') {
                return response()->json(['status' => 'error', 'message' => 'Sorry! This option is not available in demo mode']);
            }

            $this->authorize('manage tax');

            $country = Country::find($request->country_id);

            if ($request->isMethod('post')) {
                $taxRate = $request->tax;
                if ($taxRate == null) {
                    $taxRate = AppConfig::getTaxByCountry($country);
                }

                $tax['countries'][$country->iso_code] = $taxRate;

                AppConfig::setTaxSettings($tax);

                return response()->json(['status' => 'success', 'message' => __('locale.tax.tax_settings_updated')]);

            }

            return response()->json(['status' => 'error', 'message' => __('locale.exceptions.something_went_wrong')]);

        }

        public function countries()
        {
            return view('admin.taxes.countries');
        }


        public function removeCountry(Request $request)
        {

            if (config('app.stage') == 'demo') {
                return response()->json(['status' => 'error', 'message' => 'Sorry! This option is not available in demo mode']);
            }

            AppConfig::removeTaxCountryByCode($request->code);

            return response()->json([
                'status'  => 'success',
                'message' => __('locale.tax.tax_settings_updated'),
            ]);
        }

    }

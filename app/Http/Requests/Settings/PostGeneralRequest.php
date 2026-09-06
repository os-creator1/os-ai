<?php

    namespace App\Http\Requests\Settings;

    use App\Rules\ValidBrandingImageRule;
    use Illuminate\Foundation\Http\FormRequest;

    class PostGeneralRequest extends FormRequest
    {
        /**
         * Determine if the user is authorized to make this request.
         *
         * @return bool
         */
        public function authorize(): bool
        {
            return $this->user()->can('general settings');
        }

        /**
         * Get the validation rules that apply to the request.
         *
         * Design System M2 Platform Branding contract §6.3/§11 item 15:
         * app_logo/app_favicon now include ValidBrandingImageRule
         * (magic-byte content validation, SVG rejected unconditionally),
         * six new nullable branding fields added, footer_text replaced by
         * footer_company_name/footer_copyright_text.
         *
         * B3 Simplified Platform Settings §10 — SettingsController::
         * postGeneral() now reads exclusively from $request->validated()
         * (closing the previous $request->except(...) exclusion-list
         * defect that let any submitted key reach the write layer), so
         * every field the Platform section actually saves must be
         * declared here. app_keyword and time_format were previously read
         * with plain $request->input() and so were validated by neither
         * this class nor anything else -- declared here now with the same
         * shape those raw reads already implied.
         *
         * B3 Correction 1 — postGeneral() is now shared by three
         * independent forms (Platform, Appearance, Advanced), each
         * submitting only the fields it owns. app_keyword,
         * footer_company_name, footer_copyright_text and custom_script
         * are each owned by exactly one of those forms, so each is
         * 'sometimes': Laravel's Validator::passesOptionalCheck() keys a
         * 'sometimes' field's presence on array_key_exists() against the
         * raw request data, not on whether its value is empty/null, and
         * Validator::validated() omits a 'sometimes' field entirely from
         * its result when the key never existed in the request at all
         * (confirmed directly against Validator.php:865-871/642-669).
         * That is exactly the distinction the controller needs: a field
         * a different section's form never sent is absent from
         * validated() (preserve current value), while this section's own
         * form explicitly submitting '' is present-with-null (the
         * ConvertEmptyStringsToNull middleware already turns '' into null
         * before validation runs) and reaches the controller as an
         * intentional clear.
         *
         * @return array
         */
        public function rules(): array
        {
            return [
                'app_name'                => 'required',
                'app_title'               => 'required',
                'app_keyword'             => 'sometimes|nullable|string|max:255',
                'company_address'         => 'required',
                'footer_company_name'     => 'sometimes|nullable|string|max:255',
                'footer_copyright_text'   => 'sometimes|nullable|string|max:255',
                'app_logo'                => ['sometimes', 'required', 'image', new ValidBrandingImageRule('logo')],
                'app_favicon'             => ['sometimes', 'required', 'mimes:jpeg,bmp,png,ico,jpg', new ValidBrandingImageRule('favicon')],
                'logo_compact'            => ['sometimes', 'required', 'image', new ValidBrandingImageRule('logo_compact')],
                'logo_dark'               => ['sometimes', 'required', 'image', new ValidBrandingImageRule('logo_dark')],
                'auth_illustration'       => ['sometimes', 'required', 'image', new ValidBrandingImageRule('auth_illustration')],
                'installer_illustration'  => ['sometimes', 'required', 'image', new ValidBrandingImageRule('installer_illustration')],
                'country'                 => 'required',
                'timezone'                => 'required|timezone',
                'date_format'             => 'required',
                'time_format'             => 'required|string',
                'language'                => 'required',
                'custom_script'           => 'sometimes|nullable|string|max:5000',
            ];
        }

    }

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
         * @return array
         */
        public function rules(): array
        {
            return [
                'app_name'                => 'required',
                'app_title'               => 'required',
                'company_address'         => 'required',
                'footer_company_name'     => 'nullable|string|max:255',
                'footer_copyright_text'   => 'nullable|string|max:255',
                'app_logo'                => ['sometimes', 'required', 'image', new ValidBrandingImageRule('logo')],
                'app_favicon'             => ['sometimes', 'required', 'mimes:jpeg,bmp,png,ico,jpg', new ValidBrandingImageRule('favicon')],
                'logo_compact'            => ['sometimes', 'required', 'image', new ValidBrandingImageRule('logo_compact')],
                'logo_dark'               => ['sometimes', 'required', 'image', new ValidBrandingImageRule('logo_dark')],
                'auth_illustration'       => ['sometimes', 'required', 'image', new ValidBrandingImageRule('auth_illustration')],
                'installer_illustration'  => ['sometimes', 'required', 'image', new ValidBrandingImageRule('installer_illustration')],
                'country'                 => 'required',
                'timezone'                => 'required|timezone',
                'date_format'             => 'required',
                'language'                => 'required',
                'custom_script'           => 'nullable|string|max:5000',
            ];
        }

    }

<?php

namespace App\Http\Requests\Settings;

use App\Library\Legal\LegalCompany;
use App\Library\PlatformOwner\PlatformOwnerAuthority;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Platform Owner → Settings → Legal Company Information. Authorised on the server by the Platform
 * Owner rule (an administrator account, not inside a View As frame), never by what the page shows.
 * Address format is intentionally not validated beyond length: registered offices vary by country.
 */
class UpdateLegalCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(PlatformOwnerAuthority::class)->allowsRequest($this);
    }

    public function rules(): array
    {
        $max = LegalCompany::MAX;

        return [
            'base_revision' => ['required', 'integer', 'min:0'],
            'brand_name' => ['required', 'string', 'max:'.$max['brand_name']],
            'legal_entity' => ['required', 'string', 'max:'.$max['legal_entity']],
            'company_number' => ['required', 'string', 'max:'.$max['company_number']],
            'registered_address' => ['required', 'string', 'max:'.$max['registered_address']],
            'country' => ['required', 'string', 'max:'.$max['country']],
            'legal_email' => ['required', 'email:rfc', 'max:'.$max['legal_email']],
        ];
    }

    public function attributes(): array
    {
        return [
            'brand_name' => 'trading / brand name',
            'legal_entity' => 'registered legal entity',
            'company_number' => 'company registration number',
            'registered_address' => 'registered office address',
            'country' => 'country',
            'legal_email' => 'legal contact email',
        ];
    }
}

<?php

namespace App\Http\Requests\Business;

use App\Enums\Business\BusinessServiceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class SyncBusinessServicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            // Optional: leaving every row blank keeps the existing services as they are.
            'services' => ['nullable', 'array', 'max:50'],
            'services.*.id' => ['nullable', 'integer'],
            'services.*.name' => ['required', 'string', 'min:2', 'max:255'],
            'services.*.description' => ['nullable', 'string', 'max:5000'],
            'services.*.is_primary' => ['required', 'boolean'],
            'services.*.starting_price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'services.*.currency_code' => ['nullable', 'string', 'size:3'],
            'services.*.status' => ['nullable', new Enum(BusinessServiceStatus::class)],
            'services.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * The wizard form always renders one blank "add a service" row and
     * submits checkboxes that are simply absent when unchecked — drop
     * never-filled-in rows and default missing is_primary to false before
     * the required/boolean rules above run.
     */
    protected function prepareForValidation(): void
    {
        $services = collect($this->input('services', []))
            ->filter(fn ($service) => trim((string) ($service['name'] ?? '')) !== '')
            ->map(fn ($service) => array_merge(['is_primary' => false], $service))
            ->values()
            // The order on the page is the order of the services.
            ->map(fn ($service, $index) => array_merge($service, ['sort_order' => $index]))
            ->all();

        $this->merge(['services' => $services]);
    }
}

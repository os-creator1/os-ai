<?php

namespace App\Http\Requests\Business;

use Illuminate\Contracts\Validation\Validator;

/**
 * Onboarding step 3: at least one service, exactly one primary. Ticked
 * suggestions (`suggested_services[]`) are ordinary services the customer
 * chose, merged with the typed rows; nothing is ever added unselected.
 * Everything else about a service (price, description, ...) is edited later.
 */
class OnboardingServicesRequest extends SyncBusinessServicesRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['services'] = ['required', 'array', 'min:1', 'max:50'];
        $rules['suggested_services'] = ['nullable', 'array', 'max:50'];
        $rules['suggested_services.*'] = ['string', 'max:255'];
        $rules['primary_suggested'] = ['nullable', 'string', 'max:255'];

        return $rules;
    }

    public function messages(): array
    {
        return [
            'services.required' => 'Add at least one service to continue.',
            'services.min' => 'Add at least one service to continue.',
            'services.*.name.min' => 'A service name needs at least 2 characters.',
            'services.*.name.required' => 'Give each service a name.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $services = $this->input('services', []);

            if ($services === [] || $validator->errors()->has('services')) {
                return;
            }

            if (count($services) > 1) {
                $primaries = count(array_filter($services, fn (array $service) => ! empty($service['is_primary'])));

                if ($primaries === 0) {
                    $validator->errors()->add('services', 'Choose which service is your primary one.');
                } elseif ($primaries > 1) {
                    $validator->errors()->add('services', 'Choose only one primary service.');
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $rows = array_values((array) $this->input('services', []));
        $named = array_map(fn ($row) => mb_strtolower(trim((string) ($row['name'] ?? ''))), $rows);
        $primarySuggested = trim((string) $this->input('primary_suggested', ''));

        foreach ((array) $this->input('suggested_services', []) as $name) {
            $name = trim((string) $name);

            if ($name === '' || in_array(mb_strtolower($name), $named, true)) {
                continue;
            }

            $rows[] = ['name' => $name, 'is_primary' => $primarySuggested !== '' && mb_strtolower($primarySuggested) === mb_strtolower($name)];
            $named[] = mb_strtolower($name);
        }

        // A primary pick that matches a row already in the list marks that row instead.
        if ($primarySuggested !== '') {
            foreach ($rows as $index => $row) {
                if (mb_strtolower(trim((string) ($row['name'] ?? ''))) === mb_strtolower($primarySuggested)) {
                    $rows[$index]['is_primary'] = true;
                }
            }
        }

        $this->merge(['services' => $rows]);

        parent::prepareForValidation();

        // A single service needs no ticking.
        $services = $this->input('services', []);

        if (count($services) === 1) {
            $services[0]['is_primary'] = true;
            $this->merge(['services' => $services]);
        }
    }
}

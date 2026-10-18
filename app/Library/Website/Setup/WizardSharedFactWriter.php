<?php

namespace App\Library\Website\Setup;

use App\Enums\Business\BusinessServiceMode;
use App\Library\Business\BusinessLocationManager;
use App\Library\Business\BusinessManager;
use App\Models\Business;
use App\Models\Customer;

/**
 * Applies the wizard's SHARED business facts — business name, phone, email,
 * base location and service areas — through the canonical managers at the
 * moment the owner confirms a screen, so a correction made while building the
 * website is the same correction everywhere in MotionGrove (CRM, invoices,
 * email, Google Business Profile…), not a website-only copy that only
 * reaches the Business when the site is generated.
 *
 * Only steps that opt in with `shared_fact: true` are written, and only what
 * the owner actually supplied: a blank answer is never treated as "erase this
 * confirmed record", and nothing outside the step's own fields is touched.
 */
final class WizardSharedFactWriter
{
    public function __construct(
        private readonly BusinessManager $businesses,
        private readonly BusinessLocationManager $locations,
    ) {
    }

    /**
     * @param  array<int, array<string, mixed>>  $screen  the steps of the screen being confirmed
     * @param  array<string, mixed>  $values  step key => validated value
     * @return array<int, string> the shared facts that really changed, as people name them
     */
    public function write(Business $business, ?Customer $customer, array $screen, array $values): array
    {
        $profile = [];
        $location = [];
        $changed = [];

        foreach ($screen as $step) {
            if (! ($step['shared_fact'] ?? false) || ! array_key_exists($step['key'], $values)) {
                continue;
            }

            $value = $values[$step['key']];
            $module = $step['target_module'];

            if ($module === 'business') {
                $field = (string) $step['target_field'];
                $clean = is_string($value) ? trim($value) : '';

                if ($clean !== '' && $clean !== (string) $business->{$field}) {
                    $profile[$field] = $clean;
                }
            } elseif ($module === 'business_location' && ($step['input_type'] ?? null) === 'location' && is_array($value)) {
                $place = array_filter([
                    'city' => $value['city'] ?? null,
                    'region' => $value['region'] ?? null,
                    'country_code' => $value['country_code'] ?? null,
                ]);

                $current = $business->primaryLocation()->first();
                foreach ($place as $column => $new) {
                    if ($current === null || (string) $current->{$column} !== (string) $new) {
                        $location[$column] = $new;
                    }
                }

                // Coordinates saved for the OLD place would now silently describe the wrong one.
                if ($location !== [] && $current !== null && ($current->latitude !== null || $current->longitude !== null)) {
                    $location['latitude'] = null;
                    $location['longitude'] = null;
                }
            } elseif ($module === 'business_location' && ($step['input_type'] ?? null) === 'string_list' && is_array($value) && $value !== []) {
                $current = $business->primaryLocation()->first();
                $areas = ServiceAreaList::normalize($value);

                if ($current === null || ServiceAreaList::normalize((array) $current->service_area_cities) !== $areas) {
                    $location['service_area_cities'] = $areas;
                }
            }
        }

        if ($profile !== []) {
            // The customer-direct seam when there is a Customer (re-checks Workspace/Business access);
            // a staff member using View-As has none, and the generate-time apply still carries the value.
            if ($customer !== null) {
                $this->businesses->updateOwnBusinessProfile($customer, $business, $profile);
                $business->refresh();

                $changed = array_merge($changed, array_map(fn (string $field) => match ($field) {
                    'name' => 'Business name',
                    'phone' => 'Phone number',
                    'email' => 'Email address',
                    default => ucfirst($field),
                }, array_keys($profile)));
            }
        }

        if ($location !== []) {
            if ($business->primaryLocation()->doesntExist()) {
                $location += [
                    'service_mode' => BusinessServiceMode::ServiceArea->value,
                    'country_code' => $location['country_code'] ?? $business->country_code,
                ];
            }

            $this->locations->upsertPrimaryLocation($business, $location);

            $changed[] = isset($location['service_area_cities']) && count($location) === 1 ? 'Service areas' : 'Base location';
        }

        return $changed;
    }
}

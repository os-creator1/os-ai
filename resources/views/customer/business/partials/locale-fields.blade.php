{{--
    Country, timezone and currency for a customer Business form. Each is a
    select of canonical values (BusinessLocaleOptions), so the form can only
    ever submit a real country code, timezone identifier or offered currency
    code — never text a customer typed. The selects become searchable when
    the page loads select2; without it they are ordinary selects.

    $stored          the Business's saved values when editing one (kept
                     selectable even if a canonical list lacks them)
    $suggestDefaults on a brand-new Business only: preselect the browser's
                     timezone, and fill in the currency when the chosen
                     country has exactly one — never over a currency the
                     customer picked themselves
--}}
@php
    $localeOptions = app(\App\Library\Business\BusinessLocaleOptions::class);
    $stored = $stored ?? [];
    $suggestDefaults = (bool) ($suggestDefaults ?? false);

    $countryOptions = ['' => 'Choose a country'] + \App\Library\Business\BusinessLocaleOptions::withStoredValue($localeOptions->countries(), $stored['country_code'] ?? null);
    $timezoneOptions = ['' => 'Choose a timezone'] + \App\Library\Business\BusinessLocaleOptions::withStoredValue($localeOptions->timezones(), $stored['timezone'] ?? null);
    $currencyOptions = ['' => 'Choose a currency'] + \App\Library\Business\BusinessLocaleOptions::withStoredValue($localeOptions->currencies(), $stored['currency_code'] ?? null);
@endphp

<div class="row" data-business-locale @if ($suggestDefaults) data-suggest-defaults data-country-currency="{{ json_encode($localeOptions->defaultCurrencyByCountry()) }}" @endif>
    <div class="col-md-4">
        <x-select name="country_code" label="Country" :options="$countryOptions" :selected="old('country_code', $stored['country_code'] ?? null)" data-locale-field="country" required />
    </div>
    <div class="col-md-4">
        <x-select name="timezone" label="Timezone" :options="$timezoneOptions" :selected="old('timezone', $stored['timezone'] ?? null)" data-locale-field="timezone" required />
    </div>
    <div class="col-md-4">
        <x-select name="currency_code" label="Currency" :options="$currencyOptions" :selected="old('currency_code', $stored['currency_code'] ?? null)" data-locale-field="currency" required />
    </div>
</div>

@once
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var $ = window.jQuery;
            var searchable = !! ($ && $.fn && $.fn.select2);

            var listen = function (select, handler) {
                if (searchable) {
                    $(select).on('change', handler);
                } else {
                    select.addEventListener('change', handler);
                }
            };

            var choose = function (select, value) {
                var exists = Array.prototype.some.call(select.options, function (option) {
                    return option.value === value;
                });

                if (! exists) {
                    return false;
                }

                select.value = value;

                if (searchable) {
                    $(select).trigger('change.select2');
                }

                return true;
            };

            document.querySelectorAll('[data-business-locale]').forEach(function (group) {
                var country = group.querySelector('[data-locale-field="country"]');
                var timezone = group.querySelector('[data-locale-field="timezone"]');
                var currency = group.querySelector('[data-locale-field="currency"]');

                if (! country || ! timezone || ! currency) {
                    return;
                }

                if (searchable) {
                    [country, timezone, currency].forEach(function (select) {
                        $(select).select2({
                            width: '100%',
                            dropdownParent: $(select).parent(),
                            placeholder: select.options.length ? select.options[0].text : ''
                        });
                    });
                }

                if (! group.hasAttribute('data-suggest-defaults')) {
                    return;
                }

                if (timezone.value === '') {
                    try {
                        choose(timezone, Intl.DateTimeFormat().resolvedOptions().timeZone || '');
                    } catch (error) {
                        // No usable browser timezone: the customer chooses one.
                    }
                }

                var currencyByCountry = {};

                try {
                    currencyByCountry = JSON.parse(group.getAttribute('data-country-currency') || '{}');
                } catch (error) {
                    currencyByCountry = {};
                }

                // A currency already present (for example after a validation
                // error) or picked by the customer is theirs; only an
                // untouched currency follows the country.
                var currencyChosen = currency.value !== '';

                listen(currency, function () {
                    currencyChosen = true;
                });

                listen(country, function () {
                    if (! currencyChosen && currencyByCountry[country.value]) {
                        choose(currency, currencyByCountry[country.value]);
                    }
                });
            });
        });
    </script>
@endonce

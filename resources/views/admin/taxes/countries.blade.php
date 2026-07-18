@if (!count(\App\Models\AppConfig::getTaxSettings()['countries']))
    <div class="alert alert-info mt-2">
        {{ __('locale.tax.no_country_added') }}
    </div>
@else
    <div class="table-responsive">
        <table class="table table-hover mt-4">
            <thead class="table-primary">
            <tr>
                <th>{{ __('locale.labels.country') }}</th>
                <th>{{ __('locale.tax.tax_rate') }}</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @foreach(\App\Models\AppConfig::getTaxSettings()['countries'] as $code => $rate)
                <tr>
                    <td>
                        {{ \App\Models\Country::findByCode($code)->name }}
                    </td>
                    <td>{{ $rate }}%</td>
                    <td>
                        <span data-value="{{ \App\Models\Country::findByCode($code)->id }}"
                              data-tax="{{ $rate }}"
                              class="edit-country-tax text-success cursor-pointer">
                            {{__('locale.buttons.edit') }}
                        </span>
                        |
                        <span data-id="{{ $code }}"
                              class="remove-country-tax text-danger cursor-pointer">
                            {{ __('locale.labels.remove') }}
                        </span>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif

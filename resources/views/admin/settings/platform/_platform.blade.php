@php
    $countryOptions = collect(\App\Helpers\Helper::countries())->pluck('name', 'name')->toArray();
    $dateFormatOptions = [
        'd/m/Y' => '15/05/2016',
        'd.m.Y' => '15.05.2016',
        'd-m-Y' => '15-05-2016',
        'm/d/Y' => '05/15/2016',
        'Y/m/d' => '2016/05/15',
        'Y-m-d' => '2016-05-15',
        'M d Y' => 'May 15 2016',
        'd M Y' => '15 May 2016',
        'jS M y' => '15th May 16',
    ];
    $timeFormatOptions = [
        'g:i A' => '12-hour',
        'H:i' => '24-hour',
    ];
    $languageOptions = collect($language)->pluck('name', 'code')->toArray();
@endphp

<form class="form form-vertical" action="{{ route('admin.settings.general') }}" method="post">
    @csrf
    <input type="hidden" name="tab" value="platform">

    <div class="row">
        <div class="col-md-6">
            <x-input name="app_name" label="{{ __('locale.settings.application_name') }}" value="{{ old('app_name', config('app.name')) }}" required />
            @error('app_name')<div class="invalid-feedback d-block text-caption mb-2">{{ $message }}</div>@enderror

            <x-input name="app_title" label="{{ __('locale.settings.application_title') }}" value="{{ old('app_title', config('app.title')) }}" required />
            @error('app_title')<div class="invalid-feedback d-block text-caption mb-2">{{ $message }}</div>@enderror

            <x-input name="app_keyword" label="{{ __('locale.settings.application_keyword') }}" value="{{ old('app_keyword', config('app.keyword')) }}" />

            <div class="ds-field mb-3">
                <label for="company_address" class="form-label text-label required">{{ __('locale.labels.address') }}</label>
                <textarea id="company_address" name="company_address" rows="4" class="form-control transition-fast" required>{{ old('company_address', \App\Helpers\Helper::app_config('company_address')) }}</textarea>
                @error('company_address')<div class="invalid-feedback d-block text-caption">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="col-md-6">
            <x-select name="country" label="{{ __('locale.labels.country') }}" :options="$countryOptions" :selected="old('country', config('app.country'))" class="select2" />

            <x-select name="timezone" label="{{ __('locale.labels.timezone') }}" :options="\App\Helpers\Helper::timezoneList()" :selected="old('timezone', config('app.timezone'))" class="select2" />

            <x-select name="date_format" label="{{ __('locale.labels.date_format') }}" :options="$dateFormatOptions" :selected="old('date_format', config('app.date_format'))" />

            <x-select name="time_format" label="{{ __('locale.settings.time_format') }}" :options="$timeFormatOptions" :selected="old('time_format', config('app.time_format'))" />

            <x-select name="language" label="{{ __('locale.labels.default') }} {{ __('locale.labels.language') }}" :options="$languageOptions" :selected="old('language', config('app.locale'))" class="select2" />
            <p class="text-caption mb-3">
                <a href="{{ route('admin.languages.index') }}">{{ __('locale.menu.Language') }} &rarr;</a>
            </p>
        </div>
    </div>

    <button type="submit" class="btn btn-primary mb-1">
        <x-ds-icon name="save" size="16" /> {{ __('locale.buttons.save') }}
    </button>
</form>

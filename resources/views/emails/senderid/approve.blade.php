@component('mail::message')

{!! $content !!}

@component('mail::button', ['url' => $url])
{{ __('locale.buttons.view') }}
@endcomponent

{{ __('locale.labels.thanks') }},<br>
{{ config('app.name') }}
@endcomponent

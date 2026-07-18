@component('mail::message')
{!! $content !!}
@component('mail::button', ['url' => $url])
{{ __('locale.labels.sender_id') }}
@endcomponent

{{ __('locale.labels.thanks') }},<br>
{{ config('app.name') }}
@endcomponent

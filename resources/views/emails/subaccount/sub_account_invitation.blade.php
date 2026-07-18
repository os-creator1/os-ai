@component('mail::message')
{!! $content !!}
@component('mail::button', ['url' => $url])
{{ __('locale.sub_accounts.accept_invitation') }}
@endcomponent

{{ __('locale.labels.thanks') }},<br>
{{ config('app.name') }}
@endcomponent

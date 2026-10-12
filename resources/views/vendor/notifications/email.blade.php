@php
    $mailBrand = ($brand ?? null) instanceof \App\Library\Mail\MailBrand ? $brand : \App\Library\Mail\MailBrand::platform();
    $signoff = 'The ' . $mailBrand->name . ' team';
@endphp
<x-mail::message :brand="$brand ?? null" :icon="$icon ?? null">
{{-- Heading: an explicit heading/greeting, otherwise a plain default --}}
@if (! empty($greeting))
# {{ $greeting }}
@elseif ($level === 'error')
# @lang('Whoops!')
@endif

{{-- Intro lines --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

{{-- Details panel: lines supplied through the `details` view data --}}
@if (! empty($details))
<x-mail::panel>
@foreach ($details as $label => $value)
**{{ $label }}:** {{ $value }}

@endforeach
</x-mail::panel>
@endif

{{-- Action --}}
@isset($actionText)
<x-mail::button :url="$actionUrl" :brand="$brand ?? null">
{{ $actionText }}
</x-mail::button>
@endisset

{{-- Outro lines --}}
@foreach ($outroLines as $line)
{{ $line }}

@endforeach

{{-- Salutation --}}
@if (! empty($salutation))
{{ $salutation }}
@else
@lang('Regards'),<br>
{{ $signoff }}
@endif

{{-- Fallback link --}}
@isset($actionText)
<x-slot:subcopy>
@lang('Button not working? Copy and paste this link into your browser:') <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>

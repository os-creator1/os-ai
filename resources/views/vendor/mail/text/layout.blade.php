@props(['brand' => null, 'icon' => null])
@php
    $brand = $brand instanceof \App\Library\Mail\MailBrand ? $brand : \App\Library\Mail\MailBrand::platform();
@endphp
{{ $brand->name }}

{!! strip_tags($slot) !!}
@isset($subcopy)

{!! strip_tags($subcopy) !!}
@endisset

--
{!! implode("\n", array_map('e', $brand->footerLines)) !!}

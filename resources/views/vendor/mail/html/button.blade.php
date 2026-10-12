@props([
    'url',
    'color' => 'primary',
    'align' => 'center',
    'brand' => null,
])
@php
    $brand = $brand instanceof \App\Library\Mail\MailBrand ? $brand : \App\Library\Mail\MailBrand::platform();
@endphp
<table class="action" align="{{ $align }}" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:24px 0;">
<tr>
<td align="{{ $align }}">
<table border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center" bgcolor="{{ $brand->accent }}" style="background-color:{{ $brand->accent }};border-radius:10px;">
<a href="{{ $url }}" class="button button-{{ $color }}" target="_blank" rel="noopener" style="display:inline-block;padding:13px 26px;font:600 15px/1.2 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:{{ $brand->accentInk }};text-decoration:none;border-radius:10px;border:1px solid {{ $brand->accent }};">{!! $slot !!}</a>
</td>
</tr>
</table>
</td>
</tr>
</table>

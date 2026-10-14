@props(['brand' => null, 'icon' => null])
@php
    $brand = $brand instanceof \App\Library\Mail\MailBrand ? $brand : \App\Library\Mail\MailBrand::platform();
    $font = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
    $glyphs = ['mail' => '&#9993;', 'check' => '&#10003;', 'sign' => '&#9998;', 'calendar' => '&#9719;', 'alert' => '&#33;'];
    $glyph = $glyphs[$icon ?? ''] ?? null;
    $subcopyHtml = isset($subcopy) ? str_replace('<a ', '<a style="color:' . e($brand->accentText) . ';word-break:break-all;" ', (string) $subcopy) : '';
@endphp
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
<head>
<title>{{ $brand->name }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<style>
@media only screen and (max-width: 600px) {
.inner-body { width: 100% !important; }
.card-pad { padding-left: 22px !important; padding-right: 22px !important; }
}
@media only screen and (max-width: 500px) {
.button { display: block !important; width: auto !important; }
}
</style>
{!! $head ?? '' !!}
</head>
<body style="margin:0;padding:0;background-color:#f6f3ee;font-family:{!! $font !!};">
<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation" bgcolor="#f6f3ee" style="background-color:#f6f3ee;">
<tr>
<td align="center" style="padding:32px 16px;">
<table class="inner-body" align="center" width="560" cellpadding="0" cellspacing="0" role="presentation" style="width:560px;max-width:560px;">
<!-- Brand header -->
<tr>
<td align="center" style="padding:0 0 20px 0;">
<table cellpadding="0" cellspacing="0" role="presentation" align="center"><tr>
@if ($brand->logoUrl && $brand->kind === 'platform')
<td valign="middle" align="center"><a href="{{ $brand->homeUrl ?: '#' }}" style="text-decoration:none;font:600 18px/1.2 {!! $font !!};color:#2b2623;"><img src="{{ $brand->logoUrl }}" alt="{{ $brand->name }}" height="36" style="display:block;height:36px;max-height:36px;width:auto;max-width:100%;border:0;font:600 18px/36px {!! $font !!};color:#2b2623;" /></a></td>
@elseif ($brand->logoUrl)
<td style="padding-right:10px;" valign="middle"><img src="{{ $brand->logoUrl }}" alt="{{ $brand->name }}" height="32" style="display:block;height:32px;max-width:160px;width:auto;border:0;font-size:14px;color:#2b2623;" /></td>
@elseif ($brand->kind !== 'platform')
<td valign="middle" align="center" width="32" height="32" bgcolor="{{ $brand->accent }}" style="width:32px;height:32px;background-color:{{ $brand->accent }};border-radius:8px;color:{{ $brand->accentInk }};font:700 13px/32px {!! $font !!};">{{ $brand->initials() }}</td>
@endif
@if (! ($brand->logoUrl && $brand->kind === 'platform'))
<td valign="middle" style="@if ($brand->kind !== 'platform' && ! $brand->logoUrl)padding-left:10px;@endif font:600 18px/1.2 {!! $font !!};color:#2b2623;">@if ($brand->homeUrl)<a href="{{ $brand->homeUrl }}" style="color:#2b2623;text-decoration:none;">{{ $brand->name }}</a>@else{{ $brand->name }}@endif</td>
@endif
</tr></table>
</td>
</tr>
<!-- Card -->
<tr>
<td style="background-color:#ffffff;border-radius:14px;box-shadow:0 1px 3px rgba(43,38,35,0.08);" bgcolor="#ffffff">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr><td height="4" bgcolor="{{ $brand->accent }}" style="height:4px;line-height:4px;font-size:0;background-color:{{ $brand->accent }};border-radius:14px 14px 0 0;">&nbsp;</td></tr>
<tr>
<td class="content-cell card-pad" style="padding:32px 36px 28px 36px;font-family:{!! $font !!};">
@if ($glyph)
<table cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 20px 0;"><tr><td align="center" valign="middle" width="44" height="44" bgcolor="{{ $brand->accentTint }}" style="width:44px;height:44px;background-color:{{ $brand->accentTint }};border-radius:12px;color:{{ $brand->accentText }};font:400 20px/44px {!! $font !!};">{!! $glyph !!}</td></tr></table>
@endif
{!! Illuminate\Mail\Markdown::parse($slot) !!}
</td>
</tr>
@if ($subcopyHtml !== '')
<tr>
<td class="card-pad" style="padding:18px 36px;background-color:#faf8f5;border-top:1px solid #eee9e2;border-radius:0 0 14px 14px;font-family:{!! $font !!};" bgcolor="#faf8f5">
{!! $subcopyHtml !!}
</td>
</tr>
@endif
</table>
</td>
</tr>
<!-- Footer -->
<tr>
<td align="center" style="padding:22px 12px 0 12px;font:400 12px/1.6 {!! $font !!};color:#7a716a;">
@foreach ($brand->footerLines as $line)
<p style="margin:0 0 4px 0;font:400 12px/1.6 {!! $font !!};color:#7a716a;">{{ $line }}</p>
@endforeach
</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>

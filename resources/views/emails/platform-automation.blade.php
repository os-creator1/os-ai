<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>{{ config('app.name') }}</title></head>
<body style="margin:0;padding:24px;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    <div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;padding:28px;">
        <p style="margin:0 0 16px;font-weight:bold;font-size:16px;">{{ config('app.name') }}</p>
        <div style="font-size:15px;line-height:1.55;">{!! nl2br(e($body)) !!}</div>
        <p style="margin:24px 0 0;font-size:12px;color:#6b7280;">
            @if ($purpose === 'product_notice')
                You are receiving this service notice because of your {{ config('app.name') }} account.
            @else
                You are receiving this account message because of your {{ config('app.name') }} account.
            @endif
        </p>
    </div>
</body>
</html>

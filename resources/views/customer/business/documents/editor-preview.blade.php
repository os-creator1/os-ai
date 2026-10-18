{{--
    Contract 17B — the editor's preview: the document's saved draft (or issued
    version) rendered through the ONE block renderer, in a standalone print-width
    page. Nothing here is a second render implementation: $blocksHtml is the
    HtmlString DocumentBlockRenderer built; everything else is escaped Blade.
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Preview - {{ $previewTitle ?? $document->title }}</title>
    <style>
        body{margin:0;background:#EFEDE8;font-family:system-ui,-apple-system,"Segoe UI",sans-serif}
        .doc-preview-bar{position:sticky;top:0;z-index:2;display:flex;justify-content:space-between;align-items:center;gap:12px;padding:8px 16px;background:#262522;color:#fff;font-size:13px}
        .doc-preview-bar span{opacity:.8}
        .doc-preview-page{margin:24px auto;max-width:794px;box-shadow:0 1px 3px rgba(38,37,34,.18),0 8px 24px rgba(38,37,34,.08)}
        .doc-preview-brand{max-width:794px;margin:24px auto 0;padding:0 56px;color:#6F6D67;font-size:13px;letter-spacing:.04em;text-transform:uppercase}
        @media (max-width:640px){.doc-preview-brand{padding:0 18px}.doc-preview-page{margin:0}}
        @media print{body{background:#fff}.doc-preview-bar,.doc-preview-brand{display:none}.doc-preview-page{margin:0;box-shadow:none;max-width:none}}
    </style>
</head>
<body>
<div class="doc-preview-bar" data-role="preview-bar">
    <strong>Preview</strong>
    <span>@if(isset($previewNote)){{ $previewNote }}@elseif($isDraft)This is how the recipient will see your draft. Names are filled in from the contact.@else This is the version that was sent.@endif</span>
</div>
<div class="doc-preview-page" data-role="preview-page">
    {{ $blocksHtml }}
</div>
</body>
</html>

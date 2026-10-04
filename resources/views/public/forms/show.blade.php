<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $context->form->name }}</title>
    @include('public.forms._style', ['design' => $version->style()])
    <style>body{margin:0;background:var(--pf-bg)}</style>
</head>
<body class="pf-scope">
<main class="pf-shell">
    @include('public.forms._card', [
        'name' => $context->form->name,
        'preview' => false,
    ])
</main>
</body>
</html>

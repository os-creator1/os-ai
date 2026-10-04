<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $context->form->name }}</title>
    {{-- The style of the version the visitor completed, like their thank-you message. --}}
    @include('public.forms._style', ['design' => $version->style()])
    <style>body{margin:0;background:var(--pf-bg)}</style>
</head>
<body class="pf-scope">
<main class="pf-shell" data-role="public-form-thanks">
    <div class="pf-card">
        <h1 class="pf-title">{{ $context->form->name }}</h1>
        {{-- The thank-you of the version the visitor completed (see PublicFormController::thanks). --}}
        <p>{{ $version->success_message }}</p>
    </div>
</main>
</body>
</html>

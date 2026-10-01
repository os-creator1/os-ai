<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $context->form->name }}</title>
</head>
<body style="font-family:system-ui,sans-serif;max-width:42rem;margin:3rem auto;padding:0 1rem">
<main data-role="public-form-thanks">
    <h1>{{ $context->form->name }}</h1>
    <p>{{ $context->version->success_message }}</p>
</main>
</body>
</html>

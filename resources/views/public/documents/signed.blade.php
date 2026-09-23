{{--
    Implementation Contract 17 §6.5 — the post-signature confirmation.

    Makes NO legal claim: it does not describe the record as a qualified or
    advanced signature, as identity-verified, or as legally sufficient
    anywhere. It states only what actually happened.
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Signature recorded</title>
    <style>
        body{font-family:system-ui,sans-serif;max-width:42rem;margin:3rem auto;padding:0 1rem;color:#17212b}
    </style>
</head>
<body>
<main>
    <h1>Signature recorded</h1>
    <p>Thank you. Your typed signature for &ldquo;{{ $document->title }}&rdquo; was recorded on {{ $document->signed_at?->format('j F Y') }}.</p>
    <p>{{ $business->name }} has been notified. You can close this page.</p>
</main>
</body>
</html>

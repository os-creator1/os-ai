<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Page not found{{ ! empty($siteName) ? ' | '.$siteName : '' }}</title>
    <meta name="robots" content="noindex, follow">
    <style>
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #1f2937; background: #fff; display: flex; min-height: 100vh; align-items: center; justify-content: center; text-align: center; padding: 24px; }
        main { max-width: 32rem; }
        h1 { font-size: 1.75rem; margin: 0 0 .75rem; }
        p { margin: 0 0 1.5rem; line-height: 1.6; color: #4b5563; }
        a { display: inline-block; padding: .65rem 1.25rem; border-radius: .5rem; background: #111827; color: #fff; text-decoration: none; }
    </style>
</head>
<body>
    <main>
        <h1>We couldn't find that page</h1>
        <p>The page you were looking for has moved or doesn't exist.</p>
        @if (! empty($homeUrl))
            <a href="{{ $homeUrl }}">{{ ! empty($siteName) ? 'Go to '.$siteName : 'Go to the home page' }}</a>
        @endif
    </main>
</body>
</html>

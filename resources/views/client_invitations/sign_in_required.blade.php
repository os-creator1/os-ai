<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Sign in to continue</title>
</head>
<body>
    <p>Sign in or create an account to continue.</p>
    <p><a href="{{ route('login') }}">Sign in</a></p>
    @if (config('account.can_register'))
        <p><a href="{{ route('register') }}">Create an account</a></p>
    @endif
    <p>Once signed in, return to this same invitation link to continue.</p>
</body>
</html>

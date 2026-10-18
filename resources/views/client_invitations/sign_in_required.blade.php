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
    <p>After you sign in or verify your email, we bring you back to this invitation automatically.</p>
</body>
</html>

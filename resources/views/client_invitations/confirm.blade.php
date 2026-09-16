<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Accept invitation</title>
</head>
<body>
    <p>You have been invited to a new workspace.</p>
    <form method="POST" action="{{ route('client-invitations.accept', ['uid' => $uid, 'token' => $token]) }}">
        @csrf
        <button type="submit">Accept invitation</button>
    </form>
</body>
</html>

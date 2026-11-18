<?php

namespace App\Library\BusinessEmail\Imap;

final class SmtpGatewaySigner
{
    public static function sign(string $secret, string $timestamp, string $nonce, string $body): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $nonce . '.' . $body, $secret);
    }
}

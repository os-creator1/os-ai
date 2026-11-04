<?php

namespace App\Library\Website\Domains;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Does https://<host>/ really answer, with a certificate the visitor's browser would accept? TLS verification stays ON
 * (a failed handshake is "not serving yet"). A plain class so tests bind a double and never touch the network.
 */
class HttpsProbe
{
    public function isServing(string $host): bool
    {
        try {
            $response = Http::timeout(8)->withoutRedirecting()->get('https://'.$host.'/');

            // Any answer below 500 means TLS verified and our app routed the Host (a 301 to the canonical host counts).
            return $response->status() < 500;
        } catch (Throwable) {
            return false;
        }
    }
}

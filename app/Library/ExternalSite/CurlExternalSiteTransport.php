<?php

namespace App\Library\ExternalSite;

/**
 * GET over cURL, DESTINATION-PINNED.
 *
 * The connection goes to the IP the UrlGuard validated: CURLOPT_RESOLVE maps
 * `host:port` to that address, so cURL never asks DNS again, while the URL keeps
 * the original hostname — which is what the Host header, the TLS SNI and the
 * certificate check (peer AND host verification on) use. After the transfer the
 * address cURL really connected to is compared with the pinned one.
 *
 * Bounded while reading, not after: the write callback aborts the transfer the
 * moment the body would pass `maxBytes` (counted on the DECODED stream, so a
 * compression bomb is cut at the same limit) or the response is of a type we did
 * not ask for; header bytes are capped too. No cookies are ever sent or kept (the
 * cookie engine is never enabled), no redirect is followed, no credentials are
 * offered, no proxy is used, only http/https are permitted, and only GET.
 */
final class CurlExternalSiteTransport implements ExternalSiteTransport
{
    private const MAX_HEADER_BYTES = 32768;

    public function get(TransportRequest $request): TransportResponse
    {
        $target = $request->target;
        $ch = curl_init();

        $headers = [];
        $headerBytes = 0;
        $body = '';
        $bodyBytes = 0;
        $abort = null;
        $status = 0;
        $headersDone = false;

        curl_setopt_array($ch, [
            CURLOPT_URL => $target->url,
            CURLOPT_HTTPGET => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$target->host.':'.$target->port.':'.$target->ip],
            CURLOPT_CONNECTTIMEOUT => $request->connectTimeout,
            CURLOPT_TIMEOUT => $request->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => $request->userAgent,
            CURLOPT_HTTPHEADER => ['Accept: '.$request->accept, 'Accept-Language: en'],
            CURLOPT_ENCODING => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_UNRESTRICTED_AUTH => false,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$headers, &$headerBytes, &$status, &$headersDone, &$abort): int {
                $headerBytes += strlen($line);

                if ($headerBytes > self::MAX_HEADER_BYTES) {
                    $abort = 'too_large';

                    return 0;
                }

                $trimmed = trim($line);

                if (str_starts_with($trimmed, 'HTTP/')) {
                    // A new status line starts a fresh header block (interim 1xx or the final one).
                    $headers = [];
                    $headersDone = false;
                    $status = (int) (preg_split('/\s+/', $trimmed)[1] ?? 0);
                } elseif ($trimmed === '') {
                    $headersDone = $status >= 200;
                } elseif (str_contains($trimmed, ':')) {
                    [$name, $value] = explode(':', $trimmed, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$body, &$bodyBytes, &$headers, &$headersDone, &$abort, &$status, $request): int {
                if ($headersDone && $bodyBytes === 0 && $status >= 200 && $status < 300 && $request->allowedContentTypes !== []) {
                    $type = strtolower(trim(explode(';', (string) ($headers['content-type'] ?? ''))[0]));
                    $ok = false;

                    foreach ($request->allowedContentTypes as $allowed) {
                        if ($type === $allowed || str_starts_with($type, $allowed)) {
                            $ok = true;
                        }
                    }

                    if (! $ok) {
                        $abort = 'content_type_not_allowed';

                        return 0;
                    }
                }

                $bodyBytes += strlen($chunk);

                if ($bodyBytes > $request->maxBytes) {
                    $abort = 'too_large';

                    return 0;
                }

                $body .= $chunk;

                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $primaryIp = curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        curl_close($ch);

        $primaryIp = is_string($primaryIp) && $primaryIp !== '' ? $primaryIp : null;
        $error = $abort;

        if ($error === null && $ok === false) {
            $error = match (true) {
                $errno === CURLE_OPERATION_TIMEDOUT => 'timeout',
                in_array($errno, [CURLE_SSL_CONNECT_ERROR, CURLE_SSL_CERTPROBLEM, 51, 60], true) => 'tls_failed',
                in_array($errno, [CURLE_COULDNT_CONNECT, CURLE_COULDNT_RESOLVE_HOST], true) => 'connect_failed',
                default => 'transport_error',
            };
        }

        // Defence in depth: the address actually used must be the pinned one.
        if ($error === null && $primaryIp !== null && ! $this->sameAddress($primaryIp, $target->ip)) {
            $error = 'ip_mismatch';
        }

        return new TransportResponse($status, $headers, $error === null ? $body : '', $error, $primaryIp);
    }

    private function sameAddress(string $a, string $b): bool
    {
        $pa = @inet_pton($a);
        $pb = @inet_pton($b);

        return $pa !== false && $pa === $pb;
    }
}

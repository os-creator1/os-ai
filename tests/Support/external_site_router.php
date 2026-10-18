<?php

/*
 * Router for the PHP built-in server used ONLY by CurlExternalSiteTransportTest:
 * a deliberately misbehaving "remote website" so the real cURL transport's
 * bounds (bytes, content type, timeout, no redirect following, no cookies,
 * destination pinning) are exercised against a real socket.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

switch ($path) {
    case '/ok':
        header('Content-Type: text/html; charset=utf-8');
        echo '<html><head><title>OK</title></head><body>host='.($_SERVER['HTTP_HOST'] ?? '').'</body></html>';
        break;

    case '/big':
        header('Content-Type: text/html');
        for ($i = 0; $i < 30; $i++) {
            echo str_repeat('x', 100000);
            flush();
        }
        break;

    case '/pdf':
        header('Content-Type: application/pdf');
        echo '%PDF-1.4 not a page';
        break;

    case '/slow':
        sleep(3);
        header('Content-Type: text/html');
        echo 'late';
        break;

    case '/redirect':
        http_response_code(302);
        header('Location: http://169.254.169.254/latest/meta-data/');
        break;

    case '/cookie-set':
        setcookie('session', 'secret', 0, '/');
        header('Content-Type: text/html');
        echo 'cookie set';
        break;

    case '/cookie-echo':
        header('Content-Type: text/html');
        echo 'cookies='.json_encode($_COOKIE).' auth='.($_SERVER['HTTP_AUTHORIZATION'] ?? 'none').' ua='.($_SERVER['HTTP_USER_AGENT'] ?? '');
        break;

    default:
        http_response_code(404);
        header('Content-Type: text/html');
        echo 'nope';
}

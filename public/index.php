<?php

    $requiredPhpVersion = '8.2.0';
    $requiredExtensions = [
        'openssl',
        'pdo',
        'bcmath',
        'ctype',
        'fileinfo',
        'mbstring',
        'tokenizer',
        'xml',
        'json',
        'curl',
    ];

    if (version_compare(PHP_VERSION, $requiredPhpVersion, '<')) {
        echo "ERROR: PHP {$requiredPhpVersion} or higher is required.<br />";
        exit(0);
    }

    foreach ($requiredExtensions as $ext) {
        if ( ! extension_loaded($ext)) {
            echo "ERROR: The required PHP extension <strong>{$ext}</strong> is missing from your system.<br />";
            exit(0);
        }
    }

    if ( ! function_exists('proc_open')) {
        echo "ERROR: The required PHP function <strong>proc_open</strong> is disabled.<br />";
        exit(0);
    }

    if ( ! function_exists('curl_version')) {
        echo "ERROR: The required PHP function <strong>curl_version</strong> is disabled.<br />";
        exit(0);
    }

    if ( ! function_exists('base64_decode')) {
        echo "ERROR: The required PHP function <strong>base64_decode</strong> is disabled.<br />";
        exit(0);
    }

/**
 * Laravel - A PHP Framework For Web Artisans
 *
 * @package  Laravel
 * @author   Taylor Otwell <taylor@laravel.com>
 */

define('LARAVEL_START', microtime(true));

/*
|--------------------------------------------------------------------------
| Register The Auto Loader
|--------------------------------------------------------------------------
|
| Composer provides a convenient, automatically generated class loader for
| our application. We just need to utilize it! We'll simply require it
| into the script here so that we don't have to worry about manual
| loading any of our classes later on. It feels great to relax.
|
*/

require __DIR__.'/../vendor/autoload.php';

/*
|--------------------------------------------------------------------------
| Turn On The Lights
|--------------------------------------------------------------------------
|
| We need to illuminate PHP development, so let us turn on the lights.
| This bootstraps the framework and gets it ready for use, then it
| will load up this application so that we can run it and send
| the responses back to the browser and delight our users.
|
*/

$app = require_once __DIR__.'/../bootstrap/app.php';

/*
|--------------------------------------------------------------------------
| Run The Application
|--------------------------------------------------------------------------
|
| Once we have the application, we can handle the incoming request
| through the kernel, and send the associated response back to
| the client's browser allowing them to enjoy the creative
| and wonderful application we have prepared for them.
|
*/


$app->bind('path.public', function () {
    return __DIR__;
});

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

try {

    $response = $kernel->handle(
            $request = Illuminate\Http\Request::capture()
    );
} catch (Exception $ex) {
    return $ex->getMessage();
}

$response->send();

$kernel->terminate($request, $response);

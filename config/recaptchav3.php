<?php
return [
    'origin' => env('NOCAPTCHA_ORIGIN', 'https://www.google.com/recaptcha'),
    'sitekey' => env('NOCAPTCHA_SITEKEY', ''),
    'secret' => env('NOCAPTCHA_SECRET', ''),
    'locale' => env('NOCAPTCHA_LOCALE', 'en')
];
